<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Adds Bilmur tracking constants to wp-config for Pressable sites.
 *
 * Examples:
 *   # Single site
 *   team51 pressable:add-bilmur-tracking example.mystagingwebsite.com
 *
 *   # Single site dry run
 *   team51 pressable:add-bilmur-tracking example.mystagingwebsite.com --dry-run
 *
 *   # Batch mode from CSV
 *   team51 pressable:add-bilmur-tracking --sites=bilmur-sites.csv --no-output
 *
 *   # Batch mode dry run
 *   team51 pressable:add-bilmur-tracking --sites=bilmur-sites.csv --dry-run
 *
 * CSV File Format:
 *   Site,URL,Host,Done,Notes
 *   "Example Site",https://example.mystagingwebsite.com,Pressable,,
 */
#[AsCommand( name: 'pressable:add-bilmur-tracking' )]
final class Pressable_Add_Bilmur_Tracking extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Bilmur constants to set on Pressable sites.
	 */
	private const BILMUR_CONSTANTS = array(
		array(
			'name'  => 'WPCOMSP_BILMUR_TRACKING',
			'value' => 'true',
			'raw'   => true,
		),
		array(
			'name'  => 'WPCOMSP_BILMUR_PROVIDER',
			'value' => 'wp.cloud',
			'raw'   => false,
		),
		array(
			'name'  => 'WPCOMSP_BILMUR_SERVICE',
			'value' => 'pressable.com',
			'raw'   => false,
		),
		array(
			'name'  => 'WPCOMSP_BILMUR_CUSTOM_PROPERTIES',
			'value' => "array( 'wpcomsp' => '1' )",
			'raw'   => true,
		),
	);

	/**
	 * The site object.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Whether to skip confirmations and minimize output.
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Whether to run in dry-run mode.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Path to the CSV file with sites to process.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Adds Bilmur tracking constants to wp-config for Pressable sites.' )
			->setHelp( 'Use this command to add Bilmur tracking constants to a given Pressable site, or to multiple sites using a CSV file.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The site URL or domain to add tracking constants to.' )
			->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to a CSV file containing sites to process (Site,URL,Host,Done,Notes).' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Skip confirmations and minimize output.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Check constants without making any changes.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Check if processing multiple sites from CSV.
		$this->sites_csv_path = $input->getOption( 'sites' );

		if ( $this->sites_csv_path ) {
			$this->quiet   = (bool) $input->getOption( 'no-output' );
			$this->dry_run = (bool) $input->getOption( 'dry-run' );

			if ( ! file_exists( $this->sites_csv_path ) ) {
				$output->writeln( "<error>CSV file not found: {$this->sites_csv_path}</error>" );
				exit( 1 );
			}

			return;
		}

		// Single-site mode.
		$this->quiet   = (bool) $input->getOption( 'no-output' );
		$this->dry_run = (bool) $input->getOption( 'dry-run' );

		// Get and validate the site argument using the shared Pressable helper.
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( $this->quiet || $this->sites_csv_path ) {
			return;
		}

		$question = new ConfirmationQuestion( "<question>Are you sure you want to add 4 Bilmur constants to {$this->site->url} [pressable]? [y/N]</question> ", false );

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run && ! $this->quiet ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN MODE: No changes will be made ---</>' );
			$output->writeln( '' );
		}

		if ( $this->sites_csv_path ) {
			return $this->process_csv( $input, $output );
		}

		return $this->process_single_site( $output );
	}

	// endregion

	// region PROCESSING

	/**
	 * Process a single site.
	 *
	 * @param OutputInterface $output The output object.
	 *
	 * @return int
	 */
	private function process_single_site( OutputInterface $output ): int {
		$result = $this->set_bilmur_constants( $output );
		if ( ! $this->quiet ) {
			$output->writeln( '' );
			$output->writeln( "<info>Result: {$result['note']}</info>" );
		}
		return $result['success'] ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * Process multiple sites from a CSV file.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return int
	 */
	private function process_csv( InputInterface $input, OutputInterface $output ): int {
		if ( ! $this->quiet ) {
			$output->writeln( '<info>Processing sites from CSV file...</info>' );
			$output->writeln( '' );
		}

		$csv_data = $this->read_csv( $this->sites_csv_path );
		if ( empty( $csv_data ) ) {
			$output->writeln( '<error>No valid sites found in CSV file.</error>' );
			return Command::FAILURE;
		}

		// Validate required columns.
		$first_row        = reset( $csv_data );
		$required_columns = array( 'Site', 'URL' );
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

			// Validate URL.
			if ( empty( $site_url ) ) {
				$note = 'Missing required field (URL)';
				if ( ! $this->dry_run ) {
					$this->update_csv_row( $index, '', $note );
				}
				if ( ! $this->quiet ) {
					$output->writeln( "<error>[{$counter}/{$total_sites}] Skipping {$site_name} - {$note}</error>" );
				}
				++$skipped;
				continue;
			}

			// Skip non-Pressable sites.
			if ( ! empty( $host ) && 'pressable' !== $host ) {
				if ( ! $this->quiet ) {
					$output->writeln( "<comment>[{$counter}/{$total_sites}] Skipping {$site_name} - not a Pressable site ({$host})</comment>" );
				}
				++$skipped;
				continue;
			}

			// Skip if already processed.
			if ( 'Y' === strtoupper( $site_data['Done'] ?? '' ) ) {
				if ( ! $this->quiet ) {
					$output->writeln( "<comment>[{$counter}/{$total_sites}] Skipping {$site_name} - already done</comment>" );
				}
				++$skipped;
				continue;
			}

			if ( ! $this->quiet ) {
				$output->writeln( "<info>[{$counter}/{$total_sites}] Processing: {$site_name} ({$site_url})</info>" );
			}

			try {
				// Reset state.
				$this->site = null;

				// Initialize site.
				$this->initialize_site( $site_url );

				// Set constants.
				$result = $this->set_bilmur_constants( $output );

				if ( $result['success'] ) {
					if ( ! $this->dry_run ) {
						$this->update_csv_row( $index, 'Y', $result['note'] );
					}
					++$processed;
				} else {
					if ( ! $this->dry_run ) {
						$this->update_csv_row( $index, '', $result['note'] );
					}
					++$failed;
				}
			} catch ( \Exception $e ) {
				$note = "Error: {$e->getMessage()}";
				if ( ! $this->dry_run ) {
					$this->update_csv_row( $index, '', $note );
				}
				$output->writeln( "<error>[{$counter}/{$total_sites}] {$site_name}: {$note}</error>" );
				++$failed;
			}

			if ( ! $this->quiet ) {
				$output->writeln( '' );
			}
		}

		// Print summary.
		if ( ! $this->quiet ) {
			$output->writeln( '<info>--- Summary ---</info>' );
			$output->writeln( "Total: {$total_sites} | Processed: {$processed} | Skipped: {$skipped} | Failed: {$failed}" );
		}

		return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * Set Bilmur tracking constants on the current site.
	 *
	 * Opens a single SSH connection and uses it for both checking
	 * and setting all constants, then disconnects.
	 *
	 * @param OutputInterface $output The output object.
	 *
	 * @return array{ success: bool, note: string } Result with success flag and descriptive note.
	 */
	private function set_bilmur_constants( OutputInterface $output ): array {
		$site_label = $this->site->url ?? $this->site->displayName ?? 'unknown';

		$ssh = \Pressable_Connection_Helper::get_ssh_connection( $this->site->id );

		if ( null === $ssh ) {
			$output->writeln( "  <error>Could not establish SSH connection to {$site_label}</error>" );
			return array(
				'success' => false,
				'note'    => 'SSH connection failed',
			);
		}

		$ssh->setTimeout( 30 );

		$already_existed = array();
		$newly_set       = array();
		$failed          = array();

		try {
			foreach ( self::BILMUR_CONSTANTS as $constant ) {
				$name  = $constant['name'];
				$value = $constant['value'];
				$raw   = $constant['raw'];

				// Check if constant already exists via wp config get (exit code 0 = exists).
				$ssh->exec( "wp config get {$name}" );
				$exists = 0 === $ssh->getExitStatus();

				if ( $exists ) {
					if ( ! $this->quiet ) {
						$output->writeln( "  <comment>{$name} already exists on {$site_label} - skipping</comment>" );
					}
					$already_existed[] = $name;
					continue;
				}

				if ( $this->dry_run ) {
					if ( ! $this->quiet ) {
						$raw_label = $raw ? ' (--raw)' : '';
						$output->writeln( "  <info>[DRY RUN] Would set {$name} = {$value}{$raw_label} on {$site_label}</info>" );
					}
					continue;
				}

				// Set the constant.
				if ( ! $this->quiet ) {
					$output->writeln( "  Setting {$name}..." );
				}
				$raw_flag   = $raw ? ' --raw' : '';
				$wp_command = "wp config set {$name} \"{$value}\"{$raw_flag}";
				$cmd_output = $ssh->exec( $wp_command );
				$exit_code  = $ssh->getExitStatus();

				if ( 0 !== $exit_code ) {
					$output->writeln( "  <error>Failed to set {$name} on {$site_label} (exit code: {$exit_code})</error>" );
					if ( ! empty( $cmd_output ) ) {
						$output->writeln( "  <error>{$cmd_output}</error>" );
					}
					$failed[] = $name;
				} elseif ( ! $this->quiet ) {
					$output->writeln( "  <info>{$name} set successfully on {$site_label}</info>" );
					$newly_set[] = $name;
				} else {
					$newly_set[] = $name;
				}
			}
		} finally {
			$ssh->disconnect();
		}

		// Build a descriptive note.
		$note_parts = array();
		if ( ! empty( $newly_set ) ) {
			$note_parts[] = 'Set: ' . implode( ', ', $newly_set );
		}
		if ( ! empty( $already_existed ) ) {
			$note_parts[] = 'Already existed: ' . implode( ', ', $already_existed );
		}
		if ( ! empty( $failed ) ) {
			$note_parts[] = 'Failed: ' . implode( ', ', $failed );
		}

		return array(
			'success' => empty( $failed ),
			'note'    => implode( ' | ', $note_parts ),
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Initialize a Pressable site from URL.
	 *
	 * @param string $site_url The site URL.
	 *
	 * @throws \Exception If site cannot be found.
	 * @return void
	 */
	private function initialize_site( string $site_url ): void {
		$domain     = $this->extract_domain_from_url( $site_url );
		$this->site = get_pressable_site( $domain );

		if ( ! $this->site ) {
			throw new \Exception( "Could not find Pressable site: {$domain}" );
		}
	}

	/**
	 * Extract domain from a URL.
	 *
	 * @param string $url The URL.
	 *
	 * @return string The domain.
	 */
	private function extract_domain_from_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$domain = preg_replace( '#^https?://#i', '', $url );
		$domain = preg_replace( '~[/?#].*$~', '', $domain );
		$domain = rtrim( $domain ?? '', '/' );

		return $domain;
	}

	/**
	 * Prompt for site input.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return string
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): string {
		$question = new \Symfony\Component\Console\Question\Question( '<question>Enter the site URL or domain:</question> ' );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Read a CSV file and return an array of rows keyed by row index.
	 *
	 * @param string $csv_path Path to the CSV file.
	 *
	 * @return array
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
		$row_index = 1;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			++$row_index;

			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

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

	/**
	 * Update a specific row in the CSV file.
	 *
	 * @param int    $row_index The row index to update.
	 * @param string $done      The Done status ('Y' or '').
	 * @param string $note      Optional note to add.
	 *
	 * @return void
	 */
	private function update_csv_row( int $row_index, string $done, string $note = '' ): void {
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

		$done_index  = array_search( 'Done', $headers, true );
		$notes_index = array_search( 'Notes', $headers, true );

		// Add Done column if it doesn't exist.
		$needs_done_column = false === $done_index;
		if ( $needs_done_column ) {
			$headers[]  = 'Done';
			$done_index = count( $headers ) - 1;
		}

		// Add Notes column if it doesn't exist.
		$needs_notes_column = false === $notes_index;
		if ( $needs_notes_column ) {
			$headers[]   = 'Notes';
			$notes_index = count( $headers ) - 1;
		}

		$rows[] = $headers;

		$current_row = 1;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			++$current_row;

			if ( $current_row === $row_index ) {
				while ( count( $row ) < count( $headers ) ) {
					$row[] = '';
				}

				if ( false !== $done_index ) {
					$row[ $done_index ] = $done;
				}

				if ( false !== $notes_index ) {
					$row[ $notes_index ] = $note;
				}
			} elseif ( $needs_done_column || $needs_notes_column ) {
				while ( count( $row ) < count( $headers ) ) {
					$row[] = '';
				}
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

	// endregion
}
