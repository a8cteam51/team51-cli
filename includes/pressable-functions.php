<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Returns the list of Pressable sites.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  stdClass[]|null
 */
function get_pressable_sites(): ?array {
	$sites = API_Helper::make_pressable_request( 'sites' );
	return is_null( $sites ) ? null : (array) $sites;
}

/**
 * Returns the Pressable site with the specified ID or URL.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $site_id_or_url The ID or URL of the site to retrieve.
 *
 * @return  stdClass|null
 */
function get_pressable_site( string $site_id_or_url ): ?stdClass {
	$site_id_or_url = is_numeric( $site_id_or_url ) ? $site_id_or_url : urlencode( $site_id_or_url );
	return API_Helper::make_pressable_request( "sites/$site_id_or_url" );
}

/**
 * Creates a new collaborator on the specified Pressable site.
 *
 * @param   string $site_id_or_url     The ID or URL of the Pressable site to create the collaborator on.
 * @param   string $collaborator_email The email address of the collaborator to add.
 *
 * @return  stdClass|null
 */
function create_pressable_site_collaborator( string $site_id_or_url, string $collaborator_email ): ?stdClass {
	$collaborator = API_Helper::make_pressable_request( "site-collaborators/$site_id_or_url", 'POST', array( 'email' => $collaborator_email ) );
	return is_null( $collaborator ) ? null : (object) $collaborator;
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a Pressable site.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass
 */
function get_pressable_site_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): stdClass {
	$site_id_or_url = get_site_input( $input, $output, $no_input_func, $name );

	$pressable_site = get_pressable_site( $site_id_or_url );
	if ( is_null( $pressable_site ) ) {
		$output->writeln( '<error>Invalid site. Aborting.</error>' );
		exit( 1 );
	}

	return $pressable_site;
}

// endregion
