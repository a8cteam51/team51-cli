<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// region API

/**
 * Gets a list of all GitHub repositories.
 *
 * @return  stdClass[]
 */
function get_github_repositories( array $params = array() ): array {
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
 * Creates a new GitHub repository.
 *
 * @param   string      $name        The name of the repository to create.
 * @param   string|null $type        The type of repository to create aka the name of the template repository to use.
 * @param   string|null $description A short, human-friendly description for this project.
 *
 * @return  stdClass|null
 */
function create_github_repository( string $name, ?string $type = null, ?string $description = null ): ?stdClass {
	return API_Helper::make_github_request(
		'repositories',
		'POST',
		array_filter(
			array(
				'name'        => $name,
				'description' => $description,
				'template'    => $type ? "team51-$type-scaffold" : null,
			)
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
	$slug = get_string_input( $input, $output, $name, $no_input_func );

	$repository = get_github_repository( $slug );
	if ( is_null( $repository ) ) {
		$output->writeln( '<error>Invalid repository. Aborting!</error>' );
		exit( 1 );
	}

	return $repository;
}

// endregion
