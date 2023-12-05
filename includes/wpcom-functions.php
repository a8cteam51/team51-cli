<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Returns a WPCOM site object by site URL or WordPress.com site ID.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass|null
 */
function get_wpcom_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url" );
}

/**
 * Returns the list of Jetpack sites connected to WPCOM.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  stdClass[]|null
 */
function get_wpcom_jetpack_sites(): ?array {
	$sites = API_Helper::make_wpcom_request( 'sites?type=jetpack' );
	return is_null( $sites ) ? null : (array) $sites;
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
		$output->writeln( '<error>Invalid site. Aborting.</error>' );
		exit( 1 );
	}

	return $wpcom_site;
}

// endregion
