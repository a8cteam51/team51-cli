<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Command\WPCOM_Site_WP_CLI_Command_Run;

// region API

/**
 * Returns the list of Jetpack sites connected to WPCOM.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_jetpack_sites(): ?array {
	return get_wpcom_sites( array( 'type' => 'jetpack' ) );
}

/**
 * Returns the list of Agency sites connected to WPCOM.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_agency_sites(): ?array {
	return get_wpcom_sites( array( 'type' => 'agency' ) );
}

/**
 * Returns the list of sites connected to WPCOM, of any type.
 *
 * @param   array $params Optional. Additional parameters to pass to the request.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/me/sites/
 *
 * @return  stdClass[]|null
 */
function get_wpcom_sites( array $params = array() ): ?array {
	$endpoint = 'sites'; // Equivalent to 'sites?type=all'.
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	$response = API_Helper::make_wpcom_request( $endpoint );
	if ( is_null( $response ) ) {
		return null;
	}

	// Convert the response records to an associative array indexed by the site ID.
	return array_combine(
		array_column(
			$response->records,
			match ( $params['type'] ?? 'all' ) {
				'jetpack' => 'userblog_id', 'agency' => 'id', default => 'ID',
			}
		),
		$response->records
	);
}

/**
 * Returns a WPCOM site object by site URL or WordPress.com site ID.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass|null
 */
function get_wpcom_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url" );
}

/**
 * Returns an agency site object by its ID. These site IDs are not the same as WordPress.com site IDs and are unique to the agency but not globally unique.
 * For provisioned sites, the WPCOM site ID can be found in the `features.wpcom_atomic.blog_id` property of the agency site object.
 *
 * @param   integer $agency_site_id The ID of the agency site to get.
 *
 * @return  stdClass|null
 */
function get_wpcom_agency_site( int $agency_site_id ): ?stdClass {
	return get_wpcom_agency_sites()[ $agency_site_id ] ?? null;
}

/**
 * Returns a WPCOM site transfer status object by site URL or WordPress.com site ID.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass|null
 */
function get_wpcom_site_transfer_status( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/transfer-status" );
}

/**
 * Returns a batch of sites by their domains or numeric WPCOM IDs.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$sites = API_Helper::make_wpcom_request( 'sites/batch', 'POST', array( 'sites' => $site_ids_or_urls ) );
	return is_null( $sites ) ? null : parse_batch_response( $sites, $errors );
}

/**
 * Returns the list of sites and their plugins from Jetpack site profiles data.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_jetpack_sites_plugins(): ?array {
	$plugins = API_Helper::make_wpcom_request( 'sites/plugins' );
	return is_null( $plugins ) ? null : (array) $plugins;
}

/**
 * Returns the list of plugins installed on a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_plugins( string $site_id_or_domain ): ?array {
	$plugins = API_Helper::make_wpcom_request( "sites/$site_id_or_domain/plugins" );
	return is_null( $plugins ) ? null : (array) $plugins;
}

/**
 * Returns a batch of plugins installed on given WPCOM sites.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[][]|null
 */
function get_wpcom_site_plugins_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$sites_plugins = API_Helper::make_wpcom_request( 'sites/batch/plugins', 'POST', array( 'sites' => $site_ids_or_urls ) );
	if ( is_null( $sites_plugins ) ) {
		return null;
	}

	$sites_plugins = parse_batch_response( $sites_plugins, $errors );
	return array_map( static fn( stdClass $site_plugins ) => (array) $site_plugins, $sites_plugins );
}

/**
 * Returns the Atlantis plugin and module status for a batch of WPCOM sites.
 *
 * Sites without Atlantis installed (or with the endpoint unreachable) are
 * surfaced via $errors; the returned map only contains successful responses.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  array<int|string,stdClass>|null
 */
function get_wpcom_sites_atlantis_status_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$sites_status = API_Helper::make_wpcom_request( 'sites/batch/atlantis-status', 'POST', array( 'sites' => $site_ids_or_urls ) );
	if ( is_null( $sites_status ) ) {
		return null;
	}

	return parse_batch_response( $sites_status, $errors );
}

/**
 * Forces an update of a single plugin across a batch of WPCOM/Jetpack sites.
 *
 * Proxies the OpsOasis batch endpoint, which calls the WPCOM v1.2 plugin update endpoint on each
 * site. The update self-refreshes the site's update cache and installs from the plugin's own update
 * source (wp.org, or a custom `Update URI` such as GitHub), so it is not subject to the ~12h
 * dashboard cache. It only upgrades sites where a newer version is available; sites already current
 * report "No update needed" and are returned as successes with an unchanged version.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   string     $plugin           The plugin identifier in `folder/file` form without the
 *                                        `.php` suffix (e.g. `a8csp-atlantis/a8csp-atlantis`).
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null  Per-site update result keyed by site ID (with `version` and `log`).
 */
function update_wpcom_site_plugins_batch( array $site_ids_or_urls, string $plugin, ?array &$errors = null ): ?array {
	$results = API_Helper::make_wpcom_request(
		'sites/batch/plugin-update',
		'POST',
		array(
			'sites'  => $site_ids_or_urls,
			'plugin' => $plugin,
		)
	);
	if ( is_null( $results ) ) {
		return null;
	}

	return parse_batch_response( $results, $errors );
}

/**
 * Forces a fresh plugin update-check on a batch of WPCOM/Jetpack sites.
 *
 * Proxies the OpsOasis batch endpoint, which calls the Atlantis `force-update-check` route on each site
 * over the authenticated Jetpack REST tunnel. Each site clears its `update_plugins` transient (and
 * WooCommerce.com's helper cache) and re-runs its update check, so a just-published wp.org /
 * WooCommerce.com release becomes visible to the version-checked update path without waiting out
 * WordPress core's ~12h throttle. Sites without Atlantis (or without a live connection) are returned
 * via $errors.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null  Per-site result keyed by site ID, or null if the request failed entirely.
 */
function force_check_wpcom_site_plugins_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$results = API_Helper::make_wpcom_request(
		'sites/batch/atlantis-force-check',
		'POST',
		array( 'sites' => $site_ids_or_urls )
	);
	if ( is_null( $results ) ) {
		return null;
	}

	return parse_batch_response( $results, $errors );
}

