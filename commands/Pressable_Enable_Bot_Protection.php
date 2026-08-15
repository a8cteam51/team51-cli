<?php

namespace WPCOMSpecialProjects\CLI\Command;

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
 * Enables WP Cloud Bot Protection on Pressable sites by defining the
 * WPC_BOT_PROTECTION_ENABLED constant in wp-config over SSH.
 *
 * Enablement of WP Cloud Bot Protection comes from WP Cloud's own tiers; the
 * operator-settable one is the WPC_BOT_PROTECTION_ENABLED constant. WoA/Atomic
 * (wpcom) sites already have a tier armed at the platform level, so only
 * Pressable sites need the constant set explicitly. (The Atlantis Bot Protection
 * module can only inherit or force *off* — it has no enable path — so enabling
 * is done here, via the constant.)
 *
 * The command is idempotent (skips any site where the constant already exists,
 * recording its current value) and resumable: every processed site is appended
 * to a JSONL ledger that doubles as the skip-list on the next run. It uses pure
 * SSH `wp config`, so it does not touch the WPCOM Jetpack tunnel or any shared
 * OAuth token.
 *
 * Examples:
 *   # Single site — first tests (dry run, then for real)
 *   team51 pressable:enable-bot-protection example.mystagingwebsite.com --dry-run
 *   team51 pressable:enable-bot-protection example.mystagingwebsite.com
 *
 *   # Larger staging-only batch
 *   team51 pressable:enable-bot-protection --staging-only
 *
 *   # Everything else — already-recorded sites are skipped via the ledger
 *   team51 pressable:enable-bot-protection
 *
 *   # Small first batch, unattended
 *   team51 pressable:enable-bot-protection --limit=20 --no-output
 */
