<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Command\WPCOM_Site_WP_CLI_Command_Run;

// region API

/**
 * Returns the list of Jetpack sites connected to WPCOM.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_jetpack_sites(): ?array {
	return get_wpcom_sites( array( 'type' => 'jetpack' ) );
}

/**
 * Returns the list of Agency sites connected to WPCOM.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_agency_sites(): ?array {
	return get_wpcom_sites( array( 'type' => 'agency' ) );
}

/**
 * Returns the list of sites connected to WPCOM, of any type.
 *
 * @param   array $params Optional. Additional parameters to pass to the request.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/me/sites/
 *
 * @return  stdClass[]|null
 */
function get_wpcom_sites( array $params = array() ): ?array {
	$endpoint = 'sites'; // Equivalent to 'sites?type=all'.
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	$response = API_Helper::make_wpcom_request( $endpoint );
	if ( is_null( $response ) ) {
		return null;
	}

	// Convert the response records to an associative array indexed by the site ID.
	return array_combine(
		array_column(
			$response->records,
			match ( $params['type'] ?? 'all' ) {
				'jetpack' => 'userblog_id', 'agency' => 'id', default => 'ID',
			}
		),
		$response->records
	);
}

/**
 * Returns a WPCOM site object by site URL or WordPress.com site ID.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass|null
 */
function get_wpcom_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url" );
}

/**
 * Returns an agency site object by its ID. These site IDs are not the same as WordPress.com site IDs and are unique to the agency but not globally unique.
 * For provisioned sites, the WPCOM site ID can be found in the `features.wpcom_atomic.blog_id` property of the agency site object.
 *
 * @param   integer $agency_site_id The ID of the agency site to get.
 *
 * @return  stdClass|null
 */
function get_wpcom_agency_site( int $agency_site_id ): ?stdClass {
	return get_wpcom_agency_sites()[ $agency_site_id ] ?? null;
}

/**
 * Returns a WPCOM site transfer status object by site URL or WordPress.com site ID.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 *
 * @return  stdClass|null
 */
function get_wpcom_site_transfer_status( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/transfer-status" );
}

/**
 * Returns a batch of sites by their domains or numeric WPCOM IDs.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$sites = API_Helper::make_wpcom_request( 'sites/batch', 'POST', array( 'sites' => $site_ids_or_urls ) );
	return is_null( $sites ) ? null : parse_batch_response( $sites, $errors );
}

/**
 * Returns the list of sites and their plugins from Jetpack site profiles data.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_jetpack_sites_plugins(): ?array {
	$plugins = API_Helper::make_wpcom_request( 'sites/plugins' );
	return is_null( $plugins ) ? null : (array) $plugins;
}

/**
 * Returns the list of plugins installed on a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_plugins( string $site_id_or_domain ): ?array {
	$plugins = API_Helper::make_wpcom_request( "sites/$site_id_or_domain/plugins" );
	return is_null( $plugins ) ? null : (array) $plugins;
}

/**
 * Returns a batch of plugins installed on given WPCOM sites.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[][]|null
 */
function get_wpcom_site_plugins_batch( array $site_ids_or_urls, ?array &$errors = null ): ?array {
	$sites_plugins = API_Helper::make_wpcom_request( 'sites/batch/plugins', 'POST', array( 'sites' => $site_ids_or_urls ) );
	if ( is_null( $sites_plugins ) ) {
		return null;
	}

	$sites_plugins = parse_batch_response( $sites_plugins, $errors );
	return array_map( static fn( stdClass $site_plugins ) => (array) $site_plugins, $sites_plugins );
}

/**
 * Returns the stats for a WPCOM or Jetpack Connected site.
 *
 * @param   string      $site_id_or_url The site URL or WordPress.com site ID.
 * @param   array|null  $query_params   Optional. Additional parameters to pass to the request.
 * @param   string|null $type           Optional. The type of stats to retrieve.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/sites/$site/stats/summary/
 *
 * @return  stdClass|null
 */
function get_wpcom_site_stats( string $site_id_or_url, ?array $query_params = null, ?string $type = null ): ?stdClass {
	if ( ! empty( $type ) ) {
		$query_params['type'] = $type;
	}

	$endpoint = "site-stats/$site_id_or_url";
	if ( ! empty( $query_params ) ) {
		$endpoint .= '?' . http_build_query( $query_params );
	}

	return API_Helper::make_wpcom_request( $endpoint );
}