/**
 * Number of sites per batch when refreshing update-checks (each is a real per-site tunnelled call).
 * Matches the server-side `maxItems` on the OpsOasis batch route.
 */
const WPCOM_PLUGIN_UPDATE_BATCH_SIZE = 30;

/**
 * Normalizes a version string for comparison (drops a leading `v`).
 *
 * @param   string $version The version string.
 *
 * @return  string
 */
function normalize_version_string( string $version ): string {
	return \ltrim( \trim( $version ), 'vV' );
}

/**
 * Classifies a site's update outcome from its before/after versions, optionally against a release.
 *
 * With $release set, a resulting version newer than it is `ahead` and older is `behind` (so a site the
 * release has not reached, or one running a newer test build, is surfaced); otherwise the result is
 * simply whether the version moved forward (`updated`) or not (`current`).
 *
 * @param   string      $was     The version installed before the update.
 * @param   string      $now     The version reported after the update.
 * @param   string|null $release Optional release version to compare the result against.
 *
 * @return  string One of `updated`, `current`, `ahead`, `behind`.
 */
function classify_wpcom_plugin_update_result( string $was, string $now, ?string $release = null ): string {
	if ( ! empty( $release ) ) {
		$against_release = \version_compare( normalize_version_string( $now ), normalize_version_string( $release ) );
		if ( $against_release > 0 ) {
			return 'ahead';
		}
		if ( $against_release < 0 ) {
			return 'behind';
		}
	}

	return \version_compare( normalize_version_string( $was ), normalize_version_string( $now ), '<' ) ? 'updated' : 'current';
}

/**
 * Normalizes a URL or host to a bare lowercase host for comparison.
 *
 * @param   string $value The URL or host to normalize.
 *
 * @return  string
 */
function normalize_wpcom_site_host( string $value ): string {
	$value = \strtolower( \trim( $value ) );
	$value = \preg_replace( '#^https?://#', '', $value );
	$value = \preg_replace( '#/.*$#', '', $value );

	return $value;
}

/**
 * Reads the first column of a CSV file.
 *
 * @param   string $path The path to the CSV file.
 *
 * @return  string[]
 *
 * @throws  \RuntimeException If the file cannot be opened.
 */
function read_wpcom_sites_csv_first_column( string $path ): array {
	$handle = \fopen( $path, 'r' );
	if ( false === $handle ) {
		throw new \RuntimeException( "Could not open the sites file `$path`." );
	}

	$values = array();
	while ( false !== ( $row = \fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
		if ( isset( $row[0] ) ) {
			$values[] = $row[0];
		}
	}
	\fclose( $handle );

	return $values;
}

/**
 * Parses a `--sites` value into a list of requested site identifiers.
 *
 * The value is either a path to a CSV file (first column holds the site URLs) or a comma-separated list
 * of URLs and/or numeric WPCOM IDs. Blank and implausible entries (such as a CSV header row) are dropped.
 *
 * @param   string $spec The raw sites value.
 *
 * @return  string[]
 */
function parse_wpcom_site_identifiers( string $spec ): array {
	$raw = \is_file( $spec ) ? read_wpcom_sites_csv_first_column( $spec ) : \explode( ',', $spec );

	$identifiers = array();
	foreach ( $raw as $value ) {
		$value = \trim( (string) $value );
		if ( '' === $value || ( ! \is_numeric( $value ) && ! \str_contains( $value, '.' ) ) ) {
			continue;
		}
		$identifiers[ $value ] = $value;
	}

	return \array_values( $identifiers );
}

/**
 * Finds the fleet key for a single requested identifier, by numeric WPCOM ID or by host.
 *
 * @param   string $identifier The requested site URL or numeric WPCOM ID.
 * @param   array  $all_sites  The connected Jetpack sites, keyed by WPCOM ID.
 *
 * @return  int|string|null The matching fleet key, or null when no site matches.
 */
function match_wpcom_site( string $identifier, array $all_sites ): int|string|null {
	if ( \is_numeric( $identifier ) ) {
		foreach ( $all_sites as $key => $site ) {
			if ( (string) $site->userblog_id === $identifier ) {
				return $key;
			}
		}
	}

	$needle = normalize_wpcom_site_host( $identifier );
	if ( '' !== $needle ) {
		foreach ( $all_sites as $key => $site ) {
			if ( normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) ) === $needle ) {
				return $key;
			}
		}
	}

	return null;
}

/**
 * Matches the requested identifiers against the connected fleet.
 *
 * @param   string[] $identifiers The requested site URLs and/or numeric WPCOM IDs.
 * @param   array    $all_sites   The connected Jetpack sites, keyed by WPCOM ID.
 *
 * @return  array A two-element list: the matched sites keyed by WPCOM ID, and the unmatched identifiers.
 */
function resolve_wpcom_sites_from_identifiers( array $identifiers, array $all_sites ): array {
	$matched   = array();
	$unmatched = array();

	foreach ( $identifiers as $identifier ) {
		$key = match_wpcom_site( $identifier, $all_sites );
		if ( \is_null( $key ) ) {
			$unmatched[] = $identifier;
			continue;
		}
		$matched[ $key ] = $all_sites[ $key ];
	}

	return array( $matched, $unmatched );
}

/**
 * Checks whether the plugin data matches the search term exactly.
 *
 * @param   \stdClass $plugin_data The plugin data.
 * @param   string    $term        The search term.
 * @param   string    $folder      The plugin folder.
 * @param   string    $file        The plugin main file (without `.php`).
 *
 * @return  boolean
 */
function is_exact_wpcom_plugin_match( \stdClass $plugin_data, string $term, string $folder, string $file ): bool {
	return $term === $plugin_data->TextDomain // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		|| $term === $folder
		|| $term === $file;
}

/**
 * Validates the cross-option rules for a plugin update request.
 *
 * @param   string|null $environment The environment filter, if any.
 *
 * @return  void
 *
 * @throws  \InvalidArgumentException If any option is invalid.
 */
