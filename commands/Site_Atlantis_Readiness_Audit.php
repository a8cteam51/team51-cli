<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Audits sites for Atlantis installation readiness from a CSV file.
 *
 * This is a read-only command that evaluates each site's readiness for Atlantis
 * installation without making any changes. Checks include: site existence, SSH
 * access, PHP version, Atlantis installation status, legacy plugins on the site,
 * linked GitHub repository, and legacy plugins in the repository.
 *
 * Examples:
 *   # Audit sites from a CSV file
 *   team51 site:atlantis-readiness-audit --sites=my-sites.csv
 *
 *   # Audit with custom export path
 *   team51 site:atlantis-readiness-audit --sites=my-sites.csv --export=results.csv
 *
 * CSV File Format:
 *   The input CSV file should have at minimum the following columns: Site/Site Name, URL/Domain, Host
 *   Example:
 *     "Site Name",Domain,"Site ID",Host
 *     "Example Site",https://example.mystagingwebsite.com,123456,Pressable
 */
#[AsCommand( name: 'site:atlantis-readiness-audit' )]
final class Site_Atlantis_Readiness_Audit extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Path to the input CSV file.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	/**
	 * Path to the export CSV file.
	 *
	 * @var string|null
	 */
	private ?string $export_csv_path = null;

	/**
	 * File handle for CSV export.
	 *
	 * @var resource|null
	 */
	private $export_stream = null;

	/**
	 * Legacy plugin prefixes to check.
	 *
	 * @var array
	 */
	private array $legacy_plugins = array(
		'colophon',
		'plugin-autoupdate-filter',
		'team51-tracking',
	);

	/**
	 * Branch priority for repository checks.
	 *
	 * @var array
	 */
	private array $branch_priority = array( 'trunk', 'master', 'main' );

	/**
	 * Minimum PHP version for Atlantis.
	 *
	 * @var string
	 */
	private string $min_php_version = '8.3';

	/**
	 * Output CSV column headers.
	 *
	 * @var array
	 */
	private array $csv_headers = array(
		'Site',
		'URL',
		'Host (CSV)',
		'Host (Resolved)',
		'Site Exists',
		'SSH Access',
		'PHP Version',
		'PHP Ready',
		'Atlantis Status',
		'Legacy Plugins (Site)',
		'Repository',
		'Repo Branch',
		'Legacy Plugins (Repo)',
		'Notes',
	);

	/**
	 * Collected audit results for the summary table.
	 *
	 * @var array
	 */
	private array $results = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Audits sites for Atlantis installation readiness from a CSV file.' )
			->setHelp( 'Reads a CSV file of sites and performs read-only checks to determine each site\'s readiness for Atlantis installation. No modifications are made.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to the input CSV file (required). Format: Site,URL,Host columns.' )
			->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'Path to the output CSV file. Defaults to atlantis-readiness-audit-{timestamp}.csv.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->sites_csv_path = $input->getOption( 'sites' );
		if ( empty( $this->sites_csv_path ) ) {
			$output->writeln( '<error>The --sites option is required. Provide a path to a CSV file.</error>' );
			exit( 1 );
		}

		if ( ! file_exists( $this->sites_csv_path ) ) {
			$output->writeln( "<error>CSV file not found: {$this->sites_csv_path}</error>" );
			exit( 1 );
		}

		$export_option = $input->getOption( 'export' );
		if ( empty( $export_option ) ) {
			$this->export_csv_path = 'atlantis-readiness-audit-' . gmdate( 'Y-m-d-His' ) . '.csv';
		} else {
			$this->export_csv_path = $export_option;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Atlantis Readiness Audit</>' );
		$output->writeln( '' );

		// Read the input CSV.
		$csv_data = $this->read_csv( $this->sites_csv_path );
		if ( empty( $csv_data ) ) {
			$output->writeln( '<error>No valid sites found in CSV file.</error>' );
			return Command::FAILURE;
		}

		// Validate required columns.
		$first_row = reset( $csv_data );
		foreach ( array( 'Site', 'URL', 'Host' ) as $column ) {
			if ( ! isset( $first_row[ $column ] ) ) {
				$output->writeln( "<error>CSV file is missing required column: {$column}</error>" );
				return Command::FAILURE;
			}
		}

		// Check for existing export CSV to enable resume.
		$already_processed = array();
		$resuming          = false;
		if ( file_exists( $this->export_csv_path ) ) {
			$existing_handle = fopen( $this->export_csv_path, 'r' );
			if ( false !== $existing_handle ) {
				$existing_headers = fgetcsv( $existing_handle );
				if ( false !== $existing_headers ) {
					$url_index = array_search( 'URL', $existing_headers, true );
					if ( false !== $url_index ) {
						while ( ( $existing_row = fgetcsv( $existing_handle ) ) !== false ) {
							if ( isset( $existing_row[ $url_index ] ) && ! empty( $existing_row[ $url_index ] ) ) {
								$already_processed[ $existing_row[ $url_index ] ] = true;
							}
						}
					}
				}
				fclose( $existing_handle );
			}

			if ( ! empty( $already_processed ) ) {
				$resuming = true;
				$output->writeln( "<info>Resuming: found " . count( $already_processed ) . " already-processed sites in {$this->export_csv_path}</info>" );
				$output->writeln( '' );
			}
		}

		// Open export CSV - append if resuming, otherwise write headers.
		if ( $resuming ) {
			$this->export_stream = fopen( $this->export_csv_path, 'a' );
		} else {
			$this->export_stream = get_file_handle( $this->export_csv_path, 'csv' );
			fputcsv( $this->export_stream, $this->csv_headers );
		}

		$total   = count( $csv_data );
		$counter = 0;
		$skipped = 0;

		foreach ( $csv_data as $site_data ) {
			++$counter;
			$site_name = $site_data['Site'] ?? 'Unknown';
			$site_url  = $site_data['URL'] ?? '';
			$csv_host  = strtolower( trim( $site_data['Host'] ?? '' ) );

			// Skip "other" hosts entirely.
			if ( 'other' === $csv_host ) {
				$output->writeln( "<comment>[{$counter}/{$total}] Skipping: {$site_name} (host: other)</comment>" );
				++$skipped;
				continue;
			}

			// Validate required fields.
			if ( empty( $site_url ) || empty( $csv_host ) ) {
				$output->writeln( "<comment>[{$counter}/{$total}] Skipping: {$site_name} (missing URL or Host)</comment>" );
				++$skipped;
				continue;
			}

			// Skip already-processed sites when resuming.
			if ( isset( $already_processed[ $site_url ] ) ) {
				$output->writeln( "<comment>[{$counter}/{$total}] Already processed: {$site_name} ({$site_url})</comment>" );
				++$skipped;
				continue;
			}

			$output->writeln( "<info>[{$counter}/{$total}] Auditing: {$site_name} ({$site_url}) [{$csv_host}]</info>" );

			// Initialize result row with defaults.
			$row = array(
				'Site'                  => $site_name,
				'URL'                   => $site_url,
				'Host (CSV)'            => $csv_host,
				'Host (Resolved)'       => $csv_host,
				'Site Exists'           => 'No',
				'SSH Access'            => 'No',
				'PHP Version'           => '',
				'PHP Ready'             => '',
				'Atlantis Status'       => '',
				'Legacy Plugins (Site)' => '',
				'Repository'            => '',
				'Repo Branch'           => '',
				'Legacy Plugins (Repo)' => '',
				'Notes'                 => '',
			);

			// Resolve the actual host type.
			$host = $csv_host;
			if ( 'simple' === $csv_host ) {
				$resolution           = $this->resolve_simple_host( $site_url, $output );
				$host                 = $resolution['host'];
				$row['Host (Resolved)'] = $host;

				if ( ! empty( $resolution['redirect_url'] ) ) {
					$row['Notes'] = 'Redirect: ' . $resolution['redirect_url'];
				}

				if ( 'simple' === $host ) {
					$row['Notes'] = trim( ( $row['Notes'] ? $row['Notes'] . '; ' : '' ) . 'Confirmed Simple site (actionbar found)' );
					$this->write_result_row( $row, $output );
					continue;
				}
			}

			if ( ! in_array( $host, array( 'pressable', 'atomic' ), true ) ) {
				$row['Notes'] = "Unresolvable host: {$host}";
				$this->write_result_row( $row, $output );
				continue;
			}

			// Use redirect URL for lookups if host was reclassified from Simple.
			$lookup_url = $site_url;
			if ( 'simple' === $csv_host && isset( $resolution ) && ! empty( $resolution['redirect_url'] ) ) {
				$lookup_url = $resolution['redirect_url'];
			}

			// Run all checks.
			$this->audit_site( $row, $host, $lookup_url, $output );

			// Write result row.
			$this->write_result_row( $row, $output );
		}

		if ( $skipped > 0 ) {
			$skip_reason = $resuming ? 'other host, missing data, or already processed' : 'other host or missing data';
			$output->writeln( "<comment>Skipped {$skipped} sites ({$skip_reason})</comment>" );
		}

		// Close export CSV.
		fclose( $this->export_stream );
		$output->writeln( '' );
		$output->writeln( "<info>Results exported to: {$this->export_csv_path}</info>" );

		// Output summary.
		$this->output_summary( $output );

		return Command::SUCCESS;
	}

	// endregion

	// region AUDIT CHECKS

	/**
	 * Orchestrates all audit checks for a single site.
	 *
	 * @param   array           $row      The result row (passed by reference).
	 * @param   string          $host     The hosting provider.
	 * @param   string          $site_url The site URL.
	 * @param   OutputInterface $output   The output object.
	 *
	 * @return  void
	 */
	private function audit_site( array &$row, string $host, string $site_url, OutputInterface $output ): void {
		// Preserve any existing notes (e.g., redirect URL from host resolution).
		$notes = array();
		if ( ! empty( $row['Notes'] ) ) {
			$notes[] = $row['Notes'];
		}

		// --- Check 1: Site Exists ---
		$site = null;
		try {
			$site = $this->check_site_exists( $host, $site_url );
			$row['Site Exists'] = $site ? 'Yes' : 'No';
			if ( ! $site ) {
				$notes[] = 'Site not found via API';
			}
		} catch ( \Exception $e ) {
			$row['Site Exists'] = 'Error';
			$notes[] = 'Site check error: ' . $e->getMessage();
		}

		if ( ! $site ) {
			$row['Notes'] = implode( '; ', $notes );
			return;
		}

		// --- Host verification: Ensure "atomic" sites are truly Atomic before WP-CLI calls ---
		if ( 'atomic' === $host && empty( $site->is_wpcom_atomic ) ) {
			$output->writeln( '  <comment>Site is not actually Atomic (is_wpcom_atomic=false), trying Pressable...</comment>' );

			$domain         = $this->extract_domain( $site_url );
			$pressable_site = get_pressable_site( $domain );

			if ( $pressable_site ) {
				$host                   = 'pressable';
				$site                   = $pressable_site;
				$row['Host (Resolved)'] = 'pressable';
				$notes[]                = 'Reclassified: listed as atomic, actually pressable';
				$output->writeln( '  <info>Resolved as Pressable</info>' );
			} else {
				$notes[]                = 'Listed as atomic but is_wpcom_atomic=false and not found on Pressable';
				$row['Notes']           = implode( '; ', $notes );
				$row['SSH Access']      = 'N/A';
				$row['PHP Version']     = 'Unknown';
				$row['PHP Ready']       = 'Unknown';
				$row['Atlantis Status'] = 'N/A';
				$output->writeln( '  <error>Cannot determine actual host, skipping SSH-dependent checks</error>' );
				// Still try repo checks below, so don't return yet.
			}
		}

		// Only run SSH-dependent checks if we have a valid host type.
		$skip_ssh_checks = ! in_array( $host, array( 'pressable', 'atomic' ), true );

		$site_identifier = $skip_ssh_checks ? '' : ( ( 'atomic' === $host ) ? $site->ID : $site->id );

		// --- Check 2: SSH Access ---
		if ( ! $skip_ssh_checks ) {
			try {
				$ssh_ok = $this->check_ssh_access( $host, $site_identifier );
				$row['SSH Access'] = $ssh_ok ? 'Yes' : 'No';
				if ( ! $ssh_ok ) {
					$notes[] = 'SSH access failed';
				}
			} catch ( \Exception $e ) {
				$row['SSH Access'] = 'Error';
				$notes[] = 'SSH check error: ' . $e->getMessage();
			}
		}

		// --- Check 3: PHP Version ---
		if ( ! $skip_ssh_checks ) {
			try {
				$php_version = $this->check_php_version( $host, $site, $site_identifier );
				$row['PHP Version'] = $php_version ?? 'Unknown';
				if ( $php_version ) {
					$row['PHP Ready'] = version_compare( $php_version, $this->min_php_version, '>=' ) ? 'Yes' : 'No';
				} else {
					$row['PHP Ready'] = 'Unknown';
				}
			} catch ( \Exception $e ) {
				$row['PHP Version'] = 'Error';
				$row['PHP Ready']   = 'Error';
				$notes[]            = 'PHP version check error: ' . $e->getMessage();
			}
		}

		// --- Checks 4 & 5: Atlantis Status and Legacy Plugins on Site ---
		if ( ! $skip_ssh_checks && 'Yes' === $row['SSH Access'] ) {
			try {
				$plugins = $this->get_site_plugin_list( $host, $site_identifier );

				if ( null !== $plugins ) {
					$row['Atlantis Status']       = $this->check_atlantis_status( $plugins );
					$row['Legacy Plugins (Site)'] = $this->check_legacy_plugins_on_site( $plugins );
				} else {
					$row['Atlantis Status'] = 'Error (could not get plugin list)';
					$notes[]                = 'Could not retrieve plugin list';
				}
			} catch ( \Exception $e ) {
				$row['Atlantis Status'] = 'Error';
				$notes[]                = 'Plugin list error: ' . $e->getMessage();
			}
		} elseif ( ! $skip_ssh_checks ) {
			$row['Atlantis Status']       = 'N/A (no SSH)';
			$row['Legacy Plugins (Site)'] = 'N/A (no SSH)';
		}

		// --- Check 6: Linked GitHub Repository ---
		$repo_name = null;
		try {
			$repo_name        = $this->check_linked_repository( $host, $site );
			$row['Repository'] = $repo_name ?? 'none';
		} catch ( \Exception $e ) {
			$row['Repository'] = 'Error';
			$notes[]           = 'Repo check error: ' . $e->getMessage();
		}

		// --- Check 7: Legacy Plugins in Repository ---
		if ( $repo_name ) {
			try {
				$repo_check                  = $this->check_legacy_plugins_in_repo( $repo_name );
				$row['Repo Branch']           = $repo_check['branch'] ?? 'none found';
				$row['Legacy Plugins (Repo)'] = $repo_check['plugins'] ?? '';
			} catch ( \Exception $e ) {
				$row['Repo Branch']           = 'Error';
				$row['Legacy Plugins (Repo)'] = 'Error';
				$notes[]                      = 'Repo plugin check error: ' . $e->getMessage();
			}
		}

		$row['Notes'] = implode( '; ', $notes );
	}

	/**
	 * Check if the site exists via the platform API.
	 *
	 * @param   string $host     The hosting provider.
	 * @param   string $site_url The site URL.
	 *
	 * @return  \stdClass|null The site object, or null if not found.
	 */
	private function check_site_exists( string $host, string $site_url ): ?\stdClass {
		$domain = $this->extract_domain( $site_url );

		if ( 'pressable' === $host ) {
			return get_pressable_site( $domain );
		}

		return get_wpcom_site( $domain );
	}

	/**
	 * Check if SSH access is available by running a simple WP-CLI command.
	 *
	 * @param   string $host            The hosting provider.
	 * @param   string $site_identifier The site ID.
	 *
	 * @return  bool True if SSH access works.
	 */
	private function check_ssh_access( string $host, string $site_identifier ): bool {
		$command = "eval 'echo \"ok\";'";

		if ( 'pressable' === $host ) {
			$result = run_pressable_site_wp_cli_command( $site_identifier, $command, true );
		} else {
			$result = run_wpcom_site_wp_cli_command( $site_identifier, $command, true );
		}

		$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

		if ( str_contains( $wp_cli_output, 'Fatal error:' ) || str_contains( $wp_cli_output, 'critical error on this website' ) ) {
			return false;
		}

		return Command::SUCCESS === $result && str_contains( $wp_cli_output, 'ok' );
	}

	/**
	 * Check the PHP version of the site.
	 *
	 * @param   string    $host            The hosting provider.
	 * @param   \stdClass $site            The site object.
	 * @param   string    $site_identifier The site ID.
	 *
	 * @return  string|null The PHP version string, or null if unknown.
	 */
	private function check_php_version( string $host, \stdClass $site, string $site_identifier ): ?string {
		// For Pressable, try the API property first.
		if ( 'pressable' === $host && isset( $site->phpVersion ) ) {
			return $site->phpVersion;
		}

		$command = "eval 'echo phpversion();'";

		if ( 'pressable' === $host ) {
			run_pressable_site_wp_cli_command( $site_identifier, $command, true );
		} else {
			run_wpcom_site_wp_cli_command( $site_identifier, $command, true );
		}

		$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';
		if ( preg_match( '/(\d+\.\d+(\.\d+)?)/', $wp_cli_output, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Get the list of installed plugins from the site via WP-CLI.
	 *
	 * @param   string $host            The hosting provider.
	 * @param   string $site_identifier The site ID.
	 *
	 * @return  array|null Array of plugin data, or null on error.
	 */
	private function get_site_plugin_list( string $host, string $site_identifier ): ?array {
		$command = 'plugin list --format=json';

		if ( 'pressable' === $host ) {
			run_pressable_site_wp_cli_command( $site_identifier, $command, true );
		} else {
			run_wpcom_site_wp_cli_command( $site_identifier, $command, true );
		}

		$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

		if ( str_contains( $wp_cli_output, 'Fatal error:' ) || str_contains( $wp_cli_output, 'critical error on this website' ) ) {
			return null;
		}

		if ( preg_match( '/\[.*\]/s', $wp_cli_output, $matches ) ) {
			$plugins = json_decode( $matches[0], true );
			return is_array( $plugins ) ? $plugins : null;
		}

		return null;
	}

	/**
	 * Check the Atlantis plugin status from a plugin list.
	 *
	 * @param   array $plugins The plugin list from WP-CLI.
	 *
	 * @return  string The Atlantis status (active, inactive, must-use, not-installed, etc.).
	 */
	private function check_atlantis_status( array $plugins ): string {
		foreach ( $plugins as $plugin ) {
			if ( isset( $plugin['name'] ) && 'a8csp-atlantis' === $plugin['name'] ) {
				return $plugin['status'] ?? 'unknown';
			}
		}

		return 'not-installed';
	}

	/**
	 * Check for legacy plugins on the site from a plugin list.
	 *
	 * @param   array $plugins The plugin list from WP-CLI.
	 *
	 * @return  string Comma-separated list of found legacy plugins with status, or empty string.
	 */
	private function check_legacy_plugins_on_site( array $plugins ): string {
		$found = array();

		foreach ( $plugins as $plugin ) {
			if ( ! isset( $plugin['name'] ) ) {
				continue;
			}

			$plugin_name_lower = strtolower( $plugin['name'] );

			foreach ( $this->legacy_plugins as $legacy_prefix ) {
				if ( $plugin_name_lower === $legacy_prefix || str_starts_with( $plugin_name_lower, $legacy_prefix . '-' ) ) {
					$status  = $plugin['status'] ?? 'unknown';
					$found[] = "{$plugin['name']} ({$status})";
					break;
				}
			}
		}

		return implode( ', ', $found );
	}

	/**
	 * Check for a linked GitHub repository.
	 *
	 * @param   string    $host The hosting provider.
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string|null The repository name, or null if none found.
	 */
	private function check_linked_repository( string $host, \stdClass $site ): ?string {
		if ( 'pressable' === $host ) {
			$deployhq_config = get_pressable_site_deployhq_config( $site->id );
			if ( ! $deployhq_config || ! isset( $deployhq_config->project ) ) {
				return null;
			}

			$gh_repo = get_github_repository_from_deployhq_project( $deployhq_config->project->permalink );
			return $gh_repo->name ?? null;
		}

		// Atomic: WPCOM Code Deployments.
		$code_deployments = get_wpcom_site_code_deployments( $site->ID );
		if ( empty( $code_deployments ) ) {
			return null;
		}

		$repository_name = $code_deployments[0]->repository_name ?? null;
		if ( ! $repository_name ) {
			return null;
		}

		// Extract repo slug from "owner/repo" format.
		$parts     = explode( '/', $repository_name );
		$repo_slug = $parts[1] ?? $parts[0];

		$gh_repo = get_github_repository( $repo_slug );
		return $gh_repo->name ?? null;
	}

	/**
	 * Check for legacy plugins in a GitHub repository.
	 *
	 * Tries branches in priority order (trunk, master, main) and checks
	 * plugins/ and mu-plugins/ directories via the GitHub API.
	 *
	 * @param   string $repo_name The repository name.
	 *
	 * @return  array Array with 'branch' and 'plugins' keys.
	 */
	private function check_legacy_plugins_in_repo( string $repo_name ): array {
		// Find the first available branch from priority list.
		$branches     = get_github_repository_branches( $repo_name );
		$branch_names = is_array( $branches ) ? array_column( $branches, 'name' ) : array();
		$used_branch  = null;

		foreach ( $this->branch_priority as $candidate ) {
			if ( in_array( $candidate, $branch_names, true ) ) {
				$used_branch = $candidate;
				break;
			}
		}

		if ( null === $used_branch ) {
			return array(
				'branch'  => 'none found (tried: ' . implode( ', ', $this->branch_priority ) . ')',
				'plugins' => '',
			);
		}

		// Check for legacy plugins in plugins/ and mu-plugins/ directories.
		$found       = array();
		$search_dirs = array( 'plugins', 'mu-plugins' );

		foreach ( $search_dirs as $dir ) {
			$contents = get_github_repository_contents( $repo_name, $dir, $used_branch );
			if ( empty( $contents ) ) {
				continue;
			}

			foreach ( $contents as $item ) {
				foreach ( $this->legacy_plugins as $prefix ) {
					if ( $item->name === $prefix || str_starts_with( $item->name, $prefix . '-' ) ) {
						$found[] = $dir . '/' . $item->name;
						break;
					}
				}
			}
		}

		return array(
			'branch'  => $used_branch,
			'plugins' => implode( ', ', $found ),
		);
	}

	// endregion

	// region HOST RESOLUTION

	/**
	 * Resolve a "Simple" host by checking the page for the WordPress.com actionbar
	 * and using redirect URL heuristics and API properties.
	 *
	 * @param   string          $site_url The site URL.
	 * @param   OutputInterface $output   The output object.
	 *
	 * @return  array Array with 'host' (string) and 'redirect_url' (string|null) keys.
	 */
	private function resolve_simple_host( string $site_url, OutputInterface $output ): array {
		$output->writeln( '  <comment>Host listed as Simple, verifying...</comment>' );

		// Fetch the page and capture both actionbar presence and redirect URL.
		$page_info = $this->fetch_page_info( $site_url );

		if ( $page_info['redirect_url'] ) {
			$output->writeln( "  <comment>Redirected to: {$page_info['redirect_url']}</comment>" );
		}

		// If actionbar is found, it's confirmed Simple.
		if ( $page_info['has_actionbar'] ) {
			$output->writeln( '  <comment>Actionbar found - confirmed Simple site</comment>' );
			return array(
				'host'         => 'simple',
				'redirect_url' => $page_info['redirect_url'],
			);
		}

		$output->writeln( '  <comment>No actionbar found, resolving host type...</comment>' );

		// Use redirect URL heuristics to determine host.
		$redirect_url = $page_info['redirect_url'] ?? '';
		$url_to_check = strtolower( $redirect_url ?: $site_url );

		if ( str_contains( $url_to_check, 'mystagingwebsite' ) ) {
			// "mystagingwebsite" URLs are Pressable.
			$output->writeln( '  <info>Resolved as Pressable (mystagingwebsite in URL)</info>' );
			return array(
				'host'         => 'pressable',
				'redirect_url' => $page_info['redirect_url'],
			);
		}

		if ( str_contains( $url_to_check, 'wpcomstaging' ) ) {
			// "wpcomstaging" URLs are Atomic.
			$output->writeln( '  <info>Resolved as Atomic (wpcomstaging in URL)</info>' );
			return array(
				'host'         => 'atomic',
				'redirect_url' => $page_info['redirect_url'],
			);
		}

		// No redirect hint - use API lookups to determine host type.
		$domain = $this->extract_domain( $redirect_url ?: $site_url );

		// Check WPCOM API for is_wpcom_atomic property (safe, no SSH).
		try {
			$site = get_wpcom_site( $domain );
			if ( $site && ! empty( $site->is_wpcom_atomic ) ) {
				$output->writeln( '  <info>Resolved as Atomic (is_wpcom_atomic flag)</info>' );
				return array(
					'host'         => 'atomic',
					'redirect_url' => $page_info['redirect_url'],
				);
			}
		} catch ( \Exception $e ) {
			// Ignore and try Pressable.
		}

		// Try Pressable API.
		try {
			$site = get_pressable_site( $domain );
			if ( $site ) {
				$output->writeln( '  <info>Resolved as Pressable (found via API)</info>' );
				return array(
					'host'         => 'pressable',
					'redirect_url' => $page_info['redirect_url'],
				);
			}
		} catch ( \Exception $e ) {
			// Ignore.
		}

		$output->writeln( '  <comment>Could not resolve to Atomic or Pressable, treating as Simple</comment>' );
		return array(
			'host'         => 'simple',
			'redirect_url' => $page_info['redirect_url'],
		);
	}

	/**
	 * Fetch a page and return info about actionbar presence and redirect URL.
	 *
	 * @param   string $url The URL to fetch.
	 *
	 * @return  array Array with 'has_actionbar' (bool) and 'redirect_url' (string|null) keys.
	 */
	private function fetch_page_info( string $url ): array {
		// Ensure URL has a scheme.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'timeout'         => 15,
					'follow_location' => true,
					'ignore_errors'   => true,
					'header'          => 'User-Agent: Mozilla/5.0 (team51-cli audit)',
				),
				'ssl'  => array(
					'verify_peer' => false,
				),
			)
		);

		$html = @file_get_contents( $url, false, $context );

		// Extract redirect URL from response headers.
		$redirect_url     = null;
		$response_headers = http_get_last_response_headers();
		if ( ! empty( $response_headers ) ) {
			foreach ( $response_headers as $header ) {
				if ( preg_match( '/^Location:\s*(.+)/i', $header, $matches ) ) {
					$redirect_url = trim( $matches[1] );
				}
			}
		}

		// Only report redirect if it differs from the original URL.
		if ( $redirect_url && $this->extract_domain( $redirect_url ) === $this->extract_domain( $url ) ) {
			$redirect_url = null;
		}

		return array(
			'has_actionbar' => false !== $html && str_contains( $html, 'id="actionbar"' ),
			'redirect_url'  => $redirect_url,
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Extract domain from URL (remove protocol, path, etc.).
	 *
	 * @param   string $url The URL to parse.
	 *
	 * @return  string The domain only.
	 */
	private function extract_domain( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$domain = preg_replace( '#^https?://#i', '', $url );
		$domain = preg_replace( '~[/?#].*$~', '', $domain );
		$domain = rtrim( $domain ?? '', '/' );

		return $domain;
	}

	/**
	 * Write a result row to the export CSV and store for summary.
	 *
	 * @param   array           $row    The result row.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function write_result_row( array $row, OutputInterface $output ): void {
		fputcsv( $this->export_stream, array_values( $row ) );
		fflush( $this->export_stream );

		$this->results[] = $row;

		$status = $this->get_status_indicator( $row );
		$output->writeln( "  {$status}" );
	}

	/**
	 * Get a brief console status indicator for a site.
	 *
	 * @param   array $row The result row.
	 *
	 * @return  string Formatted status string.
	 */
	private function get_status_indicator( array $row ): string {
		if ( 'No' === $row['Site Exists'] || 'Error' === $row['Site Exists'] ) {
			return '<error>Site not found</error>';
		}
		if ( 'No' === $row['SSH Access'] ) {
			return '<comment>No SSH access</comment>';
		}
		if ( 'No' === $row['PHP Ready'] ) {
			return "<comment>PHP {$row['PHP Version']} (needs >= {$this->min_php_version})</comment>";
		}
		if ( 'active' === $row['Atlantis Status'] ) {
			return '<info>Atlantis already active</info>';
		}
		if ( ! empty( $row['Notes'] ) ) {
			return "<comment>{$row['Notes']}</comment>";
		}

		return '<info>Check complete</info>';
	}

	/**
	 * Output the summary table and statistics.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function output_summary( OutputInterface $output ): void {
		$table_rows = array();
		foreach ( $this->results as $row ) {
			$host_display = $row['Host (Resolved)'];
			if ( $row['Host (CSV)'] !== $row['Host (Resolved)'] ) {
				$host_display = $row['Host (Resolved)'] . ' (was ' . $row['Host (CSV)'] . ')';
			}

			$table_rows[] = array(
				$row['Site'],
				$host_display,
				$row['Site Exists'],
				$row['SSH Access'],
				$row['PHP Version'],
				$row['PHP Ready'],
				$row['Atlantis Status'],
				$row['Repository'] ?: 'none',
			);
		}

		$summary_headers = array( 'Site', 'Host', 'Exists', 'SSH', 'PHP', 'PHP Ready', 'Atlantis', 'Repository' );
		output_table( $output, $table_rows, $summary_headers, 'Atlantis Readiness Audit Summary' );

		// Statistics.
		$total                  = count( $this->results );
		$confirmed_simple       = count( array_filter( $this->results, fn( $r ) => 'simple' === $r['Host (Resolved)'] ) );
		$reclassified           = count( array_filter( $this->results, fn( $r ) => $r['Host (CSV)'] !== $r['Host (Resolved)'] && 'simple' !== $r['Host (Resolved)'] ) );
		$exists                 = count( array_filter( $this->results, fn( $r ) => 'Yes' === $r['Site Exists'] ) );
		$ssh_ok                 = count( array_filter( $this->results, fn( $r ) => 'Yes' === $r['SSH Access'] ) );
		$php_ready              = count( array_filter( $this->results, fn( $r ) => 'Yes' === $r['PHP Ready'] ) );
		$atlantis_active        = count( array_filter( $this->results, fn( $r ) => 'active' === $r['Atlantis Status'] ) );
		$atlantis_not_installed = count( array_filter( $this->results, fn( $r ) => 'not-installed' === $r['Atlantis Status'] ) );
		$has_repo               = count( array_filter( $this->results, fn( $r ) => ! empty( $r['Repository'] ) && 'none' !== $r['Repository'] && 'Error' !== $r['Repository'] ) );
		$has_legacy_site        = count( array_filter( $this->results, fn( $r ) => ! empty( $r['Legacy Plugins (Site)'] ) && 'N/A (no SSH)' !== $r['Legacy Plugins (Site)'] ) );
		$has_legacy_repo        = count( array_filter( $this->results, fn( $r ) => ! empty( $r['Legacy Plugins (Repo)'] ) ) );

		$output->writeln( '' );
		$output->writeln( '<fg=cyan;options=bold>=== Audit Statistics ===' );
		$output->writeln( "<info>Total sites in report: {$total}</info>" );
		$output->writeln( "<comment>Confirmed Simple (skipped): {$confirmed_simple}</comment>" );
		if ( $reclassified > 0 ) {
			$output->writeln( "<comment>Reclassified from Simple: {$reclassified}</comment>" );
		}
		$output->writeln( "<info>Sites found: {$exists}</info>" );
		$output->writeln( "<info>SSH accessible: {$ssh_ok}</info>" );
		$output->writeln( "<info>PHP >= {$this->min_php_version}: {$php_ready}</info>" );
		$output->writeln( "<info>Atlantis active: {$atlantis_active}</info>" );
		$output->writeln( "<info>Atlantis not installed: {$atlantis_not_installed}</info>" );
		$output->writeln( "<info>Linked repositories: {$has_repo}</info>" );
		$output->writeln( "<info>Sites with legacy plugins (WP): {$has_legacy_site}</info>" );
		$output->writeln( "<info>Repos with legacy plugins: {$has_legacy_repo}</info>" );
	}

	/**
	 * Read CSV file and return array of site data.
	 *
	 * @param   string $csv_path Path to CSV file.
	 *
	 * @return  array
	 */
	private function read_csv( string $csv_path ): array {
		$csv_data = array();
		$handle   = fopen( $csv_path, 'r' );

		if ( false === $handle ) {
			return $csv_data;
		}

		$headers = fgetcsv( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			return $csv_data;
		}

		// Normalize column names to support multiple CSV formats.
		$column_map = array(
			'Site Name' => 'Site',
			'Domain'    => 'URL',
		);
		$headers = array_map(
			fn( $h ) => $column_map[ $h ] ?? $h,
			$headers
		);

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			if ( count( $row ) !== count( $headers ) ) {
				$row = array_pad( array_slice( $row, 0, count( $headers ) ), count( $headers ), '' );
			}

			$site_data = array_combine( $headers, $row );
			if ( ! $site_data ) {
				continue;
			}

			// Skip summary/non-data rows (e.g., rows without a valid Host value).
			$host = strtolower( trim( $site_data['Host'] ?? '' ) );
			if ( ! in_array( $host, array( 'pressable', 'atomic', 'simple', 'other' ), true ) ) {
				continue;
			}

			$csv_data[] = $site_data;
		}

		fclose( $handle );
		return $csv_data;
	}

	// endregion
}