/**
 * Returns a batch of stats for given WPCOM sites.
 *
 * @param   array       $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array       $query_params     Optional. Additional parameters to pass to the request.
 * @param   string|null $type             Optional. The type of stats to retrieve.
 * @param   array|null  $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_stats_batch( array $site_ids_or_urls, array $query_params = array(), ?string $type = null, ?array &$errors = null ): ?array {
	$sites_stats = API_Helper::make_wpcom_request(
		'site-stats/batch',
		'POST',
		array_filter(
			array(
				'sites'  => $site_ids_or_urls,
				'params' => $query_params,
				'type'   => $type,
			)
		)
	);
	if ( is_null( $sites_stats ) ) {
		return null;
	}

	return parse_batch_response( $sites_stats, $errors );
}

/**
 * Returns the list of users present on given WPCOM site.
 *
 * @param   string $site_id_or_url The site URL or WordPress.com site ID.
 * @param   array  $params         Optional. Additional parameters to pass to the request.
 *
 * @link    https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/users/
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_users( string $site_id_or_url, array $params = array() ): ?array {
	$endpoint = "site-users/$site_id_or_url";
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	$response = API_Helper::make_wpcom_request( $endpoint );
	if ( null === $response ) {
		return null;
	}

	$users = $response->users ?? ( is_array( $response ) ? ( $response['users'] ?? null ) : null );
	return is_array( $users ) ? $users : null;
}

/**
 * Returns a batch of users present on given WPCOM sites.
 *
 * @param   array      $site_ids_or_urls The list of site domains or numeric WPCOM IDs.
 * @param   array      $params           Optional. Additional parameters to pass to the request.
 * @param   array|null $errors           The list of errors that occurred during the request.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_users_batch( array $site_ids_or_urls, array $params = array(), ?array &$errors = null ): ?array {
	if ( empty( $site_ids_or_urls ) ) {
		return array();
	}

	$sites_users = API_Helper::make_wpcom_request(
		'site-users/batch',
		'POST',
		array(
			'sites'  => $site_ids_or_urls,
			'params' => $params,
		)
	);
	if ( is_null( $sites_users ) ) {
		return null;
	}

	$sites_users = parse_batch_response( $sites_users, $errors );
	return array_map( static fn( stdClass $site_users ) => $site_users->records, $sites_users );
}

/**
 * Returns a WPCOM site user on a given WPCOM site.
 *
 * @param   string $site_id_or_url               The site URL or WordPress.com site ID.
 * @param   string $user_id_or_username_or_email The user ID, username, or email.
 * @param   array  $params                       Optional. Additional parameters to pass to the request.
 *
 * @return  stdClass|null
 */
function get_wpcom_site_user( string $site_id_or_url, string $user_id_or_username_or_email, array $params = array() ): ?stdClass {
	$endpoint = "site-users/$site_id_or_url/$user_id_or_username_or_email";
	if ( ! empty( $params ) ) {
		$endpoint .= '?' . http_build_query( $params );
	}

	return API_Helper::make_wpcom_request( $endpoint );
}

/**
 * Deletes a WPCOM site user from a given WPCOM site.
 *
 * @param   string $site_id_or_url               The site URL or WordPress.com site ID.
 * @param   string $user_id_or_username_or_email The user ID, username, or email.
 * @param   int    $reassign_user_id             The user ID to reassign the user's content to.
 *
 * @return  true|null
 */
function delete_wpcom_site_user( string $site_id_or_url, string $user_id_or_username_or_email, int $reassign_user_id ): true|null {
	return API_Helper::make_wpcom_request( "site-users/$site_id_or_url/$user_id_or_username_or_email?reassign=$reassign_user_id", 'DELETE' );
}

/**
 * Returns the list of stickers associated with a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 *
 * @return  string[]|null
 */
function get_wpcom_site_stickers( string $site_id_or_domain ): ?array {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain" )?->records;
}

/**
 * Adds a given sticker to a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 * @param   string $sticker           The sticker to add.
 *
 * @return  true|null
 */
function add_wpcom_site_sticker( string $site_id_or_domain, string $sticker ): ?true {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain/$sticker", 'POST' );
}

/**
 * Lists sites with a given sticker.
 *
 * @param   string $sticker The sticker to search for.
 *
 * @return string[]|null
 */
function get_wpcom_sites_with_sticker( string $sticker ): ?array {
	return API_Helper::make_wpcom_request( "sites-with-sticker/$sticker" )?->records;
}