function validate_wpcom_plugin_update_options( ?string $environment ): void {
	if ( ! \is_null( $environment ) && ! \in_array( $environment, array( 'staging', 'production' ), true ) ) {
		throw new \InvalidArgumentException( 'The --environment option must be either `staging` or `production`.' );
	}
}

/**
 * Resolves sites + plugin into a target structure for a plugin update.
 *
 * Pure of console output. Reproduces the resolve -> environment filter -> inventory -> match flow.
 *
 * @param   string      $plugin      The plugin term (matched against folder / main file / textdomain).
 * @param   string      $sites_spec  `all`, a comma-separated list of URLs/IDs, or a CSV path.
 * @param   string|null $environment Optional `staging`/`production` URL filter.
 *
 * @return  array{targets: array, unqueryable: array, unmatched: array, sites: array, fetched_count: int, matched_count: int, env_before: int, env_after: int}
 *
 * @throws  \InvalidArgumentException If no sites match or none survive the environment filter.
 * @throws  \RuntimeException         If the fleet or the inventory cannot be resolved.
 */
function build_wpcom_plugin_update_plan( string $plugin, string $sites_spec, ?string $environment ): array {
	$all_sites = get_wpcom_jetpack_sites();
	if ( \is_null( $all_sites ) ) {
		throw new \RuntimeException( 'Could not fetch the connected Jetpack sites from WPCOM.' );
	}
	$fetched_count = \count( $all_sites );

	$unmatched = array();
	if ( 'all' === \strtolower( \trim( $sites_spec ) ) ) {
		$sites = $all_sites;
	} else {
		[ $matched, $unmatched ] = resolve_wpcom_sites_from_identifiers( parse_wpcom_site_identifiers( $sites_spec ), $all_sites );
		if ( empty( $matched ) ) {
			throw new \InvalidArgumentException( 'None of the requested sites were found in the connected Jetpack fleet.' );
		}
		$sites = $matched;
	}
	$matched_count = \count( $sites );
	$env_before    = $matched_count;

	if ( ! \is_null( $environment ) ) {
		$keep_staging = 'staging' === $environment;
		$sites        = \array_filter(
			$sites,
			static function ( $site ) use ( $keep_staging ) {
				$is_staging = false !== \stripos( (string) ( $site->siteurl ?? '' ), 'staging' );
				return $keep_staging ? $is_staging : ! $is_staging;
			}
		);
		if ( empty( $sites ) ) {
			throw new \InvalidArgumentException( "No sites remain after the `$environment` environment filter." );
		}
	}
	$env_after = \count( $sites );

	$plugins = get_wpcom_site_plugins_batch( \array_column( $sites, 'userblog_id' ), $errors );
	if ( \is_null( $plugins ) ) {
		throw new \RuntimeException( 'Could not fetch the installed plugins for the requested sites from WPCOM.' );
	}
	$unqueryable = \is_array( $errors ) ? $errors : array();

	$targets = array();
	foreach ( $plugins as $site_id => $site_plugins ) {
		foreach ( $site_plugins as $plugin_file => $plugin_data ) {
			if ( ! is_exact_wpcom_plugin_match( $plugin_data, $plugin, \dirname( $plugin_file ), \basename( $plugin_file, '.php' ) ) ) {
				continue;
			}
			$targets[ $site_id ] = array(
				'name'      => \preg_replace( '/\.php$/', '', $plugin_file ),
				'installed' => (string) $plugin_data->Version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'siteurl'   => (string) ( $sites[ $site_id ]->siteurl ?? '' ),
			);
			break; // One match per site is enough.
		}
	}

	return array(
		'targets'       => $targets,
		'unqueryable'   => $unqueryable,
		'unmatched'     => $unmatched,
		'sites'         => $sites,
		'fetched_count' => $fetched_count,
		'matched_count' => $matched_count,
		'env_before'    => $env_before,
		'env_after'     => $env_after,
	);
}

/**
 * Runs a fresh update-check (Atlantis lever) on the given sites, in batches.
 *
 * @param   int[]         $site_ids The site IDs to refresh.
 * @param   callable|null $progress Optional progress emitter: `fn( string $message ): void`.
 *
 * @return  array{refreshed_count: int, total: int, unreachable: array, unrefreshed: array}
 */
function run_wpcom_plugin_update_refresh( array $site_ids, ?callable $progress = null ): array {
	$emit   = static fn( string $message ) => \is_callable( $progress ) ? $progress( $message ) : null;
	$chunks = \array_chunk( $site_ids, WPCOM_PLUGIN_UPDATE_BATCH_SIZE );
	$total  = \count( $chunks );
	$emit( '<fg=magenta;options=bold>Refreshing the update-check on ' . \count( $site_ids ) . ' site(s)…</>' );

	$results     = array();
	$errors      = array();
	$unrefreshed = array();
	$any_batch   = false;
	foreach ( $chunks as $index => $chunk ) {
		if ( $total > 1 ) {
			$emit( '<comment>Batch ' . ( $index + 1 ) . "/$total: refreshing " . \count( $chunk ) . ' site(s)…</comment>' );
		}
		$chunk_results = force_check_wpcom_site_plugins_batch( $chunk, $chunk_errors );
		if ( \is_null( $chunk_results ) ) {
			$emit( '<comment>⚠ The refresh request failed for this batch.</comment>' );
			// Match the per-site error shape maybe_output_wpcom_failed_sites_table() renders (an object with
			// an `errors` property), so a failed batch does not raise a TypeError there.
			$batch_error  = (object) array( 'errors' => array( 'refresh_batch_failed' => array( 'The batch refresh request failed (e.g. timeout).' ) ) );
			$unrefreshed += \array_fill_keys( $chunk, $batch_error );
			continue;
		}
		$any_batch = true;
		$results  += $chunk_results;
		$errors   += $chunk_errors ?? array();
	}

	if ( ! $any_batch ) {
		$emit( '<comment>⚠ No refresh batch succeeded; proceeding anyway (sites that already detected the release will still update).</comment>' );
	}

	$notes = array();
	if ( \count( $errors ) > 0 ) {
		$notes[] = \count( $errors ) . ' could not be reached (no Atlantis or connection down)';
	}
	if ( \count( $unrefreshed ) > 0 ) {
		$notes[] = \count( $unrefreshed ) . ' were in a failed batch and not re-checked';
	}
	$suffix = empty( $notes ) ? '' : '; ' . \implode( '; ', $notes ) . ' and may not detect the release';
	$emit( '<comment>Refreshed ' . \count( $results ) . ' of ' . \count( $site_ids ) . ' site(s)' . $suffix . '.</comment>' );

	return array(
		'refreshed_count' => \count( $results ),
		'total'           => \count( $site_ids ),
		'unreachable'     => $errors,
		'unrefreshed'     => $unrefreshed,
	);
}

