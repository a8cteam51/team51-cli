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
 * Command to triage Jetpack connection issues across multiple sites and generate a detailed report.
 *
 * @since 1.0.0
 */
#[AsCommand( name: 'jetpack:connection-triage' )]
final class Jetpack_Site_Connection_Triage extends Command {
	use AutocompleteTrait;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * List of known staging domains to check against.
	 *
	 * @var array
	 */
	private array $staging_domains = array(
		'mystagingwebsite.com',
		'jurassic.ninja',
	);

	/**
	 * Configures the command definition, arguments and help documentation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setDescription( 'Triages Jetpack connection issues for sites and generates a report.' )
			->setHelp(
				<<<'EOT'
Use this command to identify and analyze sites with Jetpack connection problems. It will check connection status and generate a CSV report with details about problematic connections.

You can either provide a CSV of specific sites to check, or run it without arguments to check all connected Jetpack sites.

<info>Examples:</info>
  Check all Jetpack sites:
    $ team51 jetpack:connection-triage

  Check specific sites from a CSV:
    $ team51 jetpack:connection-triage /path/to/sites.csv

<info>CSV Format:</info>
The input CSV should have 2 columns:
- Column 1: Blog ID (numeric WordPress.com blog ID)
- Column 2: Site URL (full URL including protocol)

<info>Generated Report:</info>
The command generates a CSV report with the following columns:
- Site ID: The WordPress.com blog ID
- Site URL: The site's URL
- Status: HTTP status code from connection check
- New Blog ID: If site has a new connection, the new blog ID
- Notes: Detailed findings and recommended actions
EOT
			);

		$this->addArgument(
			'csv-path',
			InputArgument::OPTIONAL,
			'A path to a CSV with 2 columns, blog ID and url. If not provided, will check all connected Jetpack sites.'
		);
	}

	/**
	 * Initializes the command by fetching the list of Jetpack sites if no CSV is provided.
	 *
	 * @since 1.0.0
	 * @throws \RuntimeException When unable to fetch Jetpack sites.
	 *
	 * @param InputInterface  $input  Command input interface.
	 * @param OutputInterface $output Command output interface.
	 * @return void
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		if ( ! $input->getArgument( 'csv-path' ) ) {
			$this->sites = get_wpcom_jetpack_sites();
			$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );
		}
	}

	/**
	 * Executes the connection triage command.
	 *
	 * Processes either a provided CSV of sites or all Jetpack sites, checking their
	 * connection status and generating a detailed report of findings.
	 *
	 * @since 1.0.0
	 * @throws \RuntimeException When CSV file cannot be read or processed.
	 * @throws \Exception When unable to create or write to output CSV file.
	 *
	 * @param InputInterface  $input  Command input interface.
	 * @param OutputInterface $output Command output interface.
	 *
	 * @return int Command::SUCCESS on success, Command::FAILURE on failure
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$broken_site_data = array();
		$csv_path         = $input->getArgument( 'csv-path' );

		// Get the list of sites to check, either from CSV or from Jetpack
		if ( $csv_path ) {
			$output->writeln( "<fg=magenta;options=bold>Reading sites from {$csv_path}...</>" );

			if ( ! file_exists( $csv_path ) ) {
				$output->writeln( "<fg=red;options=bold>CSV file not found at {$csv_path}.</>" );
				return Command::FAILURE;
			}

			// Read the CSV. Column 1: Site ID, Column 2: Site URL
			$csv = file_get_contents( $csv_path );
			$csv = explode( "\n", $csv );
			unset( $csv[0] ); // Remove header row
			$sites_to_check = array();
			foreach ( $csv as $row ) {
				$line = str_getcsv( $row );
				if ( isset( $line[0], $line[1] ) ) {
					$sites_to_check[] = array(
						'blog_id' => $line[0],
						'url'     => $line[1],
					);
				}
			}
		} else {
			$output->writeln( '<fg=magenta;options=bold>Checking all Jetpack sites with connection issues...</>' );
			$output->writeln( '<comment>This initial check may take a few minutes, please be patient...</comment>' );

			// Process sites in chunks to avoid memory issues
			$sites_to_check = array();
			foreach ( array_chunk( $this->sites, 100, true ) as $sites_chunk ) {
				$modules = get_jetpack_site_modules_batch( \array_column( $sites_chunk, 'userblog_id' ), $errors );

				foreach ( $errors as $site_id => $error ) {
					$site             = $this->sites[ $site_id ];
					$sites_to_check[] = array(
						'blog_id' => $site->userblog_id,
						'url'     => $site->siteurl,
					);
				}
			}
		}

		// Process each site through our connection check logic
		foreach ( $sites_to_check as $site ) {
			$site_id          = $site['blog_id'];
			$site_url         = $site['url'];
			$domain           = parse_url( $site_url, PHP_URL_HOST );
			$jetpack_endpoint = $site_url . '/wp-json/jetpack/v4';
			$notes            = '';
			$status_code      = '';
			$new_blog_id      = '';

			$output->writeln( 'Checking ' . $site_url . '...' );

			// fetch
			$options     = array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 60,
					'ignore_errors' => true,
				),
			);
			$context     = stream_context_create( $options );
			$result      = @file_get_contents( $site_url, false, $context );
			$headers     = parse_http_headers( http_get_last_response_headers() );
			$status_code = $headers['http_code'];

			if ( 410 === $status_code && $this->is_staging_domain( $site_url ) ) {
				// Probably deactivated
				$notes = 'Site is probably deactivated';
				// Check Pressable?
			} elseif ( 404 === $status_code && $this->is_staging_domain( $site_url ) ) {
				// probably deleted or Jetpack not installed. Next step is to curl the homepage
				$notes = '404, check site';
				if ( strpos( $result, 'Domain not found' ) !== false ) {
					$notes = 'Domain not found, probably deleted from Pressable';
				}
			} elseif ( 500 === $status_code ) {
				$notes = 'Check site, internal server error';
			} elseif ( 200 === $status_code && ! $this->is_staging_domain( $site_url ) ) {
				$notes = 'Site either moved hosts or has a new JP connection. Check DARC and NA: https://mc.a8c.com/tools/reportcard/domain/?domain=' . $domain . ' and https://wordpress.com/wp-admin/network/sites.php?s=' . $site_url;
			} elseif ( 200 === $status_code && $this->is_staging_domain( $site_url ) ) {
				// Check WPCOM API for site information
				$wpcom_api_url = 'https://public-api.wordpress.com/rest/v1.1/sites/' . rawurlencode( $domain );
				$wpcom_result  = @file_get_contents( $wpcom_api_url, false, $context );
				$wpcom_headers = http_get_last_response_headers();
				$wpcom_status  = $wpcom_headers ? parse_http_headers( $wpcom_headers )['http_code'] : '';

				if ( '400' === $wpcom_status ) {
					$notes = 'Site is not connected to WordPress.com. If site loads, it may have moved hosts.';
				} else {
					$wpcom_data = json_decode( $wpcom_result, true );

					if ( $wpcom_data && isset( $wpcom_data['ID'] ) ) {
						if ( (int) $wpcom_data['ID'] !== (int) $site_id ) {
							$notes       = 'Site has a new Jetpack connection. Old ID: ' . $site_id . ', New ID: ' . $wpcom_data['ID'];
							$new_blog_id = $wpcom_data['ID'];
						} else {
							$notes = 'Site connection appears valid. Original error may have been intermittent.';
						}
					} else {
						$notes = 'Jetpack is probably disconnected. Log in and reconnect.';
					}
				}
			}

			$broken_site_data[] = array(
				'site_id'     => $site_id,
				'site_url'    => $site_url,
				'status'      => $status_code,
				'new_blog_id' => $new_blog_id,
				'notes'       => $notes,
			);
		}

		// Write results to CSV
		$output->writeln( '<info>Making the CSV...<info>' );
		$timestamp = gmdate( 'Y-m-d-H-i-s' );
		$filename  = 'jp-connection-' . $timestamp . '.csv';
		$filepath  = getcwd() . DIRECTORY_SEPARATOR . $filename;
		$fp        = fopen( $filepath, 'w' );
		fputcsv( $fp, array( 'Site ID', 'Site URL', 'Status', 'New Blog ID', 'Notes' ), ',', '"', '\\' );
		foreach ( $broken_site_data as $fields ) {
			fputcsv( $fp, $fields, ',', '"', '\\' );
		}
		fclose( $fp );

		$output->writeln( sprintf( '<info>Done, CSV saved to: %s</info>', $filepath ) );

		return Command::SUCCESS;
	}

	/**
	 * Checks if a given URL belongs to a known staging domain.
	 *
	 * @since 1.0.0
	 * @param string $url The URL to check.
	 * @return bool True if URL matches a staging domain, false otherwise.
	 */
	private function is_staging_domain( string $url ): bool {
		foreach ( $this->staging_domains as $staging_domain ) {
			if ( false !== strpos( $url, $staging_domain ) ) {
				return true;
			}
		}
		return false;
	}

	// endregion
}
