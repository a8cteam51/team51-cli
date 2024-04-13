<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Gets a list of all GitHub repositories.
 *
 * @param   array $params An array of parameters to filter the results by.
 *
 * @return  stdClass|null
 */
function get_github_repositories( array $params = array() ): ?stdClass {
	$endpoint = 'repositories';
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	return API_Helper::make_github_request( $endpoint );
}

/**
 * Returns a given GitHub repository by name.
 *
 * @param   string $repository The name of the repository to retrieve.
 *
 * @return  stdClass|null
 */
function get_github_repository( string $repository ): ?stdClass {
	return API_Helper::make_github_request( "repositories/$repository" );
}

/**
 * Sets the topics for a given GitHub repository.
 *
 * @param   string   $repository The name of the repository to set the topics for.
 * @param   string[] $topics     The topics to set for the repository.
 *
 * @return  string[]|null
 */
function set_github_repository_topics( string $repository, array $topics ): ?array {
	return API_Helper::make_github_request( "repositories/$repository/topics", 'PUT', array( 'topics' => $topics ) )?->records;
}

/**
 * Creates a new GitHub repository.
 *
 * @param   string      $name              The name of the repository to create.
 * @param   string|null $type              The type of repository to create aka the name of the template repository to use.
 * @param   string|null $homepage          A URL with more information about the repository.
 * @param   string|null $description       A short, human-friendly description for this project.
 * @param   array|null  $custom_properties The custom properties to set for the repository. Must be an array of key-value pairs and match the properties defined on GitHub.
 *
 * @return  stdClass|null
 */
function create_github_repository( string $name, ?string $type = null, ?string $homepage = null, ?string $description = null, ?array $custom_properties = null ): ?stdClass {
	return API_Helper::make_github_request(
		'repositories',
		'POST',
		array_filter(
			array(
				'name'              => $name,
				'description'       => $description,
				'homepage'          => $homepage,
				'template'          => $type ? "team51-$type-scaffold" : null,
				'custom_properties' => $custom_properties,
			)
		)
	);
}

/**
 * Returns a list of branches for a given GitHub repository.
 *
 * @param   string $repository The name of the repository to retrieve the branches for.
 *
 * @return  stdClass|null
 */
function get_github_repository_branches( string $repository ): ?stdClass {
	return API_Helper::make_github_request( "repositories/$repository/branches" );
}

/**
 * Creates a new branch in a given GitHub repository.
 *
 * @param   string $repository The name of the repository to create the branch in.
 * @param   string $name       The name of the branch to create.
 * @param   string $source     The name of the branch to create the new branch from.
 *
 * @return  stdClass|null
 */
function create_github_repository_branch( string $repository, string $name, string $source ): ?stdClass {
	return API_Helper::make_github_request(
		"repositories/$repository/branches",
		'POST',
		array(
			'name'   => $name,
			'source' => $source,
		)
	);
}

/**
 * Creates a new webhook for a given GitHub repository.
 *
 * @param   string  $repository The name of the repository to create the webhook for.
 * @param   array   $config     The configuration of the webhook.
 * @param   array   $events     The events the webhook should trigger for.
 * @param   boolean $active     Whether the webhook is active.
 *
 * @return  stdClass|null
 */
function create_github_repository_webhook( string $repository, array $config, array $events, bool $active = true ): ?stdClass {
	return API_Helper::make_github_request(
		"repositories/$repository/webhooks",
		'POST',
		array(
			'config' => $config,
			'events' => $events,
			'active' => $active,
		)
	);
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a GitHub repository.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass
 */
function get_github_repository_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'repository' ): stdClass {
	$repository = maybe_get_github_repository_input( $input, $output, $no_input_func, $name );

	if ( is_null( $repository ) ) {
		$output->writeln( '<error>Invalid repository. Aborting!</error>' );
		exit( 1 );
	}

	return $repository;
}

/**
 * Grabs a value from the console input and validates it as a valid identifier for a GitHub repository.
 * If the input is empty, returns null.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  stdClass|null
 */
function maybe_get_github_repository_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'repository' ): ?stdClass {
	$slug = maybe_get_string_input( $input, $output, $name, $no_input_func );
	if ( is_null( $slug ) ) {
		return null;
	}

	$repository = get_github_repository( $slug );
	if ( is_null( $repository ) ) {
		$output->writeln( '<error>Invalid repository. Aborting!</error>' );
		exit( 1 );
	}

	return $repository;
}

// endregion

// region HELPERS

/**
 * Parses a GitHub repository URL into its components.
 *
 * @param   string $url The URL of the repository to parse.
 *
 * @return  stdClass|null
 */
function parse_github_remote_repository_url( string $url ): ?stdClass {
	$components = null;

	if ( str_starts_with( $url, 'git@github.com' ) && str_ends_with( $url, '.git' ) ) {
		$components = (object) array(
			'scheme' => 'ssh',
			'host'   => 'github.com',
			'path'   => explode( ':', $url )[1],
		);

		$components->user = explode( '/', $components->path )[0];
		$components->repo = explode( '/', $components->path )[1];
		$components->repo = substr( $components->repo, 0, -4 );
	} elseif ( str_starts_with( $url, 'https://github.com' ) && str_ends_with( $url, '.git' ) ) {
		$components = (object) parse_url( $url );
		if ( empty( $components->path ) ) {
			return null;
		}

		$components->user = explode( '/', $components->path )[1];
		$components->repo = explode( '/', $components->path )[2];
		$components->repo = substr( $components->repo, 0, -4 );
	}

	return $components;
}

/**
 * Returns the GitHub repository for a given DeployHQ project.
 *
 * @param   string $project The permalink of the project to get the GitHub repository for.
 *
 * @return  stdClass|null
 */
function get_github_repository_from_deployhq_project( string $project ): ?stdClass {
	$deployhq_project = get_deployhq_project( $project );
	if ( is_null( $deployhq_project ) ) {
		return null;
	}

	$gh_repo_url = parse_github_remote_repository_url( $deployhq_project->repository->url );
	if ( is_null( $gh_repo_url ) ) {
		return null;
	}

	return get_github_repository( $gh_repo_url->repo );
}

// endregion
