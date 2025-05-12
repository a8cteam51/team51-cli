<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * Checks the health of connections to 3rd party services.
 */
#[AsCommand( name: 'connection:health-check', description: 'Checks the health of connections to 3rd party services' )]
final class Connection_Health_Check extends Command {

	// region FIELDS AND CONSTANTS

	/**
	 * Services to check.
	 *
	 * @var array
	 */
	private array $services = array(
		'opsoasis'  => 'OpsOasis API Server',
		'pressable' => 'Pressable API',
		'github'    => 'GitHub API',
		'deployhq'  => 'DeployHQ API',
		'wpcom'     => 'WordPress.com API',
	);

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setHelp( 'Use this command to check the health of connections to 3rd party services.' );

		$this->addOption( 'service', 's', InputOption::VALUE_REQUIRED, 'Specific service to check. Options: ' . implode( ', ', array_keys( $this->services ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$service_option = maybe_get_string_input( $input, 'service' );

		$services_to_check = array();
		if ( $service_option && isset( $this->services[ $service_option ] ) ) {
			$services_to_check = array( $service_option => $this->services[ $service_option ] );
		} else {
			$services_to_check = $this->services;
		}

		$output->writeln( '<fg=magenta;options=bold>Checking connection health to 3rd party services...</>' );

		$results = array();

		foreach ( $services_to_check as $service_key => $service_name ) {
			$output->write( "Checking $service_name... " );

			$status = $this->check_service_connection( $service_key );

			if ( $status['success'] ) {
				$output->writeln( '<fg=green>OK</>' );
			} else {
				$output->writeln( '<fg=red>FAILED</>' );
			}

			$results[] = array(
				'service' => $service_name,
				'status'  => $status['success'] ? '<fg=green>OK</>' : '<fg=red>FAILED</>',
				'message' => $status['message'],
			);
		}

		// Output results table
		$table = new Table( $output );
		$table->setHeaders( array( 'Service', 'Status', 'Details' ) );
		$table->setRows( $results );
		$table->render();

		// Check if any service failed
		$has_failure = false;
		foreach ( $results as $result ) {
			if ( strpos( $result['status'], 'FAILED' ) !== false ) {
				$has_failure = true;
				break;
			}
		}

		return $has_failure ? Command::FAILURE : Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Checks the connection to a specific service.
	 *
	 * @param   string $service The service to check.
	 *
	 * @return  array An array with 'success' boolean and 'message' string.
	 */
	private function check_service_connection( string $service ): array {
		switch ( $service ) {
			case 'opsoasis':
				return $this->check_opsoasis_api();
			case 'pressable':
				return $this->check_pressable();

			case 'github':
				return $this->check_github();

			case 'deployhq':
				return $this->check_deployhq();

			case 'wpcom':
				return $this->check_wpcom();

			default:
				return array(
					'success' => false,
					'message' => 'Unknown service',
				);
		}
	}

	/**
	 * Checks the Pressable API connection.
	 *
	 * @return  array Connection status.
	 */
	private function check_pressable(): array {
		// Hitting the `account` endpoint might be faster, but needs to added in Opsoasis.
		$datacenters = get_pressable_datacenters();

		if ( $datacenters && ! empty( $datacenters ) ) {
			return array(
				'success' => true,
				'message' => 'Successfully retrieved ' . count( $datacenters ) . ' datacenters',
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to retrieve datacenters from Pressable API',
		);
	}

	/**
	 * Checks the GitHub API connection.
	 *
	 * @return  array Connection status.
	 */
	private function check_github(): array {
		// Check for a specific repository instead of fetching all repositories
		$repository = get_github_repository( 'team51-cli' );

		if ( null !== $repository ) {
			return array(
				'success' => true,
				'message' => 'Successfully connected to GitHub API',
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to connect to GitHub API',
		);
	}

	/**
	 * Checks the DeployHQ API connection.
	 *
	 * @return  array Connection status.
	 */
	private function check_deployhq(): array {
		$projects = get_deployhq_projects();

		if ( $projects && ! empty( $projects ) ) {
			return array(
				'success' => true,
				'message' => 'Successfully retrieved ' . count( $projects ) . ' projects',
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to retrieve projects from DeployHQ API',
		);
	}

	/**
	 * Checks the WordPress.com API connection.
	 *
	 * @return  array Connection status.
	 */
	private function check_wpcom(): array {
		$site = get_wpcom_site( '2' );

		if ( null !== $site ) {
			return array(
				'success' => true,
				'message' => 'Successfully connected to WordPress.com API',
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to connect to WordPress.com API',
		);
	}

	/**
	 * Checks the OpsOasis REST API server connection.
	 *
	 * @return  array Connection status.
	 */
	private function check_opsoasis_api(): array {
		$api_root_url = 'https://opsoasis.wpspecialprojects.com/wp-json/';

		$result = get_remote_content(
			$api_root_url,
			array( 'Accept' => 'application/json' )
		);

		if ( $result &&
			isset( $result['headers']['http_code'] ) &&
			str_starts_with( (string) $result['headers']['http_code'], '2' ) &&
			$result['body'] ) {

			$api_data = json_decode( $result['body'], true );

			if ( $api_data &&
				isset( $api_data['namespaces'] ) &&
				is_array( $api_data['namespaces'] ) ) {

				$wpcomsp_namespaces = array_filter(
					$api_data['namespaces'],
					function ( $wpcomsp_namespace ) {
						return strpos( $wpcomsp_namespace, 'wpcomsp/' ) === 0;
					}
				);

				if ( ! empty( $wpcomsp_namespaces ) ) {
					return array(
						'success' => true,
						'message' => 'Successfully connected to OpsOasis. Found ' . count( $wpcomsp_namespaces ) . ' wpcomsp endpoints',
					);
				}
			}

			return array(
				'success' => false,
				'message' => 'Connected to OpsOasis but wpcomsp endpoints were not found',
			);
		}

		return array(
			'success' => false,
			'message' => 'Failed to connect to OpsOasis API Server',
		);
	}

	// endregion
}
