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
	return API_Helper::make_deployhq_request( 'projects' );
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
		$templates = API_Helper::make_deployhq_request( 'templates' );
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