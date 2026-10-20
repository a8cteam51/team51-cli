<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Baseline for one-off scripts that visit a set of fleet sites one at a time, usually over SSH.
 *
 * A subclass names the command, optionally lists the sites it is for, and implements process_site();
 * everything else is here: picking the sites, telling the platforms apart, connecting, running
 * WP-CLI safely, the resumable ledger, the CSV report and the list of sites left for a person.
 * See `.agents/skills/fleet-scripts.md` for how to write, run and retire one.
 *
 * Behavior:
 * - Site universe is the Jetpack-connected fleet. `--sites` narrows it to `production`, `staging`
 *   (by whether the URL contains "staging", since Pressable flags many staging sites as
 *   production), `all`, a comma-separated list of URLs/IDs, or a CSV file (first column). Without
 *   it, the subclass's DEFAULT_SITES are used, or the whole fleet when there are none.
 * - The platform is settled without guessing: the platform staging domains first, then Atomic,
 *   then a Pressable lookup. A site neither platform claims is hosted elsewhere and is handed back.
 * - Opening a connection rotates the site's SFTP/SSH password, on WordPress.com as well as on
 *   Pressable, a dry run included. The confirmation prompt says so.
 * - Every processed site is appended to a JSONL ledger (not on a dry run) that doubles as the
 *   skip-list on the next run. Sites that failed stay out of later runs until `--retry-failed`.
 * - Every result goes to a CSV report, and every site a person has to finish by hand goes to a
 *   follow-up CSV with its host and what is left to do.
 */
