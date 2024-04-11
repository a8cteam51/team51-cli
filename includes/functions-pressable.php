<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use WPCOMSpecialProjects\CLI\Command\DeployHQ_Project_Create;
use WPCOMSpecialProjects\CLI\Command\DeployHQ_Project_Server_Create;
use WPCOMSpecialProjects\CLI\Command\Pressable_Site_WP_CLI_Command_Run;

// region API

/**
 * Returns the list of Pressable collaborators.
 *
 * @return  stdClass[]|null
 */
function get_pressable_collaborators(): ?array {
	return API_Helper::make_pressable_request( 'collaborators' )?->records;
}

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

	return API_Helper::make_pressable_request( $endpoint )?->records;
}

/**
 * Returns the root/production site of the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the site to retrieve the root site for.
 *
 * @return  stdClass|null
 */
function get_pressable_root_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_pressable_request( "sites/$site_id_or_url/root" );
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
	$related_sites = API_Helper::make_pressable_request( "sites/$site_id_or_url/related?find_root=$find_root" );
	if ( ! is_array( $related_sites ) ) {
		return null;
	}

	$node_generator = is_callable( $node_generator ) ? $node_generator : static fn( object $site ) => $site;
	foreach ( $related_sites as $level => $sites ) {
		$related_sites[ $level ] = (array) $sites;
		foreach ( $related_sites[ $level ] as $id => $site ) {
			$related_sites[ $level ][ $id ] = $node_generator( $site );
		}
	}

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
	$site_id_or_url = is_numeric( $site_id_or_url ) ? (string) $site_id_or_url : urlencode( $site_id_or_url );
	return API_Helper::make_pressable_request( "sites/$site_id_or_url" );
}

/**
 * Converts the specified Pressable site from staging to production or vice versa.
 *
 * @param   string $site_id_or_url The ID or URL of the site to convert.
 *
 * @return  stdClass|null
 */
function convert_pressable_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_pressable_request( "sites/$site_id_or_url/convert", 'POST' );
}

/**
 * Returns the list of notes for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the notes for.
 * @param   array  $params         An array of parameters to filter the results by.
 *
 * @return  stdClass[]|null
 */
function get_pressable_site_notes( string $site_id_or_url, array $params = array() ): ?array {
	$endpoint = "site-notes/$site_id_or_url";
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	return API_Helper::make_pressable_request( $endpoint )?->records;
}

/**
 * Creates a new note on the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to create the note on.
 * @param   string $subject        The subject of the note.
 * @param   string $content        The content of the note.
 *
 * @return  stdClass|null
 */
function create_pressable_site_note( string $site_id_or_url, string $subject, string $content ): ?stdClass {
	return API_Helper::make_pressable_request(
		"site-notes/$site_id_or_url",
		'POST',
		array(
			'subject' => $subject,
			'content' => $content,
		)
	);
}

/**
 * Returns the DeployHQ project and server configuration for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the DeployHQ configuration for.
 *
 * @return  stdClass|null
 */
function get_pressable_site_deployhq_config( string $site_id_or_url ): ?stdClass {
	$config = API_Helper::make_pressable_request( "sites/$site_id_or_url/deployhq" );
	if ( is_null( $config ) ) {
		return null;
	}

	if ( isset( $config->server->errors ) ) {
		$config->server = null; // The server configuration can be a WP_Error object.
	}

	return $config;
}

/**
 * Updates the DeployHQ project for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to update the DeployHQ project for.
 * @param   string $project        The new DeployHQ project.
 *
 * @return  stdClass|null
 */
function update_pressable_site_deployhq_project( string $site_id_or_url, string $project ): ?stdClass {
	return API_Helper::make_pressable_request( "sites/$site_id_or_url/deployhq", 'POST', array( 'project' => $project ) );
}

/**
 * Updates the DeployHQ project server for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to update the DeployHQ server for.
 * @param   string $project        The new DeployHQ project permalink.
 * @param   string $server         The new DeployHQ server identifier.
 *
 * @return  stdClass|null
 */
function update_pressable_site_deployhq_server( string $site_id_or_url, string $project, string $server ): ?stdClass {
	return API_Helper::make_pressable_request(
		"sites/$site_id_or_url/deployhq",
		'POST',
		array(
			'project' => $project,
			'server'  => $server,
		)
	);
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
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url" )?->records;
}

/**
 * Returns the list of domains for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the domains for.
 *
 * @return  stdClass[]|null
 */
function get_pressable_site_domains( string $site_id_or_url ): ?array {
	return API_Helper::make_pressable_request( "site-domains/$site_id_or_url" )?->records;
}

/**
 * Returns the primary domain for the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the primary domain for.
 *
 * @return  stdClass|null
 */
