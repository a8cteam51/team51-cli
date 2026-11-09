<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;
use WPCOMSpecialProjects\CLI\Helper\ExportTrait;

/**
 * Audits the Jetpack connections of the fleet and reports, for each site, why we cannot work with it.
 *
 * Read-only: nothing is changed on any site or connection.
 */
#[AsCommand( name: 'jetpack:audit-connections' )]
final class Jetpack_Connection_Audit extends Command {
	use AutocompleteTrait;
	use ExportTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Columns of the audit report.
	 *
	 * @var string[]
	 */
	private const COLUMNS = array( 'Site ID', 'Site URL', 'Pressable Site ID', 'Issues', 'Failure Reason', 'Suggested Action', 'Registered', 'We Are Connected', 'We Are Primary', 'Connection Owner', 'JSON API Module', 'Raw Error' );

	/**
	 * How many sites to send to OpsOasis per batch request. Larger batches risk running out of memory.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * How many sites to send per batch when retrying, so a host that rate-limited us is asked more gently.
	 *
	 * @var int
	 */
	private const RETRY_CHUNK_SIZE = 10;

	/**
	 * How many times to retry sites whose failure looks temporary.
	 *
	 * @var int
	 */
	private const RETRY_ATTEMPTS = 2;

	/**
	 * How long to wait, in seconds, before each retry pass and between its batches.
	 *
	 * @var int
	 */
	private const RETRY_DELAY = 10;

	/**
	 * The failure reasons worth retrying, because they say nothing about the site's connection itself.
	 *
	 * @var string[]
	 */
	private const RETRYABLE_REASONS = array( 'rate_limited', 'timeout', 'gateway_error', 'batch_failed' );

	/**
	 * What each failure reason means and what to do about it.
	 *
	 * @var array<string,string>
	 */
	private const ACTIONS = array(
		'site_deleted'      => 'The host no longer knows the domain, so the site is gone. Remove the Jetpack connection.',
		'site_disabled'     => 'The site is disabled on its host. Confirm it is retired, then remove the Jetpack connection.',
		'html_response'     => 'The site answered with a web page instead of the API: the domain may have moved off WordPress, or a cache or redirect intercepts the request. Check where the domain points.',
		'site_fatal'        => 'The site has a PHP fatal error. Fix the site, then re-run the audit.',
		'invalid_signature' => 'The Jetpack connection is broken (a clone sharing another site\'s identity, a changed URL, or a stale token). Reconnect Jetpack.',
		'unknown_token'     => 'The site does not recognise our connection token. Reconnect Jetpack, or remove the connection if the site is gone.',
		'no_route'          => 'The Jetpack REST route is missing. Check that Jetpack is installed and active.',
		'not_permitted'     => 'Our user is not allowed to read the connection. Check that our connected user is an administrator on the site.',
		'blocked'           => 'The server refused the request (an auth wall or a firewall). Allowlist WordPress.com, or skip the site.',
		'json_api_disabled' => 'The Jetpack JSON API module is off, so WordPress.com cannot reach the site. Turn the module on.',
		'server_error'      => 'The site returned a server error with nothing to read. Check the site.',
		'gateway_error'     => 'A proxy or gateway in front of the site failed (502/503/504) even after retries. Check that the site is up.',
		'empty_response'    => 'The site answered with nothing to read. Check the site.',
		'rate_limited'      => 'The site rate-limited us even after retries. Re-run the audit later.',
		'timeout'           => 'The site timed out even after retries. Check that it is up.',
		'connection_failed' => 'WordPress.com could not connect to the site (DNS or TLS). Check its hosting and certificate.',
		'invalid_url'       => 'WordPress.com holds an invalid URL for this site. Check the site record.',
		'batch_failed'      => 'The OpsOasis batch request failed. Re-run the audit.',
		'not_connected'     => 'This Pressable site has no Jetpack connection to our account. Connect Jetpack with our WordPress.com account.',
		'unknown'           => 'Unrecognised failure. See the raw error.',
	);

