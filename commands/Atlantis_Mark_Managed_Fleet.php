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
 * - Site universe is the Jetpack-connected fleet, optionally narrowed by `--sites` the way
 *   `jetpack:plugin-force-update` narrows it, then to the sites whose Atlantis status endpoint
 *   answers. Sites that do not answer are left alone, and checked against their plugin list so that
 *   the ones that do have Atlantis (inactive, older than the status endpoint, or unreachable) are
 *   named rather than lost among the sites that simply do not run it.
 * - Each site is reached over SSH via the helper matching its platform, resolved the way
 *   `jetpack:plugin-force-update` resolves it. A site neither platform claims is reported as a
 *   failure rather than guessed at.
 * - Each site costs one SSH connection, and opening one rotates that site's SFTP/SSH password, on
 *   WordPress.com as well as on Pressable. A dry run opens the connection too.
 * - Every processed site is appended to a JSONL ledger that doubles as the skip-list on the next
 *   run, so an interrupted sweep resumes where it stopped. A site that failed is left out of later
 *   runs as well, so that `--limit` keeps moving through the fleet instead of spending itself on
 *   the same failures; `--retry-failed` goes back for those. The settings address is never written
 *   to the ledger, nor printed.
 *
 * Examples:
 *   team51 atlantis:mark-managed-fleet --dry-run
 *   team51 atlantis:mark-managed-fleet --sites=staging --limit=5
 *   team51 atlantis:mark-managed-fleet --sites=example.com,123456
 *   team51 atlantis:mark-managed-fleet --sites=production
 *   team51 atlantis:mark-managed-fleet --retry-failed
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
	 * The folder Atlantis is installed in, as it appears in a site's plugin list.
	 *
	 * @var string
	 */
	private const ATLANTIS_FOLDER = 'a8csp-atlantis';

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
	 * The raw `--sites` value, or null for the whole fleet.
	 *
	 * @var string|null
	 */
	private ?string $sites_spec = null;

	/**
	 * Whether to process only the sites the ledger records as failed.
	 *
	 * @var bool
	 */
	private bool $retry_failed = false;

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

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Which sites to act on: `production`, `staging` (both by whether the site URL contains "staging"), a comma-separated list of URLs/IDs, or a path to a CSV file (first column). Defaults to the whole Jetpack fleet.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many sites this run (applied after the ledger skip-list). Use it to canary.' )
			->addOption( 'retry-failed', null, InputOption::VALUE_NONE, 'Process only the sites that failed on an earlier run. Without it those are left out, unless `--sites` names them.' )
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
		$this->dry_run      = (bool) $input->getOption( 'dry-run' );
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

		$fleet = get_wpcom_jetpack_sites();
		if ( empty( $fleet ) ) {
			$output->writeln( '<error>Could not fetch the Jetpack-connected sites.</error>' );
			return Command::FAILURE;
		}

		[ $sites, $unmatched, $scope, $named ] = $this->scope_sites( $fleet );
		if ( ! empty( $unmatched ) ) {
			$output->writeln( '<comment>Unmatched --sites entries (not in the connected fleet): ' . \implode( ', ', $unmatched ) . '</comment>' );
		}
		if ( empty( $sites ) ) {
			$output->writeln( "<error>No connected site matches --sites=$this->sites_spec. Nothing was changed.</error>" );
			return Command::FAILURE;
		}

		$errors   = array();
		$statuses = get_wpcom_sites_atlantis_status_batch( \array_values( \array_column( $sites, 'userblog_id' ) ), $errors );
		if ( \is_null( $statuses ) ) {
			$output->writeln( '<error>Could not fetch the Atlantis status of the fleet.</error>' );
			return Command::FAILURE;
		}

		// Only a site whose Atlantis answered is a target. The rest are accounted for at the end, so
		// that a site whose connection happened to be down during the sweep is not mistaken for one
		// that was handled.
		$targets    = \array_filter( $sites, static fn( \stdClass $site ) => isset( $statuses[ $site->userblog_id ] ) );
		$unanswered = \array_filter( $sites, static fn( \stdClass $site ) => ! isset( $statuses[ $site->userblog_id ] ) );

		// Read on a dry run too: reading the ledger writes nothing, and a preview that ignored it
		// would reconnect to every site already settled, rotating each one's password again.
		$ledger  = $this->ledger_statuses();
		$settled = \array_filter( $targets, static fn( \stdClass $site ) => \in_array( $ledger[ (string) $site->userblog_id ] ?? null, self::DONE_STATUSES, true ) );
		$failed  = \array_filter( $targets, static fn( \stdClass $site ) => 'failed' === ( $ledger[ (string) $site->userblog_id ] ?? null ) );

		// A site that failed before stays out of an ordinary run, or a small --limit would be spent
		// on the same failures every time. Naming a site is asking for it, failed or not.
		$pending = match ( true ) {
			$this->retry_failed => $failed,
			$named              => \array_diff_key( $targets, $settled ),
			default             => \array_diff_key( $targets, $settled, $failed ),
		};
		if ( null !== $this->limit ) {
			$pending = \array_slice( $pending, 0, $this->limit, true );
		}

		$output->writeln( \sprintf( '<comment>%d connected site(s)%s: %d running Atlantis, %d not answering its status endpoint.</comment>', \count( $fleet ), null === $scope ? '' : ', ' . \count( $sites ) . " in scope ($scope)", \count( $targets ), \count( $unanswered ) ) );
		if ( ! empty( $settled ) ) {
			$output->writeln( '<comment>' . \count( $settled ) . ' already settled in the ledger and skipped.</comment>' );
		}
		if ( $this->retry_failed ) {
			$output->writeln( '<comment>Going back for the ' . \count( $failed ) . ' site(s) that failed on an earlier run.</comment>' );
		} elseif ( ! empty( $failed ) && ! $named ) {
			$output->writeln( '<comment>' . \count( $failed ) . ' failed on an earlier run and left out. Go back for them with --retry-failed.</comment>' );
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
	 * Narrows the fleet to what `--sites` asks for, the way `jetpack:plugin-force-update` does.
	 *
	 * `staging` and `production` go by whether the site URL contains "staging", which is also true
	 * of every `*.wpcomstaging.com` and `*.mystagingwebsite.com` address: a production site that
	 * has no domain of its own yet counts as staging.
	 *
	 * @param   \stdClass[] $fleet The connected Jetpack sites.
	 *
	 * @return  array A four-element list: the sites in scope, the `--sites` entries that matched no
	 *                site, a label for the scope (null for the whole fleet), and whether the sites
	 *                were named one by one.
	 */
	private function scope_sites( array $fleet ): array {
		$keyword = \strtolower( (string) $this->sites_spec );

		if ( null === $this->sites_spec || 'all' === $keyword ) {
			return array( $fleet, array(), null, false );
		}

		if ( 'staging' === $keyword || 'production' === $keyword ) {
			$want_staging = ( 'staging' === $keyword );
			$sites        = \array_filter(
				$fleet,
				static fn( \stdClass $site ) => ( false !== \stripos( (string) ( $site->siteurl ?? '' ), 'staging' ) ) === $want_staging
			);

			return array( $sites, array(), $keyword, false );
		}

		[ $sites, $unmatched ] = resolve_wpcom_sites_from_identifiers( parse_wpcom_site_identifiers( $this->sites_spec ), $fleet );

		return array( $sites, $unmatched, \is_file( $this->sites_spec ) ? 'sites from CSV' : 'requested list', true );
	}

	/**
	 * Returns the latest status the ledger records for each site, keyed by site ID.
	 *
	 * @return  array<string, string>
	 */
	private function ledger_statuses(): array {
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

		return $latest;
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
	 * Accounts for the sites whose Atlantis status endpoint did not answer.
	 *
	 * Most of them simply do not run Atlantis. Their plugin lists tell those apart from the ones
	 * that have it and still did not answer, which are the ones worth naming.
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

		$label = static fn( \stdClass $site ): string => (string) ( $site->siteurl ?? $site->userblog_id );

		$output->writeln( '' );

		$errors  = array();
		$plugins = get_wpcom_site_plugins_batch( \array_values( \array_column( $unanswered, 'userblog_id' ) ), $errors );
		if ( \is_null( $plugins ) ) {
			$output->writeln( '<comment>Left alone because their Atlantis status endpoint did not answer. Their plugin lists could not be fetched, so this includes every site without Atlantis.</comment>' );
			$output->writeln( '<comment>Any of these that does run Atlantis still needs `team51 atlantis:mark-managed <site>`:</comment>' );
			foreach ( $unanswered as $site ) {
				$output->writeln( '  ' . $label( $site ) );
			}
			return;
		}

		$installed = array();
		$unknown   = array();
		foreach ( $unanswered as $site ) {
			if ( ! isset( $plugins[ $site->userblog_id ] ) ) {
				$unknown[] = $label( $site );
				continue;
			}
			foreach ( $plugins[ $site->userblog_id ] as $plugin_file => $plugin_data ) {
				if ( self::ATLANTIS_FOLDER === \dirname( (string) $plugin_file ) ) {
					$installed[] = $label( $site ) . ' (Atlantis ' . ( $plugin_data->Version ?? 'version unknown' ) . ')'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				}
			}
		}

		$without = \count( $unanswered ) - \count( $installed ) - \count( $unknown );
		$output->writeln( \sprintf( '<comment>%d site(s) left alone because their Atlantis status endpoint did not answer: %d without Atlantis, %d with it, %d whose plugin list could not be read.</comment>', \count( $unanswered ), $without, \count( $installed ), \count( $unknown ) ) );

		if ( ! empty( $installed ) ) {
			$output->writeln( '<comment>Atlantis is installed on these but did not answer (inactive, older than 1.2.0, or unreachable). Each still needs `team51 atlantis:mark-managed <site>`:</comment>' );
			foreach ( $installed as $line ) {
				$output->writeln( "  $line" );
			}
		}
		if ( ! empty( $unknown ) ) {
			$output->writeln( '<comment>Could not be checked either way. Look at these by hand:</comment>' );
			foreach ( $unknown as $line ) {
				$output->writeln( "  $line" );
			}
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
