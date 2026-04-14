<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Audits a8cteam51 GitHub repositories for legacy modules.
 *
 * Examples:
 *   # Check for trunk/master/main branches (default) and scan for legacy modules
 *   team51 github:audit-repositories --export=repos-audit.csv
 *
 *   # Check for develop branch only (original behavior)
 *   team51 github:audit-repositories --branches=develop --export=repos-develop.csv
 *
 *   # Check for specific branches
 *   team51 github:audit-repositories --branches=trunk,main --export=repos-audit.csv
 */
#[AsCommand( name: 'github:audit-repositories' )]
final class GitHub_Repository_Audit extends Command {

	// region FIELDS AND CONSTANTS

	/**
	 * The list of GitHub repositories to process.
	 *
	 * @var stdClass[]|null
	 */
	private ?array $repositories = null;

	/**
	 * The branches to check for on each repository.
	 *
	 * @var string[]
	 */
	private array $branches = array( 'trunk', 'master', 'main' );

	/**
	 * The terms to search for in directory listings.
	 *
	 * @var string[]
	 */
	private array $search_terms = array(
		'colophon',
		'team51-tracking',
		'plugin-autoupdate-filter',
	);

	/**
	 * The directories to scan for legacy modules.
	 *
	 * @var string[]
	 */
	private array $search_dirs = array(
		'plugins',
		'mu-plugins',
		'modules',
	);

	/**
	 * File handle for CSV export.
	 *
	 * @var resource|null
	 */
	private $stream = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Audits a8cteam51 GitHub repositories for legacy modules.' )
			->setHelp( 'Lists all repositories, checks for specified branches, and scans for legacy modules (colophon, team51-tracking, plugin-autoupdate-filter) in plugins, mu-plugins, and modules directories.' );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'Path to export CSV results to.' )
			->addOption( 'branches', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of branches to check for (default: trunk,master,main).' )
			->addOption( 'terms', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of terms to search for (default: colophon,team51-tracking,plugin-autoupdate-filter).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Override branches if provided.
		$branches = $input->getOption( 'branches' );
		if ( ! empty( $branches ) ) {
			$this->branches = array_map( 'trim', explode( ',', $branches ) );
		}

		// Override search terms if provided.
		$terms = $input->getOption( 'terms' );
		if ( ! empty( $terms ) ) {
			$this->search_terms = array_map( 'trim', explode( ',', $terms ) );
		}

		// Fetch all repositories.
		$output->writeln( '<comment>Fetching all a8cteam51 repositories...</comment>' );
		$this->repositories = get_github_repositories();
		if ( empty( $this->repositories ) ) {
			$output->writeln( '<error>No repositories found.</error>' );
			return;
		}
		$output->writeln( '<info>Found ' . count( $this->repositories ) . ' repositories.</info>' );

		// Build branch column headers.
		$branch_headers = array_map( fn( $b ) => "Has {$b}", $this->branches );

		// Open export file if requested and write headers immediately.
		$export_path = $input->getOption( 'export' );
		if ( ! empty( $export_path ) ) {
			$this->stream = get_file_handle( $export_path, 'csv' );
			$headers      = array_merge( array( 'Repository' ), $branch_headers, $this->search_terms );
			\fputcsv( $this->stream, $headers );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( empty( $this->repositories ) ) {
			return Command::FAILURE;
		}

		$output->writeln( '<fg=magenta;options=bold>Auditing repositories for legacy modules...</>' );

		$results = array();
		$total   = count( $this->repositories );

		$progress_bar = new ProgressBar( $output, $total );
		$progress_bar->setFormat( ' %current%/%max% [%bar%] %percent:3s%% %message%' );
		$progress_bar->setMessage( 'Starting...' );
		$progress_bar->start();

		foreach ( $this->repositories as $repo ) {
			$progress_bar->setMessage( $repo->name );
			$progress_bar->advance();

			// Check which branches exist.
			$repo_branches  = $this->get_repo_branch_names( $repo->name );
			$scan_branch    = null;

			$row = array( 'Repository' => $repo->name );

			foreach ( $this->branches as $branch ) {
				$has_branch          = \in_array( $branch, $repo_branches, true );
				$row[ "Has {$branch}" ] = $has_branch ? 'Yes' : 'No';

				// Use the first matching branch for module scanning.
				if ( $has_branch && null === $scan_branch ) {
					$scan_branch = $branch;
				}
			}

			if ( null !== $scan_branch ) {
				$found_modules = $this->scan_repo_for_modules( $repo->name, $scan_branch );
				foreach ( $this->search_terms as $term ) {
					$row[ $term ] = $found_modules[ $term ] ?? '';
				}
			} else {
				foreach ( $this->search_terms as $term ) {
					$row[ $term ] = '';
				}
			}

			$results[] = $row;

			// Write row to CSV immediately so results are available during the run.
			if ( null !== $this->stream ) {
				\fputcsv( $this->stream, array_values( $row ) );
				\fflush( $this->stream );
			}
		}

		$progress_bar->finish();
		$output->writeln( '' );

		// Build branch column headers.
		$branch_headers = array_map( fn( $b ) => "Has {$b}", $this->branches );

		// Output console table.
		$headers = array_merge( array( 'Repository' ), $branch_headers, $this->search_terms );
		output_table( $output, array_map( 'array_values', $results ), $headers, 'Repository Audit - Legacy Modules' );

		// Summary statistics.
		$output->writeln( '' );
		$output->writeln( "<info>Total repositories: {$total}</info>" );

		foreach ( $this->branches as $branch ) {
			$count = count( array_filter( $results, fn( $r ) => 'Yes' === $r[ "Has {$branch}" ] ) );
			$output->writeln( "<info>Repositories with '{$branch}' branch: {$count}</info>" );
		}

		$repos_with_modules = count(
			array_filter(
				$results,
				function ( $r ) {
					foreach ( $this->search_terms as $term ) {
						if ( ! empty( $r[ $term ] ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		$output->writeln( "<info>Repositories with legacy modules: {$repos_with_modules}</info>" );

		// Close CSV file.
		if ( null !== $this->stream ) {
			\fclose( $this->stream );

			$export_path = $input->getOption( 'export' );
			$output->writeln( "<info>Results exported to {$export_path}</info>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the list of branch names for a repository.
	 *
	 * @param   string $repository The name of the repository.
	 *
	 * @return  string[]
	 */
	private function get_repo_branch_names( string $repository ): array {
		$branches = get_github_repository_branches( $repository );
		if ( empty( $branches ) ) {
			return array();
		}

		return array_column( $branches, 'name' );
	}

	/**
	 * Scans a repo's branch for legacy modules in plugins/mu-plugins/modules directories.
	 *
	 * @param   string $repository The name of the repository.
	 * @param   string $ref        The branch to scan.
	 *
	 * @return  array Associative array: term => location path(s).
	 */
	private function scan_repo_for_modules( string $repository, string $ref ): array {
		$found = array();

		foreach ( $this->search_dirs as $dir ) {
			$contents = get_github_repository_contents( $repository, $dir, $ref );
			if ( empty( $contents ) ) {
				continue;
			}

			foreach ( $contents as $item ) {
				foreach ( $this->search_terms as $term ) {
					if ( $item->name === $term || str_starts_with( $item->name, $term . '-' ) ) {
						$location      = $dir . '/' . $item->name;
						$found[ $term ] = isset( $found[ $term ] )
							? $found[ $term ] . ', ' . $location
							: $location;
					}
				}
			}
		}

		return $found;
	}

	// endregion
}
