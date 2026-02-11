<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Removes Atlantis legacy modules from a Pressable or Atomic site.
 *
 * Examples:
 *   # Interactive mode - process a single site
 *   team51 site:remove-atlantis-legacy-modules example.com --host=pressable
 *
 *   # Process a single site with automatic PR merge
 *   team51 site:remove-atlantis-legacy-modules example.com --host=pressable --merge-pr
 *
 *   # Process a single site silently (no confirmations, minimal output)
 *   team51 site:remove-atlantis-legacy-modules example.com --host=pressable --no-output --merge-pr
 *
 *   # Process a single site and uninstall plugins from WordPress
 *   team51 site:remove-atlantis-legacy-modules example.com --host=pressable --uninstall
 *
 *   # Process multiple sites from CSV file
 *   team51 site:remove-atlantis-legacy-modules --sites=atlantis-sites.csv --no-output --merge-pr
 *
 *   # Process multiple sites from CSV with plugin uninstallation
 *   team51 site:remove-atlantis-legacy-modules --sites=atlantis-sites.csv --no-output --merge-pr --uninstall
 *
 *
 * CSV File Format:
 *   The CSV file should have the following columns: Site, URL, Host, Merged, PR, Notes
 *   Example:
 *     Site,URL,Host,Merged,PR,Notes
 *     "Example Site",https://example.mystagingwebsite.com,Pressable,,"",""
 */
