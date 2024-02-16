<?php

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

	if ( null === $templates ) {
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
