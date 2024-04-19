<?php

/**
 * Returns the list of Jetpack modules. This is hacky but works...
 *
 * @return string[]|null
 */
function get_jetpack_modules(): ?array {
	static $cache = null;

	if ( is_null( $cache ) ) {
		$all_sites = get_wpcom_jetpack_sites() ?? array();

		$first_site = array_shift( $all_sites );
		if ( ! is_object( $first_site ) ) {
			return null;
		}

		$module_list = get_jetpack_site_modules( $first_site->userblog_id );
		if ( is_null( $module_list ) ) {
			return null;
		}

		$cache = array_combine(
			array_keys( $module_list ),
			array_map(
				static fn( stdClass $module ) => array(
					'name'        => $module->name,
					'slug'        => $module->module,
					'description' => $module->description,
				),
				$module_list
			)
		);
	}

	return $cache;
}

/**
 * Returns the list of complete Jetpack modules information for a given site.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass[]|null
 */
function get_jetpack_site_modules( string $site_id_or_url ): ?array {
	$module_list = API_Helper::make_jetpack_request( "modules/$site_id_or_url" );
	return is_null( $module_list ) ? null : (array) $module_list;
}

/**
 * Returns the list of complete Jetpack modules information for a list of sites
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[][]|null
 */
function get_jetpack_site_modules_batch( array $site_ids_or_urls, array &$errors = null ): ?array {
	$sites_module_list = API_Helper::make_jetpack_request( 'modules/batch', 'POST', array( 'sites' => $site_ids_or_urls ) );
	if ( is_null( $sites_module_list ) ) {
		return null;
	}

	$sites_module_list = parse_batch_response( $sites_module_list, $errors );
	return array_map( static fn( stdClass $site_module_list ) => (array) $site_module_list, $sites_module_list );
}

/**
 * Updates the Jetpack modules settings for a given site.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 * @param   array  $settings       The settings to update.
 *
 * @return  boolean|null
 */
function update_jetpack_site_modules_settings( string $site_id_or_url, array $settings ): ?bool {
	$update_result = API_Helper::make_jetpack_request( "modules/$site_id_or_url", 'POST', array( 'settings' => encode_json_content( $settings ) ) );
	if ( is_null( $update_result ) ) {
		return null;
	}

	return ( $update_result->code ?? null ) === 'success';
}
