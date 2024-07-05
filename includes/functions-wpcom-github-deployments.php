<?php

// region API
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Returns the WPCOM installation of a GitHub repository.
 *
 * @param   stdClass $repository A GitHub repository object from the API.
 *
 * @return  integer|null
 */
function get_wpcom_installation_for_repository( stdClass $repository ): ?int {
	$installations = get_github_installations();

	if ( ! is_array( $installations ) ) {
		return null;
	}

	foreach ( $installations as $installation ) {
		if ( ! is_object( $installation ) ) {
			continue;
		}

		if ( $installation->account_name === $repository->owner->login ) {
			return $installation->external_id;
		}
	}

	return null;
}

/**
 * Returns the list of GitHub installations from the WordPress.com API.
 * This assumes we connected our GitHub account to WordPress.com GitHub app by following
 * this guide: https://developer.wordpress.com/docs/developer-tools/github-deployments/
 *
 * @return  stdClass[]|null
 */
function get_github_installations(): ?array {
	return API_Helper::make_wpcom_request(
		'hosting/github/installations'
	);
}

/**
 * Returns the list of GitHub repositories from the WordPress.com API.
 *
 * @param   integer $site_id The ID of the site to get the repositories for.
 *
 * @return  stdClass[]|null
 */
function get_code_deployments( int $site_id ): ?array {
	return API_Helper::make_wpcom_request(
		"sites/$site_id/hosting/code-deployments"
	);
}

/**
 * Creates a new code deployment for a given site.
 *
 * @param   string $site_id The ID of the site to create the deployment for.
 * @param   array  $params  Additional parameters to include in the request.
 *                      - external_repository_id: The external repository ID.
 *                      - branch_name: The branch to deploy.
 *                      - target_dir: The target directory to deploy to.
 *                      - installation_id: The installation ID.
 *                      - is_automated: Whether to deploy on a code push to the given branch or manually.
 *                      - workflow_path: The path to the workflow file.
 *
 * @return  stdClass|null
 */
function create_code_deployment( string $site_id, array $params = array() ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id/hosting/code-deployments",
		'POST',
		array( 'params' => $params )
	);
}

/**
 * Creates a new code deployment run for a given code deployment.
 * This will trigger the deployment process.
 *
 * @param   string  $site_id            The ID of the site to create the deployment run for.
 * @param   integer $code_deployment_id The ID of the code deployment to create the run for.
 *
 * @return  stdClass|null
 */
function create_code_deployment_run( string $site_id, int $code_deployment_id ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id/hosting/code-deployments/$code_deployment_id/runs",
		'POST'
	);
}

/**
 * Periodically checks the status of a WordPress.com site until it accepts SSH connections.
 *
 * @param   object          $code_deployment The code deployment object.
 * @param   string          $state           The state to wait for the site to reach.
 * @param   OutputInterface $output          The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_code_deployment_run_state( object $code_deployment, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for Deployment $code_deployment->id to be in the $state state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until the deployment is completed.
		$code_deployments = get_code_deployments( $code_deployment->blog_id );

		// Currently we only check the first deployment
		$deployment = reset( $code_deployments );

		if ( $deployment->current_deployment_run->code_deployment_id === $code_deployment->id && $deployment->current_deployment_run->status === $state ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $deployment;
}


// endregion