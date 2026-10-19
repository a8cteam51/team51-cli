<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * ONE-OFF / INCIDENT. Audits fleet sites for exposure to the UpdraftCentral signing-key theft
 * (SIRT: sirtp2.wordpress.com/2026/10/19/updraftcentral-keys-were-stolen).
 *
 * For each site it opens a single SSH connection and checks, effectively:
 *
 *   wp option get updraft_central_localkeys        # present = exposed
 *   grep -rlF "Small maintenance toolkit: keeps option storage tidy." wp-content/plugins wp-content/mu-plugins
 *
 * This command is READ-ONLY. It never deletes the option or touches any file, and it never prints
 * or records the key material itself - only whether the option exists. WP-CLI is run
 * with `--skip-plugins --skip-themes` so that a backdoored plugin is not executed by the audit.
 *
 * Each site is reached via the helper matching its platform, resolved the way
 * `atlantis:mark-managed-fleet` resolves it. Opening a connection rotates that site's SFTP/SSH
 * password, on WordPress.com as well as on Pressable.
 *
 * Defaults to the sites with UpdraftPlus installed, as listed in #team51-ops on 2026-10-19.
 *
 * Examples:
 *   team51 updraft:audit-central-keys
 *   team51 updraft:audit-central-keys --sites=hyperorg.com,188385051
 *   team51 updraft:audit-central-keys --sites=sites.csv --report=updraft.csv --yes
 */
