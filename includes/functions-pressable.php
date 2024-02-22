<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Returns the list of Pressable sites.
 *
 * @param   array $params An array of parameters to filter the results by.
 *
 * @return  stdClass[]|null
 */
function get_pressable_sites( array $params = array() ): ?array {
	$endpoint = 'sites';
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	return API_Helper::make_pressable_request( $endpoint );
}

/**
 * Returns a tree-like structure of Pressable sites that have been cloned from the specified site.
 *
 * @param   string        $site_id_or_url The ID or URL of the site to retrieve related sites for.
 * @param   boolean       $find_root      Whether to find the root site of the cloned sites.
 * @param   callable|null $node_generator The function to generate the node for each site.
 *
 * @return  stdClass[]|null
 */
function get_pressable_related_sites( string $site_id_or_url, bool $find_root = true, ?callable $node_generator = null ): ?array {
	$root_site = get_pressable_site( $site_id_or_url );
	if ( is_null( $root_site ) ) {
		return null;
	}

	// If the given site is not the root, maybe find it.
	while ( $find_root && ! empty( $root_site->clonedFromId ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$root_site = get_pressable_site( $root_site->clonedFromId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( is_null( $root_site ) ) {
			return null;
		}
	}

	// Initialize the tree with the root site.
	$node_generator = is_callable( $node_generator ) ? $node_generator : static fn( object $site ) => $site;
	$related_sites  = array( 0 => array( $root_site->id => $node_generator( $root_site ) ) );

	// Identify the related sites by level.
	$all_sites = get_pressable_sites();
	if ( ! is_array( $all_sites ) ) {
		return null;
	}

	do {
		$has_next_level = false;
		$current_level  = count( $related_sites );

		foreach ( array_keys( $related_sites[ $current_level - 1 ] ) as $parent_site_id ) {
			foreach ( $all_sites as $maybe_clone_site ) {
				if ( $maybe_clone_site->clonedFromId !== $parent_site_id ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				$related_sites[ $current_level ][ $maybe_clone_site->id ] = $node_generator( $maybe_clone_site );
				$has_next_level = true;
			}
		}
	} while ( true === $has_next_level );

	return $related_sites;
}

/**
 * Returns the Pressable site with the specified ID or URL.
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
	return API_Helper::make_pressable_request( "site-collaborators/$site_id_or_url", 'POST', array( 'email' => $collaborator_email ) );
}

/**
 * Returns the list of SFTP users for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP users for.
 *
 * @return  stdClass[]|null
 */
function get_pressable_site_sftp_users( string $site_id_or_url ): ?array {
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url" );
}

/**
 * Returns the SFTP user who is the owner of the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP owner for.
 *
 * @return  stdClass|null
 */
function get_pressable_site_sftp_owner( string $site_id_or_url ): ?stdClass {
	$sftp_users = get_pressable_site_sftp_users( $site_id_or_url );
	if ( ! is_array( $sftp_users ) ) {
		return null;
	}

	foreach ( $sftp_users as $sftp_user ) {
		if ( true === $sftp_user->owner ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Returns the SFTP user with the given username on the specified site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP user emails for.
 * @param   string $username       The username of the site SFTP user.
 *
 * @return  string[]|null
 */
function get_pressable_site_sftp_user_by_username( string $site_id_or_url, string $username ): ?stdClass {
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url/$username" );
}

/**
 * Returns the SFTP user with the given ID on the specified site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP user from.
 * @param   string $user_id        The ID of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_id( string $site_id_or_url, string $user_id ): ?stdClass {
	$sftp_users = get_pressable_site_sftp_users( $site_id_or_url );
	if ( ! is_array( $sftp_users ) ) {
		return null;
	}

	foreach ( $sftp_users as $sftp_user ) {
		if ( $user_id === (string) $sftp_user->id ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Returns the SFTP user with the given email on the specified site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP user from.
 * @param   string $user_email     The email of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user_by_email( string $site_id_or_url, string $user_email ): ?stdClass {
	$sftp_users = get_pressable_site_sftp_users( $site_id_or_url );
	if ( ! is_array( $sftp_users ) ) {
		return null;
	}

	foreach ( $sftp_users as $sftp_user ) {
		if ( ! empty( $sftp_user->email ) && true === is_case_insensitive_match( $sftp_user->email, $user_email ) ) {
			return $sftp_user;
		}
	}

	return null;
}

/**
 * Rotates the password of the specified SFTP user on the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to reset the SFTP user password on.
 * @param   string $username       The username of the SFTP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_pressable_site_sftp_user_password( string $site_id_or_url, string $username ): ?stdClass {
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url/$username/rotate-password", 'POST' );
}

/**
 * Returns the list of WP users for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the WP users for.
 *
 * @return  stdClass[]|null
 */
function get_pressable_site_wp_users( string $site_id_or_url ): ?array {
	return API_Helper::make_pressable_request( "site-wp-users/$site_id_or_url" );
}

/**
 * Returns the WP user with the given username on the specified site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the WP user from.
 * @param   string $user           The email, username, or numeric ID of the WP user.
 *
 * @return  object|null
 */
function get_pressable_site_wp_user( string $site_id_or_url, string $user ): ?stdClass {
	return API_Helper::make_pressable_request( "site-wp-users/$site_id_or_url/$user" );
}

/**
 * Rotates the password of the specified WP user on the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to reset the WP user password on.
 * @param   string $user           The email, username, or numeric ID of the WP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_pressable_site_wp_user_password( string $site_id_or_url, string $user ): ?stdClass {
	return API_Helper::make_pressable_request( "site-wp-users/$site_id_or_url/$user/rotate-password", 'POST' );
}

/**
 * Returns the list of datacenters available for creating new sites.
 *
 * @return  string[]|null
 */
function get_pressable_datacenters(): ?array {
	static $datacenters = null;

	if ( is_null( $datacenters ) ) {
		$datacenters = API_Helper::make_pressable_request( 'sites/datacenters' );
		if ( is_array( $datacenters ) ) {
			$datacenters = array_combine(
				array_column( $datacenters, 'code' ),
				array_column( $datacenters, 'name' )
			);
		}
	}

	return $datacenters;
}

/**
 * Creates a new Pressable site.
 *
 * @param   string $name       The name of the site to create.
 * @param   string $datacenter The datacenter code to create the site in.
 *
 * @return  stdClass|null
 */
function create_pressable_site( string $name, string $datacenter ): ?stdClass {
	return API_Helper::make_pressable_request(
		'sites',
		'POST',
		array(
			'name'            => $name,
			'datacenter_code' => $datacenter,
		)
	);
}

/**
 * Periodically checks the status of a Pressable site until it's no longer in the given state.
 *
 * @param   string          $site_id_or_url The ID or URL of the Pressable site to check the state of.
 * @param   string          $state          The state to wait for the site to exit.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  stdClass|null
 */
function wait_on_pressable_site_state( string $site_id_or_url, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for Pressable site $site_id_or_url to exit $state state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	do {
		$site = get_pressable_site( $site_id_or_url );

		$progress_bar->advance();
		sleep( 'deploying' === $state ? 3 : 10 );
	} while ( $site && $state === $site->state );

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $site;
}

/**
 * Periodically checks the status of a Pressable site until it accepts SSH connections.
 *
 * @param   string          $site_id_or_url The ID or URL of the Pressable site to check the state of.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  SSH2|null
 */
function wait_on_pressable_site_ssh( string $site_id_or_url, OutputInterface $output ): ?SSH2 {
	$output->writeln( "<comment>Waiting for Pressable site $site_id_or_url to accept SSH connections.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 5; $try <= 24; $try++ ) {
		$ssh_connection = Pressable_Connection_Helper::get_ssh_connection( $site_id_or_url );
		if ( ! \is_null( $ssh_connection ) ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $ssh_connection;
}

// endregion

// region CONSOLE

/**
 * Outputs the related sites in a table format.
 *
 * @param   OutputInterface $output        The output instance.
 * @param   array           $sites         The related sites in tree form. Must be an output of @get_related_pressable_sites.
 * @param   array|null      $headers       The headers of the table.
 * @param   callable|null   $row_generator The function to generate the row data from the tree node.
 *
 * @return  void
 */
function output_pressable_related_sites( OutputInterface $output, array $sites, ?array $headers = null, ?callable $row_generator = null ): void {
	$headers       = $headers ?? array( 'ID', 'Name', 'URL', 'Level', 'Parent ID' );
	$row_generator = is_callable( $row_generator ) ? $row_generator
		: static fn( $node, $level ) => array( $node->id, $node->name, $node->url, $level, $node->clonedFromId ?: '--' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	$rows = array();
	foreach ( $sites as $level => $nodes ) {
		foreach ( $nodes as $node ) {
			$rows[] = $row_generator( $node, $level );
		}

		if ( $level < ( count( $sites ) - 1 ) ) {
			$rows[] = new TableSeparator();
		}
	}

	output_table( $output, $rows, $headers, 'Related Pressable sites' );
}

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
	$id_or_url = get_site_input( $input, $output, $no_input_func, $name );

	$site = get_pressable_site( $id_or_url );
	if ( is_null( $site ) ) {
		$output->writeln( '<error>Invalid site. Aborting!</error>' );
		exit( 1 );
	}

	return $site;
}

/**
 * Grabs a value from the console input and validates it as a valid identifier for a Pressable site SFTP user.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   string          $site_id       The ID of the site to retrieve the SFTP user from.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass
 */
function get_pressable_site_sftp_user_input( InputInterface $input, OutputInterface $output, string $site_id, ?callable $no_input_func = null, string $name = 'user' ): stdClass {
	$uname_or_id_or_email = get_string_input( $input, $output, $no_input_func, $name ); // Pressable SFTP users can also be retrieved by username so no validation is needed.
	$sftp_user            = is_numeric( $uname_or_id_or_email ) ? get_pressable_site_sftp_user_by_id( $site_id, $uname_or_id_or_email )
		: ( get_pressable_site_sftp_user_by_username( $site_id, $uname_or_id_or_email ) ?? get_pressable_site_sftp_user_by_email( $site_id, $uname_or_id_or_email ) );

	if ( is_null( $sftp_user ) ) {
		$output->writeln( '<error>Invalid user. Aborting!</error>' );
		exit( 1 );
	}

	return $sftp_user;
}

// endregion
