<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports a CSV mapping sites to their GitHub repositories.
 *
 * Examples:
 *   # Export repos for all sites in a CSV file
 *   team51 site:export-repos --sites=atlantis-w1-w2-staging-master.csv
 *
 *   # Export to a custom output file
 *   team51 site:export-repos --sites=atlantis-w1-w2-staging-master.csv --export=custom-output.csv
 */
#[AsCommand( name: 'site:export-repos' )]
final class Site_Repos_Export extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Path to the input CSV file.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	/**
	 * File handle for the output CSV.
	 *
	 * @var resource|null
	 */
	private $output_stream = null;

	/**
	 * Path to the output CSV file.
	 *
	 * @var string
	 */
	private string $export_path = 'sites-repos.csv';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Exports a CSV mapping sites to their GitHub repositories.' )
			->setHelp( 'Reads a CSV file with Site, URL, Host columns and resolves the GitHub repository for each site.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to a CSV file containing sites to process (requires Site, URL, Host columns).' )
			->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'Path to export CSV results to.', 'sites-repos.csv' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Validate input CSV path.
		$this->sites_csv_path = $input->getOption( 'sites' );
		if ( empty( $this->sites_csv_path ) ) {
			throw new \RuntimeException( '--sites option is required. Provide a path to a CSV file.' );
		}
		if ( ! file_exists( $this->sites_csv_path ) ) {
			throw new \RuntimeException( "CSV file not found: {$this->sites_csv_path}" );
		}

		// Open output CSV file.
		$this->export_path   = $input->getOption( 'export' );
		$this->output_stream = get_file_handle( $this->export_path, 'csv' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$csv_data = $this->read_csv( $this->sites_csv_path );
		if ( empty( $csv_data ) ) {
			$output->writeln( '<error>No valid sites found in CSV file.</error>' );
			return Command::FAILURE;
		}

		// Validate required columns.
		$first_row = reset( $csv_data );
		foreach ( array( 'Site', 'URL', 'Host' ) as $col ) {
			if ( ! isset( $first_row[ $col ] ) ) {
				$output->writeln( "<error>CSV missing required column: {$col}</error>" );
				return Command::FAILURE;
			}
		}

		$total = count( $csv_data );
		$output->writeln( "<info>Processing {$total} sites from CSV...</info>" );

		// Write output CSV header.
		fputcsv( $this->output_stream, array( 'Site', 'URL', 'Host', 'Repository', 'Repository URL', 'Notes' ) );

		// Set up progress bar.
		$progress_bar = new ProgressBar( $output, $total );
		$progress_bar->setFormat( ' %current%/%max% [%bar%] %percent:3s%% %message%' );
		$progress_bar->setMessage( 'Starting...' );
		$progress_bar->start();

		// Cache: URL => resolved result (to handle duplicate URLs).
		$url_cache = array();
		$success   = 0;
		$failed    = 0;
		$skipped   = 0;

		foreach ( $csv_data as $site_data ) {
			$site_name = $site_data['Site'] ?? 'Unknown';
			$site_url  = trim( $site_data['URL'] ?? '' );
			$host      = strtolower( trim( $site_data['Host'] ?? '' ) );

			$progress_bar->setMessage( $site_name );
			$progress_bar->advance();

			// Validate row.
			if ( empty( $site_url ) || empty( $host ) ) {
				$this->write_output_row( $site_name, $site_url, $host, '', '', 'Missing URL or Host' );
				++$skipped;
				continue;
			}

			if ( ! in_array( $host, array( 'pressable', 'atomic' ), true ) ) {
				$this->write_output_row( $site_name, $site_url, $host, '', '', "Unknown host type: {$host}" );
				++$skipped;
				continue;
			}

			// Check cache for duplicate URLs.
			if ( isset( $url_cache[ $site_url ] ) ) {
				$cached = $url_cache[ $site_url ];
				$this->write_output_row( $site_name, $site_url, $host, $cached['repo_name'], $cached['repo_url'], $cached['notes'] );
				if ( ! empty( $cached['repo_name'] ) ) {
					++$success;
				} else {
					++$failed;
				}
				continue;
			}

			// Resolve repository.
			$result = $this->resolve_repository( $site_url, $host );

			$url_cache[ $site_url ] = $result;
			$this->write_output_row( $site_name, $site_url, $host, $result['repo_name'], $result['repo_url'], $result['notes'] );

			if ( ! empty( $result['repo_name'] ) ) {
				++$success;
			} else {
				++$failed;
			}
		}

		$progress_bar->finish();
		$output->writeln( '' );
		fclose( $this->output_stream );

		// Summary.
		$output->writeln( '' );
		$output->writeln( '<fg=cyan;options=bold>=== Export Summary ===' );
		$output->writeln( "<info>Total sites:  {$total}</info>" );
		$output->writeln( "<info>Resolved:     {$success}</info>" );
		$output->writeln( "<comment>Skipped:      {$skipped}</comment>" );
		$output->writeln( "<error>Failed:       {$failed}</error>" );
		$output->writeln( "<info>Results exported to: {$this->export_path}</info>" );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the GitHub repository for a given site URL and host type.
	 *
	 * @param   string $site_url The site URL.
	 * @param   string $host     The host type ('pressable' or 'atomic').
	 *
	 * @return  array{ repo_name: string, repo_url: string, notes: string }
	 */
	private function resolve_repository( string $site_url, string $host ): array {
		$result = array(
			'repo_name' => '',
			'repo_url'  => '',
			'notes'     => '',
		);

		try {
			$domain = $this->extract_domain_from_url( $site_url );

			if ( 'pressable' === $host ) {
				$result = $this->resolve_pressable_repository( $domain );
			} else {
				$result = $this->resolve_atomic_repository( $domain );
			}
		} catch ( \Exception $e ) {
			$result['notes'] = 'Error: ' . $e->getMessage();
		}

		return $result;
	}

	/**
	 * Resolves GitHub repository for a Pressable site via DeployHQ.
	 *
	 * @param   string $domain The site domain.
	 *
	 * @return  array{ repo_name: string, repo_url: string, notes: string }
	 */
	private function resolve_pressable_repository( string $domain ): array {
		$result = array( 'repo_name' => '', 'repo_url' => '', 'notes' => '' );

		// Step 1: Look up the Pressable site.
		$site = get_pressable_site( $domain );
		if ( ! $site ) {
			$result['notes'] = 'Pressable site not found';
			return $result;
		}

		// Step 2: Get DeployHQ config.
		$deployhq_config = get_pressable_site_deployhq_config( $site->id );
		if ( ! $deployhq_config || ! isset( $deployhq_config->project ) ) {
			$result['notes'] = 'No DeployHQ project found';
			return $result;
		}

		// Step 3: Get GitHub repository from DeployHQ project.
		$gh_repo = get_github_repository_from_deployhq_project( $deployhq_config->project->permalink );
		if ( ! $gh_repo || ! isset( $gh_repo->name ) ) {
			$result['notes'] = 'GitHub repository not found via DeployHQ';
			return $result;
		}

		$result['repo_name'] = $gh_repo->full_name ?? ( 'a8cteam51/' . $gh_repo->name );
		$result['repo_url']  = $gh_repo->html_url ?? "https://github.com/a8cteam51/{$gh_repo->name}";
		return $result;
	}

	/**
	 * Resolves GitHub repository for an Atomic/WPCOM site via code deployments.
	 *
	 * @param   string $domain The site domain.
	 *
	 * @return  array{ repo_name: string, repo_url: string, notes: string }
	 */
	private function resolve_atomic_repository( string $domain ): array {
		$result = array( 'repo_name' => '', 'repo_url' => '', 'notes' => '' );

		// Step 1: Look up the WPCOM site.
		$site = get_wpcom_site( $domain );
		if ( ! $site ) {
			$result['notes'] = 'WPCOM site not found';
			return $result;
		}

		// Step 2: Get code deployments.
		$deployments = get_wpcom_site_code_deployments( $site->ID );
		if ( empty( $deployments ) ) {
			$result['notes'] = 'No WPCOM GitHub deployments found';
			return $result;
		}

		// Step 3: Use the first deployment. Note if there are multiple.
		$repository_name = $deployments[0]->repository_name ?? null;
		if ( ! $repository_name ) {
			$result['notes'] = 'Deployment has no repository_name';
			return $result;
		}

		if ( count( $deployments ) > 1 ) {
			$all_repos       = array_column( $deployments, 'repository_name' );
			$result['notes'] = 'Multiple deployments: ' . implode( ', ', $all_repos );
		}

		// Step 4: Extract slug and fetch repository.
		$parts     = explode( '/', $repository_name );
		$repo_slug = $parts[1] ?? null;
		if ( ! $repo_slug ) {
			$result['notes'] = "Could not parse repository slug from: {$repository_name}";
			return $result;
		}

		$gh_repo = get_github_repository( $repo_slug );
		if ( ! $gh_repo || ! isset( $gh_repo->name ) ) {
			// Still store the name even if API lookup fails.
			$result['repo_name'] = $repository_name;
			$result['repo_url']  = "https://github.com/{$repository_name}";
			$result['notes']     = ( $result['notes'] ? $result['notes'] . '; ' : '' ) . 'GitHub API lookup failed, using deployment name';
			return $result;
		}

		$result['repo_name'] = $gh_repo->full_name ?? $repository_name;
		$result['repo_url']  = $gh_repo->html_url ?? "https://github.com/{$repository_name}";
		return $result;
	}

	/**
	 * Writes a single row to the output CSV.
	 *
	 * @param   string $site      The site name.
	 * @param   string $url       The site URL.
	 * @param   string $host      The host type.
	 * @param   string $repo_name The repository full name.
	 * @param   string $repo_url  The repository URL.
	 * @param   string $notes     Any notes or error messages.
	 *
	 * @return  void
	 */
	private function write_output_row( string $site, string $url, string $host, string $repo_name, string $repo_url, string $notes ): void {
		fputcsv( $this->output_stream, array( $site, $url, $host, $repo_name, $repo_url, $notes ) );
		fflush( $this->output_stream );
	}

	/**
	 * Extract domain from URL (remove protocol, path, etc.).
	 *
	 * @param   string $url The URL to parse.
	 *
	 * @return  string The domain only.
	 */
	private function extract_domain_from_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		// Remove protocol (http://, https://).
		$domain = preg_replace( '#^https?://#i', '', $url );

		// Remove path, query string, and fragment.
		$domain = preg_replace( '~[/?#].*$~', '', $domain );

		// Remove trailing slash if any.
		$domain = rtrim( $domain ?? '', '/' );

		return $domain;
	}

	/**
	 * Reads a CSV file into an associative array keyed by row index.
	 *
	 * @param   string $csv_path The path to the CSV file.
	 *
	 * @return  array
	 */
	private function read_csv( string $csv_path ): array {
		$csv_data = array();
		$handle   = fopen( $csv_path, 'r' );

		if ( false === $handle ) {
			return $csv_data;
		}

		// Read header row.
		$headers = fgetcsv( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			return $csv_data;
		}

		// Read data rows.
		$row_index = 1; // Start at 1 (0 is header).
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$row_index;

			// Skip empty rows.
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			// Ensure row has same number of columns as headers.
			if ( count( $row ) !== count( $headers ) ) {
				$row = array_pad( array_slice( $row, 0, count( $headers ) ), count( $headers ), '' );
			}

			$site_data = array_combine( $headers, $row );
			if ( $site_data ) {
				$csv_data[ $row_index ] = $site_data;
			}
		}

		fclose( $handle );
		return $csv_data;
	}

	// endregion
}