/**
 * Removes a given sticker from a given WPCOM site.
 *
 * @param   string $site_id_or_domain The site URL or WordPress.com site ID.
 * @param   string $sticker           The sticker to remove.
 *
 * @return  true|null
 */
function remove_wpcom_site_sticker( string $site_id_or_domain, string $sticker ): ?true {
	return API_Helper::make_wpcom_request( "site-stickers/$site_id_or_domain/$sticker", 'DELETE' );
}

/**
 * Gets the SSH user for a given WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 *
 * @return  string|null
 */
function get_wpcom_site_ssh_username( string $site_id_or_url ): ?string {
	$ssh_users = API_Helper::make_wpcom_request( "site-ssh-users/$site_id_or_url" );
	return $ssh_users->records[0] ?? null;
}

/**
 * Rotates the password of the specified SFTP user on the specified WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to reset the SFTP user password on.
 * @param   string $username       The username of the SFTP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_wpcom_site_sftp_user_password( string $site_id_or_url, string $username ): ?stdClass {
	return API_Helper::make_wpcom_request( "site-ssh-users/$site_id_or_url/$username/rotate-password", 'POST' );
}

/**
 * Rotates the password of the specified WP user on the specified WPCOM site.
 *
 * @param   string $site_id_or_url The ID or URL of the WPCOM site to reset the WP user password on.
 * @param   string $user           The email, username, or numeric ID of the WP user to reset the password for.
 *
 * @return  stdClass|null
 */
function rotate_wpcom_site_wp_user_password( string $site_id_or_url, string $user ): ?stdClass {
	$credentials = null;

	$exit_code = run_wpcom_site_wp_cli_command( $site_id_or_url, "user reset-password $user --skip-email --porcelain", true );
	if ( Command::SUCCESS === $exit_code ) {
		$credentials = (object) array(
			'username' => $user,
			'password' => $GLOBALS['wp_cli_output'],
		);
	}

	return $credentials;
}

/**
 * Creates a new WordPress.com site.
 *
 * @param   string $name The name of the site to create.
 *
 * @return  stdClass|null
 */
function create_wpcom_site( string $name ): ?stdClass {
	return API_Helper::make_wpcom_request( 'sites', 'POST', array( 'name' => $name ) );
}

/**
 * Creates a new WordPress.com staging site.
 *
 * @param string $site_id_or_url The ID or URL of the WordPress.com site to create the staging site for.
 *
 * @return  stdClass|null
 */
function create_wpcom_staging_site( string $site_id_or_url ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/staging-site", method: 'POST' );
}

/**
 * Updates settings of a WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to update.
 * @param   array  $settings       The settings to update.
 *
 * @return  stdClass|null
 */
function update_wpcom_site( string $site_id_or_url, array $settings ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url", 'PUT', array( 'settings' => $settings ) );
}

/**
 * Periodically checks the status of a WordPress.com agency site until it reaches a given state.
 *
 * @param   string          $agency_site_id The ID of the Agency site to check the state of.
 * @param   string          $state          The state to wait for the site to reach.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_agency_site_state( string $agency_site_id, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for WordPress.com agency site $agency_site_id to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 'provisioning' === $state ? 3 : 5; true; $try++ ) {
		$site = get_wpcom_agency_site( $agency_site_id );
		if ( is_null( $site ) || $state === $site->features->wpcom_atomic->state ) {
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
 * Periodically checks on the transfer status of a WordPress.com Atomic site until it reaches a given state.
 *
 * @param   string          $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 * @param   string          $state          The state to wait for the site to reach.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_site_transfer_state( string $site_id_or_url, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for the transfer of WordPress.com site $site_id_or_url to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 5; true; $try++ ) {
		$transfer = get_wpcom_site_transfer_status( $site_id_or_url );
		if ( is_null( $transfer ) || $state === $transfer->status ) {
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $transfer;
}

/**
 * Periodically checks the status of a WordPress.com site until it accepts SSH connections.
 *
 * @param   string          $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  SSH2|null
 */