/**
 * Executes a built plan: updates the planned sites via the detection-based update endpoint. (Refresh,
 * when requested, is a separate step the caller runs first via run_wpcom_plugin_update_refresh().)
 *
 * Pure of direct console writes — progress is emitted only via the optional callback.
 *
 * @param   array         $plan     A plan from build_wpcom_plugin_update_plan().
 * @param   callable|null $progress Optional progress emitter: `fn( string $message ): void`.
 *
 * @return  array{results: array, errors: array}
 */
function execute_wpcom_plugin_update_plan( array $plan, ?callable $progress = null ): array {
	$emit    = static fn( string $message ) => \is_callable( $progress ) ? $progress( $message ) : null;
	$targets = $plan['targets'];

	$results = array();
	$errors  = array();

	// Group by plugin identifier (near-always a single group) and update each group in one batch call.
	$groups = array();
	foreach ( $targets as $site_id => $target ) {
		$groups[ $target['name'] ][] = $site_id;
	}
	foreach ( $groups as $plugin_name => $site_ids ) {
		$group_results = update_wpcom_site_plugins_batch( $site_ids, $plugin_name, $group_errors );
		if ( \is_null( $group_results ) ) {
			$emit( "<error>The update request failed for plugin `$plugin_name`.</error>" );
			continue;
		}
		$results += $group_results;
		$errors  += $group_errors ?? array();
	}

	return array(
		'results' => $results,
		'errors'  => $errors,
	);
}

/**
 * Returns the stats for a WPCOM or Jetpack Connected site.
 *
 * @param   string      $site_id_or_url The site URL or WordPress.com site ID.
 * @param   array|null  $query_params   Optional. Additional parameters to pass to the request.
 * @param   string|null $type           Optional. The type of stats to retrieve.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/sites/$site/stats/summary/
 *
 * @return  stdClass|null
 */
function get_wpcom_site_stats( string $site_id_or_url, ?array $query_params = null, ?string $type = null ): ?stdClass {
	if ( ! empty( $type ) ) {
		$query_params['type'] = $type;
	}

	$endpoint = "site-stats/$site_id_or_url";
	if ( ! empty( $query_params ) ) {
		$endpoint .= '?' . http_build_query( $query_params );
	}

	return API_Helper::make_wpcom_request( $endpoint );
}

/**
 * Returns a batch of stats for given WPCOM sites.
 *
 * @param   array       $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array       $query_params     Optional. Additional parameters to pass to the request.
 * @param   string|null $type             Optional. The type of stats to retrieve.
 * @param   array|null  $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_stats_batch( array $site_ids_or_urls, array $query_params = array(), ?string $type = null, ?array &$errors = null ): ?array {
	$sites_stats = API_Helper::make_wpcom_request(
		'site-stats/batch',
		'POST',
		array_filter(
			array(
				'sites'  => $site_ids_or_urls,
				'params' => $query_params,
				'type'   => $type,
			)
		)
	);
	if ( is_null( $sites_stats ) ) {
		return null;
	}

	return parse_batch_response( $sites_stats, $errors );
}

/**
 * Returns the list of users present on given WPCOM site.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 * @param   array  $params         Optional. Additional parameters to pass to the request.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/users/
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_users( string $site_id_or_url, array $params = array() ): ?array {
	$endpoint = "site-users/$site_id_or_url";
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	$response = API_Helper::make_wpcom_request( $endpoint );
	if ( null === $response ) {
		return null;
	}

	$users = $response->users ?? ( is_array( $response ) ? ( $response['users'] ?? null ) : null );
	return is_array( $users ) ? $users : null;
}

/**
 * Returns a batch of users present on given WPCOM sites.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array      $params           Optional. Additional parameters to pass to the request.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_users_batch( array $site_ids_or_urls, array $params = array(), ?array &$errors = null ): ?array {
	if ( empty( $site_ids_or_urls ) ) {
		return array();
	}

	$sites_users = API_Helper::make_wpcom_request(
		'site-users/batch',
		'POST',
		array(
			'sites'  => $site_ids_or_urls,
			'params' => $params,
		)
	);
	if ( is_null( $sites_users ) ) {
		return null;
	}

	$sites_users = parse_batch_response( $sites_users, $errors );
	return array_map( static fn( stdClass $site_users ) => $site_users->records, $sites_users );
}

/**
 * Returns a WPCOM site user on a given WPCOM site.
 *
 * @param   string $site_id_or_url               The site URL or WordPress.com site ID.
 * @param   string $user_id_or_username_or_email The user ID, username, or email.
 * @param   array  $params                       Optional. Additional parameters to pass to the request.
 *
 * @return  stdClass|null
 */
function get_wpcom_site_user( string $site_id_or_url, string $user_id_or_username_or_email, array $params = array() ): ?stdClass {
	$endpoint = "site-users/$site_id_or_url/$user_id_or_username_or_email";
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	return API_Helper::make_wpcom_request( $endpoint );
}

/**
 * Deletes a WPCOM site user from a given WPCOM site.
 *
 * @param   string $site_id_or_url               The site URL or WordPress.com site ID.
 * @param   string $user_id_or_username_or_email The user ID, username, or email.
 * @param   int    $reassign_user_id             The user ID to reassign the user's content to.
 *
 * @return  true|null
 */
function delete_wpcom_site_user( string $site_id_or_url, string $user_id_or_username_or_email, int $reassign_user_id ): true|null {
	return API_Helper::make_wpcom_request( "site-users/$site_id_or_url/$user_id_or_username_or_email?reassign=$reassign_user_id", 'DELETE' );
}

