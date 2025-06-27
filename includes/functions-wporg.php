<?php

use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Fetches the list of WordPress.org themes.
 *
 * @return  string[]
 */
function get_wporg_theme_choices(): array {

	$endpoint = 'themes'; // Equivalent to 'sites?type=all'.

	$response = API_Helper::make_wporg_request( $endpoint );
	if ( is_null( $response ) ) {
		return array();
	}

	return array_combine(
		array_map( fn( $theme ) => $theme->slug, $response->records ),
		$response->records
	);
}
// endregion
