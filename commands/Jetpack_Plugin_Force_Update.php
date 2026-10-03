<?php

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Force-reinstalls a plugin from a specific package (URL or local zip) across Jetpack-connected
 * sites, over SSH.
 *
 * This is the deliberate, riskier sibling of `jetpack:plugin-update`. Where that command performs
 * normal, version-checked updates through the Jetpack REST tunnel, this one installs a *specific
 * package* with `wp plugin install --force` over a direct SSH connection to each site. It exists for
 * the cases the normal command cannot serve — replacing, reinstalling, or downgrading a plugin from
 * an exact build (e.g. a release candidate on staging only).
 *
 * It runs over SSH precisely to avoid the WPCOM `plugins/replace` upload path, whose malicious-upload
 * defense revokes the shared OAuth token fleet-wide when the same zip is uploaded to many sites. SFTP
 * + `wp plugin install` is a different door and carries none of that risk.
 *
 * Behavior:
 * - Site universe is the Jetpack-connected fleet. `--sites` narrows it to `production`, `staging`
 *   (both filtered by URL substring, not API status — Pressable staging URLs are often flagged
 *   "production"), a comma-separated list, or a CSV file (first column).
 * - Each site is reached over SSH via the helper matching its type (`is_wpcom_atomic` → WPCOM/WoA,
 *   otherwise Pressable).
 * - By default only sites that already have the plugin are touched; the plugin is updated/reinstalled
 *   in place and its activation state is never changed. `--install-new` (gated) also installs on
 *   requested sites that lack it, left inactive unless `--activate` is also passed (which only affects
 *   those new installs).
 * - wp-cli runs with `--skip-plugins --skip-themes` (as globals, before the subcommand) so a site
 *   with a fatal plugin/theme can still be fixed.
 * - Every processed site is appended to a resumable JSONL ledger that doubles as the skip-list on the
 *   next run; repeated rows for the same (plugin, site) are collapsed on read, latest winning.
 *
 * Examples:
 *   # Reinstall an Atlantis RC on staging only, from a URL (dry run first)
 *   team51 jetpack:plugin-force-update a8csp-atlantis --package=https://example.com/a8csp-atlantis.zip --sites=staging --dry-run
 *
 *   # Force-reinstall from a local build across a curated list
 *   team51 jetpack:plugin-force-update my-plugin --package=./builds/my-plugin.zip --sites=one.com,two.com
 *
 *   # Install a plugin everywhere it is requested, including sites that lack it
 *   team51 jetpack:plugin-force-update my-plugin --package=https://example.com/my-plugin.zip --install-new
 */
