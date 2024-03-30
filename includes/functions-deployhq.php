<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Returns the list of DeployHQ projects.
 *
 * @return  stdClass[]|null
 */
function get_deployhq_projects(): ?array {
	return API_Helper::make_deployhq_request( 'projects' )?->records;
}

/**
 * Returns a single DeployHQ project by its permalink.
 *
 * @param   string $project The permalink of the project to retrieve.
 *
 * @return  stdClass|null
 */
function get_deployhq_project( string $project ): ?stdClass {
	return API_Helper::make_deployhq_request( "projects/$project" );
}

/**
 * Returns the list of DeployHQ project zones.
 *
 * @link    https://www.deployhq.com/support/api/projects/create-a-new-project#supported-parameters
 *
 * @return  string[]
 */
function get_deployhq_zones(): array {
	return array(
		3 => 'Europe (UK)',
		6 => 'North America (East)',
		9 => 'North America (West)',
	);
}

/**
 * Returns a list of DeployHQ templates.
 *
 * @return  string[]|null
 */
function get_deployhq_templates(): ?array {
	static $templates = null;

	if ( is_null( $templates ) ) {
		$templates = API_Helper::make_deployhq_request( 'templates' )?->records;
		if ( is_array( $templates ) ) {
			$templates = array_combine(
				array_column( $templates, 'permalink' ),
				array_column( $templates, 'name' )
			);
		}
	}

	return $templates;
}

/**
 * Creates a new project on DeployHQ.
 *
 * @param   string  $name    The name of the new project.
 * @param   integer $zone_id The ID of the zone to create the project in.
 * @param   array   $params  Additional parameters to include in the request.
 *
 * @return  stdClass|null
 */
function create_deployhq_project( string $name, int $zone_id, array $params = array() ): ?stdClass {
	return API_Helper::make_deployhq_request(
		'projects',
		'POST',
		array(
			'name'    => $name,
			'zone_id' => $zone_id,
		) + $params
	);
}

/**
 * Rotates the private key of a DeployHQ project.
 * The key is set on the central server and this simply triggers an update on DeployHQ's end.
 *
 * @param string $project The permalink of the project to rotate the private key for.
 *
 * @return stdClass|null
 */
function rotate_deployhq_project_private_key( string $project ): ?stdClass {
	return API_Helper::make_deployhq_request( "projects/$project/rotate-private-key", 'POST' );
}

/**
 * Updates the connected GitHub repository of an existing DeployHQ's project
 *
 * @param   string $project    The permalink of the project to update.
 * @param   string $repository The SSH URL of the repository to connect.
 *
 * @return  stdClass|null
 */
function update_deployhq_project_repository( string $project, string $repository ): ?stdClass {
	return API_Helper::make_deployhq_request(
		"projects/$project/repository",
		'POST',
		array(
			'scm_type' => 'git',
			'url'      => $repository,
			'branch'   => 'trunk',
		)
	);
}

/**
 * Returns the list of servers configured for a DeployHQ project.
 *
 * @param   string $project The permalink of the project to retrieve servers for.
 *
 * @return  stdClass[]|null
 */
function get_deployhq_project_servers( string $project ): ?array {
	return API_Helper::make_deployhq_request( "projects/$project/servers" )?->records;
}

/**
 * Returns a single server configured for a DeployHQ project.
 *
 * @param   string $project The permalink of the project to retrieve the server for.
 * @param   string $server  The ID of the server to retrieve.
 *
 * @return  stdClass|null
 */
function get_deployhq_project_server( string $project, string $server ): ?stdClass {
	return API_Helper::make_deployhq_request( "projects/$project/servers/$server" );
}

/**
 * Creates a new server for a DeployHQ project.
 *
 * @param   string $project The permalink of the project to create the server for.
 * @param   string $name    The name of the new server.
 * @param   array  $params  The parameters to include in the request.
 *
 * @return  stdClass|null
 */
function create_deployhq_project_server( string $project, string $name, array $params ): ?stdClass {
	return API_Helper::make_deployhq_request(
		"projects/$project/servers",
		'POST',
		array( 'name' => $name ) + $params
	);
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a DeployHQ project.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass
 */
function get_deployhq_project_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'project' ): stdClass {
	$permalink = get_string_input( $input, $output, $name, $no_input_func );

	$project = get_deployhq_project( $permalink );
	if ( is_null( $project ) ) {
		$output->writeln( '<error>Invalid project. Aborting!</error>' );
		exit( 1 );
	}

	return $project;
}

// endregion
