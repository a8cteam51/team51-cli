<?php

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
	$type = $params['type'] ?? 'all';
	return array_combine(
		array_column( $response->records, 'jetpack' === $type ? 'userblog_id' : 'ID' ),
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
 * Returns a batch of sites by their domains or numeric WPCOM IDs.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_batch( array $site_ids_or_urls, array &$errors = null ): ?array {
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
function get_wpcom_site_plugins_batch( array $site_ids_or_urls, array &$errors = null ): ?array {
	$sites_plugins = API_Helper::make_wpcom_request( 'sites/batch/plugins', 'POST', array( 'sites' => $site_ids_or_urls ) );
	if ( is_null( $sites_plugins ) ) {
		return null;
	}

	$sites_plugins = parse_batch_response( $sites_plugins, $errors );
	return array_map( static fn( stdClass $site_plugins ) => (array) $site_plugins, $sites_plugins );
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
function get_wpcom_site_stats_batch( array $site_ids_or_urls, array $query_params = array(), ?string $type = null, array &$errors = null ): ?array {
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

	return API_Helper::make_wpcom_request( $endpoint );
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
function get_wpcom_site_users_batch( array $site_ids_or_urls, array $params = array(), array &$errors = null ): ?array {
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
 *
 * @return  true|null
 */
function delete_wpcom_site_user( string $site_id_or_url, string $user_id_or_username_or_email ): true|null {
	return API_Helper::make_wpcom_request( "site-users/$site_id_or_url/$user_id_or_username_or_email", 'DELETE' );
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

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a WPCOM site.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass
 */
function get_wpcom_site_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): stdClass {
	$site_id_or_url = get_site_input( $input, $output, $no_input_func, $name );

	$wpcom_site = get_wpcom_site( $site_id_or_url );
	if ( is_null( $wpcom_site ) ) {
		$output->writeln( '<error>Invalid site. Aborting!</error>' );
		exit( 1 );
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

/**
 * Creates a new Pressable site.
 *
 * @param   OutputInterface $output     The console output.
 * @param   string          $name       The name of the site to create.
 * @param   string          $datacenter The datacenter code to create the site in.
 *
 * @return  stdClass|null
 */
function create_wpcom_site( OutputInterface $output, string $name, string $datacenter ): ?stdClass {
	// Temporary stuff
	$agency_id = 231948494;
	$wpcom_url = 'https://public-api.wordpress.com/';
	putenv( "TEAM51_OPSOASIS_BASE_URL=$wpcom_url" );

	// List sites pending to provision
	$provisioned_sites = API_Helper::make_wpcom_request(
		"agency/$agency_id/sites/pending",
		'GET',
		null,
		'wpcom/v2'
	);

	if ( count( $provisioned_sites ) === 0 ) {
		$output->writeln( '<error>No sites available to provision. Please buy new licenses at https://agencies.automattic.com/</error>' );
		exit( 1 );
	}

	$site    = $provisioned_sites[0];
	$site_id = $site->id;

	// Provision the site
	$provisioned_site = API_Helper::make_wpcom_request(
		"agency/$agency_id/sites/$site_id/provision",
		'POST',
		// Not useful for now
		array(
			'name'       => $name,
			'datacenter' => $datacenter,
		),
		'wpcom/v2'
	);

	if ( is_null( $provisioned_site ) || ! $provisioned_site->success ) {
		$output->writeln( '<error>Failed to create the site.</error>' );
		exit( 1 );
	}

	// Temporary stuff
	putenv( 'TEAM51_OPSOASIS_BASE_URL=https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/' );

	return $site;
}

/**
 * Get a WPCOM Agency site.
 *
 * @param   integer $agency_site_id The ID of the agency site to get.
 *
 * @return  stdClass|null
 */
function get_wpcom_agency_site( int $agency_site_id ): ?stdClass {
	// Temporary stuff
	$agency_id = 231948494;
	$wpcom_url = 'https://public-api.wordpress.com/';
	putenv( "TEAM51_OPSOASIS_BASE_URL=$wpcom_url" );

	// List sites pending to provision
	$provisioned_sites = API_Helper::make_wpcom_request(
		"agency/$agency_id/sites",
		'GET',
		null,
		'wpcom/v2'
	);

	foreach ( $provisioned_sites as $site ) {
		if ( $site->id === $agency_site_id ) {
			return $site;
		}
	}

	// Temporary stuff
	putenv( 'TEAM51_OPSOASIS_BASE_URL=https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/' );

	return null;
}

/**
 * Periodically checks the status of a WordPress site until it's in the given state.
 *
 * @param   string          $site_id The ID of the Agency site to check the state of.
 * @param   string          $state   The state to wait for the site to exit.
 * @param   OutputInterface $output  The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_site_state( string $site_id, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for WordPress site $site_id to exit $state state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 3; true; $try++ ) {
		$site = get_wpcom_agency_site( $site_id );

		if ( $state === $site->features->wpcom_atomic->state ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $site;
}



// endregion