#[AsCommand( name: 'updraft:audit-central-keys' )]
final class Updraft_Central_Keys_Audit extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Platform identifiers, as recorded in the report's `platform` column.
	 *
	 * @var string
	 */
	private const PLATFORM_WPCOM     = 'wpcom';
	private const PLATFORM_PRESSABLE = 'pressable';

	/**
	 * The sites UpdraftPlus was found on (active or inactive), by WPCOM blog ID.
	 *
	 * @var string[]
	 */
	private const DEFAULT_SITES = array(
		'161169196',
		'238270407',
		'160855425',
		'250006042',
		'256328432',
		'251012849',
		'69382155',
		'257171889',
		'248045671',
		'146813527',
		'71185092',
		'231309285',
		'188385051',
		'257007258',
		'256392571',
		'257359041',
		'220434556',
		'247619006',
		'201249366',
		'158717179',
		'251934886',
		'221718627',
		'248917831',
		'147132259',
		'196373276',
		'229536257',
		'238649074',
		'248235260',
		'209559030',
		'203673389',
		'129246078',
		'8032721',
	);

	/**
	 * The option UpdraftCentral stores its site keys in.
	 *
	 * @var string
	 */
	private const KEYS_OPTION = 'updraft_central_localkeys';

	/**
	 * A string found in the backdoor plugin dropped by the attackers.
	 *
	 * @var string
	 */
	private const BACKDOOR_SIGNATURE = 'Small maintenance toolkit: keeps option storage tidy.';

	/**
	 * The wp-content path. Both platforms use it.
	 *
	 * @var string
	 */
	private const CONTENT_DIR = '/srv/htdocs/wp-content';

	/**
	 * Default CSV report filename, written in the current working directory.
	 *
	 * @var string
	 */
	private const DEFAULT_REPORT = 'updraft-central-keys-audit.csv';

	/**
	 * Per-site SSH command timeout, in seconds. The grep can take a while on large plugin folders.
	 *
	 * @var int
	 */
	private const SSH_TIMEOUT = 90;

	/**
	 * The raw `--sites` value, or null for the default list.
	 *
	 * @var string|null
	 */
	private ?string $sites_spec = null;

	/**
	 * Whether to skip the confirmation prompt.
	 *
	 * @var bool
	 */
	private bool $yes = false;

	/**
	 * Path to the CSV report.
	 *
	 * @var string
	 */
	private string $report_path = self::DEFAULT_REPORT;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'ONE-OFF: audits sites for UpdraftCentral keys and the known backdoor plugin (read-only).' )
			->setHelp( 'Checks each site over SSH for the `' . self::KEYS_OPTION . '` option and greps plugins/mu-plugins for the backdoor signature. Never modifies anything and never prints the keys. Writes every result to a CSV report.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'A comma-separated list of URLs/IDs, or a path to a CSV file (first column). Defaults to the 32 sites with UpdraftPlus installed.' )
			->addOption( 'report', null, InputOption::VALUE_REQUIRED, 'Path to the CSV report. Defaults to ./' . self::DEFAULT_REPORT . '.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->yes = (bool) $input->getOption( 'yes' );

		$sites_spec       = $input->getOption( 'sites' );
		$this->sites_spec = ( \is_string( $sites_spec ) && '' !== \trim( $sites_spec ) ) ? \trim( $sites_spec ) : null;

		$report            = $input->getOption( 'report' );
		$this->report_path = ( \is_string( $report ) && '' !== $report ) ? $report : self::DEFAULT_REPORT;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$fleet = get_wpcom_jetpack_sites();
		if ( empty( $fleet ) ) {
			$output->writeln( '<error>Could not fetch the Jetpack-connected sites.</error>' );
			return Command::FAILURE;
		}

		$identifiers           = null === $this->sites_spec ? self::DEFAULT_SITES : parse_wpcom_site_identifiers( $this->sites_spec );
		[ $sites, $unmatched ] = resolve_wpcom_sites_from_identifiers( $identifiers, $fleet );
		if ( ! empty( $unmatched ) ) {
			$output->writeln( '<comment>Not in the connected fleet, skipped (audit by hand): ' . \implode( ', ', $unmatched ) . '</comment>' );
		}
		if ( empty( $sites ) ) {
			$output->writeln( '<error>No connected site matches. Nothing to audit.</error>' );
			return Command::FAILURE;
		}

		if ( ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>Audit ' . \count( $sites ) . ' site(s)? Read-only, but each is one SSH connection, and opening it rotates that site\'s SFTP/SSH password. [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted.</comment>' );
				return Command::SUCCESS;
			}
		}

		$rows     = array();
		$tally    = array();
		$position = 0;
		foreach ( $sites as $site ) {
			++$position;

			try {
				$result = $this->audit_site( $site );
			} catch ( \Throwable $e ) {
				$result = $this->result( 'error', null, 'Error: ' . $e->getMessage() );
			}

			$rows[]                     = array( $site, $result );
			$tally[ $result['status'] ] = ( $tally[ $result['status'] ] ?? 0 ) + 1;

			$style = match ( $result['status'] ) {
				'clean'   => 'info',
				'exposed' => 'comment',
				default   => 'error',
			};
			$output->writeln( \sprintf( '[%d/%d] %s <%s>%s: %s</%s>', $position, \count( $sites ), $site->siteurl ?? $site->userblog_id, $style, $result['status'], $result['note'], $style ) );
			foreach ( $result['hits'] as $hit ) {
				$output->writeln( "        <error>backdoor: $hit</error>" );
			}
		}

		$this->write_report( $rows, $output );

		\ksort( $tally );
		$output->writeln( '' );
		$output->writeln( '<info>' . \implode( ' | ', \array_map( static fn( string $status, int $count ) => "$status: $count", \array_keys( $tally ), $tally ) ) . '</info>' );
		$output->writeln( "Report: $this->report_path" );

		return empty( $tally['compromised'] ) && empty( $tally['error'] ) && empty( $tally['unreachable'] ) ? Command::SUCCESS : Command::FAILURE;
	}

	// endregion

	// region PROCESSING

	/**
	 * Audits one site over a single SSH connection.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  array{ status: string, platform: string|null, keys: string, hits: string[], note: string }
	 */
	private function audit_site( \stdClass $site ): array {
		$platform = $this->determine_platform( $site );
		if ( null === $platform ) {
			return $this->result( 'error', null, 'Not on WordPress.com (Atomic) or Pressable, so likely hosted elsewhere. Audit it by hand.' );
		}

		$ssh = match ( $platform ) {
			self::PLATFORM_WPCOM     => \WPCOM_Connection_Helper::get_ssh_connection( (string) $site->userblog_id ),
			self::PLATFORM_PRESSABLE => \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_identifier( $site ) ),
		};
		if ( null === $ssh ) {
			return $this->result( 'unreachable', $platform, 'SSH connection failed (site unreachable, no connection, or authentication failure).' );
		}

		try {
			$ssh->setTimeout( self::SSH_TIMEOUT );
			$raw = (string) $ssh->exec( $this->build_command() );
		} finally {
			$ssh->disconnect();
		}

		return $this->parse_output( $raw, $platform );
	}

	/**
	 * Builds the shell script run on each site. It emits `KEYS=` (how many rows hold the option, so
	 * 0 or 1) or `WPERR=` with the tail of WP-CLI's output when it fails, then `GREP=` and one `HIT=`
	 * line per matching file. Plugins and themes are skipped so a backdoor is never executed by it,
	 * and only the option's presence is queried, so the keys never travel back over the connection.
	 *
	 * @return  string
	 */
	private function build_command(): string {
		return \sprintf(
			'out=$(wp --skip-plugins --skip-themes option list --search=%1$s --format=count 2>&1); rc=$?; n=$(printf "%%s\n" "$out" | tail -n 1);'
			. ' if [ "$rc" -eq 0 ] && [ -n "$n" ] && [ -z "${n##[0-9]*}" ]; then echo "KEYS=$n"; else echo "WPERR=exit $rc: $(printf "%%s" "$out" | tail -n 3 | tr "\n" " ")"; fi;'
			. ' d=%2$s; if [ -d "$d/plugins" ]; then grep -rlF -- %3$s "$d/plugins" "$d/mu-plugins" 2>/dev/null | sed "s/^/HIT=/"; echo "GREP=ok"; else echo "GREP=nodir"; fi',
			\escapeshellarg( self::KEYS_OPTION ),
			\escapeshellarg( self::CONTENT_DIR ),
			\escapeshellarg( self::BACKDOOR_SIGNATURE )
		);
	}

	/**
	 * Turns the script output into a result.
	 *
	 * @param   string $raw      The raw SSH output.
	 * @param   string $platform The site's platform.
	 *
	 * @return  array{ status: string, platform: string|null, keys: string, hits: string[], note: string }
	 */
	private function parse_output( string $raw, string $platform ): array {
		$keys  = null;
		$wperr = null;
		$grep  = null;
		$hits  = array();
		foreach ( \preg_split( '/\R/', $raw ) as $line ) {
			match ( true ) {
				\str_starts_with( $line, 'KEYS=' )  => $keys   = \substr( $line, 5 ),
				\str_starts_with( $line, 'WPERR=' ) => $wperr  = \trim( \substr( $line, 6 ) ),
				\str_starts_with( $line, 'GREP=' )  => $grep   = \substr( $line, 5 ),
				\str_starts_with( $line, 'HIT=' )   => $hits[] = \substr( $line, 4 ),
				default                             => null,
			};
		}

		$exposed = null !== $keys && '0' !== $keys;
		$notes   = array();
		$notes[] = match ( true ) {
			null === $keys => 'Could not read the keys option (WP-CLI: ' . ( $wperr ?? 'no output' ) . ')',
			$exposed       => 'UpdraftCentral keys option PRESENT',
			default        => 'no UpdraftCentral keys',
		};
		$notes[] = match ( true ) {
			! empty( $hits ) => \count( $hits ) . ' backdoor file(s) found',
			'ok' === $grep   => 'no backdoor found',
			default          => 'plugin folder not found, grep skipped',
		};

		$status = match ( true ) {
			! empty( $hits )                  => 'compromised',
			null === $keys || 'ok' !== $grep  => 'error',
			$exposed                          => 'exposed',
			default                           => 'clean',
		};

		$result         = $this->result( $status, $platform, \implode( '; ', $notes ) );
		$result['keys'] = null === $keys ? '' : ( $exposed ? 'present' : 'absent' );
		$result['hits'] = $hits;

		return $result;
	}

	/**
	 * Builds a result with no key or backdoor findings.
	 *
	 * @param   string      $status   The status.
	 * @param   string|null $platform The site's platform, if known.
	 * @param   string      $note     A human-readable note.
	 *
	 * @return  array{ status: string, platform: string|null, keys: string, hits: string[], note: string }
	 */
	private function result( string $status, ?string $platform, string $note ): array {
		return array(
			'status'   => $status,
			'platform' => $platform,
			'keys'     => '',
			'hits'     => array(),
			'note'     => $note,
		);
	}

	/**
	 * Works out which platform hosts a site, or null when it cannot be established. Never guesses.
	 *
	 * Like `atlantis:mark-managed-fleet`, except that a site off Atomic must also be found on Pressable
	 * before it is treated as one.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string|null self::PLATFORM_WPCOM, self::PLATFORM_PRESSABLE, or null if undetermined.
	 */
	private function determine_platform( \stdClass $site ): ?string {
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

		// Not being on Atomic does not make a site a Pressable one: Jetpack sites hosted elsewhere
		// are in the connected fleet too, and neither helper can reach them.
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
	 * Writes every result to the CSV report.
	 *
	 * @param   array           $rows   Pairs of site record and result.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function write_report( array $rows, OutputInterface $output ): void {
		$handle = \fopen( $this->report_path, 'w' );
		if ( false === $handle ) {
			$output->writeln( "<error>Could not open report file for writing: $this->report_path</error>" );
			return;
		}

		\fputcsv( $handle, array( 'id', 'url', 'platform', 'status', 'keys_option', 'backdoor_files', 'note' ), ',', '"', '' );
		foreach ( $rows as [ $site, $result ] ) {
			\fputcsv(
				$handle,
				array( $site->userblog_id, $site->siteurl ?? '', $result['platform'] ?? '', $result['status'], $result['keys'], \implode( ' ', $result['hits'] ), $result['note'] ),
				',',
				'"',
				''
			);
		}

		\fclose( $handle );
	}

	// endregion
}