/**
 * Sends a WordPress.com site invitation to a user by email address.
 *
 * @param   string      $site_id_or_url The site URL or WordPress.com site ID.
 * @param   string      $email          The email address of the user to invite.
 * @param   string      $role           The role to grant. One of 'contributor', 'author', 'editor'.
 * @param   string|null $message        Optional. Message to include in the invitation email.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/post/sites/%24site/invites/new/
 *
 * @return  stdClass|null
 */
function invite_wpcom_site_user( string $site_id_or_url, string $email, string $role = 'editor', ?string $message = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"site-invites/$site_id_or_url",
		'POST',
		array_filter(
			array(
				'email'   => $email,
				'role'    => $role,
				'message' => $message,
			),
			static fn( $value ) => null !== $value && '' !== $value
		)
	);
}

/**
 * Returns the list of stickers associated with a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 *
 * @return  string[]|null
 */
function get_wpcom_site_stickers( string $site_id_or_domain ): ?array {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain" )?->records;
}

/**
 * Adds a given sticker to a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 * @param   string $sticker           The sticker to add.
 *
 * @return  true|null
 */
function add_wpcom_site_sticker( string $site_id_or_domain, string $sticker ): ?true {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain/$sticker", 'POST' );
}

/**
 * Lists sites with a given sticker.
 *
 * @param   string $sticker The sticker to search for.
 *
 * @return string[]|null
 */
function get_wpcom_sites_with_sticker( string $sticker ): ?array {
	return API_Helper::make_wpcom_request( "sites-with-sticker/$sticker" )?->records;
}

/**
 * Removes a given sticker from a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 * @param   string $sticker           The sticker to remove.
 *
 * @return  true|null
 */
function remove_wpcom_site_sticker( string $site_id_or_domain, string $sticker ): ?true {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain/$sticker", 'DELETE' );
}

/**
 * Gets the SSH user for a given WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 *
 * @return  string|null
 */
function get_wpcom_site_ssh_username( string $site_id_or_url ): ?string {
	$ssh_users = API_Helper::make_wpcom_request( "site-ssh-users/$site_id_or_url" );
	return $ssh_users->records[0] ?? null;
}

/**
 * Rotates the password of the specified SFTP user on the specified WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to reset the SFTP user password on.
 * @param   string $username       The username of the SFTP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_wpcom_site_sftp_user_password( string $site_id_or_url, string $username ): ?stdClass {
	return API_Helper::make_wpcom_request( "site-ssh-users/$site_id_or_url/$username/rotate-password", 'POST' );
}

/**
 * Rotates the password of the specified WP user on the specified WPCOM site.
 *
 * @param   string $site_id_or_url The ID or URL of the WPCOM site to reset the WP user password on.
 * @param   string $user           The email, username, or numeric ID of the WP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_wpcom_site_wp_user_password( string $site_id_or_url, string $user ): ?stdClass {
	$credentials = null;

	$exit_code = run_wpcom_site_wp_cli_command( $site_id_or_url, "user reset-password $user --skip-email --porcelain", true );
	$password  = parse_wp_cli_porcelain_password( $GLOBALS['wp_cli_output'] ?? null );

	// The password is only trusted when WP-CLI actually printed one. Without this an unreachable site, or a
	// reset that failed and printed an error instead, overwrites a good 1Password entry. There is no API
	// rotation to fall back from here, so this is the only guard on the value.
	if ( Command::SUCCESS === $exit_code && ! is_null( $password ) ) {
		$credentials = (object) array(
			'username' => $user,
			'password' => $password,
		);
	}

	// Whenever output was captured but no credentials are returned - a garbled token, or a connection that
	// broke after the reset already ran and printed one - that output is the only copy of whatever the site
	// now uses, so it is surfaced rather than dropped. Suppressed only when a fatal WP-CLI error is present
	// AND no token was recoverable: that combination means the reset never ran, whereas an error alongside a
	// token means it did and the token must not be lost.
	if ( is_null( $credentials ) && '' !== trim( (string) ( $GLOBALS['wp_cli_output'] ?? '' ) ) && ( ! is_null( $password ) || ! is_wp_cli_error_output( $GLOBALS['wp_cli_output'] ) ) ) {
		report_unreadable_wp_cli_password( $user, $GLOBALS['wp_cli_output'] );
	}

	return $credentials;
}

/**
 * Creates a new WordPress.com site.
 *
 * @param   string $name The name of the site to create.
 *
 * @return  stdClass|null
 */
function create_wpcom_site( string $name ): ?stdClass {
	return API_Helper::make_wpcom_request( 'sites', 'POST', array( 'name' => $name ) );
}

/**
 * Creates a new WordPress.com staging site.
 *
 * @param string $site_id_or_url The ID or URL of the WordPress.com site to create the staging site for.
 *
 * @return  stdClass|null
 */
function create_wpcom_staging_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/staging-site", method: 'POST' );
}

/**
 * Updates settings of a WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to update.
 * @param   array  $settings       The settings to update.
 *
 * @return  stdClass|null
 */
function update_wpcom_site( string $site_id_or_url, array $settings ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url", 'PUT', array( 'settings' => $settings ) );
}