function get_pressable_site_primary_domain( string $site_id_or_url ): ?stdClass {
	$primary_domain = API_Helper::make_pressable_request( "site-domains/$site_id_or_url/primary" );
	return true === $primary_domain ? null : $primary_domain;
}

/**
 * Sets a given domain as the primary domain of a given Pressable site.
 *
 * @param   string $site_id_or_url The site ID.
 * @param   string $domain_id      The domain ID.
 *
 * @link    https://my.pressable.com/documentation/api/v1#set-primary-domain
 *
 * @return  stdClass|null
 */
function set_pressable_site_primary_domain( string $site_id_or_url, string $domain_id ): ?stdClass {
	return API_Helper::make_pressable_request( "site-domains/$site_id_or_url/$domain_id", 'PUT' );
}

/**
 * Adds a new domain on the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to create the domain on.
 * @param   string $domain         The domain to create.
 *
 * @return  stdClass[]|null
 */
function add_pressable_site_domain( string $site_id_or_url, string $domain ): ?array {
	return API_Helper::make_pressable_request( "site-domains/$site_id_or_url", 'POST', array( 'name' => $domain ) )?->records;
}

/**
 * Returns the SFTP user who is the owner of the specified Pressable site.
 *
 * @param   string $site_id_or_url The ID or URL of the Pressable site to retrieve the SFTP owner for.
 *
 * @return  stdClass|null
 */
function get_pressable_site_sftp_owner( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url/owner" );
}

/**
 * Returns the SFTP user with the given username, email, or ID on the specified site.
 *
 * @param   string $site_id_or_url       The ID or URL of the Pressable site to retrieve the SFTP user from.
 * @param   string $uname_or_email_or_id The username, email, or numeric ID of the site SFTP user.
 *
 * @return  object|null
 */
