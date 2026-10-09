<?php

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * ONE-OFF. Marks every fleet site that runs Atlantis as a managed site, ahead of the Atlantis
 * release that stops assuming it.
 *
 * Not meant to be merged: it exists for a single sweep, and a command that can write to every site
 * in one invocation is not something to leave installed. `atlantis:mark-managed` is the standing,
 * one-site-at-a-time version.
 *
 * Why the sweep has to come first: up to 1.4.0 Atlantis carries the address of the fleet's
 * autoupdate settings in its own code. The next release carries none and reads it from an option,
 * so a site that updates without the option stops following the central settings - quietly, since
 * running on local rules alone is a supported state. The options are ignored by the releases that
 * predate them, which is what makes it safe to write them now and release afterwards.
 *
 * Behavior:
 * - Site universe is the Jetpack-connected fleet, narrowed to the sites whose Atlantis status
 *   endpoint answers. Sites that do not answer are listed and left alone: either they do not run
 *   Atlantis, or they cannot be reached and need a look by hand.
 * - Each site is reached over SSH via the helper matching its platform, resolved the way
 *   `jetpack:plugin-force-update` resolves it. A site neither platform claims is reported as a
 *   failure rather than guessed at.
 * - Each site costs one SSH connection, and opening one rotates that site's SFTP/SSH password, on
 *   WordPress.com as well as on Pressable. A dry run opens the connection too.
 * - Every processed site is appended to a JSONL ledger that doubles as the skip-list on the next
 *   run, so an interrupted sweep resumes where it stopped. The settings address is never written to
 *   it, nor printed.
 *
 * Examples:
 *   team51 atlantis:mark-managed-fleet --dry-run
 *   team51 atlantis:mark-managed-fleet --limit=5
 *   team51 atlantis:mark-managed-fleet
 */