function wait_on_wpcom_site_ssh( string $site_id_or_url, OutputInterface $output ): ?SSH2 {
	$output->writeln( "<comment>Waiting for WordPress.com site $site_id_or_url to accept SSH connections.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	sleep( 5 ); // Wait a bit before checking the SSH connection. Helps prevent "API calls to this blog have been disabled" errors.
	$progress_bar->advance();
	sleep( 5 );
	$progress_bar->advance();

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until SSH connection is established.
		$ssh_connection = WPCOM_Connection_Helper::get_ssh_connection( $site_id_or_url );
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

/**
 * Periodically checks the status of a WordPress.com site until the Jetpack user token is regenerated.
 *
 * @param   string          $site_id_or_url The ID or URL of the WordPress.com site to check the state of.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  boolean
 */
function wait_until_jetpack_token_regenerated( string $site_id_or_url, OutputInterface $output ): bool {
	$output->writeln( '<fg=magenta;options=bold>Pinging site to regenerate Jetpack user token. This will cause an error and the token will be regenerated.</>' );
	$output->writeln( "<comment>Waiting for WordPress.com site $site_id_or_url to regenerate the Jetpack user token.</comment>" );

	$regenerated  = false;
	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();
	$progress_bar->advance();

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until SSH connection is established.
		$wpcom_site = get_wpcom_site( $site_id_or_url );
		if ( ! is_null( $wpcom_site ) ) {
			$regenerated = true;
			break;
		}

		$progress_bar->advance();
		sleep( $delay );
	}

	$progress_bar->finish();
	$output->writeln( '' ); // Empty line for UX purposes.

	return $regenerated;
}

/**
 * Returns the default OpsOasis webhook receiver URL for WPCOM deployment status updates.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_default_url(): string {
	return getenv( 'TEAM51_WPCOM_DEPLOYMENT_WEBHOOK_URL' ) ?: 'https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/webhooks/v1/wpcom-deployments';
}

/**
 * Returns the default lifecycle events used when creating WPCOM deployment webhooks.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_default_events(): string {
	return 'completed,failed,cancelled';
}

/**
 * Normalizes webhook events input into a string array.
 *
 * @param   string|array|null $events Events as CSV string or an array.
 *
 * @return  string[]
 */
function normalize_wpcom_site_code_deployment_webhook_events( string|array|null $events ): array {
	if ( is_array( $events ) ) {
		return array_values( array_filter( array_map( static fn( mixed $event ) => trim( (string) $event ), $events ) ) );
	}

	$events_csv = trim( (string) $events );
	if ( '' === $events_csv ) {
		$events_csv = get_wpcom_site_code_deployment_webhook_default_events();
	}

	return array_values( array_filter( array_map( 'trim', explode( ',', $events_csv ) ) ) );
}

/**
 * Returns the default OpsOasis endpoint used to persist WPCOM deployment webhook secrets.
 *
 * @return  string
 */
function get_wpcom_site_code_deployment_webhook_secret_sync_endpoint(): string {
	return getenv( 'TEAM51_WPCOM_DEPLOYMENT_WEBHOOK_SECRET_SYNC_ENDPOINT' ) ?: 'webhooks/v1/wpcom-deployments/secrets';
}

/**
 * Creates a new deployment webhook for a WordPress.com code deployment.
 *
 * @param   string      $site_id_or_url The ID or URL of the WordPress.com site to create the webhook for.
 * @param   string      $deployment_id  The ID of the code deployment.
 * @param   string      $url            The webhook receiver URL.
 * @param   string|null $events         Optional. Comma-separated deployment lifecycle events.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $url, ?string $events = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments/$deployment_id/webhooks",
		'POST',
		array_filter(
			array(
				'url'    => $url,
				'events' => $events,
			)
		)
	);
}

/**
 * Returns the list of deployment webhooks for a WordPress.com code deployment.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployment_webhooks( string $site_id_or_url, string $deployment_id ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks" )?->records;
}

/**
 * Updates a deployment webhook for a WordPress.com code deployment.
 *
 * @param   string      $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string      $deployment_id  The ID of the code deployment.
 * @param   string      $webhook_id     The ID of the webhook to update.
 * @param   string      $url            The webhook receiver URL.
 * @param   string|null $events         Optional. Comma-separated deployment lifecycle events.
 *
 * @return  stdClass|null
 */
function update_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id, string $url, ?string $events = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id",
		'PUT',
		array_filter(
			array(
				'url'    => $url,
				'events' => $events,
			)
		)
	);
}

/**
 * Deletes a deployment webhook for a WordPress.com code deployment.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to delete.
 * @param   mixed  $raw_response   Optional. The raw API response payload.
 *
 * @return  true|null
 */
