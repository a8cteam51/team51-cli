<?php

/**
 * Returns the list of complete Jetpack modules information for a given site.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass[]|null
 */
function get_jetpack_site_modules( string $site_id_or_url ): ?array {
	$module_list = API_Helper::make_jetpack_request( "site-modules/$site_id_or_url" );
	return is_null( $module_list ) ? null : (array) $module_list;
}