#[AsCommand( name: 'atlantis:mark-managed-fleet' )]
final class Atlantis_Mark_Managed_Fleet extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Platform identifiers, as recorded in the ledger's `type` field.
	 *
	 * @var string
	 */
	private const PLATFORM_WPCOM     = 'wpcom';
	private const PLATFORM_PRESSABLE = 'pressable';

	/**
	 * Default JSONL ledger filename, written in the CLI's own directory.
	 *
	 * @var string
	 */
	private const DEFAULT_LEDGER = 'atlantis-mark-managed-ledger.jsonl';

	/**
	 * Ledger statuses that settle a site, so a later run skips it.
	 *
	 * @var string[]
	 */
	private const DONE_STATUSES = array( 'success', 'unchanged' );

	/**
	 * Per-site SSH command timeout, in seconds.
	 *
	 * @var int
	 */
	private const SSH_TIMEOUT = 30;

	/**
	 * Whether to run without writing any changes.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Whether to skip the confirmation prompt.
	 *
	 * @var bool
	 */
	private bool $yes = false;

	/**
	 * The most sites to process this run, or null for no limit.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	/**
	 * Absolute path to the JSONL ledger.
	 *
	 * @var string
	 */
	private string $ledger_path = '';

	/**
	 * The autoupdate settings address, fetched from OpsOasis.
	 *
	 * @var string
	 */
	private string $settings_url = '';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'ONE-OFF: marks every fleet site running Atlantis as managed and gives it the autoupdate settings address.' )
			->setHelp( 'Sets `a8csp_atlantis_managed_site` and `a8csp_atlantis_autoupdate_settings_url` on every Jetpack-connected site whose Atlantis status endpoint answers. Run it before releasing the Atlantis version that reads them. Idempotent and resumable: a site that already holds both values is left alone, and sites settled in the ledger are skipped.' );

		$this->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many sites this run (applied after the ledger skip-list). Use it to canary.' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (append-only audit log + resume skip-list). Defaults to ' . self::DEFAULT_LEDGER . ' in the CLI directory.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything (no option writes, no ledger entries). Still opens a connection to each pending site, which rotates its SFTP/SSH password.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If --limit is not a positive integer, or the ledger cannot be written.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run = (bool) $input->getOption( 'dry-run' );
		$this->yes     = (bool) $input->getOption( 'yes' );

		$limit = $input->getOption( 'limit' );
		if ( null !== $limit ) {
			if ( ! \ctype_digit( (string) $limit ) || (int) $limit < 1 ) {
				throw new \InvalidArgumentException( 'The --limit must be a positive integer.' );
			}
			$this->limit = (int) $limit;
		}

		$log               = $input->getOption( 'log' );
		$this->ledger_path = match ( true ) {
			! \is_string( $log ) || '' === $log => TEAM51_CLI_ROOT_DIR . '/' . self::DEFAULT_LEDGER,
			\str_starts_with( $log, '/' )       => $log,
			default                             => \getcwd() . '/' . $log,
		};

		// Checked before anything is touched: the ledger is the record of what was written, so
		// finding it unwritable after a site has been changed would lose that record.
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

		$settings_url = get_atlantis_autoupdate_settings_url();
		if ( \is_null( $settings_url ) ) {
			$output->writeln( '<error>OpsOasis did not provide the autoupdate settings address. Nothing was changed.</error>' );
			return Command::FAILURE;
		}
		$this->settings_url = $settings_url;

		$sites = get_wpcom_jetpack_sites();
		if ( empty( $sites ) ) {
			$output->writeln( '<error>Could not fetch the Jetpack-connected sites.</error>' );
			return Command::FAILURE;
		}

		$errors   = array();
		$statuses = get_wpcom_sites_atlantis_status_batch( \array_column( $sites, 'userblog_id' ), $errors );
		if ( \is_null( $statuses ) ) {
			$output->writeln( '<error>Could not fetch the Atlantis status of the fleet.</error>' );
			return Command::FAILURE;
		}

		// Only a site whose Atlantis answered is a target. The rest are named, so that a site whose
		// connection happened to be down during the sweep is not mistaken for one that was handled.
		$targets    = \array_filter( $sites, static fn( \stdClass $site ) => isset( $statuses[ $site->userblog_id ] ) );
		$unanswered = \array_filter( $sites, static fn( \stdClass $site ) => ! isset( $statuses[ $site->userblog_id ] ) );

		// Read on a dry run too: reading the ledger writes nothing, and a preview that ignored it
		// would reconnect to every site already settled, rotating each one's password again.
		$done    = $this->completed_ids();
		$pending = \array_filter( $targets, static fn( \stdClass $site ) => ! isset( $done[ (string) $site->userblog_id ] ) );
		$skipped = \count( $targets ) - \count( $pending );
		if ( null !== $this->limit ) {
			$pending = \array_slice( $pending, 0, $this->limit, true );
		}

		$output->writeln( \sprintf( '<comment>%d connected site(s): %d running Atlantis, %d not answering its status endpoint.</comment>', \count( $sites ), \count( $targets ), \count( $unanswered ) ) );
		if ( $skipped > 0 ) {
			$output->writeln( "<comment>$skipped already settled in the ledger and skipped.</comment>" );
		}

		if ( empty( $pending ) ) {
			$output->writeln( '<info>Nothing left to do.</info>' );
			$this->report_unanswered( $unanswered, $output );
			return Command::SUCCESS;
		}

		if ( ! $this->dry_run && ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>Mark ' . \count( $pending ) . ' site(s) as managed for Atlantis? Each is one SSH connection, and opening it rotates that site\'s SFTP/SSH password, on WordPress.com as well as Pressable. [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. Nothing was changed.</comment>' );
				return Command::SUCCESS;
			}
		}

		$tally    = array();
		$position = 0;
		foreach ( $pending as $site ) {
			++$position;

			try {
				$result = $this->process_site( $site );
			} catch ( \Throwable $e ) {
				$result = array(
					'status' => 'failed',
					'type'   => null,
					'note'   => 'Error: ' . $this->redact( $e->getMessage() ),
				);
			}

			$this->append_ledger( $site, $result );
			$tally[ $result['status'] ] = ( $tally[ $result['status'] ] ?? 0 ) + 1;

			$style = match ( $result['status'] ) {
				'success', 'would-set' => 'info',
				'unchanged'            => 'comment',
				default                => 'error',
			};
			$output->writeln( \sprintf( '[%d/%d] %s <%s>%s</%s>', $position, \count( $pending ), $site->siteurl ?? $site->userblog_id, $style, $result['note'], $style ) );
		}

		\ksort( $tally );
		$output->writeln( '' );
		$output->writeln( '<info>' . \implode( ' | ', \array_map( static fn( string $status, int $count ) => "$status: $count", \array_keys( $tally ), $tally ) ) . '</info>' );
		if ( ! $this->dry_run ) {
			$output->writeln( "Ledger: $this->ledger_path" );
		}
		$this->report_unanswered( $unanswered, $output );

		return empty( $tally['failed'] ) ? Command::SUCCESS : Command::FAILURE;
	}

	// endregion

	// region PROCESSING

	/**
	 * Marks one site over a single SSH connection.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  array{ status: string, type: string|null, note: string }
	 */
	private function process_site( \stdClass $site ): array {
		$platform = $this->determine_platform( $site );
		if ( null === $platform ) {
			return array(
				'status' => 'failed',
				'type'   => null,
				'note'   => 'Neither WordPress.com nor Pressable claims this site. Mark it by hand.',
			);
		}

		$ssh = match ( $platform ) {
			self::PLATFORM_WPCOM     => \WPCOM_Connection_Helper::get_ssh_connection( (string) $site->userblog_id ),
			self::PLATFORM_PRESSABLE => \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_identifier( $site ) ),
		};
		if ( null === $ssh ) {
			return array(
				'status' => 'failed',
				'type'   => $platform,
				'note'   => 'SSH connection failed (site unreachable, no connection, or authentication failure).',
			);
		}

		try {
			$ssh->setTimeout( self::SSH_TIMEOUT );
			$result = mark_site_as_atlantis_managed( $ssh, $this->settings_url, $this->dry_run );
		} finally {
			$ssh->disconnect();
		}

		return array(
			'status' => $result['status'],
			'type'   => $platform,
			'note'   => $result['note'],
		);
	}

	/**
	 * Works out which platform hosts a site, or null when it cannot be established. Never guesses.
	 *
	 * The same order `jetpack:plugin-force-update` settles it in, and for the reasons recorded there.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string|null self::PLATFORM_WPCOM, self::PLATFORM_PRESSABLE, or null if undetermined.
	 */
	private function determine_platform( \stdClass $site ): ?string {
		if ( isset( $site->is_wpcom_atomic ) ) {
			return $site->is_wpcom_atomic ? self::PLATFORM_WPCOM : self::PLATFORM_PRESSABLE;
		}

		$host = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		if ( \str_ends_with( $host, '.wpcomstaging.com' ) ) {
			return self::PLATFORM_WPCOM;
		}
		if ( \str_ends_with( $host, '.mystagingwebsite.com' ) ) {
			return self::PLATFORM_PRESSABLE;
		}

		$full = get_wpcom_site( (string) $site->userblog_id );
		if ( isset( $full->is_wpcom_atomic ) ) {
			return $full->is_wpcom_atomic ? self::PLATFORM_WPCOM : self::PLATFORM_PRESSABLE;
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
	private function pressable_identifier( \stdClass $site ): string {
		$host = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		return '' !== $host ? $host : (string) $site->userblog_id;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the IDs of the sites the ledger records as settled, latest row per site winning.
	 *
	 * @return  array<string, true>
	 */
	private function completed_ids(): array {
		if ( ! \is_file( $this->ledger_path ) ) {
			return array();
		}

		$latest = array();
		foreach ( \file( $this->ledger_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$entry = \json_decode( $line, true );
			if ( \is_array( $entry ) && isset( $entry['id'], $entry['status'] ) ) {
				$latest[ (string) $entry['id'] ] = (string) $entry['status'];
			}
		}

		return \array_fill_keys( \array_keys( \array_filter( $latest, static fn( string $status ) => \in_array( $status, self::DONE_STATUSES, true ) ) ), true );
	}

	/**
	 * Appends a site's result to the ledger. No-op during a dry run.
	 *
	 * Append-only under an exclusive lock, so neither a partial read nor a second run can lose a
	 * row. A repeated site gains a second row, which is history rather than a duplicate.
	 *
	 * @param   \stdClass                                                $site   The processed site.
	 * @param   array{ status: string, type: string|null, note: string } $result The processing result.
	 *
	 * @throws  \RuntimeException If the entry cannot be encoded or appended (must fail loudly).
	 *
	 * @return  void
	 */
	private function append_ledger( \stdClass $site, array $result ): void {
		if ( $this->dry_run ) {
			return;
		}

		$encoded = \json_encode(
			array(
				'id'        => $site->userblog_id,
				'url'       => $site->siteurl ?? null,
				'type'      => $result['type'],
				'status'    => $result['status'],
				'note'      => $this->redact( $result['note'] ),
				'timestamp' => \gmdate( 'c' ),
			),
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( false === $encoded || false === \file_put_contents( $this->ledger_path, $encoded . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( "Failed to append to the ledger at $this->ledger_path." );
		}
	}

	/**
	 * Lists the connected sites whose Atlantis status endpoint did not answer.
	 *
	 * @param   \stdClass[]     $unanswered The sites that did not answer.
	 * @param   OutputInterface $output     The output object.
	 *
	 * @return  void
	 */
	private function report_unanswered( array $unanswered, OutputInterface $output ): void {
		if ( empty( $unanswered ) ) {
			return;
		}

		$output->writeln( '' );
		$output->writeln( '<comment>Left alone because their Atlantis status endpoint did not answer (no Atlantis, or the site could not be reached).</comment>' );
		$output->writeln( '<comment>Any of these that does run Atlantis still needs `team51 atlantis:mark-managed <site>`:</comment>' );
		foreach ( $unanswered as $site ) {
			$output->writeln( '  ' . ( $site->siteurl ?? $site->userblog_id ) );
		}
	}

	/**
	 * Removes the settings address from a string bound for the terminal or the ledger.
	 *
	 * @param   string $text The text to redact.
	 *
	 * @return  string
	 */
	private function redact( string $text ): string {
		return '' === $this->settings_url ? $text : \str_replace( $this->settings_url, '[settings address]', $text );
	}

	// endregion
}