function delete_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id, mixed &$raw_response = null ): ?true {
	$delete_response = API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id", 'DELETE' );
	$raw_response    = $delete_response;

	if ( is_null( $delete_response ) ) {
		return null;
	}

	// WPCOM proxy can return either an empty success body (true) or a success object.
	if ( true === $delete_response ) {
		return true;
	}

	if ( is_object( $delete_response ) ) {
		$success     = $delete_response->success ?? null;
		$status_code = $delete_response->status_code ?? null;
		$error       = $delete_response->error ?? null;

		if ( true === $success && ( is_null( $status_code ) || ( (int) $status_code >= 200 && (int) $status_code < 300 ) ) && is_null( $error ) ) {
			return true;
		}
	}

	return null;
}

/**
 * Triggers a test delivery for a deployment webhook.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to test.
 *
 * @return  stdClass|null
 */
function test_wpcom_site_code_deployment_webhook( string $site_id_or_url, string $deployment_id, string $webhook_id ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id/test", 'POST' );
}

/**
 * Returns the deliveries of a deployment webhook.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site.
 * @param   string $deployment_id  The ID of the code deployment.
 * @param   string $webhook_id     The ID of the webhook to inspect.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployment_webhook_deliveries( string $site_id_or_url, string $deployment_id, string $webhook_id ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$deployment_id/webhooks/$webhook_id/deliveries" )?->records;
}

/**
 * Attempts to persist a deployment webhook secret in OpsOasis so incoming webhook signatures can be verified.
 *
 * @param   string $site_id       The WPCOM site ID.
 * @param   string $deployment_id The code deployment ID.
 * @param   string $webhook_id    The webhook ID.
 * @param   string $url           The webhook receiver URL.
 * @param   array  $events        The webhook events subscribed for delivery.
 * @param   string $secret        The webhook secret returned by WordPress.com.
 *
 * @return  true|null
 */
function sync_wpcom_site_code_deployment_webhook_secret( string $site_id, string $deployment_id, string $webhook_id, string $url, array $events, string $secret ): ?true {
	$sync_response = API_Helper::make_opsoasis_request(
		get_wpcom_site_code_deployment_webhook_secret_sync_endpoint(),
		'POST',
		array(
			'site_id'       => is_numeric( $site_id ) ? (int) $site_id : $site_id,
			'deployment_id' => $deployment_id,
			'webhook_id'    => $webhook_id,
			'url'           => $url,
			'events'        => array_values( $events ),
			'secret'        => $secret,
		)
	);

	// OpsOasis may return either an empty success body (true) or a success object.
	return is_null( $sync_response ) ? null : true;
}

/**
 * Extracts the deployment webhook object from a WPCOM deployment webhook API response.
 *
 * @param   stdClass $response The webhook response payload.
 *
 * @return  stdClass
 */
function get_wpcom_site_code_deployment_webhook_from_response( stdClass $response ): stdClass {
	return $response->webhook ?? $response;
}

/**
 * Extracts a deployment webhook secret from a WPCOM deployment webhook API response.
 *
 * @param   stdClass $response The webhook response payload.
 *
 * @return  string|null
 */
function get_wpcom_site_code_deployment_webhook_secret_from_response( stdClass $response ): ?string {
	$webhook = get_wpcom_site_code_deployment_webhook_from_response( $response );
	return $webhook->secret ?? $response->secret ?? null;
}

/**
 * Connects a WordPress.com site to a GitHub repository for code deployments.
 *
 * @param   string     $site_id_or_url         The ID or URL of the WordPress.com site to connect to the GitHub repository.
 * @param   string     $external_repository_id The ID of the external repository.
 * @param   string     $branch_name            The branch to deploy.
 * @param   string     $target_dir             The target directory to deploy to.
 * @param   array|null $params                 Optional. Additional parameters to pass to the request.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment( string $site_id_or_url, string $external_repository_id, string $branch_name, string $target_dir, ?array $params = null ): ?stdClass {
	return API_Helper::make_wpcom_request(
		"sites/$site_id_or_url/code-deployments",
		'POST',
		array(
			'external_repository_id' => $external_repository_id,
			'branch_name'            => $branch_name,
			'target_dir'             => $target_dir,
			'is_automated'           => true,
		) + (array) $params
	);
}

/**
 * Triggers a code deployment run for a given code deployment.
 *
 * @param   string $site_id_or_url     The ID or URL of the WordPress.com site to create the run for.
 * @param   string $code_deployment_id The ID of the code deployment to create the run for.
 *
 * @return  stdClass|null
 */