/**
 * Periodically checks the status of a WordPress.com agency site until it reaches a given state.
 *
 * @param   string          $agency_site_id   The ID of the Agency site to check the state of.
 * @param   string          $state            The state to wait for the site to reach.
 * @param   OutputInterface $output           The output instance.
 * @param   integer         $max_wait_seconds How long to keep checking before giving up.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_agency_site_state( string $agency_site_id, string $state, OutputInterface $output, int $max_wait_seconds = 1200 ): ?stdClass {
	$output->writeln( "<comment>Waiting for WordPress.com agency site $agency_site_id to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	// Budgeted by wall clock, like the sibling waits, since the poll interval differs per state.
	$delay        = 'provisioning' === $state ? 3 : 5;
	$max_attempts = (int) ceil( $max_wait_seconds / $delay );

	// A transient failed lookup is retried rather than treated as terminal - one null from the API against a
	// freshly created site must not throw away the whole budget - and only a few in a row give up.
	$reached      = false;
	$null_lookups = 0;
	for ( $try = 0; $try < $max_attempts; $try++ ) {
		$site = get_wpcom_agency_site( $agency_site_id );
		if ( is_null( $site ) ) {
			if ( 3 <= ++$null_lookups ) {
				break;
			}
		} else {
			$null_lookups = 0;
			if ( $state === $site->features->wpcom_atomic->state ) {
				$reached = true;
				break;
			}
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	if ( ! $reached ) {
		$minutes = (int) round( $max_wait_seconds / 60 );
		$output->writeln(
			3 <= $null_lookups
				? "<error>WordPress.com agency site $agency_site_id could not be looked up ($null_lookups consecutive failed lookups).</error>"
				: "<error>WordPress.com agency site $agency_site_id did not reach the `$state` state within $minutes minutes. The site exists and may still be provisioning.</error>"
		);
		return null;
	}

	return $site;
}

/**
 * Periodically checks on the transfer status of a WordPress.com Atomic site until it reaches a given state.
 *
 * @param   string          $site_id_or_url   The ID or URL of the WordPress.com site to check the state of.
 * @param   string          $state            The state to wait for the site to reach.
 * @param   OutputInterface $output           The output instance.
 * @param   integer         $max_wait_seconds How long to keep checking before giving up.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_site_transfer_state( string $site_id_or_url, string $state, OutputInterface $output, int $max_wait_seconds = 1200 ): ?stdClass {
	$output->writeln( "<comment>Waiting for the transfer of WordPress.com site $site_id_or_url to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	// Budgeted by wall clock, matching the Pressable state wait, so the give-up message can say how long the
	// command actually waited. A transfer copies the whole site, so the budget is deliberately generous.
	$delay        = 5;
	$max_attempts = (int) ceil( $max_wait_seconds / $delay );

	// A transient failed lookup is retried rather than treated as terminal - one null from the API against a
	// freshly created site must not throw away the whole budget - and only a few in a row give up.
	$reached      = false;
	$null_lookups = 0;
	for ( $try = 0; $try < $max_attempts; $try++ ) {
		$transfer = get_wpcom_site_transfer_status( $site_id_or_url );
		if ( is_null( $transfer ) ) {
			if ( 3 <= ++$null_lookups ) {
				break;
			}
		} else {
			$null_lookups = 0;
			if ( $state === $transfer->status ) {
				$reached = true;
				break;
			}
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	if ( ! $reached ) {
		$minutes = (int) round( $max_wait_seconds / 60 );
		$output->writeln(
			3 <= $null_lookups
				? "<error>The transfer of WordPress.com site $site_id_or_url could not be looked up ($null_lookups consecutive failed lookups).</error>"
				: "<error>The transfer of WordPress.com site $site_id_or_url did not reach the `$state` state within $minutes minutes.</error>"
		);
		return null;
	}

	return $transfer;
}

/**
 * Periodically checks the status of a WordPress.com site until it accepts SSH connections.
 *
 * @param   string          $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 * @param   OutputInterface $output         The output instance.
 * @param   integer         $max_attempts   The maximum number of connection attempts, 5 seconds apart.
 *
 * @return  SSH2|null
 */
