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
 * - wp-cli runs with `--skip-plugins --skip-themes` so a site with a fatal plugin/theme can still be
 *   fixed.
 * - Every processed site is recorded in a resumable JSONL ledger (one entry per site, updated in
 *   place on retry) that doubles as the skip-list on the next run.
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
	 * Default JSONL ledger filename, written in the current working directory.
	 *
	 * @var string
	 */
	private const DEFAULT_LEDGER = 'plugin-force-update-ledger.jsonl';

	/**
	 * Ledger statuses that mark a site as complete (skipped on future runs). A `skipped-absent` or
	 * `failed` site is deliberately re-attempted next run.
	 *
	 * @var array<int, string>
	 */
	private const DONE_STATUSES = array( 'updated', 'installed-new' );

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
	 * Path to the JSONL ledger (audit log + resume skip-list).
	 *
	 * @var string
	 */
	private string $ledger_path = self::DEFAULT_LEDGER;

	/**
	 * The ledger contents, one entry per site keyed by site ID, in first-seen order.
	 *
	 * @var array<int|string, array<string, mixed>>
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
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (audit log + resume skip-list). Defaults to ./' . self::DEFAULT_LEDGER . '.' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Skip confirmations and minimize output (for large runs).' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything (no installs, no ledger entries).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run     = (bool) $input->getOption( 'dry-run' );
		$this->quiet       = (bool) $input->getOption( 'no-output' );
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

		$log_path          = $input->getOption( 'log' );
		$this->ledger_path = ( \is_string( $log_path ) && '' !== $log_path ) ? $log_path : self::DEFAULT_LEDGER;

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

			$status = $installed ? 'updated' : 'installed-new';
			$note   = $installed
				? "Force-installed '{$this->plugin}': {$was} → {$now} (" . classify_wpcom_plugin_update_result( $was, $now ) . ').'
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
	 * local zip the file is uploaded via SFTP to the (non-web-accessible) home directory, installed,
	 * then removed.
	 *
	 * @param   \stdClass $site     The site object (needed to open a matching SFTP connection).
	 * @param   SSH2      $ssh      The already-open SSH connection.
	 * @param   bool      $activate Whether to activate the plugin after installing (new installs only).
	 *
	 * @return  array{ 0: bool, 1: string } Success flag and command output / error detail.
	 */
	private function install_from_source( \stdClass $site, SSH2 $ssh, bool $activate = false ): array {
		$flags = '--force --skip-plugins --skip-themes';
		if ( $activate ) {
			$flags .= ' --activate';
		}

		if ( $this->is_url ) {
			// A generous but finite read bound: a stalled download fails this site rather than hanging
			// the whole fleet run (setTimeout( 0 ) would disable the timeout entirely).
			$ssh->setTimeout( 600 );
			$out = $ssh->exec( 'wp plugin install ' . \escapeshellarg( $this->package ) . " $flags 2>&1" );
			return array( 0 === $ssh->getExitStatus(), (string) $out );
		}

		// Local zip: upload to the home directory (above htdocs, so not web-accessible), then install.
		$sftp = $this->sftp_for_site( $site );
		if ( null === $sftp ) {
			return array( false, 'SFTP connection failed for the zip upload.' );
		}

		// Resolve an absolute path from the SFTP login directory so the separate SSH session installs
		// the exact file we uploaded, even if the two sessions' working directories differ.
		$remote = 't51-force-' . $this->safe_slug() . '-' . \getmypid() . '.zip';
		try {
			$sftp->setTimeout( 600 );
			$base = \rtrim( (string) $sftp->pwd(), '/' );
			if ( '' !== $base ) {
				$remote = $base . '/' . $remote;
			}
			if ( ! $sftp->put( $remote, $this->local_zip, SFTP::SOURCE_LOCAL_FILE ) ) {
				return array( false, 'Failed to upload the plugin zip via SFTP.' );
			}
		} finally {
			$sftp->disconnect();
		}

		$ssh->setTimeout( 600 );
		$out = $ssh->exec( 'wp plugin install ' . \escapeshellarg( $remote ) . " $flags 2>&1" );
		$ok  = 0 === $ssh->getExitStatus();

		// Best-effort cleanup; the install result is what matters.
		$ssh->exec( 'rm -f ' . \escapeshellarg( $remote ) . ' 2>/dev/null' );

		return array( $ok, (string) $out );
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
	 * wp-cli cannot bootstrap (no WordPress at the login dir, a wp-config fatal, or the seccomp SIGSYS
	 * kill) it exits non-zero *with* an error on stderr — which must not be read as "absent".
	 *
	 * @param   SSH2 $ssh The open SSH connection.
	 *
	 * @return  string One of `installed`, `absent`, `error`.
	 */
	private function plugin_install_state( SSH2 $ssh ): string {
		$out = $ssh->exec( 'wp plugin is-installed ' . \escapeshellarg( $this->plugin ) . ' --skip-plugins --skip-themes 2>&1' );
		if ( 0 === $ssh->getExitStatus() ) {
			return 'installed';
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
		$out     = $ssh->exec( 'wp plugin get ' . \escapeshellarg( $this->plugin ) . ' --field=version --skip-plugins --skip-themes 2>/dev/null' );
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
	 * Loads the ledger from disk into $this->ledger_entries, keyed by site ID in first-seen order.
	 *
	 * A later line for the same site supersedes an earlier one, collapsing duplicates from older runs.
	 *
	 * @return  void
	 */
	private function load_ledger(): void {
		$this->ledger_entries = array();
		if ( ! \is_file( $this->ledger_path ) ) {
			return;
		}

		$handle = \fopen( $this->ledger_path, 'r' );
		if ( false === $handle ) {
			return;
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

			$this->ledger_entries[ $entry['id'] ] = $entry;
		}

		\fclose( $handle );
	}

	/**
	 * Derives the set of site IDs already recorded complete in the ledger.
	 *
	 * @return  array<int|string, true> Map of completed site IDs.
	 */
	private function completed_ids(): array {
		$done = array();
		foreach ( $this->ledger_entries as $id => $entry ) {
			// The default ledger is a fixed filename shared across plugins, so a done entry only
			// counts when it is for THIS plugin — otherwise force-updating a second plugin from the
			// same directory would see every site as done and no-op.
			if ( ( $entry['plugin'] ?? null ) !== $this->plugin ) {
				continue;
			}
			if ( isset( $entry['status'] ) && \in_array( $entry['status'], self::DONE_STATUSES, true ) ) {
				$done[ $id ] = true;
			}
		}

		return $done;
	}

	/**
	 * Records a single result in the ledger, replacing any existing entry for the same site, then
	 * flushes the ledger to disk. No-op during a dry run.
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

		$this->ledger_entries[ $site->userblog_id ] = array(
			'id'        => $site->userblog_id,
			'url'       => $site->siteurl ?? null,
			'type'      => $this->site_is_atomic( $site ) ? 'wpcom' : 'pressable',
			'plugin'    => $this->plugin,
			'status'    => $result['status'],
			'note'      => $result['note'],
			'timestamp' => \gmdate( 'c' ),
		);

		$this->write_ledger();
	}

	/**
	 * Atomically rewrites the whole ledger from $this->ledger_entries.
	 *
	 * @throws  \RuntimeException If the ledger cannot be written (the resume mechanism must fail loudly).
	 *
	 * @return  void
	 */
	private function write_ledger(): void {
		$lines = array();
		foreach ( $this->ledger_entries as $entry ) {
			$encoded = \json_encode( $entry, JSON_UNESCAPED_SLASHES );
			if ( false !== $encoded ) {
				$lines[] = $encoded;
			}
		}

		$payload   = empty( $lines ) ? '' : \implode( "\n", $lines ) . "\n";
		$temp_path = $this->ledger_path . '.' . \getmypid() . '.tmp';

		if ( false === \file_put_contents( $temp_path, $payload, LOCK_EX ) ) {
			throw new \RuntimeException( "Failed to write the ledger at {$this->ledger_path}." );
		}

		if ( ! \rename( $temp_path, $this->ledger_path ) ) {
			\unlink( $temp_path );
			throw new \RuntimeException( "Failed to finalize the ledger at {$this->ledger_path}." );
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
		// --no-output is the explicit "I know what I'm doing, run unattended" opt-out. A plain
		// non-interactive run (no TTY, --no-interaction) instead reaches the questions below, whose
		// `false` default aborts — so a mistyped argument cannot silently force-install the fleet.
		if ( $this->quiet ) {
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
		if ( $this->quiet || $this->dry_run ) {
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