function create_wpcom_site_code_deployment_run( string $site_id_or_url, string $code_deployment_id ): ?stdClass {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments/$code_deployment_id/runs", 'POST' );
}

/**
 * Returns the list of code deployments for a given WordPress.com site.
 *
 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to get the repositories for.
 *
 * @return  stdClass[]|null
 */
function get_wpcom_site_code_deployments( string $site_id_or_url ): ?array {
	return API_Helper::make_wpcom_request( "sites/$site_id_or_url/code-deployments" )?->records;
}

/**
 * Periodically checks the status of a WordPress.com site until it accepts SSH connections.
 *
 * @param   stdClass        $code_deployment The code deployment object.
 * @param   string          $state           The state to wait for the site to reach.
 * @param   OutputInterface $output          The output instance.
 *
 * @return  stdClass|null
 */
function wait_until_wpcom_code_deployment_run_state( stdClass $code_deployment, string $state, OutputInterface $output ): ?stdClass {
	$output->writeln( "<comment>Waiting for Deployment $code_deployment->id to reach the `$state` state.</comment>" );

	$progress_bar = new ProgressBar( $output );
	$progress_bar->start();

	for ( $try = 0, $delay = 5; true; $try++ ) { // Infinite loop until the deployment is completed.
		$code_deployments = get_wpcom_site_code_deployments( $code_deployment->blog_id );

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

/**
 * Runs a WP-CLI command on the specified WordPress.com site.
 *
 * @param   string  $site_id_or_url The ID or URL of the site to run the WP-CLI command on.
 * @param   string  $wp_cli_command The WP-CLI command to run.
 * @param   boolean $skip_output    Whether to skip outputting the response to the console.
 * @param   boolean $interactive    Whether to run the command interactively.
 *
 * @return  integer
 * @noinspection PhpDocMissingThrowsInspection
 */
function run_wpcom_site_wp_cli_command( string $site_id_or_url, string $wp_cli_command, bool $skip_output = false, bool $interactive = false ): int {
	/* @noinspection PhpUnhandledExceptionInspection */
	return run_app_command(
		WPCOM_Site_WP_CLI_Command_Run::getDefaultName(),
		array(
			'site'           => $site_id_or_url,
			'wp-cli-command' => $wp_cli_command,
			'--skip-output'  => $skip_output,
		),
		$interactive
	);
}

// endregion

// region CONSOLE

/**
 * Grabs a value from the console input and validates it as a valid identifier for a WPCOM site.
 *
 * @param   InputInterface $input         The console input.
 * @param   callable|null  $no_input_func The function to call if no input is given.
 * @param   string         $name          The name of the value to grab.
 *
 * @throws  InvalidArgumentException If the provided site does not resolve to a valid WPCOM site.
 *
 * @return  stdClass
 */
function get_wpcom_site_input( InputInterface $input, ?callable $no_input_func = null, string $name = 'site' ): stdClass {
	$site_id_or_url = get_site_input( $input, $no_input_func, $name );

	$wpcom_site = get_wpcom_site( $site_id_or_url );
	if ( is_null( $wpcom_site ) ) {
		throw new InvalidArgumentException( 'Invalid WPCOM site.' );
	}

	return $wpcom_site;
}

/**
 * Outputs a table of WPCOM sites that failed to be processed.
 *
 * @param   OutputInterface $output       The console output.
 * @param   stdClass[]      $errors       The list of errors indexed by the site ID.
 * @param   stdClass[]|null $sites        The WPCOM sites that were processed.
 * @param   string|null     $header_title Optional. The title to use for the table header.
 *
 * @return  void
 */
function maybe_output_wpcom_failed_sites_table( OutputInterface $output, array $errors, ?array $sites = null, ?string $header_title = null ): void {
	if ( empty( $errors ) ) {
		return;
	}

	$sites        = $sites ?? get_wpcom_sites( array( 'fields' => 'ID,URL' ) );
	$header_title = $header_title ?? 'Sites that failed to be processed';

	output_table(
		$output,
		array_map(
			static function ( string $site_id, stdClass $wp_error ) use ( $sites ) {
				$site = $sites[ $site_id ];
				return array(
					$site->ID ?? $site->userblog_id ?? $site->blog_id,
					$site->URL ?? $site->siteurl ?? $site->site_url, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					encode_json_content( $wp_error->errors ),
				);
			},
			array_keys( $errors ),
			$errors
		),
		array( 'WPCOM Site ID', 'Site URL', 'Error' ),
		$header_title
	);
}
// endregion