function wait_on_wpcom_site_ssh( string $site_id_or_url, OutputInterface $output, int $max_attempts = 60 ): ?SSH2 {
	$output->writeln( "<comment>Waiting for WordPress.com site $site_id_or_url to accept SSH connections.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	sleep( 5 ); // Wait a bit before checking the SSH connection. Helps prevent "API calls to this blog have been disabled" errors.
	$progress_bar->advance();
	sleep( 5 );
	$progress_bar->advance();

	$ssh_connection = null;
	for ( $try = 0, $delay = 5; $try < $max_attempts; $try++ ) {
		$ssh_connection = WPCOM_Connection_Helper::get_ssh_connection( $site_id_or_url );
		if ( ! is_null( $ssh_connection ) ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	if ( is_null( $ssh_connection ) ) {
		$output->writeln( "<error>WordPress.com site $site_id_or_url did not accept SSH connections after $max_attempts attempts.</error>" );
	}

	return $ssh_connection;
}

/**
 * Periodically checks the status of a WordPress.com site until the Jetpack user token is regenerated.
 *
 * @param   string          $site_id_or_url   The ID or URL of the WordPress.com site to check the state of.
 * @param   OutputInterface $output           The output instance.
 * @param   integer         $max_wait_seconds How long to keep checking before giving up.
 *
 * @return  boolean
 */
function wait_until_jetpack_token_regenerated( string $site_id_or_url, OutputInterface $output, int $max_wait_seconds = 300 ): bool {
	$output->writeln( '<fg=magenta;options=bold>Pinging site to regenerate Jetpack user token. This will cause an error and the token will be regenerated.</>' );
	$output->writeln( "<comment>Waiting for WordPress.com site $site_id_or_url to regenerate the Jetpack user token.</comment>" );

	$regenerated  = false;
	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();
	$progress_bar->advance();

	// Bounded like the other waits: the caller already copes with a false return by skipping the repository
	// deployment with a warning, which beats hanging the clone forever on a token that never regenerates.
	$delay        = 5;
	$max_attempts = (int) ceil( $max_wait_seconds / $delay );

	for ( $try = 0; $try < $max_attempts; $try++ ) {
		$wpcom_site = get_wpcom_site( $site_id_or_url );
		if ( ! is_null( $wpcom_site ) ) {
			$regenerated = true;
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	if ( ! $regenerated ) {
		$minutes = (int) round( $max_wait_seconds / 60 );
		$output->writeln( "<error>The Jetpack user token of WordPress.com site $site_id_or_url did not regenerate within $minutes minutes.</error>" );
	}

	return $regenerated;
}

/**
 * Returns the default OpsOasis webhook receiver URL for WPCOM deployment status updates.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_default_url(): string {
	$webhook_url_override = getenv( 'TEAM51_WPCOM_DEPLOYMENT_WEBHOOK_URL' );
	if ( false !== $webhook_url_override && '' !== trim( $webhook_url_override ) ) {
		return $webhook_url_override;
	}

	$opsoasis_base_url = getenv( 'TEAM51_OPSOASIS_BASE_URL' ) ?: getenv( 'OPSOASIS_BASE_URL' ) ?: 'https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/';
	$components        = parse_url( $opsoasis_base_url );
	$origin            = sprintf(
		'%s://%s%s',
		$components['scheme'] ?? 'https',
		$components['host'] ?? 'opsoasis.wpspecialprojects.com',
		isset( $components['port'] ) ? ':' . $components['port'] : ''
	);

	return rtrim( $origin, '/' ) . '/wp-json/wpcomsp/webhooks/v1/wpcom-deployments';
}

/**
 * Returns the default lifecycle events used when creating WPCOM deployment webhooks.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_default_events(): string {
	return 'completed,failed,cancelled';
}

/**
 * Normalizes webhook events input into a string array.
 *
 * @param   string|array|null $events Events as CSV string or an array.
 *
 * @return  string[]
 */
function normalize_wpcom_site_code_deployment_webhook_events( string|array|null $events ): array {
	if ( is_array( $events ) ) {
		return array_values( array_filter( array_map( static fn( mixed $event ) => trim( (string) $event ), $events ) ) );
	}

	$events_csv = trim( (string) $events );
	if ( '' === $events_csv ) {
		$events_csv = get_wpcom_site_code_deployment_webhook_default_events();
	}

	return array_values( array_filter( array_map( 'trim', explode( ',', $events_csv ) ) ) );
}

/**
 * Returns the default OpsOasis endpoint used to persist WPCOM deployment webhook secrets.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_secret_sync_endpoint(): string {
	return getenv( 'TEAM51_WPCOM_DEPLOYMENT_WEBHOOK_SECRET_SYNC_ENDPOINT' ) ?: 'webhooks/v1/wpcom-deployments/secrets';
}

/**
 * Creates a new deployment webhook for a WordPress.com code deployment.
 *
 * @param   string      $site_id_or_url The ID or URL of the WordPress.com site to create the webhook for.
 * @param   string      $deployment_id  The ID of the code deployment.
 * @param   string      $url            The webhook receiver URL.
 * @param   string|null $events         Optional. Comma-separated deployment lifecycle events.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $url, ?string $events = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments/$deployment_id/webhooks",
		'POST',
		array_filter(
			array(
				'url'    => $url,
				'events' => $events,
			)
		)
	);
}

/**
 * Returns the list of deployment webhooks for a WordPress.com code deployment.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployment_webhooks( string $site_id_or_url, string $deployment_id ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks" )?->records;
}

/**
 * Returns a deployment webhook for a WordPress.com code deployment by webhook ID.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook.
 *
 * @return  stdClass|null
 */
function get_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id ): ?stdClass {
	$webhooks = get_wpcom_site_code_deployment_webhooks( $site_id_or_url, $deployment_id );
	if ( is_null( $webhooks ) ) {
		return null;
	}

	foreach ( $webhooks as $webhook ) {
		if ( isset( $webhook->id ) && (string) $webhook->id === $webhook_id ) {
			return $webhook;
		}
	}

	return null;
}

/**
 * Updates a deployment webhook for a WordPress.com code deployment.
 *
 * @param   string      $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string      $deployment_id  The ID of the code deployment.
 * @param   string      $webhook_id     The ID of the webhook to update.
 * @param   string      $url            The webhook receiver URL.
 * @param   string|null $events         Optional. Comma-separated deployment lifecycle events.
 *
 * @return  stdClass|null
 */
function update_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id, string $url, ?string $events = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id",
		'PUT',
		array_filter(
			array(
				'url'    => $url,
				'events' => $events,
			)
		)
	);
}

/**
 * Deletes a deployment webhook for a WordPress.com code deployment.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to delete.
 * @param   mixed  $raw_response   Optional. The raw API response payload.
 *
 * @return  true|null
 */
function delete_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id, mixed &$raw_response = null ): ?true {
	$delete_response = API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id", 'DELETE' );
	$raw_response    = $delete_response;

	if ( is_null( $delete_response ) ) {
		return null;
	}

	// WPCOM proxy can return either an empty success body (true) or a success object.
	if ( true === $delete_response ) {
		return true;
	}

	if ( is_object( $delete_response ) ) {
		$success     = $delete_response->success ?? null;
		$status_code = $delete_response->status_code ?? null;
		$error       = $delete_response->error ?? null;

		if ( true === $success && ( is_null( $status_code ) || ( (int) $status_code >= 200 && (int) $status_code < 300 ) ) && is_null( $error ) ) {
			return true;
		}
	}

	return null;
}

/**
 * Triggers a test delivery for a deployment webhook.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to test.
 *
 * @return  stdClass|null
 */
function test_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id/test", 'POST' );
}

/**
 * Returns the deliveries of a deployment webhook.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to inspect.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployment_webhook_deliveries( string $site_id_or_url, string $deployment_id, string $webhook_id ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id/deliveries" )?->records;
}

/**
 * Attempts to persist a deployment webhook secret in OpsOasis so incoming webhook signatures can be verified.
 *
 * @param   string $site_id       The WPCOM site ID.
 * @param   string $deployment_id The code deployment ID.
 * @param   string $webhook_id    The webhook ID.
 * @param   string $url           The webhook receiver URL.
 * @param   array  $events        The webhook events subscribed for delivery.
 * @param   string $secret        The webhook secret returned by WordPress.com.
 *
 * @return  true|null
 */
function sync_wpcom_site_code_deployment_webhook_secret( string $site_id, string $deployment_id, string $webhook_id, string $url, array $events, string $secret ): ?true {
	$sync_response = API_Helper::make_opsoasis_request(
		get_wpcom_site_code_deployment_webhook_secret_sync_endpoint(),
		'POST',
		array(
			'site_id'       => is_numeric( $site_id ) ? (int) $site_id : $site_id,
			'deployment_id' => $deployment_id,
			'webhook_id'    => $webhook_id,
			'url'           => $url,
			'events'        => array_values( $events ),
			'secret'        => $secret,
		)
	);

	if ( is_null( $sync_response ) ) {
		return null;
	}

	// OpsOasis may return either an empty success body (true) or a success object.
	if ( true === $sync_response ) {
		return true;
	}

	if ( is_object( $sync_response ) ) {
		$success     = $sync_response->success ?? null;
		$status_code = $sync_response->status_code ?? null;
		$error       = $sync_response->error ?? null;

		if ( true === $success && ( is_null( $status_code ) || ( (int) $status_code >= 200 && (int) $status_code < 300 ) ) && is_null( $error ) ) {
			return true;
		}
	}

	return null;
}