#[AsCommand( name: 'jetpack:plugin-force-update' )]
final class Jetpack_Plugin_Force_Update extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The wp-cli invocation, with the bootstrap-skipping globals.
	 *
	 * `--skip-plugins` and `--skip-themes` are wp-cli *global* parameters and must precede the
	 * subcommand: placed after it, both Pressable and WoA reject the whole call with
	 * `Error: Parameter errors: unknown --skip-plugins parameter` (exit 1) before it does anything.
	 * (`wp plugin list` tolerates them trailing because it reads unknown `--<field>=` args as
	 * filters, which makes the mistake look harmless.) Skipping the bootstrap keeps a site whose
	 * plugin or theme fatals still fixable — the whole point of reaching for this command.
	 *
	 * @var string
	 */
	private const WP = 'wp --skip-plugins --skip-themes';

	/**
	 * Default JSONL ledger filename, written in the CLI's own directory.
	 *
	 * @var string
	 */
	private const DEFAULT_LEDGER = 'plugin-force-update-ledger.jsonl';

	/**
	 * Ledger statuses that mark a site as complete (skipped on future runs). A `failed` site is
	 * deliberately re-attempted next run.
	 *
	 * @var array<int, string>
	 */
	private const DONE_STATUSES = array( 'updated', 'installed-new' );

	/**
	 * Ledger statuses that count as complete only while `--install-new` is off.
	 *
	 * A site without the plugin is a settled no-op for a default run, and re-probing it every time
	 * would clog `--limit`: `--limit` slices *after* the ledger skip, so sites that can only ever
	 * answer `skipped-absent` sit at the front of the list and a batched rollout never advances past
	 * them. Under `--install-new` the same row is not settled at all — that run is precisely the one
	 * meant to reach those sites — so it is re-probed instead.
	 *
	 * @var array<int, string>
	 */
	private const DONE_UNLESS_INSTALLING_NEW = array( 'skipped-absent' );

	/**
	 * The plugin slug (folder name) to force-install.
	 *
	 * @var string
	 */
	private string $plugin = '';

	/**
	 * The raw `--package` value: an http(s) URL or a local .zip path.
	 *
	 * @var string
	 */
	private string $package = '';

	/**
	 * Whether the package is a URL (installed server-side) rather than a local zip (SFTP-uploaded).
	 *
	 * @var bool
	 */
	private bool $is_url = false;

	/**
	 * Absolute path to the local zip, when the package is a local file.
	 *
	 * @var string|null
	 */
	private ?string $local_zip = null;

	/**
	 * The raw `--sites` value, or null for the whole fleet.
	 *
	 * @var string|null
	 */
	private ?string $sites_spec = null;

	/**
	 * Whether to also install the plugin on requested sites that do not yet have it.
	 *
	 * @var bool
	 */
	private bool $install_new = false;

	/**
	 * Whether to activate the plugin on sites where it is newly installed (only meaningful with
	 * --install-new; existing sites keep their current activation state).
	 *
	 * @var bool
	 */
	private bool $activate = false;

	/**
	 * Maximum number of sites to process this run, or null for no limit.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * Whether to skip confirmations and minimize output.
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Whether to run without writing any changes.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Whether the confirmations were explicitly waived with --yes.
	 *
	 * @var bool
	 */
	private bool $yes = false;

	/**
	 * Absolute path to the JSONL ledger (append-only audit log + resume skip-list).
	 *
	 * @var string
	 */
	private string $ledger_path = '';

	/**
	 * The ledger contents, keyed by (plugin, site) in first-seen order, latest row winning.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $ledger_entries = array();

	/**
	 * The resolved target sites, keyed by WPCOM ID.
	 *
	 * @var array<int|string, \stdClass>
	 */
	private array $targets = array();

	/**
	 * Requested `--sites` identifiers that did not match any connected site.
	 *
	 * @var string[]
	 */
	private array $unmatched = array();

	/**
	 * A human-readable description of the current run scope, for prompts and output.
	 *
	 * @var string
	 */
	private string $scope_label = 'all Jetpack sites';

	/**
	 * Cache of resolved site-is-atomic decisions, keyed by WPCOM ID.
	 *
	 * @var array<string, bool>
	 */
	private array $atomic_cache = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Force-reinstalls a plugin from a specific package (URL or local zip) across Jetpack-connected sites, over SSH.' )
			->setHelp( 'The deliberate, riskier sibling of `jetpack:plugin-update`: installs a specific package with `wp plugin install --force` over SSH. Use it only to replace, reinstall, or downgrade a plugin from an exact build. By default it only touches sites that already have the plugin and never force-activates it.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin slug (folder name) to force-install, e.g. `a8csp-atlantis`.' )
			->addOption( 'package', null, InputOption::VALUE_REQUIRED, 'The package to install: an http(s) URL, or a path to a local .zip file.' )
			->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Which sites to act on: `production`, `staging` (both by URL substring), a comma-separated list of URLs/IDs, or a path to a CSV file (first column). Defaults to the whole Jetpack fleet.' )
			->addOption( 'install-new', null, InputOption::VALUE_NONE, 'Also install the plugin on requested sites that do not yet have it (gated by an extra confirmation).' )
			->addOption( 'activate', null, InputOption::VALUE_NONE, 'Activate the plugin on sites where it is newly installed. Only valid with --install-new; existing sites keep their current activation state.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many sites this run (applied after filtering and the ledger skip-list).' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (append-only audit log + resume skip-list). Defaults to ' . self::DEFAULT_LEDGER . ' in the CLI directory.' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Minimize output (for large runs). Does not skip the confirmations — use --yes for that.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompts before force-installing.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything (no installs, no ledger entries).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run     = (bool) $input->getOption( 'dry-run' );
		$this->quiet       = (bool) $input->getOption( 'no-output' );
		$this->yes         = (bool) $input->getOption( 'yes' );
		$this->install_new = (bool) $input->getOption( 'install-new' );
		$this->activate    = (bool) $input->getOption( 'activate' );

		if ( $this->activate && ! $this->install_new ) {
			$output->writeln( '<error>--activate is only valid together with --install-new (it activates newly installed plugins; existing sites keep their activation state).</error>' );
			exit( 1 );
		}

		$this->plugin = \trim( get_string_input( $input, 'plugin' ) );

		$this->package = \trim( (string) $input->getOption( 'package' ) );
		if ( '' === $this->package ) {
			$output->writeln( '<error>--package is required (an http(s) URL or a local .zip path).</error>' );
			exit( 1 );
		}
		$this->resolve_package( $output );

		$limit = $input->getOption( 'limit' );
		if ( null !== $limit ) {
			if ( ! \ctype_digit( (string) $limit ) || (int) $limit < 1 ) {
				$output->writeln( '<error>--limit must be a positive integer.</error>' );
				exit( 1 );
			}
			$this->limit = (int) $limit;
		}

		// Resolve and pre-flight the ledger before any site is touched: it is both the audit trail and
		// the resume skip-list, so finding it unwritable after the first install would lose the record
		// of a change that already happened.
		$this->ledger_path = $this->resolve_ledger_path( $input->getOption( 'log' ) );
		if ( ! $this->dry_run ) {
			$this->assert_ledger_writable( $output );
		}

		$sites_spec       = $input->getOption( 'sites' );
		$this->sites_spec = ( \is_string( $sites_spec ) && '' !== $sites_spec ) ? $sites_spec : null;

		$this->resolve_targets( $output );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run && ! $this->quiet ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: no changes will be written ---</>' );
			$output->writeln( '' );
		}

		if ( ! empty( $this->unmatched ) ) {
			$output->writeln( '<comment>Unmatched --sites entries (not in the connected fleet): ' . \implode( ', ', $this->unmatched ) . '</comment>' );
		}

		if ( empty( $this->targets ) ) {
			$output->writeln( '<comment>No sites matched after filtering.</comment>' );
			return Command::SUCCESS;
		}

		// The gating warning: this command is the sharp tool; steer the operator to the safe one first.
		if ( ! $this->confirm_force_is_intended( $input, $output ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			return Command::FAILURE;
		}

		// Load the existing ledger so results upsert in place (one entry per site).
		$this->load_ledger();
		$done = $this->completed_ids();

		$pending      = array();
		$skipped_done = 0;
		foreach ( $this->targets as $site ) {
			if ( isset( $done[ $site->userblog_id ] ) ) {
				++$skipped_done;
				continue;
			}
			$pending[] = $site;
		}

		if ( null !== $this->limit && count( $pending ) > $this->limit ) {
			$pending = \array_slice( $pending, 0, $this->limit );
		}

		if ( empty( $pending ) ) {
			$output->writeln( "<info>Nothing to do: all {$skipped_done} matching site(s) are already recorded in the ledger ({$this->ledger_path}).</info>" );
			return Command::SUCCESS;
		}

		if ( ! $this->confirm_batch( $input, $output, count( $pending ), $skipped_done ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			return Command::FAILURE;
		}

		return $this->process( $pending, $skipped_done, $output );
	}

	// endregion

	// region PROCESSING

	/**
	 * Iterates the pending sites, force-installs the plugin, and records each result in the ledger.
	 *
	 * @param   \stdClass[]     $pending      The sites to process.
	 * @param   int             $skipped_done Sites skipped because they were already recorded done.
	 * @param   OutputInterface $output       The output object.
	 *
	 * @return  int
	 */
	private function process( array $pending, int $skipped_done, OutputInterface $output ): int {
		$total   = count( $pending );
		$counter = 0;
		$tally   = array(
			'updated'        => 0,
			'installed-new'  => 0,
			'skipped-absent' => 0,
			'would'          => 0,
			'failed'         => 0,
		);

		foreach ( $pending as $site ) {
			++$counter;
			$label = $this->site_label( $site );

			if ( ! $this->quiet ) {
				$output->writeln( "<info>[{$counter}/{$total}] {$label}</info>" );
			}

			try {
				$result = $this->process_site( $site );
			} catch ( \Throwable $e ) {
				$result = array(
					'status' => 'failed',
					'note'   => 'Error: ' . $e->getMessage(),
				);
			}

			$status = $result['status'];
			if ( isset( $tally[ $status ] ) ) {
				++$tally[ $status ];
			}

			$this->upsert_ledger( $site, $result );
			$this->report_result( $status, $result['note'], $output );
		}

		return $this->summarize( $tally, $total, $skipped_done, $output );
	}

	/**
	 * Force-installs the plugin on a single site over SSH.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  array{ status: string, note: string } Result status and descriptive note.
	 */
	private function process_site( \stdClass $site ): array {
		$ssh = $this->ssh_for_site( $site );
		if ( null === $ssh ) {
			return array(
				'status' => 'failed',
				'note'   => 'SSH connection failed (site unreachable, no connection, or authentication failure).',
			);
		}

		try {
			$ssh->setTimeout( 60 );

			$install_state = $this->plugin_install_state( $ssh );
			if ( 'error' === $install_state ) {
				// wp-cli itself could not answer (no WordPress at the login dir, a wp-config fatal, or
				// the seccomp SIGSYS kill). Treat as a failure rather than "absent" — otherwise an
				// --install-new run could install/activate on a site that already has the plugin.
				return array(
					'status' => 'failed',
					'note'   => "Could not determine whether '{$this->plugin}' is installed (wp-cli did not answer); skipped.",
				);
			}

			$installed = ( 'installed' === $install_state );

			if ( ! $installed && ! $this->install_new ) {
				return array(
					'status' => 'skipped-absent',
					'note'   => "Plugin '{$this->plugin}' not installed; skipped (use --install-new to install it).",
				);
			}

			$was = $installed ? $this->read_plugin_version( $ssh ) : '(absent)';

			// Activation is only ever applied to a fresh install, and only when explicitly requested;
			// an existing site keeps whatever activation state it already had.
			$activate_new = ! $installed && $this->activate;

			if ( $this->dry_run ) {
				$action        = $installed ? 'reinstall/update' : 'install (new)';
				$activate_note = $activate_new ? ' and activate' : '';
				return array(
					'status' => 'would',
					'note'   => "Would {$action}{$activate_note} '{$this->plugin}' from " . ( $this->is_url ? 'URL' : 'local zip' ) . ". Current version: {$was}.",
				);
			}

			list( $ok, $detail ) = $this->install_from_source( $site, $ssh, $activate_new );
			if ( ! $ok ) {
				return array(
					'status' => 'failed',
					'note'   => 'Install failed: ' . $this->first_line( $detail ),
				);
			}

			$ssh->setTimeout( 60 ); // install_from_source raised it for the download; restore for the read.
			$now = $this->read_plugin_version( $ssh );

			// A green install exit but no readable version for this slug means the package unpacked
			// under a different folder (e.g. a release zip expanding to `repo-1.2.3/`): the target was
			// never replaced. Fail loudly so it is retried, not recorded as done.
			if ( 'unknown' === $now ) {
				return array(
					'status' => 'failed',
					'note'   => "Install exited 0 but '{$this->plugin}' has no readable version afterward — the package likely unpacked under a different folder. Not recorded as done.",
				);
			}

			// The before/after verdict is only meaningful when the prior version was readable; an
			// unreadable `$was` would make version_compare arbitrary, so report it as unverified.
			$verdict = ( 'unknown' === $was ) ? 'unverified' : classify_wpcom_plugin_update_result( $was, $now );
			$status  = $installed ? 'updated' : 'installed-new';
			$note    = $installed
				? "Force-installed '{$this->plugin}': {$was} → {$now} ({$verdict})."
				: "Installed '{$this->plugin}' (new, " . ( $activate_new ? 'active' : 'inactive' ) . "): {$now}.";

			return array(
				'status' => $status,
				'note'   => $note,
			);
		} finally {
			$ssh->disconnect();
		}
	}

	/**
	 * Installs the plugin from the configured source. For a URL the site fetches it directly; for a
	 * local zip the file is uploaded via SFTP to a staging directory outside the web root
	 * ({@see self::resolve_upload_dir()}), installed, then removed.
	 *
	 * @param   \stdClass $site     The site object (needed to open a matching SFTP connection).
	 * @param   SSH2      $ssh      The already-open SSH connection.
	 * @param   bool      $activate Whether to activate the plugin after installing (new installs only).
	 *
	 * @return  array{ 0: bool, 1: string } Success flag and command output / error detail.
	 */
	private function install_from_source( \stdClass $site, SSH2 $ssh, bool $activate = false ): array {
		$flags = '--force';
		if ( $activate ) {
			$flags .= ' --activate';
		}

		if ( $this->is_url ) {
			// A generous but finite read bound: a stalled download fails this site rather than hanging
			// the whole fleet run (setTimeout( 0 ) would disable the timeout entirely).
			$ssh->setTimeout( 600 );
			$out = $ssh->exec( self::WP . ' plugin install ' . \escapeshellarg( $this->package ) . " $flags 2>&1" );
			return array( 0 === $ssh->getExitStatus(), (string) $out );
		}

		// Local zip: upload it somewhere outside the web root, then install from there.
		$upload_dir = $this->resolve_upload_dir( $ssh );
		if ( '' === $upload_dir ) {
			return array( false, 'Could not resolve a writable upload directory outside the web root.' );
		}

		$remote = $upload_dir . '/t51-force-' . $this->safe_slug() . '-' . \getmypid() . '.zip';

		$sftp = $this->sftp_for_site( $site );
		if ( null === $sftp ) {
			return array( false, 'SFTP connection failed for the zip upload.' );
		}

		try {
			$sftp->setTimeout( 600 );
			if ( ! $sftp->put( $remote, $this->local_zip, SFTP::SOURCE_LOCAL_FILE ) ) {
				return array( false, 'Failed to upload the plugin zip via SFTP to ' . $remote . '.' );
			}
		} finally {
			$sftp->disconnect();
		}

		$ssh->setTimeout( 600 );
		$out = $ssh->exec( self::WP . ' plugin install ' . \escapeshellarg( $remote ) . " $flags 2>&1" );
		$ok  = 0 === $ssh->getExitStatus();

		// Best-effort cleanup; the install result is what matters.
		$ssh->exec( 'rm -f ' . \escapeshellarg( $remote ) . ' 2>/dev/null' );

		return array( $ok, (string) $out );
	}

	/**
	 * Resolves an absolute directory to stage the zip in, outside the web root.
	 *
	 * The SFTP session lands in the *web root* (`pwd()` is `/srv/htdocs` on both Pressable and WoA),
	 * so uploading to the login directory would publish the package under a predictable name for the
	 * length of the install — and permanently if the session dies before the cleanup. The SFTP user
	 * is not chrooted, and an absolute path means the same file to both sessions, so the staging
	 * directory is resolved over SSH instead: `/tmp` where it is usable (as
	 * `pressable:download-site-plugins` already does for its archive), else the home directory.
	 *
	 * @param   SSH2 $ssh The open SSH connection.
	 *
	 * @return  string The absolute directory, or an empty string if neither candidate is usable.
	 */
	private function resolve_upload_dir( SSH2 $ssh ): string {
		$ssh->setTimeout( 60 );
		$out = $ssh->exec(
			'if [ -d /tmp ] && [ -w /tmp ]; then printf %s /tmp; '
			. 'elif [ -d "$HOME" ] && [ -w "$HOME" ]; then printf %s "$HOME"; fi'
		);

		$dir = \trim( (string) $out );

		// Only ever an absolute path: a relative answer would resolve against the SSH login
		// directory, which is not where the SFTP session would have written.
		return \str_starts_with( $dir, '/' ) ? \rtrim( $dir, '/' ) : '';
	}

	// endregion

	// region SITE / CONNECTION HELPERS

	/**
	 * Resolves and validates the `--package` value into either a URL or a local zip.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function resolve_package( OutputInterface $output ): void {
		if ( 1 === \preg_match( '#^https?://#i', $this->package ) ) {
			$this->is_url = true;
			return;
		}

		$real = \realpath( $this->package );
		if ( false === $real || ! \is_file( $real ) ) {
			$output->writeln( "<error>--package is neither an http(s) URL nor an existing file: {$this->package}</error>" );
			exit( 1 );
		}
		if ( ! \str_ends_with( \strtolower( $real ), '.zip' ) ) {
			$output->writeln( '<error>A local --package must be a .zip file.</error>' );
			exit( 1 );
		}

		$this->is_url    = false;
		$this->local_zip = $real;
	}

	/**
	 * Resolves the target sites from the Jetpack fleet, honouring `--sites`.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function resolve_targets( OutputInterface $output ): void {
		$fleet = get_wpcom_jetpack_sites();
		if ( null === $fleet ) {
			$output->writeln( '<error>Failed to fetch the Jetpack-connected sites list.</error>' );
			exit( 1 );
		}

		$keyword = \strtolower( \trim( (string) $this->sites_spec ) );

		if ( null === $this->sites_spec || 'all' === $keyword ) {
			$this->targets     = $fleet;
			$this->scope_label = 'all Jetpack sites';
			return;
		}

		if ( 'staging' === $keyword || 'production' === $keyword ) {
			$want_staging      = ( 'staging' === $keyword );
			$this->targets     = \array_filter(
				$fleet,
				static fn( $site ) => ( false !== \stripos( (string) ( $site->siteurl ?? '' ), 'staging' ) ) === $want_staging
			);
			$this->scope_label = $keyword . ' only (by URL)';
			return;
		}

		// Comma-separated list or CSV file.
		$identifiers                             = parse_wpcom_site_identifiers( $this->sites_spec );
		list( $this->targets, $this->unmatched ) = resolve_wpcom_sites_from_identifiers( $identifiers, $fleet );
		$this->scope_label                       = \is_file( $this->sites_spec ) ? 'sites from CSV' : 'requested list';
	}

	/**
	 * Opens an SSH connection to a site via the helper matching its type.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  SSH2|null
	 */
	private function ssh_for_site( \stdClass $site ): ?SSH2 {
		return $this->site_is_atomic( $site )
			? \WPCOM_Connection_Helper::get_ssh_connection( (string) $site->userblog_id )
			: \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_identifier( $site ) );
	}

	/**
	 * Opens an SFTP connection to a site via the helper matching its type.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  SFTP|null
	 */
	private function sftp_for_site( \stdClass $site ): ?SFTP {
		return $this->site_is_atomic( $site )
			? \WPCOM_Connection_Helper::get_sftp_connection( (string) $site->userblog_id )
			: \Pressable_Connection_Helper::get_sftp_connection( $this->pressable_identifier( $site ) );
	}

	/**
	 * Whether the site is a WPCOM/WoA (Atomic) site, as opposed to Pressable.
	 *
	 * Uses the `is_wpcom_atomic` flag when the list carries it, otherwise enriches once from the full
	 * site object (cached per site).
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  bool
	 */
	private function site_is_atomic( \stdClass $site ): bool {
		$id = (string) $site->userblog_id;
		if ( isset( $this->atomic_cache[ $id ] ) ) {
			return $this->atomic_cache[ $id ];
		}

		if ( isset( $site->is_wpcom_atomic ) ) {
			$this->atomic_cache[ $id ] = (bool) $site->is_wpcom_atomic;
			return $this->atomic_cache[ $id ];
		}

		$full                      = get_wpcom_site( $id );
		$this->atomic_cache[ $id ] = (bool) ( $full->is_wpcom_atomic ?? false );
		return $this->atomic_cache[ $id ];
	}

	/**
	 * The identifier a Pressable site is reached by: its host (Pressable resolves URLs), falling back
	 * to the WPCOM ID.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string
	 */
	private function pressable_identifier( \stdClass $site ): string {
		$host = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		return '' !== $host ? $host : (string) $site->userblog_id;
	}

	/**
	 * Determines whether the plugin is installed, distinguishing a genuine absence from wp-cli being
	 * unable to answer at all.
	 *
	 * `wp plugin is-installed` exits 0 when installed and 1 (no output) when genuinely absent. When
	 * wp-cli cannot bootstrap (no WordPress at the login dir, a wp-config fatal) it exits non-zero
	 * *with* an error on stderr; a signal kill (the seccomp SIGSYS case) or a read timeout instead
	 * yields no exit status at all. Neither must be read as "absent".
	 *
	 * @param   SSH2 $ssh The open SSH connection.
	 *
	 * @return  string One of `installed`, `absent`, `error`.
	 */
	private function plugin_install_state( SSH2 $ssh ): string {
		$out  = $ssh->exec( self::WP . ' plugin is-installed ' . \escapeshellarg( $this->plugin ) . ' 2>&1' );
		$code = $ssh->getExitStatus();

		if ( 0 === $code ) {
			return 'installed';
		}

		// No exit status (false) means the command was killed by a signal or timed out with no reply —
		// undeterminable, not absent.
		if ( false === $code ) {
			return 'error';
		}

		// A clean non-zero exit with no diagnostic output is a genuine "not installed"; any output on
		// failure (an `Error:` line, a fatal) means wp-cli could not answer.
		return '' === \trim( (string) $out ) ? 'absent' : 'error';
	}

	/**
	 * Reads the installed version of the plugin, or `unknown` when it cannot be determined.
	 *
	 * @param   SSH2 $ssh The open SSH connection.
	 *
	 * @return  string
	 */
	private function read_plugin_version( SSH2 $ssh ): string {
		$out     = $ssh->exec( self::WP . ' plugin get ' . \escapeshellarg( $this->plugin ) . ' --field=version 2>/dev/null' );
		$version = \trim( (string) $out );
		return '' !== $version ? $version : 'unknown';
	}

	/**
	 * A filesystem-safe form of the plugin slug, for the remote temp filename.
	 *
	 * @return  string
	 */
	private function safe_slug(): string {
		$safe = \preg_replace( '/[^A-Za-z0-9._-]/', '', $this->plugin );
		return '' !== (string) $safe ? (string) $safe : 'plugin';
	}

	// endregion

	// region LEDGER

	/**
	 * Builds the composite ledger key for an entry. The default ledger is a fixed filename shared
	 * across plugins, so entries must be keyed by (plugin, site) — keying by site alone would collapse
	 * a second plugin's rows into the first and drop them on the next rewrite.
	 *
	 * @param   string     $plugin The plugin slug.
	 * @param   int|string $id     The site ID.
	 *
	 * @return  string
	 */
	private function ledger_key( string $plugin, int|string $id ): string {
		return $plugin . '|' . $id;
	}

	/**
	 * Resolves the ledger path to an absolute one.
	 *
	 * A bare filename resolves against the process CWD, and `team51` is installed on the PATH and run
	 * from arbitrary directories — so a second run started elsewhere would find no ledger, skip
	 * nothing, and re-process the whole fleet. The default is pinned to the CLI's own directory,
	 * where .gitignore expects it.
	 *
	 * @param   mixed $log_option The raw `--log` option value.
	 *
	 * @return  string
	 */
	private function resolve_ledger_path( mixed $log_option ): string {
		if ( ! \is_string( $log_option ) || '' === $log_option ) {
			return TEAM51_CLI_ROOT_DIR . '/' . self::DEFAULT_LEDGER;
		}

		// An explicit relative --log stays relative to the CWD, which is what an operator typing a
		// path means by it.
		return \str_starts_with( $log_option, '/' ) ? $log_option : \getcwd() . '/' . $log_option;
	}

	/**
	 * Verifies the ledger can be appended to, before any site is touched.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function assert_ledger_writable( OutputInterface $output ): void {
		if ( \is_file( $this->ledger_path ) ) {
			if ( ! \is_writable( $this->ledger_path ) ) {
				$output->writeln( "<error>The ledger at {$this->ledger_path} is not writable.</error>" );
				exit( 1 );
			}

			return;
		}

		$directory = \dirname( $this->ledger_path );
		if ( ! \is_dir( $directory ) || ! \is_writable( $directory ) ) {
			$output->writeln( "<error>The ledger directory {$directory} does not exist or is not writable.</error>" );
			exit( 1 );
		}
	}

	/**
	 * Loads the ledger from disk into $this->ledger_entries, keyed by (plugin, site) in first-seen
	 * order.
	 *
	 * A later line for the same (plugin, site) supersedes an earlier one, collapsing the repeated rows
	 * that append-only writing leaves while preserving rows for other plugins in the shared ledger.
	 *
	 * @throws  \RuntimeException If an existing ledger cannot be read (the resume skip-list must fail loudly).
	 *
	 * @return  void
	 */
	private function load_ledger(): void {
		$this->ledger_entries = array();
		if ( ! \is_file( $this->ledger_path ) ) {
			return;
		}

		// The ledger is the resume skip-list: silently treating an unreadable one as empty would
		// re-process every site already recorded done.
		$handle = \is_readable( $this->ledger_path ) ? \fopen( $this->ledger_path, 'r' ) : false;
		if ( false === $handle ) {
			throw new \RuntimeException( "The ledger at {$this->ledger_path} exists but could not be read." );
		}

		while ( true ) {
			$line = \fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$line = \trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$entry = \json_decode( $line, true );
			if ( ! \is_array( $entry ) || ! isset( $entry['id'] ) ) {
				continue;
			}

			$this->ledger_entries[ $this->ledger_key( (string) ( $entry['plugin'] ?? '' ), $entry['id'] ) ] = $entry;
		}

		\fclose( $handle );
	}

	/**
	 * Derives the set of site IDs already recorded complete in the ledger.
	 *
	 * What counts as complete depends on the mode: see self::DONE_UNLESS_INSTALLING_NEW.
	 *
	 * @return  array<int|string, true> Map of completed site IDs.
	 */
	private function completed_ids(): array {
		$done_statuses = $this->install_new
			? self::DONE_STATUSES
			: \array_merge( self::DONE_STATUSES, self::DONE_UNLESS_INSTALLING_NEW );

		$done = array();
		foreach ( $this->ledger_entries as $entry ) {
			// The default ledger is a fixed filename shared across plugins, so a done entry only
			// counts when it is for THIS plugin — otherwise force-updating a second plugin from the
			// same directory would see every site as done and no-op.
			if ( ( $entry['plugin'] ?? null ) !== $this->plugin ) {
				continue;
			}
			if ( isset( $entry['status'], $entry['id'] ) && \in_array( $entry['status'], $done_statuses, true ) ) {
				$done[ $entry['id'] ] = true;
			}
		}

		return $done;
	}

	/**
	 * Counts the sites the ledger records as not having this plugin.
	 *
	 * @return  int
	 */
	private function ledger_absent_count(): int {
		$count = 0;
		foreach ( $this->ledger_entries as $entry ) {
			if ( ( $entry['plugin'] ?? null ) === $this->plugin && \in_array( $entry['status'] ?? '', self::DONE_UNLESS_INSTALLING_NEW, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Records a single result in the ledger, in memory and on disk. No-op during a dry run.
	 *
	 * The in-memory map is kept current so the skip-list stays right within a run; the on-disk
	 * ledger is only ever appended to {@see self::append_ledger()}.
	 *
	 * @param   \stdClass                             $site   The processed site.
	 * @param   array{ status: string, note: string } $result The processing result.
	 *
	 * @return  void
	 */
	private function upsert_ledger( \stdClass $site, array $result ): void {
		if ( $this->dry_run ) {
			return;
		}

		$entry = array(
			'id'        => $site->userblog_id,
			'url'       => $site->siteurl ?? null,
			'type'      => $this->site_is_atomic( $site ) ? 'wpcom' : 'pressable',
			'plugin'    => $this->plugin,
			'status'    => $result['status'],
			'note'      => $result['note'],
			'timestamp' => \gmdate( 'c' ),
		);

		$this->ledger_entries[ $this->ledger_key( $this->plugin, $site->userblog_id ) ] = $entry;
		$this->append_ledger( $entry );
	}

	/**
	 * Appends one entry to the ledger.
	 *
	 * Append-only on purpose. Reading the file into memory and writing it back made this writer
	 * capable of deleting rows it never wrote: a line the loader skipped, or anything a second run
	 * against the shared default ledger recorded after this one loaded it, was dropped on the next
	 * flush — and a full-fleet run re-wrote every row once per site. Appending under an exclusive
	 * lock is concurrency-safe and cannot truncate, and `load_ledger()` already collapses repeated
	 * rows for the same (plugin, site) on read, so history accumulating here costs nothing.
	 *
	 * @param   array<string, mixed> $entry The entry to record.
	 *
	 * @throws  \RuntimeException If the entry cannot be encoded or appended (the resume mechanism must fail loudly).
	 *
	 * @return  void
	 */
	private function append_ledger( array $entry ): void {
		// Substitute rather than fail on malformed UTF-8 from the API: a slightly degraded row still
		// records that the site was acted on, which is the point of the ledger.
		$encoded = \json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded ) {
			throw new \RuntimeException( 'Failed to encode the ledger entry for site ' . $entry['id'] . '.' );
		}

		if ( false === \file_put_contents( $this->ledger_path, $encoded . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( "Failed to append to the ledger at {$this->ledger_path}." );
		}
	}

	// endregion

	// region OUTPUT / PROMPTS

	/**
	 * The gating confirmation that steers the operator toward the safe `jetpack:plugin-update`.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool True to proceed.
	 */
	private function confirm_force_is_intended( InputInterface $input, OutputInterface $output ): bool {
		// A dry run writes nothing, so gating it buys no safety and would block the recommended
		// `--dry-run` first check from running unattended. --yes is the explicit "I meant this"
		// opt-out; quieting the output is not one, and a plain non-interactive real run reaches the
		// questions below, whose `false` default aborts, so a mistyped argument cannot silently
		// force-install the fleet.
		if ( $this->yes || $this->dry_run ) {
			return true;
		}

		$output->writeln( '<comment>You are about to FORCE-install a specific package with `wp plugin install --force` over SSH.</comment>' );
		$output->writeln( '<comment>For a normal, version-checked update you should use `jetpack:plugin-update` instead — it is safer and does not overwrite a site with an exact build.</comment>' );
		$output->writeln( '<comment>Only continue if you specifically need to replace, reinstall, or downgrade from this package.</comment>' );

		$question = new ConfirmationQuestion( '<question>Are you sure you should not be using `jetpack:plugin-update`? Continue with force-install? [y/N]</question> ', false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			return false;
		}

		if ( $this->install_new ) {
			$new_prompt   = '--install-new will INSTALL the plugin on requested sites that do not have it yet'
				. ( $this->activate ? ', and --activate will activate it on those sites' : ' (left inactive)' )
				. '. Are you sure?';
			$new_question = new ConfirmationQuestion( "<question>{$new_prompt} [y/N]</question> ", false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $new_question ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The per-run confirmation showing count and scope.
	 *
	 * @param   InputInterface  $input        The input object.
	 * @param   OutputInterface $output       The output object.
	 * @param   int             $count        Sites to process.
	 * @param   int             $skipped_done Sites already recorded done.
	 *
	 * @return  bool True to proceed.
	 */
	private function confirm_batch( InputInterface $input, OutputInterface $output, int $count, int $skipped_done ): bool {
		if ( $this->yes || $this->dry_run ) {
			return true;
		}

		$source = $this->is_url ? "URL {$this->package}" : "local zip {$this->local_zip}";
		$extra  = $skipped_done > 0 ? " ({$skipped_done} already done, skipped)" : '';
		$mode   = $this->install_new
			? ( 'update existing + install new' . ( $this->activate ? ' (activate new)' : '' ) )
			: 'update existing only';

		$question = new ConfirmationQuestion(
			"<question>Force-install '{$this->plugin}' from {$source} on {$count} site(s) [{$this->scope_label}; {$mode}]{$extra}? [y/N]</question> ",
			false
		);

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			return false;
		}

		$output->writeln( '' );
		return true;
	}

	/**
	 * Prints a single site's result line (skipped when --no-output).
	 *
	 * @param   string          $status The result status.
	 * @param   string          $note   The descriptive note.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function report_result( string $status, string $note, OutputInterface $output ): void {
		if ( $this->quiet ) {
			return;
		}

		$style = match ( $status ) {
			'updated', 'installed-new', 'would' => 'info',
			'skipped-absent'                    => 'comment',
			default                             => 'error',
		};

		// The note can contain remote wp-cli output; escape it so stray angle brackets are not parsed
		// as Symfony style tags (which would swallow the very error text an operator needs).
		$safe_note = \Symfony\Component\Console\Formatter\OutputFormatter::escape( $note );
		$output->writeln( "  <{$style}>{$safe_note}</{$style}>" );
	}

	/**
	 * Prints the run summary and returns the exit code.
	 *
	 * @param   array<string, int> $tally        Per-status counts.
	 * @param   int                $total        Total sites processed this run.
	 * @param   int                $skipped_done Sites skipped as already-done.
	 * @param   OutputInterface    $output       The output object.
	 *
	 * @return  int
	 */
	private function summarize( array $tally, int $total, int $skipped_done, OutputInterface $output ): int {
		if ( ! $this->quiet ) {
			$output->writeln( '' );
			$output->writeln( '<info>--- Summary ---</info>' );
			$output->writeln(
				\sprintf(
					'Processed: %d | Updated: %d | Installed new: %d | Skipped (absent): %d | Would: %d | Failed: %d | Skipped (already done): %d',
					$total,
					$tally['updated'],
					$tally['installed-new'],
					$tally['skipped-absent'],
					$tally['would'],
					$tally['failed'],
					$skipped_done
				)
			);
			// Once a site is recorded `skipped-absent` it folds into the "already done" count above, so
			// say plainly that the plugin is missing on those sites and how to reach them — otherwise
			// a status that is deliberately terminal becomes invisible after the run that found it.
			$absent = $this->ledger_absent_count();
			if ( ! $this->install_new && $absent > 0 ) {
				$output->writeln( "<comment>{$absent} site(s) recorded as not having '{$this->plugin}' and skipped. Re-run with --install-new to install it there.</comment>" );
			}

			if ( ! $this->dry_run ) {
				$output->writeln( "Ledger: {$this->ledger_path}" );
			}
		}

		return $tally['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * A human-readable label for a site.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string
	 */
	private function site_label( \stdClass $site ): string {
		return (string) ( $site->siteurl ?? $site->userblog_id );
	}

	/**
	 * Returns the first non-empty line of a multi-line string, trimmed.
	 *
	 * @param   string $text The text.
	 *
	 * @return  string
	 */
	private function first_line( string $text ): string {
		foreach ( \preg_split( '/\r\n|\r|\n/', \trim( $text ) ) ?: array() as $line ) {
			$line = \trim( $line );
			if ( '' !== $line ) {
				return $line;
			}
		}

		return '(no output)';
	}

	// endregion
}