#[AsCommand( name: 'site:remove-atlantis-legacy-modules' )]
final class Site_Remove_Atlantis_Legacy_Modules extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The site to remove Atlantis legacy modules from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The DeployHQ project for the given site.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $deployhq_project = null;

	/**
	 * The GitHub repository connected to the DeployHQ project or WPCOM site.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The GitHub repository branch.
	 *
	 * @var string|null
	 */
	private ?string $gh_repo_branch = null;

	/**
	 * The repos directory path.
	 *
	 * @var string|null
	 */
	private ?string $repos_dir = null;

	/**
	 * The repository directory path.
	 *
	 * @var string|null
	 */
	private ?string $repo_dir = null;

	/**
	 * The hosting provider (pressable or atomic).
	 *
	 * @var string|null
	 */
	private ?string $host = null;

	/**
	 * Legacy modules/plugins to be removed from the repository.
	 *
	 * @var array
	 */
	private array $legacy_modules = array(
		'colophon',
		'plugin-autoupdate-filter',
		'team51-tracking',
	);

	/**
	 * Removed modules/plugins from the repository.
	 *
	 * @var array
	 */
	private array $removed_modules = array();

	/**
	 * The base branch to checkout from.
	 *
	 * @var string
	 */
	private string $git_base_branch = 'develop';

	/**
	 * Whether to skip confirmations and minimize output (quiet mode).
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Whether to automatically merge PRs.
	 *
	 * @var bool
	 */
	private bool $merge_pr = false;

	/**
	 * Whether to uninstall plugins from WordPress.
	 *
	 * @var bool
	 */
	private bool $uninstall_plugins = false;

	/**
	 * Path to the CSV file with sites to process.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	/**
	 * The URL of the created PR (captured during execution).
	 *
	 * @var string|null
	 */
	private ?string $pr_url = null;

	/**
	 * The PHP version of the current site.
	 *
	 * @var string|null
	 */
	private ?string $php_version = null;

	/**
	 * Minimum required PHP version for Atlantis plugin.
	 *
	 * @var string
	 */
	private string $min_php_version = '8.3';

	/**
	 * Note explaining why a site was skipped (for CSV processing).
	 *
	 * @var string|null
	 */
	private ?string $skip_note = null;

	/**
	 * The CSV URL for environment detection (more reliable than API URL).
	 *
	 * @var string|null
	 */
	private ?string $csv_url = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Removes Atlantis legacy modules from a Pressable or Atomic site.' )
			->setHelp( 'Use this command to remove Atlantis legacy modules from a given Pressable or Atomic site, or from multiple sites using a CSV file.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The site to get repository information for.' )
			->addOption( 'host', null, InputOption::VALUE_REQUIRED, 'The hosting provider (pressable or atomic).' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from.', 'develop' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Skip confirmations and minimize output (except PR creation).' )
			->addOption( 'merge-pr', null, InputOption::VALUE_NONE, 'Automatically merge the PR without asking for confirmation.' )
			->addOption( 'uninstall', null, InputOption::VALUE_NONE, 'Uninstall plugins from WordPress in addition to removing them from the repository.' )
			->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to a CSV file containing sites to process (Site,URL,Host,Merged,PR).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Check if processing multiple sites from CSV.
		$this->sites_csv_path = $input->getOption( 'sites' );

		if ( $this->sites_csv_path ) {
			// Get options for CSV processing.
			$this->quiet             = (bool) $input->getOption( 'no-output' );
			$this->merge_pr          = (bool) $input->getOption( 'merge-pr' );
			$this->uninstall_plugins = (bool) $input->getOption( 'uninstall' );

			// Validate CSV file exists.
			if ( ! file_exists( $this->sites_csv_path ) ) {
				$output->writeln( "<error>CSV file not found: {$this->sites_csv_path}</error>" );
				exit( 1 );
			}

			// Skip individual site initialization.
			return;
		}

		// Get no-output, merge-pr, and uninstall options.
		$this->quiet             = (bool) $input->getOption( 'no-output' );
		$this->merge_pr          = (bool) $input->getOption( 'merge-pr' );
		$this->uninstall_plugins = (bool) $input->getOption( 'uninstall' );

		// Get the site argument. If not provided, prompt for it (unless in quiet mode).
		$site_input = $input->getArgument( 'site' );
		if ( empty( $site_input ) ) {
			if ( $this->quiet ) {
				$output->writeln( '<error>Site argument is required in quiet mode.</error>' );
				exit( 1 );
			}
			$site_input = $this->prompt_site_input( $input, $output );
			$input->setArgument( 'site', $site_input );
		}

		// Get and validate the host option.
		$this->host = get_enum_input( $input, 'host', array( 'pressable', 'atomic' ), fn() => $this->prompt_host_input( $input, $output ) );
		$input->setOption( 'host', $this->host );

		// Initialize site.
		try {
			$this->initialize_site_from_url( $site_input, $this->host );

			// For WPCOM sites, normalize the URL property.
			if ( 'atomic' === $this->host && isset( $this->site->URL ) ) {
				$this->site->url = $this->site->URL;
			}

			$input->setArgument( 'site', $this->site );
		} catch ( \Exception $e ) {
			$output->writeln( "<error>Failed to find the site with input: $site_input</error>" );
			exit( 1 );
		}

		// Initialize repository.
		try {
			$this->initialize_repository( $input, $output );

			// Show DeployHQ info for Pressable sites.
			if ( 'pressable' === $this->host && $this->deployhq_project ) {
				$output->writeln( "<comment>Found DeployHQ project {$this->deployhq_project->name} (permalink {$this->deployhq_project->permalink}) for the given site.</comment>", OutputInterface::VERBOSITY_VERBOSE );
			}
		} catch ( \Exception $e ) {
			$output->writeln( '<error>Failed to get the GitHub repository connected to the project or invalid connected repository.</error>' );
			exit( 1 );
		}

		// Initialize repository paths.
		$this->repos_dir = getcwd() . '/repos';
		$this->repo_dir  = $this->repos_dir . '/' . $this->gh_repository->name;

		// Check if the site is a staging site and set the base branch accordingly.
		$environment = $this->get_site_environment( $output );
		if ( 'production' === $environment ) {
			$this->git_base_branch = 'trunk';
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		// Skip interaction if quiet mode is enabled or processing CSV.
		if ( $this->quiet || $this->sites_csv_path ) {
			return;
		}

		$question = new ConfirmationQuestion( "<question>Are you sure you want to remove legacy modules from the repository for {$this->site->url} [{$this->host}] (repository: {$this->gh_repository->name} [{$this->git_base_branch}])? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		// If CSV file provided, process multiple sites.
		if ( $this->sites_csv_path ) {
			return $this->process_csv( $input, $output );
		}

		// Otherwise, process single site.
		return $this->process_single_site( $input, $output );
	}

	// endregion

	// region CSV PROCESSING

	/**
	 * Process multiple sites from a CSV file.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  int
	 */
	private function process_csv( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<info>Processing sites from CSV file...</info>' );
		$output->writeln( '' );

		// Read CSV file.
		$csv_data = $this->read_csv( $this->sites_csv_path );
		if ( empty( $csv_data ) ) {
			$output->writeln( '<error>No valid sites found in CSV file.</error>' );
			return Command::FAILURE;
		}

		// Validate required columns exist in first row.
		$first_row        = reset( $csv_data );
		$required_columns = array( 'Site', 'URL', 'Host' );
		foreach ( $required_columns as $column ) {
			if ( ! isset( $first_row[ $column ] ) ) {
				$output->writeln( "<error>CSV file is missing required column: {$column}</error>" );
				return Command::FAILURE;
			}
		}

		$total_sites = count( $csv_data );
		$processed   = 0;
		$skipped     = 0;
		$failed      = 0;
		$counter     = 0;

		foreach ( $csv_data as $index => $site_data ) {
			++$counter;
			$site_name = $site_data['Site'] ?? 'Unknown';
			$site_url  = $site_data['URL'] ?? '';
			$host      = strtolower( $site_data['Host'] ?? '' );

			// Validate required fields.
			if ( empty( $site_url ) || empty( $host ) ) {
				$note = 'Missing required fields (URL or Host)';
				$this->update_csv_row( $index, '', '', $note );
				$output->writeln( "<error>[{$counter}/{$total_sites}] Skipping {$site_name} - {$note}</error>" );
				++$skipped;
				continue;
			}

			// Validate host value.
			if ( ! in_array( $host, array( 'pressable', 'atomic' ), true ) ) {
				$note = "Invalid host value: {$host} (must be 'pressable' or 'atomic')";
				$this->update_csv_row( $index, '', '', $note );
				$output->writeln( "<error>[{$counter}/{$total_sites}] Skipping {$site_name} - {$note}</error>" );
				++$skipped;
				continue;
			}

			// Skip if already processed (has PR URL or is marked as Merged).
			if ( ! empty( $site_data['PR'] ?? '' ) || 'Y' === strtoupper( $site_data['Merged'] ?? '' ) ) {
				$note = 'Already processed (has PR or marked as Merged)';
				$this->update_csv_row( $index, $site_data['PR'] ?? '', $site_data['Merged'] ?? '', $note );
				$output->writeln( "<comment>[{$counter}/{$total_sites}] Skipping {$site_name} - already processed</comment>" );
				++$skipped;
				continue;
			}

			$output->writeln( "<info>[{$counter}/{$total_sites}] Processing: {$site_name} ({$site_url})</info>" );

			try {
				// Reset state for this site.
				$this->reset_site_state();
				$this->pr_url = null;

				// Initialize site data.
				$this->host = $host;
				$input->setOption( 'host', $host );

				// Initialize site.
				$this->initialize_site_from_url( $site_url, $host );

				// Normalize URL property for WPCOM sites (they use uppercase URL).
				if ( 'atomic' === $host && isset( $this->site->URL ) ) {
					$this->site->url = $this->site->URL;
				}

				// Safety check: Compare CSV URL with API URL (applies to both Atomic AND Pressable).
				$csv_domain = $this->extract_domain_from_url( $site_url );
				$api_domain = $this->extract_domain_from_url( $this->site->url ?? '' );

				if ( $csv_domain !== $api_domain ) {
					$note = "API URL differs from CSV: {$this->site->url}";
					$this->update_csv_row( $index, '', '', $note );
					$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
					++$skipped;
					continue;
				}

				// Store CSV URL for environment detection (more reliable than API URL).
				$this->csv_url = $site_url;

				// Initialize repository (handle deployment configuration errors gracefully).
				try {
					$this->initialize_repository( $input, $output );
				} catch ( \Exception $e ) {
					// Check if it's a deployment configuration error (DeployHQ for Pressable, WPCOM GitHub Deployments for Atomic).
					$is_deployment_error = str_contains( $e->getMessage(), 'WPCOM GitHub Deployments' )
						|| str_contains( $e->getMessage(), 'GitHub repository' )
						|| str_contains( $e->getMessage(), 'DeployHQ' );

					if ( $is_deployment_error ) {
						$deployment_type = ( 'pressable' === $site_host ) ? 'DeployHQ' : 'WPCOM GitHub';
						$note            = "Unable to find a {$deployment_type} deployment for the site";
						$this->update_csv_row( $index, '', '', $note );
						$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
						++$skipped;
						continue;
					}
					// Re-throw if it's a different error.
					throw $e;
				}

				$this->initialize_paths_and_branch( $input );

				// Determine environment and base branch.
				$environment = $this->get_site_environment( $output );
				if ( 'production' === $environment ) {
					$this->git_base_branch = 'trunk';
				}

				// Safety check: If CSV URL indicates staging, never use trunk/master.
				// This prevents accidentally processing production when staging was intended.
				$csv_url_lower  = strtolower( $site_url );
				$is_staging_url = str_contains( $csv_url_lower, 'staging' ) ||
					str_contains( $csv_url_lower, 'mystagingwebsite' ) ||
					str_contains( $csv_url_lower, 'wpcomstaging' ) ||
					str_contains( $csv_url_lower, '-dev' ) ||
					str_contains( $csv_url_lower, '-development' );

				if ( $is_staging_url && in_array( $this->git_base_branch, array( 'trunk', 'master' ), true ) ) {
					$note = "Staging URL but would use {$this->git_base_branch} branch - skipping for safety";
					$this->update_csv_row( $index, '', '', $note );
					$output->writeln( "<error>⚠ {$site_name}: {$note}</error>" );
					++$skipped;
					continue;
				}

				// Process the site.
				$result = $this->process_single_site( $input, $output );

				if ( Command::INVALID === $result ) {
					// Site was skipped due to PHP version or branch issue.
					$note = $this->skip_note ?? 'Site skipped (unknown reason)';
					$this->update_csv_row( $index, '', '', $note );
					$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
					++$skipped;
				} elseif ( Command::SUCCESS === $result ) {
					if ( $this->pr_url ) {
						// Update CSV with PR URL and Merged status.
						// Mark as 'Y' only if --merge-pr was used.
						$merged_status = $this->merge_pr ? 'Y' : '';
						$this->update_csv_row( $index, $this->pr_url, $merged_status );
						$output->writeln( "<info>✓ {$site_name} completed successfully</info>" );
					} else {
						// No PR created - plugin installed, no repo changes needed (no legacy modules in repo).
						// This is still a successful completion.
						$this->update_csv_row( $index, '', 'Y', 'Plugin installed (no repo changes needed)' );
						$output->writeln( "<info>✓ {$site_name} completed successfully (no repo changes needed)</info>" );
					}
					++$processed;
				} else {
					++$failed;
					$output->writeln( "<error>✗ {$site_name} failed</error>" );
				}
			} catch ( \Exception $e ) {
				$note = 'Error: ' . $e->getMessage();
				$this->update_csv_row( $index, '', '', $note );
				++$failed;
				$output->writeln( "<error>✗ {$site_name} failed: {$e->getMessage()}</error>" );
			}

			$output->writeln( '' );
		}

		// Summary.
		$output->writeln( '' );
		$output->writeln( '<fg=cyan;options=bold>=== Processing Summary ===' );
		$output->writeln( "<info>Total sites: {$total_sites}</info>" );
		$output->writeln( "<info>Processed: {$processed}</info>" );
		$output->writeln( "<comment>Skipped: {$skipped}</comment>" );
		if ( $failed > 0 ) {
			$output->writeln( "<error>Failed: {$failed}</error>" );
		}

		return Command::SUCCESS;
	}

	/**
	 * Process a single site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  int
	 */
	private function process_single_site( InputInterface $input, OutputInterface $output ): int {
		$this->write_output( $output, "<fg=magenta;options=bold>Processing repository for site {$this->site->url} (base branch: {$this->git_base_branch}).</>" );

		// Check PHP version first - Atlantis plugin requires PHP 8.3+.
		$this->check_php_version( $output );
		if ( ! $this->meets_php_requirement() ) {
			$this->skip_note = "PHP version {$this->php_version} < {$this->min_php_version} required";
			$this->write_output( $output, "<error>{$this->skip_note}. Skipping Atlantis plugin installation.</error>" );
			// Return a special status to indicate PHP version issue (caller will handle CSV update).
			return Command::INVALID;
		}

		$this->clone_repo( $output );

		// Checkout the base branch - handle failure gracefully.
		$checkout_process = \run_system_command( array( 'git', 'checkout', $this->git_base_branch ), $this->repo_dir, false );
		if ( ! $checkout_process->isSuccessful() ) {
			$this->skip_note = "Branch '{$this->git_base_branch}' not found";
			$this->write_output( $output, "<error>{$this->skip_note}. Skipping site.</error>" );
			// Clean up the cloned repo folder.
			if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
				\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
			}
			// Return a special status to indicate branch issue (caller will handle CSV update).
			return Command::INVALID;
		}

		// Checkout the branch 'remove/atlantis-legacy-modules'.
		$this->git_checkout_branch( 'remove/atlantis-legacy-modules', $output );

		// Check for legacy modules and delete them if they exist.
		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Searching for legacy modules to remove...</>' );

		// First, find which legacy modules exist.
		$found_modules = array();
		foreach ( $this->legacy_modules as $plugin_name ) {
			$plugin_info = $this->find_plugin( $plugin_name, $output );
			if ( null !== $plugin_info ) {
				$found_modules[ $plugin_name ] = $plugin_info;
			}
		}

		// Check autoupdate filter status BEFORE installing Atlantis.
		// This must happen first so the database option is set before Atlantis activates.
		$this->check_autoupdate_filter_status( $output );

		// Always install the Atlantis plugin.
		$plugin_installed = $this->install_atlantis_plugin( $output );

		if ( $plugin_installed && ! empty( $found_modules ) ) {
			// Delete the found modules from the repository.
			foreach ( $found_modules as $plugin_name => $plugin_info ) {
				$this->delete_plugin( $plugin_name, $plugin_info['location'], $plugin_info['path'], $output );
			}
		}

		// Uninstall plugins from WordPress if --uninstall option is provided.
		// No confirmation needed - just proceed automatically.
		$legacy_modules_count = count( $this->legacy_modules );
		if ( $this->uninstall_plugins && $legacy_modules_count > 0 ) {
			$this->uninstall_plugins_from_wordpress( $output );
		}

		if ( count( $this->removed_modules ) > 0 ) {
			$this->stage_commit_push_pr_and_merge( $input, $output );

			// Delete the repository folder (with safety check).
			if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
				\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
				$this->write_output( $output, "<info>Deleted repository folder: {$this->repo_dir}</info>" );
			} else {
				$this->write_output( $output, '<error>Safety check failed: repository path is not within repos directory. Skipping deletion.</error>' );
			}
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Writes output to the console unless in quiet mode.
	 *
	 * @param   OutputInterface    $output  The output object.
	 * @param   string|array       $message The message(s) to write.
	 * @param   int                $options Output options.
	 *
	 * @return  void
	 */
	private function write_output( OutputInterface $output, $message, int $options = 0 ): void {
		if ( ! $this->quiet ) {
			$output->writeln( $message, $options );
		}
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

		// Remove protocol (http://, https://)
		$domain = preg_replace( '#^https?://#i', '', $url );

		// Remove path, query string, and fragment
		// Use a different delimiter (e.g., ~) to avoid conflicts with '#'
		$domain = preg_replace( '~[/?#].*$~', '', $domain );

		// Remove trailing slash if any
		$domain = rtrim( $domain ?? '', '/' );

		return $domain;
	}

	/**
	 * Initialize site from URL and host.
	 *
	 * @param   string $site_url The site URL.
	 * @param   string $host     The hosting provider ('pressable' or 'atomic').
	 *
	 * @throws  \Exception If site cannot be found.
	 * @return  void
	 */
	private function initialize_site_from_url( string $site_url, string $host ): void {
		// Extract domain only (remove protocol, path, etc.).
		$domain = $this->extract_domain_from_url( $site_url );

		if ( 'pressable' === $host ) {
			$this->site = get_pressable_site( $domain );
		} else {
			$this->site = get_wpcom_site( $domain );
		}

		if ( ! $this->site ) {
			throw new \Exception( "Could not find site: {$domain}" );
		}
	}

	/**
	 * Initialize GitHub repository based on host.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @throws  \Exception If repository cannot be found.
	 * @return  void
	 */
	private function initialize_repository( InputInterface $input, OutputInterface $output ): void {
		if ( 'pressable' === $this->host ) {
			// Get Pressable DeployHQ config and GitHub repository.
			$deployhq_config = get_pressable_site_deployhq_config( $this->site->id );
			if ( $deployhq_config ) {
				$this->deployhq_project = $deployhq_config->project;
				$this->gh_repository    = get_github_repository_from_deployhq_project( $this->deployhq_project->permalink );
			}
		} else {
			// Get WPCOM repository.
			// Check for WPCOM GitHub Deployments first.
			$wpcom_gh_repositories = get_wpcom_site_code_deployments( $this->site->ID );

			if ( empty( $wpcom_gh_repositories ) ) {
				// In CSV processing mode, throw exception instead of asking.
				if ( $this->sites_csv_path ) {
					throw new \Exception( 'Unable to find a WPCOM GitHub Deployments for the site.' );
				}

				// In interactive mode, ask user.
				$output->writeln( '<error>Unable to find a WPCOM GitHub Deployments for the site.</error>' );
				$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
				if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
					$output->writeln( '<comment>Command aborted by user.</comment>' );
					exit( 1 );
				}
				return; // Exit early if user chooses to continue without repository.
			}

			// Continue with normal WPCOM repository initialization.
			$this->get_wpcom_repository( $input, $output );
		}

		// Validate repository was found.
		if ( ! $this->gh_repository || ! $this->gh_repository->name ) {
			throw new \Exception( 'Could not find GitHub repository for site: ' . ( $this->site->url ?? 'unknown' ) );
		}
	}

	/**
	 * Initialize repository paths and branch.
	 *
	 * @param   InputInterface $input The input object.
	 *
	 * @return  void
	 */
	private function initialize_paths_and_branch( InputInterface $input ): void {
		// Set up repository paths.
		$this->repos_dir = getcwd() . '/repos';
		$this->repo_dir  = $this->repos_dir . '/' . $this->gh_repository->name;

		// Set default branch.
		$this->gh_repo_branch = 'develop';
		$input->setOption( 'branch', $this->gh_repo_branch );
	}

	/**
	 * Prompts the user for a hosting provider.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_host_input( InputInterface $input, OutputInterface $output ): ?string {
		$choices = array(
			'pressable' => 'Pressable',
			'atomic'    => 'Atomic',
		);

		$question = new ChoiceQuestion( '<question>Please select the hosting provider:</question> ', $choices, 'pressable' );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, $choices ) );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or site ID:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a branch name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_branch_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the branch to deploy from [develop]:</question> ', 'develop' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository->name ) ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Gets the repository slug from the owner/repo-slug name.
	 *
	 * @param   string $repository_name The repository name.
	 *
	 * @return  string|null
	 */
	private function get_repository_slug_from_repository_name( string $repository_name ): ?string {
		$repository_parts = explode( '/', $repository_name );

		return $repository_parts[1] ?? null;
	}

	/**
	 * Gets the site environment.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string
	 */
	private function get_site_environment( OutputInterface $output ): string {
		// Use stored CSV URL if available (more reliable for staging detection).
		$url_to_check = $this->csv_url ?? $this->site->url ?? '';

		// If the URL contains 'mystagingwebsite' or 'wpcomstaging' it's a staging site. Otherwise it's a production site.
		if ( str_contains( $url_to_check, 'mystagingwebsite' ) || str_contains( $url_to_check, 'wpcomstaging' ) ) {
			$this->write_output( $output, '<info>Site is a staging site.</info>' );
			return 'staging';
		}
		$this->write_output( $output, '<info>Site is a production site.</info>' );
		return 'production';
	}

	/**
	 * Clones the GitHub repository.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function clone_repo( OutputInterface $output ): void {
		// Create a folder named "repos" in the current working directory if it doesn't exist.
		if ( ! file_exists( $this->repos_dir ) ) {
			mkdir( $this->repos_dir, 0755, true );
		}

		// Create a folder named the repository name in the repos folder if it doesn't exist.
		if ( ! file_exists( $this->repo_dir ) ) {
			mkdir( $this->repo_dir, 0755, true );
			$this->write_output( $output, "<info>Created folder: {$this->repo_dir}</info>" );

			// Clone the repository.
			\run_system_command( array( 'git', 'clone', $this->gh_repository->clone_url, $this->repo_dir ) );
			$this->write_output( $output, "<info>Cloned repository: {$this->gh_repository->name} ({$this->gh_repository->clone_url}) into {$this->repo_dir}</info>" );
		}
	}

	/**
	 * Gets the WPCOM repository.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function get_wpcom_repository( InputInterface $input, OutputInterface $output ): void {
		$wpcom_gh_repositories = get_wpcom_site_code_deployments( $this->site->ID );
		$gh_repository_name    = null;

		if ( empty( $wpcom_gh_repositories ) ) {
			$output->writeln( '<error>Unable to find a WPCOM GitHub Deployments for the site.</error>' );

			$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 1 );
			}
		}

		if ( $wpcom_gh_repositories && 1 < count( $wpcom_gh_repositories ) ) {
			$output->writeln( '<comment>Found multiple WPCOM GitHub Deployments for the site.</comment>' );

			$question = new ChoiceQuestion(
				'<question>Choose from which repository you want to use:</question> ',
				\array_column( $wpcom_gh_repositories, 'repository_name' ),
				0
			);
			$question->setErrorMessage( 'Repository %s is invalid.' );

			$gh_repository_name = $this->get_repository_slug_from_repository_name( $this->getHelper( 'question' )->ask( $input, $output, $question ) );
		} elseif ( $wpcom_gh_repositories && 1 === count( $wpcom_gh_repositories ) ) {
			$gh_repository_name = $this->get_repository_slug_from_repository_name( $wpcom_gh_repositories[0]->repository_name );
		}

		if ( $gh_repository_name ) {
			$this->gh_repo_branch = get_string_input( $input, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
			$input->setOption( 'branch', $this->gh_repo_branch );

			$this->gh_repository = get_github_repository( $gh_repository_name );
		}
	}

	/**
	 * Checks out the provided branch in the repository.
	 * Creates the branch if it doesn't exist, otherwise checks out the existing branch.
	 *
	 * @param   string          $branch The branch to checkout.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function git_checkout_branch( string $branch, OutputInterface $output ): void {
		// First, check if the branch exists locally using git show-ref.
		$check_process = new \Symfony\Component\Process\Process(
			array( 'git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}" ),
			$this->repo_dir
		);
		$check_process->run();

		if ( 0 === $check_process->getExitCode() ) {
			// Branch exists, just checkout.
			$this->write_output( $output, "  <comment>Branch {$branch} exists. Checking out...</comment>" );
			\run_system_command( array( 'git', 'checkout', $branch ), $this->repo_dir );
			$this->write_output( $output, "<info>Checked out existing branch: {$branch}</info>" );
		} else {
			// Branch doesn't exist, create it from current HEAD.
			$this->write_output( $output, "  <comment>Branch {$branch} doesn't exist. Creating from {$this->git_base_branch}...</comment>" );
			$process = \run_system_command( array( 'git', 'checkout', '-b', $branch ), $this->repo_dir );

			if ( $process->getExitCode() !== 0 ) {
				$output->writeln( "<error>Failed to create and checkout branch: {$branch}</error>" );
				exit( 1 );
			}
			$this->write_output( $output, "<info>Created and checked out branch: {$branch}</info>" );
		}
	}

	/**
	 * Stages, commits, pushes, creates a PR and merges the changes.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function stage_commit_push_pr_and_merge( InputInterface $input, OutputInterface $output ): void {
		// Stage file changes.
		\run_system_command( array( 'git', 'add', '.' ), $this->repo_dir, false );
		$this->write_output( $output, '<info>Staged file changes.</info>' );

		// Commit file changes.
		\run_system_command( array( 'git', 'commit', '-m', 'Remove legacy modules' ), $this->repo_dir, false );
		$this->write_output( $output, '<info>Committed file changes.</info>' );

		// Push the branch with changes to remote.
		// Use --force-with-lease to handle the case where the branch already exists from a previous run.
		// This is safe because it's a temporary branch that will be deleted after merge.
		\run_system_command( array( 'git', 'push', '--force-with-lease', '--set-upstream', 'origin', 'remove/atlantis-legacy-modules' ), $this->repo_dir, false );
		$this->write_output( $output, "<info>Pushed branch 'remove/atlantis-legacy-modules' to remote.</info>" );

		// Create PR from remove/atlantis-legacy-modules to the base branch.
		// Always show PR creation output, even in silent mode.
		$this->write_output( $output, "<info>Creating PR to merge remove/atlantis-legacy-modules into {$this->git_base_branch}...</info>", OutputInterface::VERBOSITY_QUIET );

		// Try to create the PR. If it already exists, gh will output the existing PR URL.
		$process = \run_system_command(
			array(
				'gh',
				'pr',
				'create',
				'--title',
				'Atlantis Rollout - Remove legacy modules',
				'--body',
				'This PR was automatically generated by a script. Please review the changes and merge if they look good.',
				'--base',
				$this->git_base_branch,
				'--head',
				'remove/atlantis-legacy-modules',
				'--repo',
				'a8cteam51/' . $this->gh_repository->name,
			),
			$this->repo_dir,
			false // Don't exit on error - the PR might already exist.
		);

		// Output the result (either new PR URL or error message).
		$pr_output = trim( $process->getOutput() . $process->getErrorOutput() );
		if ( ! empty( $pr_output ) ) {
			$output->writeln( $pr_output, OutputInterface::VERBOSITY_QUIET );

			// Extract and save PR URL for CSV processing.
			// The output typically contains the PR URL (e.g., https://github.com/a8cteam51/repo/pull/123).
			if ( preg_match( '#https://github\.com/[^/]+/[^/]+/pull/\d+#', $pr_output, $matches ) ) {
				$this->pr_url = $matches[0];
			}
		}

		// Ask for confirmation before merging PR and deleting branches (unless merge-pr or quiet flag is set).
		$should_merge = $this->merge_pr;
		if ( ! $this->quiet && ! $this->merge_pr ) {
			$output->writeln( '' );
			$question = new ConfirmationQuestion( '<question>Do you want to merge the PR and delete the branches? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Skipping PR merge and branch deletion.</comment>' );
				return;
			}
			$should_merge = true;
		}

		if ( $should_merge ) {
			// Merge the PR - always show output, even in silent mode.
			$output->writeln( '<info>Merging PR...</info>', OutputInterface::VERBOSITY_QUIET );

			// Try with --auto first (for PRs that need to wait for checks).
			// Use Process directly to avoid printing the error when it's expected.
			$process = new \Symfony\Component\Process\Process(
				array(
					'gh',
					'pr',
					'merge',
					'remove/atlantis-legacy-modules',
					'--squash',  // Squash all commits into one
					'--auto',    // Auto-merge when requirements are met
					'--delete-branch',  // Delete the branch after merge
					'--repo',
					'a8cteam51/' . $this->gh_repository->name,
				),
				$this->repo_dir
			);
			$process->run();

			// Check if auto-merge failed and we should retry without --auto.
			// This happens when:
			// - PR is already in clean status (ready to merge immediately)
			// - Branch protection rules not configured (auto-merge not available)
			$error_output = $process->getErrorOutput();
			$should_retry = str_contains( $error_output, 'is in clean status' ) ||
				str_contains( $error_output, 'Protected branch rules not configured' ) ||
				str_contains( $error_output, 'enablePullRequestAutoMerge' );

			if ( 0 !== $process->getExitCode() && $should_retry ) {
				// Retry without --auto flag.
				$output->writeln( '<comment>Auto-merge not available, merging directly...</comment>', OutputInterface::VERBOSITY_QUIET );
				$process = \run_system_command(
					array(
						'gh',
						'pr',
						'merge',
						'remove/atlantis-legacy-modules',
						'--squash',
						'--delete-branch',
						'--repo',
						'a8cteam51/' . $this->gh_repository->name,
					),
					$this->repo_dir,
					false
				);
			} elseif ( 0 !== $process->getExitCode() ) {
				// Some other error occurred, output it and exit.
				$output->writeln( '<error>Failed to merge PR:</error>', OutputInterface::VERBOSITY_QUIET );
				$output->writeln( $error_output, OutputInterface::VERBOSITY_QUIET );
				exit( 1 );
			}

			// Output the merge result (only if successful).
			if ( 0 === $process->getExitCode() ) {
				$merge_output = trim( $process->getOutput() . $process->getErrorOutput() );
				if ( ! empty( $merge_output ) ) {
					$output->writeln( $merge_output, OutputInterface::VERBOSITY_QUIET );
				}
			}
		}
	}

	/**
	 * Finds a plugin folder in the repository.
	 * Checks mu-plugins first, then plugins.
	 *
	 * @param   string          $plugin_name The plugin folder name to search for.
	 * @param   OutputInterface $output      The output object.
	 *
	 * @return  array|null Array with 'location' and 'path' keys, or null if not found.
	 */
	private function find_plugin( string $plugin_name, OutputInterface $output ): ?array {
		// Check in mu-plugins folder first.
		$mu_plugins_path = $this->repo_dir . '/mu-plugins/' . $plugin_name;
		if ( file_exists( $mu_plugins_path ) ) {
			$this->write_output( $output, "<comment>{$plugin_name} found in mu-plugins</comment>" );
			return array(
				'location' => 'mu-plugins',
				'path'     => $mu_plugins_path,
			);
		}

		// Check in plugins folder.
		$plugins_path = $this->repo_dir . '/plugins/' . $plugin_name;
		if ( file_exists( $plugins_path ) ) {
			$this->write_output( $output, "<comment>{$plugin_name} found in plugins</comment>" );
			return array(
				'location' => 'plugins',
				'path'     => $plugins_path,
			);
		}

		$this->write_output( $output, "<info>{$plugin_name} not found in mu-plugins or plugins directories.</info>" );
		return null;
	}

	/**
	 * Deletes a plugin folder from the repository.
	 *
	 * @param   string          $plugin_name The plugin name.
	 * @param   string          $location    The location type ('mu-plugins' or 'plugins').
	 * @param   string          $plugin_path The path to the plugin folder.
	 * @param   OutputInterface $output      The output object.
	 *
	 * @return  void
	 */
	private function delete_plugin( string $plugin_name, string $location, string $plugin_path, OutputInterface $output ): void {
		if ( ! file_exists( $plugin_path ) ) {
			return;
		}

		$this->write_output( $output, "<fg=yellow>Removing {$plugin_name} from {$location}...</>" );

		// Check if .gitmodules exists and contains the plugin as a submodule.
		$gitmodules_path = $this->repo_dir . '/.gitmodules';
		$submodule_path  = $location . '/' . $plugin_name;
		$is_submodule    = false;

		if ( file_exists( $gitmodules_path ) ) {
			$gitmodules_content = file_get_contents( $gitmodules_path );

			// Check if the plugin submodule exists in .gitmodules for this location.
			if ( false !== $gitmodules_content && str_contains( $gitmodules_content, $submodule_path ) ) {
				$is_submodule = true;
			}
		}

		if ( $is_submodule ) {
			$this->write_output( $output, "  <comment>→ {$plugin_name} is a git submodule. Removing...</comment>" );

			// Use git commands to properly remove the submodule.
			// 1. Deinitialize the submodule.
			$this->write_output( $output, '  1. Deinitializing submodule...' );
			\run_system_command( array( 'git', 'submodule', 'deinit', '-f', $submodule_path ), $this->repo_dir, false );

			// 2. Remove the submodule from git index.
			$this->write_output( $output, '  2. Removing submodule from git index...' );
			\run_system_command( array( 'git', 'rm', '-f', $submodule_path ), $this->repo_dir, false );

			// 3. Remove the submodule's .git directory.
			$submodule_git_dir = $this->repo_dir . '/.git/modules/' . $submodule_path;
			if ( file_exists( $submodule_git_dir ) ) {
				$this->write_output( $output, '  3. Cleaning up .git/modules directory...' );
				\run_system_command( array( 'rm', '-rf', $submodule_git_dir ) );
			}

			$this->write_output( $output, "  <info>✓ {$plugin_name} submodule successfully removed from {$location}</info>" );
		} else {
			$this->write_output( $output, "  <comment>→ {$plugin_name} is a regular directory. Removing...</comment>" );
			\run_system_command( array( 'rm', '-rf', $plugin_path ) );
			$this->write_output( $output, "  <info>✓ {$plugin_name} directory successfully removed from {$location}</info>" );
		}

		$this->removed_modules[] = $plugin_name;
	}

	/**
	 * Installs and activates the Atlantis plugin from GitHub.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool True if plugin was installed and activated successfully, false otherwise.
	 */
	private function install_atlantis_plugin( OutputInterface $output ): bool {
		$plugin_url      = 'https://github.com/a8cteam51/a8csp-atlantis/releases/download/v1.0.2/a8csp-atlantis.zip';
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Installing Atlantis plugin from GitHub...</>' );

		try {
			// Install the plugin.
			$install_command = "plugin install {$plugin_url} --force";
			$this->write_output( $output, "  Running: wp {$install_command}" );

			if ( 'pressable' === $this->host ) {
				$install_result = run_pressable_site_wp_cli_command( $site_identifier, $install_command, $this->quiet );
			} else {
				$install_result = run_wpcom_site_wp_cli_command( $site_identifier, $install_command, $this->quiet );
			}

			// Check if installation was successful.
			if ( Command::SUCCESS !== $install_result ) {
				$this->write_output( $output, '  <error>Plugin installation failed.</error>' );
				return false;
			}

			$this->write_output( $output, '  <info>✓ Plugin installed successfully</info>' );

			// Activate the plugin.
			$activate_command = 'plugin activate a8csp-atlantis';
			$this->write_output( $output, "  Running: wp {$activate_command}" );

			if ( 'pressable' === $this->host ) {
				$activate_result = run_pressable_site_wp_cli_command( $site_identifier, $activate_command, $this->quiet );
			} else {
				$activate_result = run_wpcom_site_wp_cli_command( $site_identifier, $activate_command, $this->quiet );
			}

			// Check if activation was successful.
			if ( Command::SUCCESS !== $activate_result ) {
				$this->write_output( $output, '  <error>Plugin activation failed.</error>' );
				return false;
			}

			$this->write_output( $output, '  <info>✓ Plugin activated successfully</info>' );
			return true;

		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to install/activate Atlantis plugin: {$e->getMessage()}</error>" );
			return false;
		}
	}

	/**
	 * Checks if plugin-autoupdate-filter (or variants) is installed but deactivated,
	 * and if so, disables the Atlantis autoupdates module via option.
	 *
	 * The plugin slug can have variants like:
	 * - plugin-autoupdate-filter
	 * - plugin-autoupdate-filter-5
	 * - plugin-autoupdate-filter-trunk
	 * - plugin-autoupdate-filter-1.4.3
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function check_autoupdate_filter_status( OutputInterface $output ): void {
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Checking plugin-autoupdate-filter status...</>' );

		try {
			// Get list of all plugins in JSON format.
			$list_command = 'plugin list --format=json';

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $list_command, true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $list_command, true );
			}

			// Get the output from the global variable.
			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

			// Extract JSON array from output (may contain warnings before JSON).
			$plugins = null;
			if ( preg_match( '/\[.*\]/s', $wp_cli_output, $matches ) ) {
				$plugins = json_decode( $matches[0], true );
			}

			if ( ! is_array( $plugins ) ) {
				$this->write_output( $output, '  <comment>Could not parse plugin list.</comment>' );
				return;
			}

			// Find any plugin-autoupdate-filter variants.
			$autoupdate_plugins = array();
			foreach ( $plugins as $plugin ) {
				if ( isset( $plugin['name'] ) && str_starts_with( $plugin['name'], 'plugin-autoupdate-filter' ) ) {
					$autoupdate_plugins[] = $plugin;
				}
			}

			if ( empty( $autoupdate_plugins ) ) {
				$this->write_output( $output, '  <info>No plugin-autoupdate-filter variants found on the site.</info>' );
				return;
			}

			// Check if any of them are active.
			// Note: must-use plugins are always active (they load automatically).
			$active_statuses = array( 'active', 'active-network', 'must-use' );
			$any_active      = false;
			foreach ( $autoupdate_plugins as $plugin ) {
				$this->write_output( $output, "  Found: {$plugin['name']} (status: {$plugin['status']})" );
				if ( in_array( $plugin['status'], $active_statuses, true ) ) {
					$any_active = true;
				}
			}

			// If found but none are active, disable the Atlantis autoupdates module.
			if ( ! $any_active ) {
				$this->write_output( $output, '  <comment>Plugin found but not active. Disabling Atlantis autoupdates module...</comment>' );

				// Use wp eval to set the option as an array directly (avoids shell escaping issues).
				$option_command = 'eval "update_option( \'a8csp_module_autoupdates\', array( \'enabled\' => \'0\' ) );"';

				if ( 'pressable' === $this->host ) {
					run_pressable_site_wp_cli_command( $site_identifier, $option_command, $this->quiet );
				} else {
					run_wpcom_site_wp_cli_command( $site_identifier, $option_command, $this->quiet );
				}

				$this->write_output( $output, '  <info>✓ Atlantis autoupdates module disabled</info>' );
			} else {
				$this->write_output( $output, '  <info>Plugin is active, Atlantis autoupdates module will remain enabled.</info>' );
			}
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to check plugin status: {$e->getMessage()}</error>" );
			$this->write_output( $output, '  <comment>Continuing with legacy module removal...</comment>' );
		}
	}

	/**
	 * Uninstalls all legacy plugins from WordPress in a single command.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function uninstall_plugins_from_wordpress( OutputInterface $output ): void {
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;
		$plugins_list    = implode( ' ', $this->legacy_modules );

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Deactivating and uninstalling plugins from WordPress...</>' );

		try {
			// Run single command to deactivate and uninstall all plugins.
			// The --uninstall flag will also deactivate if needed.
			$command = "plugin uninstall {$plugins_list} --deactivate";

			// Skip output in silent mode.
			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $command, $this->quiet );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $command, $this->quiet );
			}

			$this->write_output( $output, '  <info>✓ Plugins deactivated and uninstalled from WordPress</info>' );
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to uninstall plugins from WordPress: {$e->getMessage()}</error>" );
			$this->write_output( $output, '  <comment>This is usually fine if the plugins were not installed on the site.</comment>' );
		}
	}

	/**
	 * Check the PHP version of the current site.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null The PHP version string, or null on error.
	 */
	private function check_php_version( OutputInterface $output ): ?string {
		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Checking PHP version...</>' );

		// For Pressable sites, try to get PHP version from the API response first.
		if ( 'pressable' === $this->host && isset( $this->site->phpVersion ) ) {
			$this->php_version = $this->site->phpVersion;
			$this->write_output( $output, "  <info>PHP version: {$this->php_version} (from API)</info>" );
			return $this->php_version;
		}

		// Fall back to WP-CLI for Atomic sites or if API doesn't have PHP version.
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		try {
			$command = "eval 'echo phpversion();'";

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $command, true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $command, true );
			}

			// Get the output from the global variable.
			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

			// Parse the version from output (should be something like "8.3.0" or "8.2.30").
			if ( preg_match( '/(\d+\.\d+(\.\d+)?)/', $wp_cli_output, $matches ) ) {
				$this->php_version = $matches[1];
				$this->write_output( $output, "  <info>PHP version: {$this->php_version}</info>" );
				return $this->php_version;
			}

			$this->write_output( $output, '  <comment>Could not determine PHP version from output.</comment>' );
			return null;
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to check PHP version: {$e->getMessage()}</error>" );
			return null;
		}
	}

	/**
	 * Check if the site meets the minimum PHP version requirement.
	 *
	 * @return  bool True if PHP version is sufficient, false otherwise.
	 */
	private function meets_php_requirement(): bool {
		if ( null === $this->php_version ) {
			return false;
		}

		return version_compare( $this->php_version, $this->min_php_version, '>=' );
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
				// Pad or truncate row to match header count.
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

	/**
	 * Update a specific row in the CSV file.
	 *
	 * @param   int    $row_index  The row index to update (1-based, accounting for header).
	 * @param   string $pr_url     The PR URL to set.
	 * @param   string $merged     The Merged status ('Y' or '').
	 * @param   string $note       Optional note to add.
	 *
	 * @return  void
	 */
	private function update_csv_row( int $row_index, string $pr_url, string $merged, string $note = '' ): void {
		if ( ! $this->sites_csv_path || ! file_exists( $this->sites_csv_path ) ) {
			return;
		}

		// Read all rows.
		$rows   = array();
		$handle = fopen( $this->sites_csv_path, 'r' );

		if ( false === $handle ) {
			return;
		}

		// Read header to find column indices.
		$headers = fgetcsv( $handle );
		if ( false === $headers ) {
			fclose( $handle );
			return;
		}

		// Find column indices (use strict comparison since array_search can return 0).
		$pr_index     = array_search( 'PR', $headers, true );
		$merged_index = array_search( 'Merged', $headers, true );
		$notes_index  = array_search( 'Notes', $headers, true );

		// If Notes column doesn't exist, we'll add it.
		$needs_notes_column = false === $notes_index;
		if ( $needs_notes_column ) {
			$headers[]   = 'Notes';
			$notes_index = count( $headers ) - 1;
		}

		$rows[] = $headers; // Add header row.

		$current_row = 1; // Start at 1 (header is row 0).
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$current_row;

			// Update the target row.
			if ( $current_row === $row_index ) {
				// Ensure row has enough columns.
				while ( count( $row ) < count( $headers ) ) {
					$row[] = '';
				}

				// Update PR column.
				if ( false !== $pr_index ) {
					$row[ $pr_index ] = $pr_url;
				}

				// Update Merged column.
				if ( false !== $merged_index ) {
					$row[ $merged_index ] = $merged;
				}

				// Update or add Notes column.
				if ( $needs_notes_column ) {
					// Add Notes column to existing rows that don't have it.
					while ( count( $row ) <= $notes_index ) {
						$row[] = '';
					}
				}
				$row[ $notes_index ] = $note;
			} elseif ( $needs_notes_column ) {
				// Add empty Notes column to other rows.
				while ( count( $row ) < count( $headers ) ) {
					$row[] = '';
				}
			}

			$rows[] = $row;
		}

		fclose( $handle );

		// Write back to file.
		$handle = fopen( $this->sites_csv_path, 'w' );
		if ( false === $handle ) {
			return;
		}

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );
	}

	/**
	 * Reset site state between processing multiple sites.
	 *
	 * @return  void
	 */
	private function reset_site_state(): void {
		$this->site             = null;
		$this->deployhq_project = null;
		$this->gh_repository    = null;
		$this->gh_repo_branch   = null;
		$this->repo_dir         = null;
		$this->removed_modules  = array();
		$this->git_base_branch  = 'develop';
		$this->php_version      = null;
		$this->skip_note        = null;
		$this->csv_url          = null;
	}

	// endregion
}