/**
 * Extracts the deployment webhook object from a WPCOM deployment webhook API response.
 *
 * @param   stdClass $response The webhook response payload.
 *
 * @return  stdClass
 */
function get_wpcom_site_code_deployment_webhook_from_response( stdClass $response ): stdClass {
	return $response->webhook ?? $response;
}

/**
 * Extracts a deployment webhook secret from a WPCOM deployment webhook API response.
 *
 * @param   stdClass $response The webhook response payload.
 *
 * @return  string|null
 */
function get_wpcom_site_code_deployment_webhook_secret_from_response( stdClass $response ): ?string {
	$webhook = get_wpcom_site_code_deployment_webhook_from_response( $response );
	return $webhook->secret ?? $response->secret ?? null;
}

/**
 * Connects a WordPress.com site to a GitHub repository for code deployments.
 *
 * @param   string     $site_id_or_url         The ID or URL of the WordPress.com site to connect to the GitHub repository.
 * @param   string     $external_repository_id The ID of the external repository.
 * @param   string     $branch_name            The branch to deploy.
 * @param   string     $target_dir             The target directory to deploy to.
 * @param   array|null $params                 Optional. Additional parameters to pass to the request.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment( string $site_id_or_url, string $external_repository_id, string $branch_name, string $target_dir, ?array $params = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments",
		'POST',
		array(
			'external_repository_id' => $external_repository_id,
			'branch_name'            => $branch_name,
			'target_dir'             => $target_dir,
			'is_automated'           => true,
		) + (array) $params
	);
}

/**
 * Triggers a code deployment run for a given code deployment.
 *
 * @param   string $site_id_or_url     The ID or URL of the WordPress.com site to create the run for.
 * @param   string $code_deployment_id The ID of the code deployment to create the run for.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment_run( string $site_id_or_url, string $code_deployment_id ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$code_deployment_id/runs", 'POST' );
}

/**
 * Returns the list of code deployments for a given WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to get the repositories for.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployments( string $site_id_or_url ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments" )?->records;
}

/**
 * Periodically checks the status of a WordPress.com site until it accepts SSH connections.
 *
 * @param   stdClass        $code_deployment The code deployment object.
 * @param   string          $state           The state to wait for the site to reach.
 * @param   OutputInterface $output          The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_code_deployment_run_state( stdClass $code_deployment, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for Deployment $code_deployment->id to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until the deployment is completed.
		$code_deployments = get_wpcom_site_code_deployments( $code_deployment->blog_id );

		// Currently we only check the first deployment
		$deployment = reset( $code_deployments );
		if ( $deployment->current_deployment_run->code_deployment_id === $code_deployment->id && $deployment->current_deployment_run->status === $state ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $deployment;
}

/**
 * Runs a WP-CLI command on the specified WordPress.com site.
 *
 * @param   string  $site_id_or_url The ID or URL of the site to run the WP-CLI command on.
 * @param   string  $wp_cli_command The WP-CLI command to run.
 * @param   boolean $skip_output    Whether to skip outputting the response to the console.
 * @param   boolean $interactive    Whether to run the command interactively.
 *
 * @return  integer
 * @noinspection PhpDocMissingThrowsInspection
 */
function run_wpcom_site_wp_cli_command( string $site_id_or_url, string $wp_cli_command, bool $skip_output = false, bool $interactive = false ): int {
	/* @noinspection PhpUnhandledExceptionInspection */
	return run_app_command(
		WPCOM_Site_WP_CLI_Command_Run::getDefaultName(),
		array(
			'site'           => $site_id_or_url,
			'wp-cli-command' => $wp_cli_command,
			'--skip-output'  => $skip_output,
		),
		$interactive
	);
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a WPCOM site.
 *
 * @param   InputInterface $input         The console input.
 * @param   callable|null  $no_input_func The function to call if no input is given.
 * @param   string         $name          The name of the value to grab.
 *
 * @throws  InvalidArgumentException If the provided site does not resolve to a valid WPCOM site.
 *
 * @return  stdClass
 */
function get_wpcom_site_input( InputInterface $input, ?callable $no_input_func = null, string $name = 'site' ): stdClass {
	$site_id_or_url = get_site_input( $input, $no_input_func, $name );

	$wpcom_site = get_wpcom_site( $site_id_or_url );
	if ( is_null( $wpcom_site ) ) {
		throw new InvalidArgumentException( 'Invalid WPCOM site.' );
	}

	return $wpcom_site;
}

/**
 * Outputs a table of WPCOM sites that failed to be processed.
 *
 * @param   OutputInterface $output       The console output.
 * @param   stdClass[]      $errors       The list of errors indexed by the site ID.
 * @param   stdClass[]|null $sites        The WPCOM sites that were processed.
 * @param   string|null     $header_title Optional. The title to use for the table header.
 *
 * @return  void
 */
function maybe_output_wpcom_failed_sites_table( OutputInterface $output, array $errors, ?array $sites = null, ?string $header_title = null ): void {
	if ( empty( $errors ) ) {
		return;
	}

	$sites        = $sites ?? get_wpcom_sites( array( 'fields' => 'ID,URL' ) );
	$header_title = $header_title ?? 'Sites that failed to be processed';

	output_table(
		$output,
		array_map(
			static function ( string $site_id, stdClass $wp_error ) use ( $sites ) {
				$site = $sites[ $site_id ];
				return array(
					$site->ID ?? $site->userblog_id ?? $site->blog_id,
					$site->URL ?? $site->siteurl ?? $site->site_url, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					encode_json_content( $wp_error->errors ),
				);
			},
			array_keys( $errors ),
			$errors
		),
		array( 'WPCOM Site ID', 'Site URL', 'Error' ),
		$header_title
	);
}
// endregion