	/**
	 * The Jetpack sites to audit, keyed by WPCOM site ID.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The Pressable sites with no Jetpack connection to our account, or null when that check is skipped.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $unconnected_pressable_sites = null;

	/**
	 * The Pressable site ID of each audited Jetpack site that is hosted on Pressable, keyed by WPCOM site ID.
	 *
	 * @var array<int|string,int|string>
	 */
	private array $pressable_ids = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Audits the Jetpack connections of the fleet and reports why sites cannot be reached.' )
			->setHelp(
				<<<'EOT'
Reports, for every site connected to our WordPress.com account through Jetpack:
  - whether the site can be reached through its Jetpack connection, and if not, why;
  - whether our connected user is the connection's primary user, and who is otherwise;
  - whether the Jetpack JSON API module is active.

It also lists the Pressable sites that have no Jetpack connection to our account at all.

Nothing is changed. Temporary failures (rate limits, timeouts) are retried before they are reported.

<info>Examples:</info>
  Audit the whole fleet and save the report:
    $ team51 jetpack:audit-connections --export=~/Downloads/jetpack-audit.csv

  Audit a few sites only (skips the Pressable check):
    $ team51 jetpack:audit-connections --sites=example.com,123456789
EOT
			);

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'A comma-separated list of site URLs and/or numeric WPCOM IDs to audit. Defaults to every connected Jetpack site, plus the Pressable check.' );

		$this->configure_export_options( self::COLUMNS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws  \RuntimeException When the Jetpack sites cannot be fetched.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->initialize_export( $input, $output, 'jetpack-connection-audit', self::COLUMNS );

		$jetpack_sites = get_wpcom_jetpack_sites();
		if ( \is_null( $jetpack_sites ) ) {
			throw new \RuntimeException( 'Could not fetch the Jetpack sites connected to WPCOM.' );
		}
		$output->writeln( '<comment>Successfully fetched ' . \count( $jetpack_sites ) . ' Jetpack site(s).</comment>' );

		$filter = maybe_get_string_input( $input, 'sites' );
		if ( ! empty( $filter ) ) {
			$this->sites = $this->filter_sites( $jetpack_sites, \array_filter( \array_map( 'trim', \explode( ',', $filter ) ) ), $unmatched );
			if ( ! empty( $unmatched ) ) {
				$output->writeln( '<error>Not connected to WPCOM through Jetpack, so not audited: ' . \implode( ', ', $unmatched ) . '</error>' );
			}
			$output->writeln( '<comment>Auditing ' . \count( $this->sites ) . ' of them, as asked. The Pressable check is skipped.</comment>' );
			return;
		}

		$this->sites = $jetpack_sites;

		$pressable_sites = get_pressable_sites();
		if ( \is_null( $pressable_sites ) ) {
			$output->writeln( '<error>Could not fetch the Pressable sites. The Pressable check is skipped.</error>' );
			return;
		}
		$output->writeln( '<comment>Successfully fetched ' . \count( $pressable_sites ) . ' Pressable site(s).</comment>' );

		$this->match_pressable_sites( $pressable_sites );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws  \RuntimeException When every OpsOasis batch request fails.
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Auditing the Jetpack connection of ' . \count( $this->sites ) . ' site(s). This may take a few minutes...</>' );

		$site_ids = \array_keys( $this->sites );
		$results  = array();
		foreach ( \array_chunk( $site_ids, self::CHUNK_SIZE ) as $chunk ) {
			$results = $this->fetch( $chunk ) + $results;
		}

		// Every batch failing means OpsOasis itself could not answer, which retrying site by site will not fix.
		if ( ! empty( $results ) && \count( $results ) === \count( \array_filter( $results, static fn( array $result ) => 'batch_failed' === $result['reason'] ) ) ) {
			throw new \RuntimeException( 'Every OpsOasis batch request failed. Check that OpsOasis is up and serves the `sites/batch/jetpack-connection` route.' );
		}

		for ( $attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++ ) {
			$retry = \array_keys( \array_filter( $results, static fn( array $result ) => \in_array( $result['reason'], self::RETRYABLE_REASONS, true ) ) );
			if ( empty( $retry ) ) {
				break;
			}

			$output->writeln( '<comment>Retrying ' . \count( $retry ) . " site(s) whose failure looks temporary (attempt $attempt of " . self::RETRY_ATTEMPTS . ')...</comment>' );
			foreach ( \array_chunk( $retry, self::RETRY_CHUNK_SIZE ) as $chunk ) {
				// Only a site's own host needs a pause; a failed OpsOasis batch can be asked again straight away.
				if ( ! empty( \array_filter( $chunk, static fn( $site_id ) => 'batch_failed' !== $results[ $site_id ]['reason'] ) ) ) {
					\sleep( self::RETRY_DELAY );
				}
				$results = $this->fetch( $chunk ) + $results;
			}
		}

		$rows = array();
		foreach ( $site_ids as $site_id ) {
			$rows[] = $this->build_row( $this->sites[ $site_id ], $results[ $site_id ] );
		}
		foreach ( $this->unconnected_pressable_sites ?? array() as $pressable_site ) {
			$rows[] = array( '', $pressable_site->url, $pressable_site->id, 'not_connected', 'not_connected', self::ACTIONS['not_connected'], '', 'no', '', '', '', '' );
		}

		output_table( $output, $rows, self::COLUMNS, 'Jetpack connection audit' );

		$issues    = array_count_values( \array_merge( ...\array_map( static fn( array $row ) => \explode( ', ', $row[3] ), $rows ) ) );
		$no_issues = $issues['ok'] ?? 0;
		unset( $issues['ok'] );
		\ksort( $issues );

		$summary_output = array(
			'REPORT SUMMARY'                 => '',
			'Jetpack sites audited'          => \count( $site_ids ),
			'Pressable sites not in Jetpack' => \is_null( $this->unconnected_pressable_sites ) ? 'Not checked' : \count( $this->unconnected_pressable_sites ),
			'Sites with no issues'           => $no_issues,
		);
		foreach ( $issues as $issue => $count ) {
			$summary_output[ "Sites with issue `$issue`" ] = $count;
		}

		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>$key: $value</info>" );
		}

		$this->maybe_export( $output, self::COLUMNS, $rows, $summary_output );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Keeps the Jetpack sites the user asked for, matched by WPCOM ID or by host.
	 *
	 * @param   \stdClass[] $jetpack_sites The connected Jetpack sites, keyed by WPCOM site ID.
	 * @param   string[]    $identifiers   The site URLs and/or WPCOM IDs to keep.
	 * @param   array|null  $unmatched     The identifiers that matched no connected site.
	 *
	 * @throws  \InvalidArgumentException When none of the given sites are connected.
	 *
	 * @return  \stdClass[]
	 */
	private function filter_sites( array $jetpack_sites, array $identifiers, ?array &$unmatched = null ): array {
		$wanted = \array_combine( $identifiers, \array_map( fn( string $identifier ) => \is_numeric( $identifier ) ? $identifier : $this->normalize_host( $identifier ), $identifiers ) );

		$sites = \array_filter(
			$jetpack_sites,
			fn( \stdClass $site ) => \in_array( (string) $site->userblog_id, $wanted, true ) || \in_array( $this->normalize_host( $site->siteurl ), $wanted, true )
		);

		$found     = \array_merge( \array_map( 'strval', \array_column( $sites, 'userblog_id' ) ), \array_map( fn( \stdClass $site ) => $this->normalize_host( $site->siteurl ), $sites ) );
		$unmatched = \array_map( 'strval', \array_keys( \array_diff( $wanted, $found ) ) ); // Numeric keys come back as integers.
		if ( empty( $sites ) ) {
			throw new \InvalidArgumentException( 'None of the given sites are connected to WPCOM through Jetpack.' );
		}

		return $sites;
	}

	/**
	 * Matches the Pressable sites against the Jetpack sites by host.
	 *
	 * A Pressable site counts as connected when either its primary URL or its default
	 * `mystagingwebsite.com` domain is the host of a connected Jetpack site.
	 *
	 * @param   \stdClass[] $pressable_sites The Pressable sites.
	 *
	 * @return  void
	 */
	private function match_pressable_sites( array $pressable_sites ): void {
		$jetpack_hosts = array();
		foreach ( $this->sites as $site_id => $site ) {
			$jetpack_hosts[ $this->normalize_host( $site->siteurl ) ][] = $site_id;
		}

		$this->unconnected_pressable_sites = array();
		foreach ( $pressable_sites as $pressable_site ) {
			$hosts    = \array_unique( \array_filter( array( $this->normalize_host( $pressable_site->url ?? '' ), empty( $pressable_site->name ) ? '' : \strtolower( "$pressable_site->name.mystagingwebsite.com" ) ) ) );
			$site_ids = \array_merge( ...\array_map( static fn( string $host ) => $jetpack_hosts[ $host ] ?? array(), \array_values( $hosts ) ) );
			if ( empty( $site_ids ) ) {
				$this->unconnected_pressable_sites[] = $pressable_site;
				continue;
			}

			foreach ( $site_ids as $site_id ) {
				$this->pressable_ids[ $site_id ] = $pressable_site->id;
			}
		}
	}

	/**
	 * Returns the lowercase host of a URL, without a leading `www.`.
	 *
	 * @param   string $url The URL, with or without a scheme.
	 *
	 * @return  string
	 */
	private function normalize_host( string $url ): string {
		$host = \parse_url( \str_contains( $url, '://' ) ? $url : "https://$url", PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host = \strtolower( \is_string( $host ) ? $host : '' );

		return \str_starts_with( $host, 'www.' ) ? \substr( $host, 4 ) : $host;
	}

	/**
	 * Reads the connection data and the module list of a batch of sites.
	 *
	 * @param   array $site_ids The WPCOM site IDs.
	 *
	 * @return  array<int|string,array{connection: ?\stdClass, modules: ?array, reason: ?string, error: ?string}>
	 */
	private function fetch( array $site_ids ): array {
		$connections = get_wpcom_sites_jetpack_connection_batch( $site_ids, $connection_errors );
		$modules     = get_jetpack_site_modules_batch( $site_ids, $module_errors );

		$results = array();
		foreach ( $site_ids as $site_id ) {
			$connection = $connections[ $site_id ] ?? null;
			$error      = \is_null( $connections ) ? null : ( $connection_errors[ $site_id ] ?? null );

			$results[ $site_id ] = array(
				'connection' => $connection,
				'modules'    => $modules[ $site_id ] ?? null,
				'reason'     => match ( true ) {
					\is_null( $connections ) => 'batch_failed',
					! \is_null( $error ) => $this->classify_error( $error ),
					\is_null( $connection ) => 'empty_response',
					default => null,
				},
				'error'      => \is_null( $error ) ? null : encode_json_content( $error->errors ?? $error ),
			);

			// Only worth reporting when the connection itself answered, since otherwise the connection error says it all.
			if ( \is_null( $results[ $site_id ]['reason'] ) && \is_null( $modules ) ) {
				$results[ $site_id ]['error'] = 'Modules: the batch request failed.';
			} elseif ( \is_null( $results[ $site_id ]['reason'] ) && isset( $module_errors[ $site_id ] ) ) {
				$results[ $site_id ]['error'] = 'Modules: ' . encode_json_content( $module_errors[ $site_id ]->errors ?? $module_errors[ $site_id ] );
			}
		}

		return $results;
	}

	/**
	 * Names the reason a site could not be reached, from the error OpsOasis relayed for it.
	 *
	 * The error is keyed by the HTTP status WordPress.com answered with (or a WP_Error code), and its
	 * message usually carries the site's own JSON error after the status text, e.g.
	 * `Bad Request: {"error":"rest_invalid_signature",...}`.
	 *
	 * @param   \stdClass $error The serialized WP_Error.
	 *
	 * @return  string
	 */
	private function classify_error( \stdClass $error ): string {
		$errors  = (array) ( $error->errors ?? array() );
		$code    = (string) ( \array_key_first( $errors ) ?? '' );
		$message = (string) ( ( (array) ( $errors[ $code ] ?? array() ) )[0] ?? '' );

		$status = \is_numeric( $code ) ? (int) $code : 0;
		$inner  = \str_contains( $message, ': {' ) ? \json_decode( \substr( $message, \strpos( $message, ': {' ) + 2 ) ) : null; // Not decode_json_content(), which reports every body it cannot parse.
		$inner  = \is_object( $inner ) ? $inner : null;

		$site_error = (string) ( $inner->error ?? $inner->code ?? $code );
		$site_text  = (string) ( $inner->message ?? $message );
		$raw_body   = (string) ( $inner->data->raw_body ?? '' );

		return match ( true ) {
			\str_contains( $raw_body, 'Domain not found' ) => 'site_deleted',
			410 === $status || \str_contains( $raw_body, 'This site is disabled' ) => 'site_disabled',
			\str_contains( $raw_body, 'critical error' ) => 'site_fatal',
			'rest_invalid_signature' === $site_error => 'invalid_signature',
			'unknown_token' === $site_error => 'unknown_token',
			'rest_no_route' === $site_error => 'no_route',
			\str_contains( $site_text, 'API calls to this blog have been disabled' ) => 'json_api_disabled',
			\in_array( $status, array( 401, 403 ), true ) && \str_starts_with( $site_error, 'rest_' ) => 'not_permitted',
			\in_array( $status, array( 401, 403 ), true ) => 'blocked',
			429 === $status => 'rate_limited',
			\in_array( $status, array( 502, 503, 504 ), true ) => 'gateway_error',
			'' !== $raw_body => 'html_response',
			'http_request_failed' === $site_error && \str_contains( $site_text, 'valid URL' ) => 'invalid_url',
			'http_request_failed' === $site_error && \str_contains( $site_text, 'cURL error 28' ) => 'timeout',
			'http_request_failed' === $site_error => 'connection_failed',
			\in_array( $code, array( 'invalid_response', 'empty_response' ), true ) => 'empty_response',
			$status >= 500 => 'server_error',
			default => 'unknown',
		};
	}

	/**
	 * Builds the report row of one Jetpack site.
	 *
	 * @param   \stdClass $site   The Jetpack site.
	 * @param   array     $result The site's result, as returned by `fetch()`.
	 *
	 * @return  array
	 */
	private function build_row( \stdClass $site, array $result ): array {
		$connection = $result['connection'];
		$modules    = $result['modules'];
		$json_api   = $modules['json-api'] ?? null;
		$yes_no     = static fn( mixed $value ) => \is_null( $value ) ? '' : ( $value ? 'yes' : 'no' );

		$is_master = $connection?->currentUser?->isMaster ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$json_on   = \is_object( $json_api ) ? (bool) $json_api->activated : null;

		$issues = \array_filter(
			array(
				$result['reason'] ? 'unreachable' : null,
				false === $is_master ? 'not_primary' : null,
				false === $json_on ? 'json_api_off' : null,
				\is_null( $result['reason'] ) && \is_null( $modules ) ? 'modules_unknown' : null,
			)
		);

		return array(
			$site->userblog_id,
			$site->siteurl,
			$this->pressable_ids[ $site->userblog_id ] ?? '',
			empty( $issues ) ? 'ok' : \implode( ', ', $issues ),
			$result['reason'] ?? '',
			$result['reason'] ? self::ACTIONS[ $result['reason'] ] : ( false === $is_master ? 'Make our connected user the primary user.' : ( false === $json_on ? 'Turn the Jetpack JSON API module on.' : '' ) ),
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$yes_no( $connection?->isRegistered ?? null ),
			$yes_no( $connection?->currentUser?->isConnected ?? null ),
			$yes_no( $is_master ),
			(string) ( $connection?->connectionOwner ?? '' ),
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			\is_null( $json_on ) ? '' : ( $json_on ? 'on' : 'off' ),
			$result['error'] ?? '',
		);
	}

	// endregion
}
