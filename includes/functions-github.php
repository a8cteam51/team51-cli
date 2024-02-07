<?php

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
