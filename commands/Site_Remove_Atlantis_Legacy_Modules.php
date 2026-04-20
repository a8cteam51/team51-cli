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
 *   # Site-only mode: verify Atlantis is active and uninstall legacy plugins (no repository operations)
 *   team51 site:remove-atlantis-legacy-modules example.com --host=pressable --site-only
 *
 *   # Site-only batch mode: process multiple sites without repository operations
 *   team51 site:remove-atlantis-legacy-modules --sites=atlantis-sites.csv --no-output --site-only
 *
 *   # Dry run: check all sites without making any changes
 *   team51 site:remove-atlantis-legacy-modules --sites=atlantis-sites.csv --dry-run
 *
 *   # Repo-only mode: process repositories without any site operations (creates PRs only, no merge)
 *   team51 site:remove-atlantis-legacy-modules --repos=repos-to-process.csv
 *
 *   # Repo-only dry run
 *   team51 site:remove-atlantis-legacy-modules --repos=repos-to-process.csv --dry-run
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
		'wc-usage-tracking-auto-opt-in',
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
	 * The working branch name for removing legacy modules.
	 * Derived from the base branch (e.g. remove/atlantis-legacy-modules-trunk).
	 *
	 * @var string
	 */
	private string $working_branch = 'remove/atlantis-legacy-modules-develop';

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
	 * Whether to skip repository operations and only perform site operations.
	 *
	 * @var bool
	 */
	private bool $skip_repository = false;

	/**
	 * Whether --site-only was explicitly passed on the CLI.
	 * Preserved across CSV iterations (not reset by reset_site_state()).
	 *
	 * @var bool
	 */
	private bool $site_only_option = false;

	/**
	 * Whether to run in dry-run mode (read-only, no changes made).
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Whether the current site had no deployment found (used to gracefully
	 * fall back to site-only if branch checkout fails for a manually entered repo).
	 *
	 * @var bool
	 */
	private bool $deployment_not_found = false;

	/**
	 * Path to the CSV file with sites to process.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	/**
	 * Path to the CSV file with repositories to process (repo-only mode).
	 *
	 * @var string|null
	 */
	private ?string $repos_csv_path = null;

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
	private string $min_php_version = '8.2';

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
			->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to a CSV file containing sites to process (Site,URL,Host,Merged,PR).' )
			->addOption( 'site-only', null, InputOption::VALUE_NONE, 'Skip repository operations; only verify Atlantis is active and uninstall legacy plugins from WordPress.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Run all checks without making any changes (no plugin install/uninstall, no repo modifications, no CSV updates).' )
			->addOption( 'repos', null, InputOption::VALUE_REQUIRED, 'Path to a CSV file of repositories to process (repo-only mode, no site operations). Format: Repository,Branch,PR,Notes' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Check if processing repositories from CSV (repo-only mode).
		$this->repos_csv_path = $input->getOption( 'repos' );

		if ( $this->repos_csv_path ) {
			$this->quiet   = (bool) $input->getOption( 'no-output' );
			$this->dry_run = (bool) $input->getOption( 'dry-run' );

			if ( ! file_exists( $this->repos_csv_path ) ) {
				$output->writeln( "<error>CSV file not found: {$this->repos_csv_path}</error>" );
				exit( 1 );
			}

			// Skip individual site initialization.
			return;
		}

		// Check if processing multiple sites from CSV.
		$this->sites_csv_path = $input->getOption( 'sites' );

		if ( $this->sites_csv_path ) {
			// Get options for CSV processing.
			$this->quiet             = (bool) $input->getOption( 'no-output' );
			$this->merge_pr          = (bool) $input->getOption( 'merge-pr' );
			$this->uninstall_plugins = (bool) $input->getOption( 'uninstall' );
			$this->site_only_option  = (bool) $input->getOption( 'site-only' );
			$this->skip_repository   = $this->site_only_option;
			$this->dry_run           = (bool) $input->getOption( 'dry-run' );

			// When --site-only is set, uninstalling plugins is implied.
			if ( $this->skip_repository ) {
				$this->uninstall_plugins = true;
			}

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
		$this->site_only_option  = (bool) $input->getOption( 'site-only' );
		$this->skip_repository   = $this->site_only_option;
		$this->dry_run           = (bool) $input->getOption( 'dry-run' );

		// When --site-only is set, uninstalling plugins is implied.
		if ( $this->skip_repository ) {
			$this->uninstall_plugins = true;
		}

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

		// Skip repository operations when --site-only is set.
		if ( ! $this->skip_repository ) {
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
				$this->working_branch  = 'remove/atlantis-legacy-modules-trunk';
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		// Skip interaction if quiet mode is enabled or processing CSV/repos.
		if ( $this->quiet || $this->sites_csv_path || $this->repos_csv_path ) {
			return;
		}

		if ( $this->skip_repository ) {
			$question = new ConfirmationQuestion( "<question>Are you sure you want to verify Atlantis and remove legacy plugins from {$this->site->url} [{$this->host}]? [y/N]</question> ", false );
		} else {
			$question = new ConfirmationQuestion( "<question>Are you sure you want to remove legacy modules from the repository for {$this->site->url} [{$this->host}] (repository: {$this->gh_repository->name} [{$this->git_base_branch}])? [y/N]</question> ", false );
		}
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN MODE: No changes will be made ---</>' );
			$output->writeln( '' );
		}

		// If repos CSV provided, process repositories only.
		if ( $this->repos_csv_path ) {
			return $this->process_repos_csv( $input, $output );
		}

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

		$total_sites     = count( $csv_data );
		$processed       = 0;
		$skipped         = 0;
		$failed          = 0;
		$counter         = 0;
		$processed_sites = array();

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

				// Host verification: Ensure "atomic" sites are truly Atomic before proceeding.
				if ( 'atomic' === $host && empty( $this->site->is_wpcom_atomic ) ) {
					$pressable_site = get_pressable_site( $this->extract_domain_from_url( $site_url ) );
					$actual_host    = $pressable_site ? 'Pressable' : 'unknown';
					$note           = "Listed as Atomic but is_wpcom_atomic=false (actually {$actual_host}) - requires manual verification";
					$this->update_csv_row( $index, '', '', $note );
					$output->writeln( "<error>⚠ {$site_name}: {$note}</error>" );
					++$skipped;
					continue;
				}

				// Store CSV URL for environment detection (more reliable than API URL).
				$this->csv_url = $site_url;

				// Skip repository operations when --site-only is set globally.
				if ( ! $this->skip_repository ) {
					// Initialize repository (handle deployment configuration errors gracefully).
					try {
						$this->initialize_repository( $input, $output );
					} catch ( \Exception $e ) {
						// Check if it's a deployment configuration error (DeployHQ for Pressable, WPCOM GitHub Deployments for Atomic).
						$is_deployment_error = str_contains( $e->getMessage(), 'WPCOM GitHub Deployments' )
							|| str_contains( $e->getMessage(), 'GitHub repository' )
							|| str_contains( $e->getMessage(), 'DeployHQ' );

						if ( ! $is_deployment_error ) {
							// Re-throw if it's a different error.
							throw $e;
						}

						$deployment_type = ( 'pressable' === $host ) ? 'DeployHQ' : 'WPCOM GitHub';
						$output->writeln( "<comment>⚠ {$site_name}: Unable to find a {$deployment_type} deployment for the site.</comment>" );

						// Present the user with choices for how to proceed.
						$choices = array(
							'enter_repo' => 'Enter a repository URL or slug manually',
							'site_only'  => 'Skip repository, process site only (install Atlantis, handle plugins)',
							'skip'       => 'Skip this site entirely',
						);

						$question          = new ChoiceQuestion( '<question>How would you like to proceed?</question> ', $choices, 'skip' );
						$deployment_choice = $this->getHelper( 'question' )->ask( $input, $output, $question );

						if ( 'skip' === $deployment_choice ) {
							$note = "No {$deployment_type} deployment for the site - skipped by user";
							$this->update_csv_row( $index, '', '', $note );
							$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
							++$skipped;
							continue;
						}

						$this->deployment_not_found = true;

						if ( 'enter_repo' === $deployment_choice ) {
							$repo = $this->prompt_and_resolve_repository( $input, $output );
							if ( null === $repo ) {
								$note = "No {$deployment_type} deployment - manual repo entry failed";
								$this->update_csv_row( $index, '', '', $note );
								$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
								++$skipped;
								continue;
							}
							$this->gh_repository = $repo;
						} elseif ( 'site_only' === $deployment_choice ) {
							$this->skip_repository = true;
						}
					}

					if ( ! $this->skip_repository ) {
						$this->initialize_paths_and_branch( $input );

						// Determine environment and base branch.
						$environment = $this->get_site_environment( $output );
						if ( 'production' === $environment ) {
							$this->git_base_branch = 'trunk';
							$this->working_branch  = 'remove/atlantis-legacy-modules-trunk';
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
					}
				}

				// Process the site.
				$result = $this->process_single_site( $input, $output );

				if ( Command::INVALID === $result ) {
					// Site was skipped due to PHP version or branch issue.
					$note = $this->skip_note ?? 'Site skipped (unknown reason)';
					if ( ! $this->dry_run ) {
						$this->update_csv_row( $index, '', '', $note );
					}
					$output->writeln( "<comment>⚠ {$site_name}: {$note}</comment>" );
					++$skipped;
				} elseif ( Command::SUCCESS === $result ) {
					if ( $this->dry_run ) {
						$output->writeln( "<fg=yellow>✓ {$site_name} dry run complete</>" );
					} elseif ( $this->skip_repository ) {
						// Site-only processing: no repo operations.
						if ( $this->site_only_option ) {
							$note = 'Site-only: Atlantis active, legacy plugins cleaned up';
							if ( $this->skip_note ) {
								$note .= '; ' . $this->skip_note;
							}
						} else {
							$note = $this->skip_note
								? "Site-only: Atlantis installed, {$this->skip_note}"
								: 'Site-only: Atlantis installed, no deployment found (repo skipped)';
						}
						$this->update_csv_row( $index, '', 'Y', $note );
						$output->writeln( "<info>✓ {$site_name} completed (site-only, no repository)</info>" );
					} elseif ( $this->pr_url ) {
						// Update CSV with PR URL and Merged status.
						// Mark as 'Y' only if --merge-pr was used.
						$merged_status = $this->merge_pr ? 'Y' : '';
						$this->update_csv_row( $index, $this->pr_url, $merged_status, $this->skip_note ?? '' );
						$output->writeln( "<info>✓ {$site_name} completed successfully</info>" );
					} else {
						// No PR created - plugin installed, no repo changes needed (no legacy modules in repo).
						// This is still a successful completion.
						$note = 'Plugin installed (no repo changes needed)';
						if ( $this->skip_note ) {
							$note .= '; ' . $this->skip_note;
						}
						$this->update_csv_row( $index, '', 'Y', $note );
						$output->writeln( "<info>✓ {$site_name} completed successfully (no repo changes needed)</info>" );
					}
					if ( ! $this->dry_run ) {
							$processed_sites[] = array(
								'index'     => $index,
								'site_name' => $site_name,
								'site_url'  => $this->site->url ?? $site_url,
								'host'      => $this->host,
								'site'      => clone $this->site,
							);
						}
						++$processed;
				} else {
					$note = $this->skip_note ?? 'Processing failed';
					if ( ! $this->dry_run ) {
						$this->update_csv_row( $index, '', '', $note );
					}
					++$failed;
					$output->writeln( "<error>✗ {$site_name} failed: {$note}</error>" );
				}
			} catch ( \Exception $e ) {
				$note = 'Error: ' . $e->getMessage();
				if ( ! $this->dry_run ) {
					$this->update_csv_row( $index, '', '', $note );
				}
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

		// Second pass: verify site health for all processed sites.
		// This runs after all deployments have had time to propagate.
		if ( ! empty( $processed_sites ) ) {
			$output->writeln( '' );
			$output->writeln( '<fg=cyan;options=bold>=== Site Health Verification ===' );
			$output->writeln( "<info>Verifying {$processed} processed sites...</info>" );
			$output->writeln( '' );

			$health_warnings = 0;
			$health_counter  = 0;

			foreach ( $processed_sites as $site_info ) {
				++$health_counter;
				$output->writeln( "<info>[{$health_counter}/{$processed}] Verifying: {$site_info['site_name']} ({$site_info['site_url']})</info>" );

				// Restore site context for the health check.
				$this->site      = $site_info['site'];
				$this->host      = $site_info['host'];
				$this->skip_note = null;

				$this->verify_site_health( $output );

				if ( $this->skip_note ) {
					++$health_warnings;
					$this->append_csv_note( $site_info['index'], $this->skip_note );
					$output->writeln( "<error>⚠ {$site_info['site_name']}: {$this->skip_note}</error>" );
				}

				$output->writeln( '' );
			}

			$output->writeln( '<fg=cyan;options=bold>=== Health Check Summary ===' );
			$output->writeln( "<info>Sites verified: {$processed}</info>" );
			if ( $health_warnings > 0 ) {
				$output->writeln( "<error>Sites with warnings: {$health_warnings}</error>" );
			} else {
				$output->writeln( '<info>All sites passed health checks</info>' );
			}
		}

		return Command::SUCCESS;
	}

	/**
	 * Process repositories from a CSV file (repo-only mode).
	 *
	 * Performs only repository operations: clone, remove legacy modules, create PR.
	 * No site operations (no SSH, no plugin install/uninstall).
	 *
	 * CSV format: Repository,Branch,PR,Notes
	 *   Repository: GitHub repo name (e.g. "my-site-repo")
	 *   Branch: Base branch to target (e.g. "trunk", "master", "main"). Defaults to "trunk".
	 *   PR: Left empty, populated by the script with the PR URL.
	 *   Notes: Left empty, populated by the script.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  int
	 */
	private function process_repos_csv( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Processing repositories from CSV (repo-only mode)...</>' );
		$output->writeln( '' );

		// Read CSV.
		$handle = fopen( $this->repos_csv_path, 'r' );
		if ( false === $handle ) {
			$output->writeln( '<error>Could not open CSV file.</error>' );
			return Command::FAILURE;
		}

		$headers = fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $headers ) {
			fclose( $handle );
			$output->writeln( '<error>Could not read CSV headers.</error>' );
			return Command::FAILURE;
		}

		// Normalize headers.
		$headers = array_map( 'trim', $headers );

		// Find column indices.
		$repo_col   = array_search( 'Repository', $headers, true );
		$branch_col = array_search( 'Branch', $headers, true );

		if ( false === $repo_col ) {
			fclose( $handle );
			$output->writeln( '<error>CSV is missing required "Repository" column.</error>' );
			return Command::FAILURE;
		}

		// Read all rows.
		$csv_data = array();
		$row_idx  = 1;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			++$row_idx;
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}
			// Pad row to match headers.
			while ( count( $row ) < count( $headers ) ) {
				$row[] = '';
			}
			$csv_data[ $row_idx ] = array_combine( $headers, $row );
		}
		fclose( $handle );

		if ( empty( $csv_data ) ) {
			$output->writeln( '<error>No repositories found in CSV.</error>' );
			return Command::FAILURE;
		}

		$total     = count( $csv_data );
		$processed = 0;
		$skipped   = 0;
		$failed    = 0;
		$counter   = 0;

		foreach ( $csv_data as $index => $row_data ) {
			++$counter;
			$repo_name = trim( $row_data['Repository'] ?? '' );
			$base_branch = trim( $row_data['Branch'] ?? 'trunk' );

			if ( empty( $repo_name ) ) {
				$output->writeln( "<comment>[{$counter}/{$total}] Skipping empty repository name</comment>" );
				++$skipped;
				continue;
			}

			// Skip if already processed (has PR URL).
			if ( ! empty( $row_data['PR'] ?? '' ) ) {
				$output->writeln( "<comment>[{$counter}/{$total}] Skipping {$repo_name} - already has PR</comment>" );
				++$skipped;
				continue;
			}

			$output->writeln( "<info>[{$counter}/{$total}] Processing repository: {$repo_name} (branch: {$base_branch})</info>" );

			try {
				// Look up the GitHub repository.
				$this->gh_repository = get_github_repository( $repo_name );
				if ( ! $this->gh_repository || ! $this->gh_repository->name ) {
					$note = "Repository not found: {$repo_name}";
					$this->update_repos_csv_row( $index, '', $note );
					$output->writeln( "<error>✗ {$repo_name}: {$note}</error>" );
					++$failed;
					continue;
				}

				// Set up branches.
				$this->git_base_branch = $base_branch;
				$this->working_branch  = 'remove/atlantis-legacy-modules-' . $base_branch;

				// Set up paths.
				$this->repos_dir = getcwd() . '/repos';
				$this->repo_dir  = $this->repos_dir . '/' . $this->gh_repository->name;
				$this->pr_url    = null;
				$this->removed_modules = array();

				// Clone.
				$this->clone_repo( $output );

				// Checkout base branch.
				$checkout_process = new \Symfony\Component\Process\Process(
					array( 'git', 'checkout', $this->git_base_branch ),
					$this->repo_dir
				);
				$checkout_process->run();
				if ( ! $checkout_process->isSuccessful() ) {
					// Clean up.
					if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
						\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
					}
					$note = "Branch '{$this->git_base_branch}' not found";
					$this->update_repos_csv_row( $index, '', $note );
					$output->writeln( "<error>✗ {$repo_name}: {$note}</error>" );
					++$failed;
					continue;
				}

				// Checkout working branch.
				$this->git_checkout_branch( $this->working_branch, $output );

				// Search for legacy modules.
				$output->writeln( '<fg=cyan;options=bold>Searching for legacy modules to remove...</>' );
				$found_modules = array();
				foreach ( $this->legacy_modules as $plugin_name ) {
					$plugin_info = $this->find_plugin( $plugin_name, $output );
					if ( null !== $plugin_info ) {
						$found_modules[ $plugin_name ] = $plugin_info;
					}
				}

				if ( $this->dry_run ) {
					if ( ! empty( $found_modules ) ) {
						foreach ( $found_modules as $plugin_name => $plugin_info ) {
							$output->writeln( "<fg=yellow>[DRY RUN] Would remove {$plugin_name} from {$plugin_info['location']}</>" );
						}
						$output->writeln( "<fg=yellow>[DRY RUN] Would create PR to merge {$this->working_branch} into {$this->git_base_branch}</>" );
					} else {
						$output->writeln( '<fg=yellow>[DRY RUN] No legacy modules found in repository</>' );
					}
					// Clean up cloned repo.
					if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
						\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
					}
					$output->writeln( "<fg=yellow>✓ {$repo_name} dry run complete</>" );
					++$processed;
					continue;
				}

				if ( empty( $found_modules ) ) {
					// Clean up.
					if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
						\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
					}
					$this->update_repos_csv_row( $index, '', 'No legacy modules found' );
					$output->writeln( "<info>✓ {$repo_name}: No legacy modules found</info>" );
					++$processed;
					continue;
				}

				// Delete found modules.
				foreach ( $found_modules as $plugin_name => $plugin_info ) {
					$this->delete_plugin( $plugin_name, $plugin_info['location'], $plugin_info['path'], $output );
				}

				// Stage, commit, push, create PR (no merge in repo-only mode).
				if ( count( $this->removed_modules ) > 0 ) {
					// Stage and commit.
					\run_system_command( array( 'git', 'add', '.' ), $this->repo_dir, false );
					$output->writeln( '<info>Staged file changes.</info>' );

					\run_system_command( array( 'git', 'commit', '-m', 'Remove legacy modules' ), $this->repo_dir, false );
					$output->writeln( '<info>Committed file changes.</info>' );

					// Push.
					\run_system_command( array( 'git', 'push', '--force-with-lease', '--set-upstream', 'origin', $this->working_branch ), $this->repo_dir, false );
					$output->writeln( "<info>Pushed branch '{$this->working_branch}' to remote.</info>" );

					// Create PR (no merge).
					$process = \run_system_command(
						array(
							'gh',
							'pr',
							'create',
							'--title',
							'Atlantis Rollout - Remove legacy modules',
							'--body',
							"This PR was automatically generated by a script. Please review the changes and merge if they look good.\n\n@coderabbitai ignore",
							'--base',
							$this->git_base_branch,
							'--head',
							$this->working_branch,
							'--repo',
							'a8cteam51/' . $this->gh_repository->name,
						),
						$this->repo_dir,
						false
					);

					$pr_output = trim( $process->getOutput() . $process->getErrorOutput() );
					if ( ! empty( $pr_output ) ) {
						$output->writeln( $pr_output );
						if ( preg_match( '#https://github\.com/[^/]+/[^/]+/pull/\d+#', $pr_output, $matches ) ) {
							$this->pr_url = $matches[0];
						}
					}

					$this->update_repos_csv_row( $index, $this->pr_url ?? '', '' );
					$output->writeln( "<info>✓ {$repo_name} PR created</info>" );
				} else {
					$this->update_repos_csv_row( $index, '', 'Modules found but removal failed' );
					$output->writeln( "<comment>⚠ {$repo_name}: Modules found but removal failed</comment>" );
				}

				// Clean up cloned repo.
				if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
					\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
					$output->writeln( "<info>Deleted repository folder: {$this->repo_dir}</info>" );
				}

				++$processed;
			} catch ( \Exception $e ) {
				$this->update_repos_csv_row( $index, '', 'Error: ' . $e->getMessage() );
				++$failed;
				$output->writeln( "<error>✗ {$repo_name}: {$e->getMessage()}</error>" );
			}

			$output->writeln( '' );
		}

		// Summary.
		$output->writeln( '' );
		$output->writeln( '<fg=cyan;options=bold>=== Repository Processing Summary ===' );
		$output->writeln( "<info>Total repositories: {$total}</info>" );
		$output->writeln( "<info>Processed: {$processed}</info>" );
		$output->writeln( "<comment>Skipped: {$skipped}</comment>" );
		if ( $failed > 0 ) {
			$output->writeln( "<error>Failed: {$failed}</error>" );
		}

		return Command::SUCCESS;
	}

	/**
	 * Update a row in the repos CSV file.
	 *
	 * @param   int    $row_index The row index to update.
	 * @param   string $pr_url    The PR URL.
	 * @param   string $note      Optional note.
	 *
	 * @return  void
	 */
	private function update_repos_csv_row( int $row_index, string $pr_url, string $note ): void {
		if ( ! $this->repos_csv_path || ! file_exists( $this->repos_csv_path ) ) {
			return;
		}

		$rows   = array();
		$handle = fopen( $this->repos_csv_path, 'r' );
		if ( false === $handle ) {
			return;
		}

		$headers = fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $headers ) {
			fclose( $handle );
			return;
		}

		$pr_index    = array_search( 'PR', $headers, true );
		$notes_index = array_search( 'Notes', $headers, true );

		// Add missing columns if needed.
		if ( false === $pr_index ) {
			$headers[]  = 'PR';
			$pr_index   = count( $headers ) - 1;
		}
		if ( false === $notes_index ) {
			$headers[]   = 'Notes';
			$notes_index = count( $headers ) - 1;
		}

		$rows[] = $headers;

		$current_row = 1;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			++$current_row;

			while ( count( $row ) < count( $headers ) ) {
				$row[] = '';
			}

			if ( $current_row === $row_index ) {
				if ( false !== $pr_index && ! empty( $pr_url ) ) {
					$row[ $pr_index ] = $pr_url;
				}
				if ( false !== $notes_index && ! empty( $note ) ) {
					$row[ $notes_index ] = $note;
				}
			}

			$rows[] = $row;
		}

		fclose( $handle );

		$handle = fopen( $this->repos_csv_path, 'w' );
		if ( false === $handle ) {
			return;
		}

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '' );
		}

		fclose( $handle );
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
		if ( $this->skip_repository ) {
			$this->write_output( $output, "<fg=magenta;options=bold>Processing site-only operations for {$this->site->url} (no repository).</>" );
		} else {
			$this->write_output( $output, "<fg=magenta;options=bold>Processing repository for site {$this->site->url} (base branch: {$this->git_base_branch}).</>" );
		}

		// Pre-flight SSH check — verify connectivity before attempting any WP-CLI operations.
		if ( ! $this->check_ssh_access( $output ) ) {
			$this->skip_note = 'SSH access check failed (site may be unreachable)';
			$this->write_output( $output, "<error>{$this->skip_note}. Skipping site.</error>" );
			return Command::INVALID;
		}

		// Check PHP version first - Atlantis plugin requires PHP 8.2+.
		$this->check_php_version( $output );
		if ( null === $this->php_version ) {
			$this->skip_note = 'Unable to connect to site (SSH may be disabled)';
			$this->write_output( $output, "<error>{$this->skip_note}. Skipping site.</error>" );
			return Command::INVALID;
		}
		if ( ! $this->meets_php_requirement() ) {
			$this->skip_note = "PHP version {$this->php_version} < {$this->min_php_version} required";
			$this->write_output( $output, "<error>{$this->skip_note}. Skipping Atlantis plugin installation.</error>" );
			// Return a special status to indicate PHP version issue (caller will handle CSV update).
			return Command::INVALID;
		}

		$found_modules = array();

		if ( ! $this->skip_repository ) {
			$this->clone_repo( $output );

			// Checkout the base branch - handle failure gracefully.
			$checkout_process = \run_system_command( array( 'git', 'checkout', $this->git_base_branch ), $this->repo_dir, false );
			if ( ! $checkout_process->isSuccessful() ) {
				// Clean up the cloned repo folder.
				if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
					\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
				}

				if ( $this->deployment_not_found ) {
					// Repo was manually entered due to missing deployment — fall back to site-only.
					$this->skip_repository = true;
					$this->skip_note       = "Repo specified but branch '{$this->git_base_branch}' not found";
					$this->write_output( $output, "<comment>{$this->skip_note}. Continuing with site-only operations.</comment>" );
				} else {
					$this->skip_note = "Branch '{$this->git_base_branch}' not found";
					$this->write_output( $output, "<error>{$this->skip_note}. Skipping site.</error>" );
					return Command::INVALID;
				}
			}

			if ( ! $this->skip_repository ) {
				// Checkout the working branch for legacy module removal.
				$this->git_checkout_branch( $this->working_branch, $output );

				// Check for legacy modules and delete them if they exist.
				$this->write_output( $output, '' );
				$this->write_output( $output, '<fg=cyan;options=bold>Searching for legacy modules to remove...</>' );

				// First, find which legacy modules exist.
				foreach ( $this->legacy_modules as $plugin_name ) {
					$plugin_info = $this->find_plugin( $plugin_name, $output );
					if ( null !== $plugin_info ) {
						$found_modules[ $plugin_name ] = $plugin_info;
					}
				}
			}
		}

		// When --site-only was explicitly passed, check if Atlantis is already active.
		// If not, install it (provided PHP version is sufficient).
		if ( $this->skip_repository && $this->site_only_option ) {
			$atlantis_active = $this->verify_atlantis_active( $output );

			if ( $this->dry_run ) {
				$this->write_output( $output, '<fg=yellow>[DRY RUN] Would ' . ( $atlantis_active ? 'uninstall legacy plugins' : 'install Atlantis and uninstall legacy plugins' ) . '</>' );
				return Command::SUCCESS;
			}

			if ( $atlantis_active ) {
				// Atlantis is already active — proceed directly to uninstalling legacy plugins.
				$this->uninstall_plugins_from_wordpress( $output );
				return Command::SUCCESS;
			}

			// Atlantis not active — install it.
			$this->check_autoupdate_filter_status( $output );

			$plugin_installed = $this->install_atlantis_plugin( $output );
			if ( ! $plugin_installed ) {
				$this->skip_note = 'Atlantis plugin installation failed (site has a critical error)';
				return Command::FAILURE;
			}

			$this->uninstall_plugins_from_wordpress( $output );
			return Command::SUCCESS;
		}

		// Check autoupdate filter status BEFORE installing Atlantis.
		// This must happen first so the database option is set before Atlantis activates.
		$this->check_autoupdate_filter_status( $output );

		if ( $this->dry_run ) {
			// In dry-run mode, report what would happen without making changes.
			$this->write_output( $output, '' );
			$this->write_output( $output, '<fg=yellow>[DRY RUN] Would install Atlantis plugin</>' );

			if ( ! $this->skip_repository && ! empty( $found_modules ) ) {
				foreach ( $found_modules as $plugin_name => $plugin_info ) {
					$this->write_output( $output, "<fg=yellow>[DRY RUN] Would remove {$plugin_name} from {$plugin_info['location']}</>" );
				}
				$this->write_output( $output, "<fg=yellow>[DRY RUN] Would create PR to merge {$this->working_branch} into {$this->git_base_branch}</>" );
			}

			if ( $this->uninstall_plugins ) {
				$this->write_output( $output, '<fg=yellow>[DRY RUN] Would uninstall legacy plugins from WordPress</>' );
			}

			// Clean up cloned repo.
			if ( $this->repo_dir && $this->repos_dir && str_starts_with( $this->repo_dir, $this->repos_dir ) ) {
				\run_system_command( array( 'rm', '-rf', $this->repo_dir ), $this->repo_dir, false );
			}

			return Command::SUCCESS;
		}

		// Always install the Atlantis plugin.
		$plugin_installed = $this->install_atlantis_plugin( $output );

		if ( ! $plugin_installed ) {
			$this->skip_note = 'Atlantis plugin installation failed (site has a critical error)';
			return Command::FAILURE;
		}

		if ( ! $this->skip_repository && $plugin_installed && ! empty( $found_modules ) ) {
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

		if ( ! $this->skip_repository && count( $this->removed_modules ) > 0 ) {
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
				// In CSV processing mode, throw exception to trigger the "no deployment found"
				// flow which offers manual repo entry, site-only, or skip options.
				if ( $this->sites_csv_path ) {
					throw new \Exception( 'Unable to find WPCOM GitHub Deployments for the site.' );
				}

				// In interactive mode, ask user.
				$output->writeln( '<error>Unable to find WPCOM GitHub Deployments for the site.</error>' );
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
	 * Prompts the user for a GitHub repository URL or slug and resolves it via the GitHub API.
	 *
	 * Accepts a plain slug ("my-repo"), org/slug ("a8cteam51/my-repo"),
	 * a full HTTPS URL ("https://github.com/a8cteam51/my-repo"), or
	 * an SSH clone URL ("git@github.com:a8cteam51/my-repo.git").
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  \stdClass|null The resolved GitHub repository object, or null on failure.
	 */
	private function prompt_and_resolve_repository( InputInterface $input, OutputInterface $output ): ?\stdClass {
		$question   = new Question( '<question>Enter the GitHub repository URL or slug (e.g. "my-repo" or "https://github.com/a8cteam51/my-repo"):</question> ' );
		$repo_input = $this->getHelper( 'question' )->ask( $input, $output, $question );

		if ( empty( $repo_input ) ) {
			$output->writeln( '<error>No repository provided.</error>' );
			return null;
		}

		$repo_input = trim( $repo_input );

		// Try to parse as a full URL (HTTPS or SSH).
		$git_url = str_ends_with( $repo_input, '.git' ) ? $repo_input : $repo_input . '.git';
		$parsed  = parse_github_remote_repository_url( $git_url );

		if ( null !== $parsed && ! empty( $parsed->repo ) ) {
			$repo_slug = $parsed->repo;
		} elseif ( str_contains( $repo_input, '/' ) ) {
			// Handle "org/repo" format — extract the repo part.
			$parts     = explode( '/', rtrim( $repo_input, '/' ) );
			$repo_slug = end( $parts );
		} else {
			// Plain slug.
			$repo_slug = $repo_input;
		}

		$output->writeln( "<comment>Resolving repository: {$repo_slug}...</comment>" );

		$repository = get_github_repository( $repo_slug );
		if ( null === $repository || empty( $repository->name ) ) {
			$output->writeln( "<error>Could not find GitHub repository: {$repo_slug}</error>" );
			return null;
		}

		$output->writeln( "<info>Found repository: {$repository->name} ({$repository->clone_url})</info>" );
		return $repository;
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

			// Shallow clone (depth 1) to save time on large repos, with no timeout.
			// Use --no-single-branch so we can checkout the base branch later.
			$clone_process = new \Symfony\Component\Process\Process(
				array( 'git', 'clone', '--depth', '1', '--no-single-branch', $this->gh_repository->clone_url, $this->repo_dir )
			);
			$clone_process->setTimeout( null );
			$clone_process->mustRun();
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
		\run_system_command( array( 'git', 'push', '--force-with-lease', '--set-upstream', 'origin', $this->working_branch ), $this->repo_dir, false );
		$this->write_output( $output, "<info>Pushed branch '{$this->working_branch}' to remote.</info>" );

		// Create PR from working branch to the base branch.
		// Always show PR creation output, even in silent mode.
		$this->write_output( $output, "<info>Creating PR to merge {$this->working_branch} into {$this->git_base_branch}...</info>", OutputInterface::VERBOSITY_QUIET );

		// Try to create the PR. If it already exists, gh will output the existing PR URL.
		$process = \run_system_command(
			array(
				'gh',
				'pr',
				'create',
				'--title',
				'Atlantis Rollout - Remove legacy modules',
				'--body',
				"This PR was automatically generated by a script. Please review the changes and merge if they look good.\n\n@coderabbitai ignore",
				'--base',
				$this->git_base_branch,
				'--head',
				$this->working_branch,
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
					$this->working_branch,
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
						$this->working_branch,
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
			\run_system_command( array( 'git', 'rm', '-rf', $submodule_path ), $this->repo_dir, false );

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
		$plugin_url      = 'https://github.com/a8cteam51/a8csp-atlantis/releases/download/v1.0.9/a8csp-atlantis.zip';
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

			// Check if installation was successful (check both return code and WP-CLI output).
			if ( Command::SUCCESS !== $install_result || $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <error>Plugin installation failed (site has a critical error).</error>' );
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

			// Check if activation was successful (check both return code and WP-CLI output).
			if ( Command::SUCCESS !== $activate_result || $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <error>Plugin activation failed (site has a critical error).</error>' );
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
	 * Verifies that the Atlantis plugin is installed and active on the site.
	 *
	 * Used in --site-only mode to confirm Atlantis is present before
	 * proceeding with legacy plugin cleanup.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool True if a8csp-atlantis is active, false otherwise.
	 */
	private function verify_atlantis_active( OutputInterface $output ): bool {
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Verifying Atlantis plugin is active...</>' );

		try {
			$list_command = 'plugin list --format=json';

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $list_command, true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $list_command, true );
			}

			if ( $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <error>Could not retrieve plugin list (site has a critical error).</error>' );
				return false;
			}

			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';
			$plugins       = null;
			if ( preg_match( '/\[.*\]/s', $wp_cli_output, $matches ) ) {
				$plugins = json_decode( $matches[0], true );
			}

			if ( ! is_array( $plugins ) ) {
				$this->write_output( $output, '  <error>Could not parse plugin list.</error>' );
				return false;
			}

			$active_statuses = array( 'active', 'active-network', 'must-use' );
			foreach ( $plugins as $plugin ) {
				if ( isset( $plugin['name'] ) && 'a8csp-atlantis' === $plugin['name'] ) {
					if ( in_array( $plugin['status'], $active_statuses, true ) ) {
						$this->write_output( $output, "  <info>Atlantis is active (status: {$plugin['status']})</info>" );
						return true;
					}
					$this->write_output( $output, "  <comment>Atlantis found but not active (status: {$plugin['status']})</comment>" );
					return false;
				}
			}

			$this->write_output( $output, '  <comment>Atlantis plugin not found on the site.</comment>' );
			return false;
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to verify Atlantis status: {$e->getMessage()}</error>" );
			return false;
		}
	}

	/**
	 * Verifies site health after changes by checking for PHP fatal errors and
	 * exposed shortcodes on the front-end.
	 *
	 * Clears the site cache first, then:
	 * 1. Runs `wp php-errors` to check for recent fatal errors.
	 * 2. Fetches the front-end HTML and looks for unrendered shortcode tags
	 *    (e.g. [team51-credits]) that indicate a missing shortcode handler.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function verify_site_health( OutputInterface $output ): void {
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Verifying site health...</>' );

		// Clear caches before checking.
		try {
			$this->write_output( $output, '  Clearing object cache...' );
			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, 'cache flush', true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, 'cache flush', true );
			}
			$this->write_output( $output, '  <info>Object cache cleared</info>' );
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <comment>Object cache flush failed: {$e->getMessage()}</comment>" );
		}

		// Check 1: Exposed shortcodes on the front-end.
		$shortcode_patterns = array(
			'team51-credits',
			'team51-tracking',
			'colophon',
		);

		$has_exposed_shortcodes = false;
		try {
			$site_url = $this->site->url ?? '';
			if ( ! empty( $site_url ) ) {
				$this->write_output( $output, '  Checking front-end for exposed shortcodes...' );

				// Ensure URL has a scheme.
				if ( ! preg_match( '#^https?://#i', $site_url ) ) {
					$site_url = 'https://' . $site_url;
				}

				// Add cache-busting query string to bypass edge cache.
				$site_url = rtrim( $site_url, '/' ) . '/?nocache=' . time();

				$context = stream_context_create(
					array(
						'http' => array(
							'method'          => 'GET',
							'timeout'         => 15,
							'follow_location' => true,
							'ignore_errors'   => true,
							'header'          => 'User-Agent: Mozilla/5.0 (team51-cli health check)',
						),
						'ssl'  => array(
							'verify_peer' => false,
						),
					)
				);

				$html = @file_get_contents( $site_url, false, $context );

				if ( false !== $html ) {
					foreach ( $shortcode_patterns as $shortcode ) {
						// Match [shortcode] or [shortcode ...attributes].
						if ( preg_match( '/\[' . preg_quote( $shortcode, '/' ) . '[\]\s]/i', $html ) ) {
							$has_exposed_shortcodes = true;
							$this->write_output( $output, "  <error>⚠ Exposed shortcode found: [{$shortcode}]</error>" );
						}
					}

					if ( ! $has_exposed_shortcodes ) {
						$this->write_output( $output, '  <info>No exposed shortcodes found on front-end</info>' );
					}
				} else {
					$this->write_output( $output, '  <comment>Could not fetch front-end HTML</comment>' );
				}
			}
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <comment>Could not check front-end: {$e->getMessage()}</comment>" );
		}

		// Check 2: PHP fatal errors via wp php-errors.
		$has_errors = false;
		try {
			$this->write_output( $output, '  Checking for PHP errors...' );

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, 'php-errors', true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, 'php-errors', true );
			}

			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

			// Only flag errors from today to avoid false positives from old log entries.
			// Log format: [08-Apr-2026 12:34:56 UTC] PHP Fatal error: ...
			$today_prefix = gmdate( 'd-M-Y' );
			$lines        = array_filter( explode( "\n", $wp_cli_output ) );
			$today_errors = array();

			foreach ( $lines as $line ) {
				if ( str_contains( $line, $today_prefix ) && preg_match( '/fatal|critical/i', $line ) ) {
					$today_errors[] = $line;
				}
			}

			if ( ! empty( $today_errors ) ) {
				$has_errors  = true;
				$error_count = count( $today_errors );
				$this->write_output( $output, "  <error>⚠ {$error_count} PHP fatal/critical error(s) detected today:</error>" );
				$count = 0;
				foreach ( $today_errors as $line ) {
					if ( $count < 5 ) {
						$this->write_output( $output, "    <error>{$line}</error>" );
						++$count;
					}
				}
			} else {
				$this->write_output( $output, '  <info>No PHP fatal errors detected today</info>' );
			}
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <comment>Could not check PHP errors: {$e->getMessage()}</comment>" );
		}

		// Summary.
		if ( $has_errors || $has_exposed_shortcodes ) {
			$this->skip_note = ( $this->skip_note ? $this->skip_note . '; ' : '' ) . 'HEALTH CHECK WARNING:'
				. ( $has_errors ? ' PHP fatal errors detected' : '' )
				. ( $has_exposed_shortcodes ? ' Exposed shortcodes on front-end' : '' );
			$this->write_output( $output, '' );
			$this->write_output( $output, "<error>⚠ Site health check found issues — manual review recommended</error>" );
		} else {
			$this->write_output( $output, '  <info>✓ Site health check passed</info>' );
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

			// Check for critical errors in WP-CLI output before parsing.
			if ( $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <comment>Could not parse plugin list (site has a critical error).</comment>' );
				return;
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
				if ( isset( $plugin['name'] ) && str_starts_with( strtolower( $plugin['name'] ), 'plugin-autoupdate-filter' ) ) {
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
				if ( $this->dry_run ) {
					$this->write_output( $output, '  <fg=yellow>[DRY RUN] Would disable Atlantis autoupdates module (plugin found but not active)</>' );
				} else {
					$this->write_output( $output, '  <comment>Plugin found but not active. Disabling Atlantis autoupdates module...</comment>' );

					// Use wp eval to set the option as an array directly (avoids shell escaping issues).
					$option_command = 'eval "update_option( \'a8csp_module_autoupdates\', array( \'enabled\' => \'0\' ) );"';

					if ( 'pressable' === $this->host ) {
						run_pressable_site_wp_cli_command( $site_identifier, $option_command, $this->quiet );
					} else {
						run_wpcom_site_wp_cli_command( $site_identifier, $option_command, $this->quiet );
					}

					$this->write_output( $output, '  <info>✓ Atlantis autoupdates module disabled</info>' );
				}
			} else {
				$this->write_output( $output, '  <info>Plugin is active, Atlantis autoupdates module will remain enabled.</info>' );
			}
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to check plugin status: {$e->getMessage()}</error>" );
			$this->write_output( $output, '  <comment>Continuing with legacy module removal...</comment>' );
		}
	}

	/**
	 * Uninstalls all legacy plugins from WordPress.
	 *
	 * Discovers installed plugins by prefix-matching against the legacy module
	 * slugs so that variants like "colophon-trunk", "colophon-1.2.1",
	 * "plugin-autoupdate-filter-1", etc. are also caught.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function uninstall_plugins_from_wordpress( OutputInterface $output ): void {
		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Deactivating and uninstalling plugins from WordPress...</>' );

		try {
			// Get the list of installed plugins to find variant slugs.
			$list_command = 'plugin list --format=json';

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $list_command, true );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $list_command, true );
			}

			if ( $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <error>Plugin uninstall may have failed (site has a critical error).</error>' );
				return;
			}

			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';
			$plugins       = null;
			if ( preg_match( '/\[.*\]/s', $wp_cli_output, $matches ) ) {
				$plugins = json_decode( $matches[0], true );
			}

			// Build the list of actual slugs to uninstall by prefix-matching.
			$slugs_to_uninstall = array();

			if ( is_array( $plugins ) ) {
				foreach ( $plugins as $plugin ) {
					if ( ! isset( $plugin['name'] ) ) {
						continue;
					}
					$plugin_name_lower = strtolower( $plugin['name'] );
					foreach ( $this->legacy_modules as $legacy_slug ) {
						// Match exact slug or slug followed by a dash (variant suffix), case-insensitive.
						if ( $plugin_name_lower === $legacy_slug || str_starts_with( $plugin_name_lower, $legacy_slug . '-' ) ) {
							$slugs_to_uninstall[] = $plugin['name'];
							break;
						}
					}
				}
			} else {
				// Fallback to exact slugs if we can't parse the plugin list.
				$this->write_output( $output, '  <comment>Could not parse plugin list, falling back to exact slugs.</comment>' );
				$slugs_to_uninstall = $this->legacy_modules;
			}

			if ( empty( $slugs_to_uninstall ) ) {
				$this->write_output( $output, '  <info>No legacy plugins found on the site to uninstall.</info>' );
				return;
			}

			$plugins_list = implode( ' ', $slugs_to_uninstall );
			$this->write_output( $output, "  Uninstalling: {$plugins_list}" );

			$command = "plugin uninstall {$plugins_list} --deactivate";

			if ( 'pressable' === $this->host ) {
				run_pressable_site_wp_cli_command( $site_identifier, $command, $this->quiet );
			} else {
				run_wpcom_site_wp_cli_command( $site_identifier, $command, $this->quiet );
			}

			if ( $this->wp_cli_output_has_errors() ) {
				$this->write_output( $output, '  <error>Plugin uninstall may have failed (site has a critical error).</error>' );
				return;
			}

			$this->write_output( $output, '  <info>✓ Plugins deactivated and uninstalled from WordPress</info>' );
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>Failed to uninstall plugins from WordPress: {$e->getMessage()}</error>" );
			$this->write_output( $output, '  <comment>This is usually fine if the plugins were not installed on the site.</comment>' );
		}
	}

	/**
	 * Check if SSH access is available by running a simple WP-CLI command.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool True if SSH access works.
	 */
	private function check_ssh_access( OutputInterface $output ): bool {
		$this->write_output( $output, '' );
		$this->write_output( $output, '<fg=cyan;options=bold>Checking SSH access...</>' );

		$site_identifier = 'atomic' === $this->host ? $this->site->ID : $this->site->id;

		try {
			$command = "eval 'echo \"ok\";'";

			if ( 'pressable' === $this->host ) {
				$result = run_pressable_site_wp_cli_command( $site_identifier, $command, true );
			} else {
				$result = run_wpcom_site_wp_cli_command( $site_identifier, $command, true );
			}

			$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

			if ( str_contains( $wp_cli_output, 'Fatal error:' ) || str_contains( $wp_cli_output, 'critical error on this website' ) ) {
				$this->write_output( $output, '  <error>SSH connected but site has a fatal error</error>' );
				return false;
			}

			if ( Command::SUCCESS === $result && str_contains( $wp_cli_output, 'ok' ) ) {
				$this->write_output( $output, '  <info>SSH access verified</info>' );
				return true;
			}

			$this->write_output( $output, '  <error>SSH access check failed</error>' );
			return false;
		} catch ( \Exception $e ) {
			$this->write_output( $output, "  <error>SSH access check error: {$e->getMessage()}</error>" );
			return false;
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
		$headers = fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $headers ) {
			fclose( $handle );
			return $csv_data;
		}

		// Read data rows.
		$row_index = 1; // Start at 1 (0 is header).
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
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
		$headers = fgetcsv( $handle, 0, ',', '"', '' );
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
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
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
			fputcsv( $handle, $row, ',', '"', '' );
		}

		fclose( $handle );
	}

	/**
	 * Append a note to an existing CSV row without changing PR or Merged columns.
	 *
	 * @param   int    $row_index The row index to update.
	 * @param   string $note      The note to append.
	 *
	 * @return  void
	 */
	private function append_csv_note( int $row_index, string $note ): void {
		if ( ! $this->sites_csv_path || ! file_exists( $this->sites_csv_path ) ) {
			return;
		}

		$rows   = array();
		$handle = fopen( $this->sites_csv_path, 'r' );
		if ( false === $handle ) {
			return;
		}

		$headers = fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $headers ) {
			fclose( $handle );
			return;
		}

		$notes_index = array_search( 'Notes', $headers, true );
		if ( false === $notes_index ) {
			$headers[]   = 'Notes';
			$notes_index = count( $headers ) - 1;
		}

		$rows[] = $headers;

		$current_row = 1;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			++$current_row;

			// Ensure row has enough columns.
			while ( count( $row ) < count( $headers ) ) {
				$row[] = '';
			}

			if ( $current_row === $row_index ) {
				$existing = trim( $row[ $notes_index ] ?? '' );
				$row[ $notes_index ] = $existing ? $existing . '; ' . $note : $note;
			}

			$rows[] = $row;
		}

		fclose( $handle );

		$handle = fopen( $this->sites_csv_path, 'w' );
		if ( false === $handle ) {
			return;
		}

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '' );
		}

		fclose( $handle );
	}

	/**
	 * Checks whether the last WP-CLI command output contains fatal/critical error indicators.
	 *
	 * The underlying WP-CLI command runners always return Command::SUCCESS as long as
	 * the SSH connection succeeds, even when WP-CLI itself encounters a fatal PHP error.
	 * This method inspects the captured output to detect such failures.
	 *
	 * @return  bool True if the output contains error indicators, false otherwise.
	 */
	private function wp_cli_output_has_errors(): bool {
		$wp_cli_output = $GLOBALS['wp_cli_output'] ?? '';

		if ( empty( $wp_cli_output ) ) {
			return false;
		}

		// Check for common WP-CLI / PHP fatal error patterns.
		return str_contains( $wp_cli_output, 'Fatal error:' )
			|| str_contains( $wp_cli_output, 'critical error on this website' );
	}

	/**
	 * Reset site state between processing multiple sites.
	 *
	 * @return  void
	 */
	private function reset_site_state(): void {
		$this->site                 = null;
		$this->deployhq_project     = null;
		$this->gh_repository        = null;
		$this->gh_repo_branch       = null;
		$this->repo_dir             = null;
		$this->removed_modules      = array();
		$this->git_base_branch      = 'develop';
		$this->working_branch       = 'remove/atlantis-legacy-modules-develop';
		$this->php_version          = null;
		$this->skip_note            = null;
		$this->csv_url              = null;
		$this->skip_repository      = $this->site_only_option;
		$this->deployment_not_found = false;
	}

	// endregion
}
