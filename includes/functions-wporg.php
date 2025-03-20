<?php

use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Fetches the list of WordPress.org themes and returns them as an array of slugs.
 *
 * @param   OutputInterface $output The output interface.
 *
 * @return  string[]
 */
function get_wporg_theme_choices( OutputInterface $output ): array {

	$output->writeln( '<info>Fetching WordPress.org themes...</info>' );

	$endpoint = 'themes'; // Equivalent to 'sites?type=all'.

	$response = API_Helper::make_wporg_request( $endpoint );
	if ( is_null( $response ) ) {
		return array();
	}

	return array_combine(
		array_map( fn( $theme ) => $theme->slug, $response->records ),
		array_map( fn( $theme ) => $theme->name, $response->records )
	);
}
// endregion