#[AsCommand( name: 'pressable:enable-bot-protection' )]
final class Pressable_Enable_Bot_Protection extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The wp-config constant that arms WP Cloud's bot-protection tier.
	 *
	 * @var string
	 */
	private const CONSTANT_NAME = 'WPC_BOT_PROTECTION_ENABLED';

	/**
	 * Default JSONL ledger filename, written in the current working directory.
	 *
	 * @var string
	 */
	private const DEFAULT_LEDGER = 'bot-protection-ledger.jsonl';

	/**
	 * Per-site SSH command timeout, in seconds.
	 *
	 * @var int
	 */
	private const SSH_TIMEOUT = 30;

	/**
	 * Ledger statuses that mark a site as complete (skipped on future runs).
	 *
	 * @var array<int, string>
	 */
	private const DONE_STATUSES = array( 'success', 'exists', 'exists-disabled' );

	/**
	 * The single site to process, when a site argument is supplied. Null in
	 * fleet mode.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Whether to restrict the fleet to staging sites only.
	 *
	 * @var bool
	 */
	private bool $staging_only = false;

	/**
	 * Whether to restrict the fleet to non-staging (production) sites only.
	 *
	 * @var bool
	 */
	private bool $production_only = false;

	/**
	 * Maximum number of sites to process this run, or null for no limit.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * Whether to skip confirmation and minimize output.
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

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Enables WP Cloud Bot Protection on Pressable sites by setting the WPC_BOT_PROTECTION_ENABLED constant in wp-config.' )
			->setHelp( 'Sets `WPC_BOT_PROTECTION_ENABLED = true` in wp-config on Pressable sites over SSH. WoA/Atomic sites already have this armed at the platform level, so only Pressable sites need it. The command is idempotent (skips sites where the constant already exists) and resumable via a JSONL ledger that records every processed site.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'A single Pressable site (URL, domain, or ID) to process. Omit to process the whole fleet.' )
			->addOption( 'staging-only', null, InputOption::VALUE_NONE, 'Only process staging sites (by the Pressable staging flag, not URL matching).' )
			->addOption( 'production-only', null, InputOption::VALUE_NONE, 'Only process non-staging (production) sites.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many sites this run (applied after filtering and the ledger skip-list).' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (audit log + resume skip-list). Defaults to ./' . self::DEFAULT_LEDGER . '.' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Skip confirmation and minimize output (for large runs).' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything (no SSH writes, no ledger entries).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->staging_only    = (bool) $input->getOption( 'staging-only' );
		$this->production_only = (bool) $input->getOption( 'production-only' );
		$this->dry_run         = (bool) $input->getOption( 'dry-run' );
		$this->quiet           = (bool) $input->getOption( 'no-output' );

		if ( $this->staging_only && $this->production_only ) {
			$output->writeln( '<error>--staging-only and --production-only are mutually exclusive.</error>' );
			exit( 1 );
		}

		$limit = $input->getOption( 'limit' );
		if ( null !== $limit ) {
			if ( ! ctype_digit( (string) $limit ) || (int) $limit < 1 ) {
				$output->writeln( '<error>--limit must be a positive integer.</error>' );
				exit( 1 );
			}
			$this->limit = (int) $limit;
		}

		$log_path          = $input->getOption( 'log' );
		$this->ledger_path = ( is_string( $log_path ) && '' !== $log_path ) ? $log_path : self::DEFAULT_LEDGER;

		// Single-site mode: resolve and validate the site argument. Filters and
		// the fleet fetch only apply when no site is given.
		$site_arg = $input->getArgument( 'site' );
		if ( null !== $site_arg ) {
			if ( $this->staging_only || $this->production_only ) {
				$output->writeln( '<comment>Note: --staging-only/--production-only are ignored when a single site is given.</comment>' );
			}

			$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $this->site );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run && ! $this->quiet ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: no changes will be written ---</>' );
			$output->writeln( '' );
		}

		$is_fleet = null === $this->site;

		// Build the (filtered) target list.
		$targets = $this->resolve_targets( $output );
		if ( null === $targets ) {
			return Command::FAILURE;
		}
		if ( empty( $targets ) ) {
			$output->writeln( '<comment>No sites matched after filtering.</comment>' );
			return Command::SUCCESS;
		}

		// In fleet mode, drop any site already recorded complete in the ledger.
		$pending      = $targets;
		$skipped_done = 0;
		if ( $is_fleet ) {
			$done    = $this->load_completed_ids();
			$pending = array();
			foreach ( $targets as $site ) {
				if ( isset( $done[ $site->id ] ) ) {
					++$skipped_done;
					continue;
				}
				$pending[] = $site;
			}
		}

		// Cap to --limit.
		if ( null !== $this->limit && count( $pending ) > $this->limit ) {
			$pending = array_slice( $pending, 0, $this->limit );
		}

		if ( empty( $pending ) ) {
			$output->writeln( "<info>Nothing to do: all {$skipped_done} matching site(s) are already recorded in the ledger ({$this->ledger_path}).</info>" );
			return Command::SUCCESS;
		}

		// Confirm before making changes.
		if ( ! $this->quiet && ! $this->dry_run ) {
			$count    = count( $pending );
			$scope    = $this->describe_scope();
			$extra    = $skipped_done > 0 ? " ({$skipped_done} already done, skipped)" : '';
			$question = new ConfirmationQuestion(
				'<question>Set ' . self::CONSTANT_NAME . " = true on {$count} Pressable site(s) [{$scope}]{$extra}? [y/N]</question> ",
				false
			);
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				return Command::FAILURE;
			}
			$output->writeln( '' );
		}

		return $this->process( $pending, $skipped_done, $output );
	}

	// endregion

	// region PROCESSING

	/**
	 * Iterates the pending sites, applies the constant, and records each result
	 * in the ledger.
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
			'success'         => 0,
			'exists'          => 0,
			'exists-disabled' => 0,
			'would-set'       => 0,
			'failed'          => 0,
		);

		foreach ( $pending as $site ) {
			++$counter;
			$label = $this->site_label( $site );

			if ( ! $this->quiet ) {
				$output->writeln( "<info>[{$counter}/{$total}] {$label}</info>" );
			}

			try {
				$result = $this->process_site( $site, $output );
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

			$this->append_ledger( $site, $result );
			$this->report_result( $status, $result['note'], $output );
		}

		return $this->summarize( $tally, $total, $skipped_done, $output );
	}

	/**
	 * Applies the constant to a single site over a single SSH connection.
	 *
	 * @param   \stdClass       $site   The Pressable site object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array{ status: string, note: string } Result status and descriptive note.
	 */
	private function process_site( \stdClass $site, OutputInterface $output ): array {
		$ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $site->id );
		if ( null === $ssh ) {
			return array(
				'status' => 'failed',
				'note'   => 'SSH connection failed (site unreachable, no connection, or authentication failure).',
			);
		}

		$ssh->setTimeout( self::SSH_TIMEOUT );

		try {
			// Idempotency: is the constant already defined? `wp config get`
			// exits 0 and prints the value when present, non-zero when absent.
			$current = $ssh->exec( 'wp config get ' . self::CONSTANT_NAME . ' 2>/dev/null' );
			$exists  = 0 === $ssh->getExitStatus();

			if ( $exists ) {
				$value  = trim( (string) $current );
				$truthy = in_array( strtolower( $value ), array( 'true', '1' ), true );

				if ( $truthy ) {
					return array(
						'status' => 'exists',
						'note'   => "Already set (value: {$value}).",
					);
				}

				// Present but not truthy: never overwrite — this could be a
				// deliberate manual disable. Flag it for review instead.
				return array(
					'status' => 'exists-disabled',
					'note'   => "Already present but NOT truthy (value: {$value}) — left unchanged, review manually.",
				);
			}

			if ( $this->dry_run ) {
				return array(
					'status' => 'would-set',
					'note'   => 'Would set ' . self::CONSTANT_NAME . ' = true.',
				);
			}

			$set_output = $ssh->exec( 'wp config set ' . self::CONSTANT_NAME . ' true --raw' );
			$exit_code  = $ssh->getExitStatus();

			if ( 0 !== $exit_code ) {
				$detail = trim( (string) $set_output );
				$detail = '' !== $detail ? ": {$detail}" : '.';
				return array(
					'status' => 'failed',
					'note'   => "wp config set failed (exit {$exit_code}){$detail}",
				);
			}

			return array(
				'status' => 'success',
				'note'   => 'Set ' . self::CONSTANT_NAME . ' = true.',
			);
		} finally {
			$ssh->disconnect();
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the list of sites to act on, applying environment filters in
	 * fleet mode.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  \stdClass[]|null The target sites, or null on a fatal fetch error.
	 */
	private function resolve_targets( OutputInterface $output ): ?array {
		if ( null !== $this->site ) {
			return array( $this->site );
		}

		$sites = get_pressable_sites();
		if ( null === $sites ) {
			$output->writeln( '<error>Failed to fetch the Pressable sites list.</error>' );
			return null;
		}

		$filtered = array();
		foreach ( $sites as $site ) {
			$is_staging = (bool) ( $site->staging ?? false );

			if ( $this->staging_only && ! $is_staging ) {
				continue;
			}
			if ( $this->production_only && $is_staging ) {
				continue;
			}

			$filtered[] = $site;
		}

		return $filtered;
	}

	/**
	 * Loads the set of site IDs already recorded complete in the ledger.
	 *
	 * Latest-status-wins: a later `failed` entry re-opens a site for retry, and
	 * a later completion entry closes it again.
	 *
	 * @return  array<int|string, true> Map of completed site IDs.
	 */
	private function load_completed_ids(): array {
		$done = array();
		if ( ! is_file( $this->ledger_path ) ) {
			return $done;
		}

		$handle = fopen( $this->ledger_path, 'r' );
		if ( false === $handle ) {
			return $done;
		}

		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['status'] ) ) {
				continue;
			}

			if ( in_array( $entry['status'], self::DONE_STATUSES, true ) ) {
				$done[ $entry['id'] ] = true;
			} else {
				unset( $done[ $entry['id'] ] );
			}
		}

		fclose( $handle );
		return $done;
	}

	/**
	 * Appends a single result to the JSONL ledger. No-op during a dry run.
	 *
	 * @param   \stdClass                             $site   The processed site.
	 * @param   array{ status: string, note: string } $result The processing result.
	 *
	 * @throws  \RuntimeException If the ledger cannot be written (the resume mechanism must fail loudly).
	 *
	 * @return  void
	 */
	private function append_ledger( \stdClass $site, array $result ): void {
		if ( $this->dry_run ) {
			return;
		}

		$entry = array(
			'id'        => $site->id,
			'url'       => $site->url ?? null,
			'name'      => $site->displayName ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'staging'   => (bool) ( $site->staging ?? false ),
			'status'    => $result['status'],
			'note'      => $result['note'],
			'timestamp' => gmdate( 'c' ),
		);

		$line = json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( false === $line ) {
			return;
		}

		if ( false === file_put_contents( $this->ledger_path, $line . "\n", FILE_APPEND | LOCK_EX ) ) {
			// Ledger is the resume mechanism; a write failure must be loud.
			throw new \RuntimeException( "Failed to write the ledger at {$this->ledger_path}." );
		}
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
			'success', 'would-set' => 'info',
			'exists'               => 'comment',
			'exists-disabled'      => 'comment',
			default                => 'error',
		};

		$output->writeln( "  <{$style}>{$note}</{$style}>" );
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
				sprintf(
					'Processed: %d | Set: %d | Already enabled: %d | Present-but-off: %d | Would set: %d | Failed: %d | Skipped (already done): %d',
					$total,
					$tally['success'],
					$tally['exists'],
					$tally['exists-disabled'],
					$tally['would-set'],
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
	 * Builds a human-readable label for a site.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string
	 */
	private function site_label( \stdClass $site ): string {
		return (string) ( $site->url ?? $site->displayName ?? $site->id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Describes the current run scope for the confirmation prompt.
	 *
	 * @return  string
	 */
	private function describe_scope(): string {
		if ( null !== $this->site ) {
			return 'single site';
		}
		if ( $this->staging_only ) {
			return 'staging only';
		}
		if ( $this->production_only ) {
			return 'production only';
		}
		return 'all Pressable sites';
	}

	/**
	 * Prompts for a site when the argument is omitted in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the site URL, domain, or ID:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
