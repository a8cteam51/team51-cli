<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Triages the connection status of a given list of sites
 */
#[AsCommand( name: 'jetpack:connection-triage' )]
final class Jetpack_Site_Connection_triage extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to fetch the information for.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists the status of Jetpack modules on a given site.' )
			->setHelp( 'Use this command to show a list of Jetpack modules on a given site together with their status. This command requires that the given site has an active Jetpack connection to WPCOM.' );

		$this->addArgument( 'csv-path', InputArgument::REQUIRED, 'A path to a CSV with 2 columns, blog ID and url' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing Jetpack modules information for {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		// Read in the CSV
		$csv_path = $input->getArgument( 'csv-path' );

		// For each row in the CSV, check the connection status
		if ( ! file_exists( $csv_path ) ) {
			$output->writeln( "<fg=red;options=bold>CSV file not found at {$csv_path}.</>" );
			return Command::FAILURE;
		} else {
			// Read the CSV. Column 1: Site ID, Column 2: Site URL, Column 3: Connection Status
			$csv = file_get_contents( $csv_path );
			$csv = explode( "\n", $csv );
			unset( $csv[0] );
			$broken_site_data = array();
			foreach ( $csv as $row ) {
				$line             = str_getcsv( $row );
				$site_id          = $line[0];
				$site_url         = $line[1];
				$jetpack_endpoint = $site_url . '/wp-json/jetpack/v4';
				$notes            = '';
				$status_code	  = '';

				$output->writeln( 'Checking ' . $site_url . '...');

				//fetch
				$options = array(
					'http' => array(
	
						'method'        => 'GET',
						'timeout'       => 60,
						'ignore_errors' => true,
					)
				);
				$context = stream_context_create( $options );
				$result = @file_get_contents( $site_url, false, $context );
				$headers = parse_http_headers( $http_response_header );
				$status_code = $headers['http_code'];

				if ( 410 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) !== false ) {
					// Probably deactivated
					$notes = 'Site is probably deactivated';
					// Check Pressable?
				} elseif ( 404 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) !== false ) {
					// probably deleted or Jetpack not installed. Next step is to curl the homepage
					//$notes = $result['body'];
					$notes = "404, check site";
					if ( strpos( $result, 'Domain not found' ) !== false ) {
						$notes = 'Domain not found, probably deleted from Pressable';
					}
				} elseif ( 500 === $status_code ) {
					//$notes = $result['body'];
					$notes = "Check site, internal server error";
				} elseif ( 200 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) == false ) {
					$notes = 'Site either moved hosts or has a new JP connection. Check DARC and NA: https://mc.a8c.com/tools/reportcard/domain/?domain=' . $site_url . ' and https://wordpress.com/wp-admin/network/sites.php?s=' . $site_url;
				}
				// wp-json/jetpack/v4/connection/data

				$broken_site_data[] = array(
					'site_id'  => $site_id,
					'site_url' => $site_url,
					'status'   => $status_code,
					'notes'    => $notes,
				);

				//var_dump( $broken_site_data );
			}
			// Write a new CSV from the array
			$output->writeln( '<info>Making the CSV...<info>' );
			$timestamp = date( 'Y-m-d-H-i-s' );
			$fp        = fopen( 'jp-connection-' . $timestamp . '.csv', 'w' );
			fputcsv( $fp, array( 'Site ID', 'Site URL', 'Status', 'Notes' ) );
			foreach ( $broken_site_data as $fields ) {
				fputcsv( $fp, $fields );
			}
			fclose( $fp );

			$output->writeln( '<info>Done, CSV saved to your current working directory: jp-connection-' . $timestamp . '.csv<info>' );

		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	// endregion
}