function get_pressable_site_sftp_user( string $site_id_or_url, string $uname_or_email_or_id ): ?stdClass {
	return API_Helper::make_pressable_request( "site-sftp-users/$site_id_or_url/$uname_or_email_or_id" );
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
	return API_Helper::make_pressable_request( "site-wp-users/$site_id_or_url" )?->records;
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
 * Deletes the specified collaborator from the specified Pressable site.
 *
 * @param   string  $site_id_or_url The ID or URL of the Pressable site to delete the collaborator from.
 * @param   string  $collaborator   The email, username, or numeric ID of the collaborator to delete.
 * @param   boolean $delete_wp_user Whether to delete the WP user associated with the collaborator.
 *
 * @return  true|null
 */
function delete_pressable_site_collaborator( string $site_id_or_url, string $collaborator, bool $delete_wp_user = false ): true|null {
	return API_Helper::make_pressable_request( "site-collaborators/$site_id_or_url/$collaborator", 'DELETE', array( 'delete_wp_user' => $delete_wp_user ) );
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
 * Creates a new Pressable site clone.
 *
 * @param   string      $site_id_or_url The ID or URL of the site to clone.
 * @param   string      $name           The name of the site to create.
 * @param   string|null $datacenter     The datacenter code to create the site in.
 * @param   boolean     $staging        Whether to create the site as a staging site.
 *
 * @return  stdClass|null
 */
function create_pressable_site_clone( string $site_id_or_url, string $name, ?string $datacenter = null, bool $staging = true ): ?stdClass {
	return API_Helper::make_pressable_request(
		'sites',
		'POST',
		array(
			'name'            => $name,
			'datacenter_code' => $datacenter,
			'staging'         => $staging,
			'template'        => $site_id_or_url,
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

	for ( $try = 0, $delay = 'deploying' === $state ? 3 : 10; true; $try++ ) {
		$site = get_pressable_site( $site_id_or_url );
		if ( is_null( $site ) || $state !== $site->state ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

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

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until SSH connection is established.
		$ssh_connection = Pressable_Connection_Helper::get_ssh_connection( $site_id_or_url );
		if ( ! is_null( $ssh_connection ) ) {
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
	$uname_or_id_or_email = get_string_input( $input, $output, $name, $no_input_func );
	$sftp_user            = get_pressable_site_sftp_user( $site_id, $uname_or_id_or_email );

	if ( is_null( $sftp_user ) ) {
		$output->writeln( '<error>Invalid SFTP user. Aborting!</error>' );
		exit( 1 );
	}

	return $sftp_user;
}

// endregion

// region WRAPPERS

/**
 * Runs a WP-CLI command on the specified Pressable site.
 *
 * @param   string  $site_id_or_url The ID or URL of the site to run the WP-CLI command on.
 * @param   string  $wp_cli_command The WP-CLI command to run.
 * @param   boolean $interactive    Whether to run the command interactively.
 *
 * @return  integer
 * @noinspection PhpDocMissingThrowsInspection
 */
function run_pressable_site_wp_cli_command( string $site_id_or_url, string $wp_cli_command, bool $interactive = false ): int {
	/* @noinspection PhpUnhandledExceptionInspection */
	return run_app_command(
		Pressable_Site_WP_CLI_Command_Run::getDefaultName(),
		array(
			'site'           => $site_id_or_url,
			'wp-cli-command' => $wp_cli_command,
		),
		$interactive
	);
}

/**
 * Creates a new DeployHQ project for the specified Pressable site.
 *
 * @param   stdClass $pressable_site        The Pressable site to create the DeployHQ project for.
 * @param   stdClass $github_repository     The name of the GitHub repository to connect to the project.
 * @param   string   $deployhq_project_name The name of the DeployHQ project to create.
 *
 * @return  stdClass|null
 * @noinspection PhpDocMissingThrowsInspection
 */
function create_deployhq_project_for_pressable_site( stdClass $pressable_site, stdClass $github_repository, string $deployhq_project_name ): ?stdClass {
	$deployhq_project = null;

	$listener = static function ( GenericEvent $event ) use ( &$deployhq_project, $pressable_site, &$listener ) {
		global $team51_cli_output;
		$deployhq_project = $event->getSubject();

		$note = update_pressable_site_deployhq_project( $pressable_site->id, $deployhq_project->permalink );
		if ( is_null( $note ) ) {
			$team51_cli_output->writeln( '<error>Failed to set the Pressable site note for the DeployHQ project permalink.</error>' );
		}

		remove_event_listener( 'deployhq.project.created', $listener );
	};
	add_event_listener( 'deployhq.project.created', $listener );

	/* @noinspection PhpUnhandledExceptionInspection */
	run_app_command(
		DeployHQ_Project_Create::getDefaultName(),
		array(
			'name'          => $deployhq_project_name,
			'--zone-id'     => match ( $pressable_site->datacenterCode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'AMS' => 3,
				'BUR' => 9,
				default => 6,
			},
			'--template-id' => 'pressable-included-integration',
			'--repository'  => $github_repository->name,
		),
	);

	return $deployhq_project;
}

/**
 * Creates a new DeployHQ project server for the specified Pressable site.
 *
 * @param   stdClass $pressable_site       The Pressable site to create the DeployHQ project server for.
 * @param   stdClass $deployhq_project     The DeployHQ project to create the server for.
 * @param   string   $deployhq_server_name The name of the DeployHQ server to create.
 * @param   string   $github_branch        The GitHub branch to deploy to the server.
 * @param   string   $github_branch_source The GitHub branch to create the deployment from, if applicable.
 *
 * @return  stdClass|null
 * @noinspection PhpDocMissingThrowsInspection
 */
function create_deployhq_project_server_for_pressable_site( stdClass $pressable_site, stdClass $deployhq_project, string $deployhq_server_name, string $github_branch, string $github_branch_source = 'trunk' ): ?stdClass {
	$deployhq_project_server = null;

	$listener = static function ( GenericEvent $event ) use ( &$deployhq_project_server, $deployhq_project, $pressable_site, &$listener ) {
		global $team51_cli_output;
		$deployhq_project_server = $event->getSubject();

		$note = update_pressable_site_deployhq_server( $pressable_site->id, $deployhq_project->permalink, $deployhq_project_server->identifier );
		if ( is_null( $note ) ) {
			$team51_cli_output->writeln( '<error>Failed to set the Pressable site note for the DeployHQ project server ID.</error>' );
		}

		remove_event_listener( 'deployhq.project.server.created', $listener );
	};
	add_event_listener( 'deployhq.project.server.created', $listener );

	/* @noinspection PhpUnhandledExceptionInspection */
	run_app_command(
		DeployHQ_Project_Server_Create::getDefaultName(),
		array(
			'project'         => $deployhq_project->permalink,
			'site'            => $pressable_site->id,
			'name'            => $deployhq_server_name,
			'--branch'        => $github_branch,
			'--branch-source' => $github_branch_source,
		),
	);

	return $deployhq_project_server;
}

// endregion

// region HELPERS

/**
 * Returns the root name of a given Pressable site. The root name is defined as the site name without
 * the "-production" or "-development" or any other suffix.
 *
 * @param   string $site_id_or_url The ID or URL of the site to retrieve the root site name for.
 *
 * @return  string|null
 */
function get_pressable_site_root_name( string $site_id_or_url ): ?string {
	$site = get_pressable_root_site( $site_id_or_url );
	if ( is_null( $site ) ) {
		return null;
	}

	return str_replace( '-production', '', $site->name );
}

// endregion