abstract class Abstract_Fleet_Command extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Platform identifiers, as recorded in the ledger and the reports.
	 *
	 * @var string
	 */
	public const PLATFORM_WPCOM     = 'wpcom';
	public const PLATFORM_PRESSABLE = 'pressable';

	/**
	 * The status the base class gives a site it could not process. Always followed up.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Why a site failed, so the follow-up list says what a person has to do about it.
	 *
	 * @var string
	 */
	public const REASON_NOT_IN_FLEET = 'not-in-fleet';
	public const REASON_EXTERNAL     = 'external';
	public const REASON_NO_ACCESS    = 'no-access';
	public const REASON_SSH          = 'ssh-failed';
	public const REASON_ERROR        = 'error';

	/**
	 * Whether the script only reads. A read-only script has no `--dry-run` and says so when it asks.
	 *
	 * @var bool
	 */
	protected const READ_ONLY = false;

	/**
	 * The sites the script is for, by WPCOM blog ID or URL. Empty means the whole fleet.
	 *
	 * @var string[]
	 */
	protected const DEFAULT_SITES = array();

	/**
	 * Statuses that settle a site, so the next run skips it. A script that should look at every site
	 * every time, like an audit, sets this to an empty array.
	 *
	 * @var string[]
	 */
	protected const SETTLED_STATUSES = array( 'success', 'unchanged' );

	/**
	 * Statuses that put a site on the follow-up list. `failed` is always on it.
	 *
	 * @var string[]
	 */
	protected const FOLLOW_UP_STATUSES = array();

	/**
	 * Extra report columns, filled from the `columns` array of each result.
	 *
	 * @var string[]
	 */
	protected const REPORT_COLUMNS = array();

	/**
	 * Per-site SSH command timeout, in seconds. Raise it for greps over a whole wp-content.
	 *
	 * @var int
	 */
	protected const SSH_TIMEOUT = 60;

	/**
	 * Whether to run without writing any changes.
	 *
	 * @var bool
	 */
	protected bool $dry_run = false;

	/**
	 * Whether to skip the confirmation prompt.
	 *
	 * @var bool
	 */
	protected bool $yes = false;

	/**
	 * The most sites to process this run, or null for no limit.
	 *
	 * @var int|null
	 */
	protected ?int $limit = null;

	/**
	 * The raw `--sites` value, or null when it was not given.
	 *
	 * @var string|null
	 */
	protected ?string $sites_spec = null;

	/**
	 * Whether to process only the sites the ledger records as failed.
	 *
	 * @var bool
	 */
	protected bool $retry_failed = false;

	/**
	 * Absolute path to the JSONL ledger.
	 *
	 * @var string
	 */
	protected string $ledger_path = '';

	/**
	 * Path to the CSV report.
	 *
	 * @var string
	 */
	protected string $report_path = '';

	/**
	 * Path to the follow-up CSV, next to the report.
	 *
	 * @var string
	 */
	protected string $follow_up_path = '';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * A subclass sets its description and help, then calls `parent::configure()`.
	 */
	protected function configure(): void {
		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Which sites to act on: `production`, `staging` (by whether the URL contains "staging"), `all`, a comma-separated list of URLs/IDs, or a path to a CSV file (first column). ' . ( empty( static::DEFAULT_SITES ) ? 'Defaults to the whole Jetpack fleet.' : 'Defaults to the ' . \count( static::DEFAULT_SITES ) . ' sites this script was written for.' ) )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many sites this run (after the ledger skip-list). Use it to try a few sites first.' )
			->addOption( 'retry-failed', null, InputOption::VALUE_NONE, 'Process only the sites that failed on an earlier run. Without it those are left out, unless `--sites` names them.' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (append-only log and resume skip-list). Defaults to ' . $this->file_slug() . '-ledger.jsonl in the CLI directory.' )
			->addOption( 'report', null, InputOption::VALUE_REQUIRED, 'Path to the CSV report. The follow-up list is written next to it. Defaults to ./' . $this->file_slug() . '-report.csv.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt.' );

		if ( ! static::READ_ONLY ) {
			$this->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything to the sites or the ledger. Still connects to each site, which rotates its SFTP/SSH password.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If --limit is not a positive integer, or the ledger cannot be written.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run      = $input->hasOption( 'dry-run' ) && (bool) $input->getOption( 'dry-run' );
		$this->yes          = (bool) $input->getOption( 'yes' );
		$this->retry_failed = (bool) $input->getOption( 'retry-failed' );

		$sites_spec       = $input->getOption( 'sites' );
		$this->sites_spec = ( \is_string( $sites_spec ) && '' !== \trim( $sites_spec ) ) ? \trim( $sites_spec ) : null;

		$limit = $input->getOption( 'limit' );
		if ( null !== $limit ) {
			if ( ! \ctype_digit( (string) $limit ) || (int) $limit < 1 ) {
				throw new \InvalidArgumentException( 'The --limit must be a positive integer.' );
			}
			$this->limit = (int) $limit;
		}

		$this->ledger_path = $this->absolute_path( $input->getOption( 'log' ), TEAM51_CLI_ROOT_DIR . '/' . $this->file_slug() . '-ledger.jsonl' );
		$this->report_path = $this->absolute_path( $input->getOption( 'report' ), \getcwd() . '/' . $this->file_slug() . '-report.csv' );

		// A dry run's follow-up list is a preview, kept apart so it can never replace or remove the
		// real hand-off list.
		$this->follow_up_path = \preg_replace( '/(\.csv)?$/i', $this->dry_run ? '-dry-run-follow-up.csv' : '-follow-up.csv', $this->report_path, 1 );

		// Checked before anything is touched: the ledger is the record of what was done, so finding it
		// unwritable after a site has been changed would lose that record.
		if ( ! $this->dry_run ) {
			$target = \is_file( $this->ledger_path ) ? $this->ledger_path : \dirname( $this->ledger_path );
			if ( ! \is_writable( $target ) ) {
				throw new \InvalidArgumentException( "The ledger at $this->ledger_path cannot be written." );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: no changes will be written ---</>' );
		}

		if ( ! $this->prepare( $input, $output ) ) {
			return Command::FAILURE;
		}

		$fleet = $this->fetch_fleet();
		if ( empty( $fleet ) ) {
			$output->writeln( '<error>Could not fetch the Jetpack-connected sites.</error>' );
			return Command::FAILURE;
		}

		[ $sites, $unmatched, $scope, $named ] = $this->scope_sites( $fleet );
		$sites                                 = $this->filter_sites( $sites, $output );

		if ( ! empty( $unmatched ) ) {
			$output->writeln( '<comment>Not in the connected fleet, left for a person: ' . \implode( ', ', $unmatched ) . '</comment>' );
		}

		// Read on a dry run too: reading the ledger writes nothing, and a preview that ignored it
		// would reconnect to every site already settled, rotating each one's password again.
		$ledger  = $this->ledger_entries();
		$settled = \array_filter( $sites, static fn( \stdClass $site ) => \in_array( $ledger[ (string) $site->userblog_id ]['status'] ?? null, static::SETTLED_STATUSES, true ) );
		$failed  = \array_filter( $sites, static fn( \stdClass $site ) => self::STATUS_FAILED === ( $ledger[ (string) $site->userblog_id ]['status'] ?? null ) );

		// A site that failed before stays out of an ordinary run, or a small --limit would be spent on
		// the same failures every time. Naming a site is asking for it, failed or not.
		$pending = match ( true ) {
			$this->retry_failed => $failed,
			$named              => \array_diff_key( $sites, $settled ),
			default             => \array_diff_key( $sites, $settled, $failed ),
		};
		if ( null !== $this->limit ) {
			$pending = \array_slice( $pending, 0, $this->limit, true );
		}

		$output->writeln( \sprintf( '<comment>%d connected site(s), %d in scope (%s), %d to process this run.</comment>', \count( $fleet ), \count( $sites ), $scope, \count( $pending ) ) );
		if ( ! empty( $settled ) ) {
			$output->writeln( '<comment>' . \count( $settled ) . ' already settled in the ledger and skipped.</comment>' );
		}
		if ( ! $this->retry_failed && ! $named && ! empty( $failed ) ) {
			$output->writeln( '<comment>' . \count( $failed ) . ' failed on an earlier run and left out. Go back for them with --retry-failed.</comment>' );
		}

		if ( empty( $pending ) ) {
			$output->writeln( '<info>Nothing left to do.</info>' );
			return $this->write_follow_up( $unmatched, $ledger, $output ) ? Command::FAILURE : Command::SUCCESS;
		}

		if ( ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>' . $this->confirmation_message( \count( $pending ) ) . ' Each is one SSH connection, and opening it rotates that site\'s SFTP/SSH password, on WordPress.com as well as Pressable. [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. Nothing was changed.</comment>' );
				return Command::SUCCESS;
			}
		}

		$report = $this->open_report( $output );
		$tally  = array();
		$total  = \count( $pending );
		$index  = 0;
		foreach ( $pending as $site ) {
			++$index;
			$platform = null;

			try {
				$platform = $this->determine_platform( $site );
				$result   = null === $platform
					? $this->failure( self::REASON_EXTERNAL, 'Not on WordPress.com (Atomic) or Pressable, so hosted elsewhere and out of the CLI\'s reach. Do it with the site\'s own host.' )
					: $this->run_site( $site, $platform );
			} catch ( \Throwable $e ) {
				$result = $this->failure( self::REASON_ERROR, 'Error: ' . $e->getMessage() );
			}
			$result['platform'] ??= $platform;
			$result['note']       = $this->redact( (string) ( $result['note'] ?? '' ) );

			$entry                                 = $this->ledger_entry( $site, $result );
			$ledger[ (string) $site->userblog_id ] = $entry;
			$this->append_ledger( $entry );
			$this->write_report_row( $report, $site, $result );
			$tally[ $result['status'] ] = ( $tally[ $result['status'] ] ?? 0 ) + 1;

			$style = match ( true ) {
				self::STATUS_FAILED === $result['status']  => 'error',
				$this->needs_follow_up( $result )          => 'comment',
				default                                    => 'info',
			};
			$output->writeln( \sprintf( '[%d/%d] %s <%s>%s: %s</%s>', $index, $total, $this->site_label( $site ), $style, $result['status'], $result['note'], $style ) );
		}

		if ( \is_resource( $report ) ) {
			\fclose( $report );
		}
		$outstanding = $this->write_follow_up( $unmatched, $ledger, $output );

		\ksort( $tally );
		$output->writeln( '' );
		$output->writeln( '<info>' . \implode( ' | ', \array_map( static fn( string $status, int $count ) => "$status: $count", \array_keys( $tally ), $tally ) ) . '</info>' );
		$output->writeln( "Report: $this->report_path" );
		if ( ! $this->dry_run ) {
			$output->writeln( "Ledger: $this->ledger_path" );
		}

		return $outstanding ? Command::FAILURE : Command::SUCCESS;
	}

	// endregion

	// region HOOKS

	/**
	 * Does the work on one site, over a connection the base class opened and will close.
	 *
	 * Must be idempotent: check before writing, and return `unchanged` when there is nothing to do.
	 * Must honour $this->dry_run when the script writes. Throwing is fine; the site is recorded as
	 * failed with the message, and the run carries on.
	 *
	 * @param   \stdClass $site     The fleet site record (`userblog_id`, `siteurl`, ...).
	 * @param   SSH2      $ssh      An open SSH connection to the site.
	 * @param   string    $platform self::PLATFORM_WPCOM or self::PLATFORM_PRESSABLE.
	 *
	 * @return  array{ status: string, note: string, reason?: string, columns?: array<string, string> }
	 */
	abstract protected function process_site( \stdClass $site, SSH2 $ssh, string $platform ): array;

	/**
	 * Runs once before any site is looked at, e.g. to fetch a value every site needs. Return false to stop.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  boolean
	 */
	protected function prepare( InputInterface $input, OutputInterface $output ): bool {
		return true;
	}

	/**
	 * The sites `--sites` picks from, keyed by WPCOM ID, each with at least `userblog_id` and `siteurl`.
	 *
	 * @return  \stdClass[]|null
	 */
	protected function fetch_fleet(): ?array {
		return get_wpcom_jetpack_sites();
	}

	/**
	 * Narrows the sites in scope further, e.g. to those with a plugin installed. Runs before the ledger.
	 *
	 * @param   \stdClass[]     $sites  The sites in scope, keyed by WPCOM ID.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  \stdClass[]
	 */
	protected function filter_sites( array $sites, OutputInterface $output ): array {
		return $sites;
	}

	/**
	 * The question asked before the run.
	 *
	 * @param   int $count How many sites are about to be processed.
	 *
	 * @return  string
	 */
	protected function confirmation_message( int $count ): string {
		return static::READ_ONLY
			? "Check $count site(s)? Read-only."
			: ( $this->dry_run ? "Preview $count site(s)? Nothing will be written." : "Change $count site(s)?" );
	}

	/**
	 * Removes anything secret from a note before it reaches the terminal, the ledger or a report.
	 *
	 * @param   string $text The text to redact.
	 *
	 * @return  string
	 */
	protected function redact( string $text ): string {
		return $text;
	}

	/**
	 * Connects to one site and hands it to process_site(). Override it for a script that needs no SSH.
	 *
	 * @param   \stdClass $site     The fleet site record.
	 * @param   string    $platform The site's platform.
	 *
	 * @return  array
	 */
	protected function run_site( \stdClass $site, string $platform ): array {
		// Asked separately, at the cost of one extra request, because the connection helper only says
		// that it failed. This is the step that fails when the CLI's account is not the site owner.
		if ( self::PLATFORM_WPCOM === $platform && null === get_wpcom_site_ssh_username( (string) $site->userblog_id ) ) {
			return $this->failure( self::REASON_NO_ACCESS, 'WordPress.com would not hand over the SSH user. A 403 above means the concierge user (WordPress.com username a8cteam51) is not the site owner (the primary connected user), and only the owner can manage SSH users through the API: do it in phpMyAdmin or wp-admin, or have the owner do it. With no error above, SSH is not enabled on the site yet.' );
		}

		$ssh = match ( $platform ) {
			self::PLATFORM_WPCOM     => \WPCOM_Connection_Helper::get_ssh_connection( (string) $site->userblog_id ),
			self::PLATFORM_PRESSABLE => \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_identifier( $site ) ),
		};
		if ( null === $ssh ) {
			return $this->failure(
				self::REASON_SSH,
				self::PLATFORM_PRESSABLE === $platform
					? 'SSH connection failed. Usually the site has no SFTP user for the concierge user (add it as a collaborator in MyPressable), or it is unreachable. Retry with --retry-failed, or do it in phpMyAdmin.'
					: 'SSH connection failed (SSH disabled, site unreachable, or authentication failure). Retry with --retry-failed, or do it in phpMyAdmin.'
			);
		}

		try {
			$ssh->setTimeout( static::SSH_TIMEOUT );
			return $this->process_site( $site, $ssh, $platform );
		} finally {
			$ssh->disconnect();
		}
	}

	// endregion

	// region SSH HELPERS

	/**
	 * Runs a shell command and returns its output, standard error and exit code separately.
	 *
	 * @param   SSH2   $ssh     The connection.
	 * @param   string $command The command. Quote every interpolated value with escapeshellarg().
	 *
	 * @throws  \RuntimeException If the command times out, so a half-run command is not read as a result.
	 *
	 * @return  array{ output: string, error: string, exit: int }
	 */
	protected function exec( SSH2 $ssh, string $command ): array {
		$ssh->enableQuietMode();
		try {
			$output = (string) $ssh->exec( $command );
			$error  = (string) $ssh->getStdError();
			$exit   = $ssh->getExitStatus();
		} finally {
			$ssh->disableQuietMode();
		}

		if ( $ssh->isTimeout() ) {
			throw new \RuntimeException( 'The command timed out after ' . static::SSH_TIMEOUT . 's.' );
		}

		return array(
			'output' => \trim( $output ),
			'error'  => \trim( $error ),
			'exit'   => false === $exit ? -1 : (int) $exit,
		);
	}

	/**
	 * Runs a WP-CLI command with plugins and themes skipped, so a broken or malicious plugin is never run.
	 *
	 * The flags go straight after `wp`: the Atomic wrapper rejects them after the subcommand
	 * ("unknown --skip-plugins parameter"). Prefer subcommands with structured output
	 * (`--format=count|json`, `--field=`) over `wp eval`, which can return nothing at all.
	 *
	 * @param   SSH2   $ssh          The connection.
	 * @param   string $args         Everything after `wp`, already escaped, e.g. `option get 'blogname'`.
	 * @param   bool   $skip_plugins Whether to skip plugins and themes. Only turn off when the command needs them loaded.
	 *
	 * @return  array{ output: string, error: string, exit: int }
	 */
	protected function wp( SSH2 $ssh, string $args, bool $skip_plugins = true ): array {
		return $this->exec( $ssh, 'wp ' . ( $skip_plugins ? '--skip-plugins --skip-themes ' : '' ) . $args );
	}

	/**
	 * Reads the output of a script that prints one `TAG=value` line per fact.
	 *
	 * Plugin notices and PHP warnings get mixed into the output of anything that loads WordPress, so
	 * a script that needs several facts should tag each one and let this pick them out.
	 *
	 * @param   string $raw The raw output.
	 *
	 * @return  array<string, string[]> The values of each tag, in order.
	 */
	protected function parse_tagged( string $raw ): array {
		$tags = array();
		foreach ( \preg_split( '/\R/', $raw ) as $line ) {
			if ( 1 === \preg_match( '/^([A-Z][A-Z0-9_]*)=(.*)$/', \trim( $line ), $matches ) ) {
				$tags[ $matches[1] ][] = \trim( $matches[2] );
			}
		}

		return $tags;
	}

	// endregion

	// region SITE HELPERS

	/**
	 * Works out which platform hosts a site, or null when it is hosted elsewhere. Never guesses.
	 *
	 * Not being on Atomic does not make a site a Pressable one: sites hosted elsewhere are in the
	 * connected fleet too, so Pressable has to claim it. Looking up a site Pressable does not have
	 * prints an OpsOasis API error (often a 500); that is expected, not a fault.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string|null self::PLATFORM_WPCOM, self::PLATFORM_PRESSABLE, or null.
	 */
	protected function determine_platform( \stdClass $site ): ?string {
		$host = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		if ( \str_ends_with( $host, '.wpcomstaging.com' ) ) {
			return self::PLATFORM_WPCOM;
		}
		if ( \str_ends_with( $host, '.mystagingwebsite.com' ) ) {
			return self::PLATFORM_PRESSABLE;
		}

		$atomic = $site->is_wpcom_atomic ?? get_wpcom_site( (string) $site->userblog_id )?->is_wpcom_atomic ?? null;
		if ( true === $atomic ) {
			return self::PLATFORM_WPCOM;
		}

		if ( null !== get_pressable_site( $this->pressable_identifier( $site ) ) ) {
			return self::PLATFORM_PRESSABLE;
		}

		return null;
	}

	/**
	 * The identifier a Pressable site is reached by: its host, falling back to the WPCOM ID.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string
	 */
	protected function pressable_identifier( \stdClass $site ): string {
		$host = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		return '' !== $host ? $host : (string) $site->userblog_id;
	}

	/**
	 * Builds the result for a site that could not be processed.
	 *
	 * @param   string $reason One of the REASON_* constants.
	 * @param   string $note   What happened and what a person should do about it.
	 *
	 * @return  array{ status: string, reason: string, note: string }
	 */
	protected function failure( string $reason, string $note ): array {
		return array(
			'status' => self::STATUS_FAILED,
			'reason' => $reason,
			'note'   => $note,
		);
	}

	/**
	 * How a site is named in the output.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string
	 */
	protected function site_label( \stdClass $site ): string {
		return (string) ( $site->siteurl ?? $site->userblog_id );
	}

	// endregion

	// region INTERNALS

	/**
	 * Narrows the fleet to what `--sites` asks for, or to DEFAULT_SITES when it is not given.
	 *
	 * @param   \stdClass[] $fleet The connected Jetpack sites.
	 *
	 * @return  array A four-element list: the sites in scope, the identifiers that matched no site,
	 *                a label for the scope, and whether the sites were typed out one by one.
	 */
	private function scope_sites( array $fleet ): array {
		if ( null === $this->sites_spec ) {
			if ( empty( static::DEFAULT_SITES ) ) {
				return array( $fleet, array(), 'whole fleet', false );
			}

			[ $sites, $unmatched ] = resolve_wpcom_sites_from_identifiers( static::DEFAULT_SITES, $fleet );
			return array( $sites, $unmatched, 'default sites', false );
		}

		$keyword = \strtolower( $this->sites_spec );
		if ( 'all' === $keyword ) {
			return array( $fleet, array(), 'whole fleet', false );
		}
		if ( 'staging' === $keyword || 'production' === $keyword ) {
			$want_staging = 'staging' === $keyword;
			$sites        = \array_filter(
				$fleet,
				static fn( \stdClass $site ) => ( false !== \stripos( (string) ( $site->siteurl ?? '' ), 'staging' ) ) === $want_staging
			);

			return array( $sites, array(), $keyword, false );
		}

		[ $sites, $unmatched ] = resolve_wpcom_sites_from_identifiers( parse_wpcom_site_identifiers( $this->sites_spec ), $fleet );
		// Only a typed list counts as naming sites one by one. A CSV is passed on every run of a job, so
		// treating it as named would bring earlier failures back each time and rotate their passwords again.
		$is_csv = \is_file( $this->sites_spec );
		return array( $sites, $unmatched, $is_csv ? 'sites from CSV' : 'requested list', ! $is_csv );
	}

	/**
	 * Whether a result puts its site on the follow-up list.
	 *
	 * @param   array $result The processing result.
	 *
	 * @return  boolean
	 */
	private function needs_follow_up( array $result ): bool {
		return self::STATUS_FAILED === $result['status'] || \in_array( $result['status'], static::FOLLOW_UP_STATUSES, true );
	}

	/**
	 * Returns the latest entry the ledger records for each site, keyed by site ID.
	 *
	 * @return  array<string, array>
	 */
	private function ledger_entries(): array {
		if ( ! \is_file( $this->ledger_path ) ) {
			return array();
		}

		$latest = array();
		foreach ( \file( $this->ledger_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$entry = \json_decode( $line, true );
			if ( \is_array( $entry ) && isset( $entry['id'], $entry['status'] ) ) {
				$latest[ (string) $entry['id'] ] = $entry;
			}
		}

		return $latest;
	}

	/**
	 * Builds the ledger entry for a site's result.
	 *
	 * @param   \stdClass $site   The processed site.
	 * @param   array     $result The processing result.
	 *
	 * @return  array
	 */
	private function ledger_entry( \stdClass $site, array $result ): array {
		return array(
			'id'        => $site->userblog_id,
			'url'       => $site->siteurl ?? null,
			'platform'  => $result['platform'] ?? null,
			'status'    => $result['status'],
			'reason'    => $result['reason'] ?? null,
			'note'      => $result['note'],
			'timestamp' => \gmdate( 'c' ),
		);
	}

	/**
	 * Appends an entry to the ledger. No-op during a dry run.
	 *
	 * Append-only under an exclusive lock, so neither a partial read nor a second run can lose a row.
	 * A repeated site gains a second row, which is history rather than a duplicate.
	 *
	 * @param   array $entry The entry, from ledger_entry().
	 *
	 * @throws  \RuntimeException If the entry cannot be encoded or appended (must fail loudly).
	 *
	 * @return  void
	 */
	private function append_ledger( array $entry ): void {
		if ( $this->dry_run ) {
			return;
		}

		$encoded = \json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded || false === \file_put_contents( $this->ledger_path, $encoded . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( "Failed to append to the ledger at $this->ledger_path." );
		}
	}

	/**
	 * Opens the CSV report and writes its header. Rows are written as sites finish, so an interrupted
	 * run still leaves a report.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  resource|null
	 */
	private function open_report( OutputInterface $output ) {
		$handle = \fopen( $this->report_path, 'w' );
		if ( false === $handle ) {
			$output->writeln( "<error>Could not open the report for writing: $this->report_path. Carrying on; the ledger still records every site.</error>" );
			return null;
		}

		\fputcsv( $handle, \array_merge( array( 'id', 'url', 'platform', 'status', 'reason', 'note' ), static::REPORT_COLUMNS ), ',', '"', '' );
		return $handle;
	}

	/**
	 * Writes one site's row to the report.
	 *
	 * @param   resource|null $handle The report, or null if it could not be opened.
	 * @param   \stdClass     $site   The processed site.
	 * @param   array         $result The processing result.
	 *
	 * @return  void
	 */
	private function write_report_row( $handle, \stdClass $site, array $result ): void {
		if ( ! \is_resource( $handle ) ) {
			return;
		}

		$columns = \array_map( fn( string $column ) => $this->redact( (string) ( $result['columns'][ $column ] ?? '' ) ), static::REPORT_COLUMNS );
		\fputcsv( $handle, \array_merge( array( $site->userblog_id, $site->siteurl ?? '', $result['platform'] ?? '', $result['status'], $result['reason'] ?? '', $result['note'] ), $columns ), ',', '"', '' );
		\fflush( $handle );
	}

	/**
	 * Writes the sites a person has to finish by hand, with their host and what is left to do.
	 *
	 * Built from the latest outcome of every site in the ledger, earlier runs and other `--sites`
	 * included, so a run that only processed a few sites still hands over the whole job. An empty list
	 * removes a stale file.
	 *
	 * @param   string[]             $unmatched The identifiers that matched no site.
	 * @param   array<string, array> $latest    The latest ledger entry per site, this run included.
	 * @param   OutputInterface      $output    The output object.
	 *
	 * @return  boolean  Whether any site is left for a person.
	 */
	private function write_follow_up( array $unmatched, array $latest, OutputInterface $output ): bool {
		$rows = array();
		foreach ( $unmatched as $identifier ) {
			$rows[] = array( $identifier, $identifier, '', self::STATUS_FAILED, self::REASON_NOT_IN_FLEET, 'Not in the Jetpack-connected fleet (disconnected, or not ours). Check the site by hand.' );
		}
		foreach ( $latest as $entry ) {
			if ( $this->needs_follow_up( $entry ) ) {
				$rows[] = array( $entry['id'], $entry['url'] ?? '', $entry['platform'] ?? 'unknown', $entry['status'], $entry['reason'] ?? '', $entry['note'] ?? '' );
			}
		}

		if ( empty( $rows ) ) {
			if ( \is_file( $this->follow_up_path ) && \unlink( $this->follow_up_path ) ) {
				$output->writeln( "<info>Nothing is left for a person. Removed the old follow-up list at $this->follow_up_path.</info>" );
			}
			return false;
		}

		$handle = \fopen( $this->follow_up_path, 'w' );
		if ( false === $handle ) {
			$output->writeln( "<error>Could not write the follow-up list to $this->follow_up_path.</error>" );
			return true;
		}

		\fputcsv( $handle, array( 'id', 'url', 'host', 'status', 'reason', 'todo' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			\fputcsv( $handle, $row, ',', '"', '' );
		}
		\fclose( $handle );

		$output->writeln( '<comment>' . \count( $rows ) . " site(s) need a person. Follow-up list: $this->follow_up_path</comment>" );
		return true;
	}

	/**
	 * Resolves a path option against the current directory, falling back to a default.
	 *
	 * @param   mixed  $value    The option value.
	 * @param   string $fallback The path to use when the option was not given.
	 *
	 * @return  string
	 */
	private function absolute_path( mixed $value, string $fallback ): string {
		return match ( true ) {
			! \is_string( $value ) || '' === $value => $fallback,
			\str_starts_with( $value, '/' )         => $value,
			default                                 => \getcwd() . '/' . $value,
		};
	}

	/**
	 * The command name, made safe for a file name, e.g. `updraft-remove-central-keys`.
	 *
	 * @return  string
	 */
	private function file_slug(): string {
		return \trim( (string) \preg_replace( '/[^a-z0-9]+/i', '-', (string) $this->getName() ), '-' );
	}

	// endregion
}
