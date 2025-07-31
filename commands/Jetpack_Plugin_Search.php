<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Lists the connected Jetpack sites with a given plugin.
 */
#[AsCommand( name: 'jetpack:plugin-search' )]
final class Jetpack_Plugin_Search extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The plugin to search for.
	 *
	 * @var string|null
	 */
	private ?string $plugin = null;

	/**
	 * Whether to do a partial search.
	 *
	 * @var bool|null
	 */
	private ?bool $partial = null;

	/**
	 * The version of the plugin to search for.
	 *
	 * @var string|null
	 */
	private ?string $version = null;

	/**
	 * The version comparison operator to use.
	 *
	 * @var string|null
	 */
	private ?string $version_operator = null;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The list of plugins installed on the sites.
	 *
	 * @var array|null
	 */
	private ?array $plugins = null;

	/**
	 * Columns included in the export and thus that can be excluded via options.
	 */
	private array $export_columns = array( 'Site ID', 'Site URL', 'Plugin Name', 'Plugin Slug', 'Plugin Version', 'Plugin Status' );

	/**
	 * List of columns to exclude from the export.
	 *
	 * @var array|null
	 */
	private ?array $export_excluded_columns = null;

	/**
	 * The format to export the sites in.
	 *
	 * @var string|null
	 */
	private ?string $format = null;

	/**
	 * The destination to save the output to in addition to the terminal.
	 *
	 * @var string|null
	 */
	private ?string $destination = null;

	/**
	 * The stream to write the output to.
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
		$this->setDescription( 'List all connected sites where a given plugin is installed.' )
			->setHelp( 'Use this command to find which sites have a given plugin installed. Only sites with an active Jetpack connection to WPCOM are searched through.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin to search for. The term will be matched against the folder name, the main file name, and the textdomain.' )
			->addOption( 'partial', null, InputOption::VALUE_NONE, 'Whether to do a partial search. If set, the plugin term will be matched against partial strings.' );

		$this->addOption( 'version-search', null, InputOption::VALUE_REQUIRED, 'The version of the plugin to search for.' )
			->addOption( 'version-operator', null, InputOption::VALUE_REQUIRED, 'The operator to use for the version comparison.' );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file in addition to the terminal.' )
			->addOption( 'export-format', null, InputOption::VALUE_REQUIRED, 'The format to export the sites in. Accepted values are `json`, and `csv`.', 'csv' )
			->addOption( 'export-exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude columns from the export option. Possible values: `Site ID`, `Site URL`, `Plugin Name`, `Plugin Slug`, `Plugin Version`, and `Plugin Status`.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		// Set the search parameters.
		$this->partial = get_bool_input( $input, 'partial' );
		$this->version = maybe_get_string_input( $input, 'version-search' );
		if ( ! empty( $this->version ) ) {
			$this->version_operator = get_enum_input(
				$input,
				'version-operator',
				array( '<', '<=', '>', '>=', '==', '=', '!=', '<>' ),
				fn() => $this->prompt_version_operator_input( $input, $output )
			);
		}

		// Open the destination file if provided.
		$this->format      = get_enum_input( $input, 'export-format', array( 'json', 'csv' ) );
		$this->destination = maybe_get_string_input( $input, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! empty( $this->destination ) ) {
			$this->export_excluded_columns = $input->getOption( 'export-exclude' ) ?: $this->prompt_export_excluded_columns_input( $input, $output );
			$input->setOption( 'export-exclude', $this->export_excluded_columns );

			$this->stream = get_file_handle( $this->destination, $this->format );
		}

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		// Compile the list of plugins to process.
		$this->plugins = get_wpcom_site_plugins_batch( \array_column( $this->sites, 'userblog_id' ), $errors );
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites, 'Sites that could NOT be searched' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$partial_match_text = $this->partial ? 'partial' : 'exact';
		$output->writeln( "<fg=magenta;options=bold>Listing connected sites where the plugin `$this->plugin` is found ($partial_match_text match).</>" );

		// Search for the plugin.
		$matches = array();
		foreach ( $this->plugins as $site_id => $plugins ) {
			foreach ( $plugins as $plugin => $plugin_data ) {
				$plugin_folder = \dirname( $plugin );
				$plugin_file   = \basename( $plugin, '.php' );

				if ( $this->is_exact_match( $plugin_data, $plugin_folder, $plugin_file ) || ( $this->partial && $this->is_partial_match( $plugin_data, $plugin_folder, $plugin_file ) ) ) {
					if ( $this->is_version_match( $plugin_data ) ) {
						$matches[ $site_id ][ $plugin ] = $plugin_data;
					}
				}
			}
		}

		// Output the results.
		$output->writeln( '<fg=green;options=bold>Found ' . \count( $matches ) . ' sites with the plugin installed.</>', OutputInterface::VERBOSITY_VERBOSE );

		$rows = array();
		foreach ( $matches as $site_id => $plugins ) {
			$site = $this->sites[ $site_id ];
			foreach ( $plugins as $plugin => $plugin_data ) {
				$rows[] = array(
					$site->userblog_id,
					$site->siteurl,
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$plugin_data->Name,
					\dirname( $plugin ),
					$plugin_data->Version,
					$plugin_data->active ? 'Active' : 'Inactive',
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		$table_header = array( 'Site ID', 'Site URL', 'Plugin Name', 'Plugin Slug', 'Plugin Version', 'Plugin Status' );
		output_table(
			$output,
			$rows,
			$table_header,
			'Sites found with plugins matching the given term'
		);

		$summary_output = array(
			'REPORT SUMMARY'         => '',
			'Plugin searched for'    => $this->plugin,
			'Search type'            => $partial_match_text,
			'Version filter'         => ! empty( $this->version ) ? $this->version_operator . ' ' . $this->version : 'None',
			'Total sites found'      => \count( $matches ),
			'Total plugin instances' => \count( $rows ),
		);

		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>$key: $value</info>" );
		}

		if ( ! \is_null( $this->stream ) ) {
			match ( $this->format ) {
				'csv' => $this->create_csv( $table_header, $rows, $summary_output ),
				'json' => $this->create_json( $table_header, $rows, $summary_output )
			};
			$output->writeln( "<info>Output saved to $this->destination</info>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for the plugin term to search for.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_plugin_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the plugin term to search for:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for the version comparison operator to use.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_version_operator_input( InputInterface $input, OutputInterface $output ): string {
		$choices = array( '<', '<=', '>', '>=', '==', '=', '!=', '<>' );

		$question = new ChoiceQuestion( '<question>Select the version comparison operator to use [=]:</question> ', $choices, '=' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for the destination to save the output to.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_destination_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to save the output to a file? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$default  = get_user_folder_path( 'Downloads/jetpack-plugin-search_' . gmdate( 'Y-m-d-H-i-s' ) . ".$this->format" );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Prompts the user to maybe exclude columns from the exported files.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array|null
	 */
	private function prompt_export_excluded_columns_input( InputInterface $input, OutputInterface $output ): ?array {
		$question = new ConfirmationQuestion( '<question>Would you like to exclude any columns from exported plugin search results? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the columns you want to exclude from the exported file [' . $this->export_columns[0] . ']:</question> ', $this->export_columns );
			$question->setMultiselect( true );

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Checks if the plugin data matches the search term exactly.
	 *
	 * @param   \stdClass $plugin_data   The plugin data.
	 * @param   string    $plugin_folder The plugin folder.
	 * @param   string    $plugin_file   The plugin file.
	 *
	 * @return  boolean
	 */
	private function is_exact_match( \stdClass $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return $this->plugin === $plugin_data->TextDomain // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			|| $this->plugin === $plugin_folder
			|| $this->plugin === $plugin_file;
	}

	/**
	 * Checks if the plugin data matches the search term partially.
	 *
	 * @param   \stdClass $plugin_data   The plugin data.
	 * @param   string    $plugin_folder The plugin folder.
	 * @param   string    $plugin_file   The plugin file.
	 *
	 * @return  boolean
	 */
	private function is_partial_match( \stdClass $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return str_contains( $plugin_data->TextDomain, $this->plugin ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			|| str_contains( $plugin_folder, $this->plugin )
			|| str_contains( $plugin_file, $this->plugin );
	}

	/**
	 * Checks if the plugin data matches the version comparison.
	 *
	 * @param   \stdClass $plugin_data The plugin data.
	 *
	 * @return  boolean
	 */
	private function is_version_match( \stdClass $plugin_data ): bool {
		return empty( $this->version )
			|| \version_compare( $plugin_data->Version, $this->version, $this->version_operator ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Creates a CSV file from the final list of sites.
	 *
	 * @param   array $headers The header for the CSV file.
	 * @param   array $rows    The final list of sites.
	 * @param   array $summary The summary of the report.
	 *
	 * @return  void
	 */
	protected function create_csv( array $headers, array $rows, array $summary ): void {
		$csv_header_compare = array_map(
			static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
			$headers
		);

		if ( ! empty( $this->export_excluded_columns ) ) {
			$this->export_excluded_columns = array_map(
				static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
				$this->export_excluded_columns
			);

			foreach ( $this->export_excluded_columns as $column ) {
				$column_index = array_search( $column, $csv_header_compare, true );
				unset( $headers[ $column_index ] );
				foreach ( $rows as &$site ) {
					unset( $site[ $column_index ] );
				}
				unset( $site );
			}

			// Reindex arrays after column removal for consistency
			$headers = array_values( $headers );
			$rows = array_map( 'array_values', $rows );
		}

		\fputcsv( $this->stream, $headers );
		foreach ( $rows as $fields ) {
			\fputcsv( $this->stream, $fields );
		}
		foreach ( $summary as $key => $item ) {
			\fputcsv( $this->stream, array( $key, $item ) );
		}
		\fclose( $this->stream );
	}

	/**
	 * Creates a JSON file from the final list of sites.
	 *
	 * @param array $headers The header for the CSV file.
	 * @param array $rows    The final list of sites.
	 * @param array $summary The summary of the report.
	 *
	 * @return  void
	 */
	protected function create_json( array $headers, array $rows, array $summary ): void {
		$json_header_compare = array_map(
			static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
			$headers
		);

		if ( ! empty( $this->export_excluded_columns ) ) {
			$this->export_excluded_columns = array_map(
				static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
				$this->export_excluded_columns
			);

			foreach ( $this->export_excluded_columns as $column ) {
				$column_index = array_search( $column, $json_header_compare, true );
				unset( $headers[ $column_index ] );
				foreach ( $rows as &$site ) {
					unset( $site[ $column_index ] );
				}
				unset( $site );
			}

			// Reindex arrays after column removal for consistency
			$headers = array_values( $headers );
			$rows = array_map( 'array_values', $rows );
		}

		$rows[] = $summary;
		\fwrite( $this->stream, encode_json_content( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		\fclose( $this->stream );
	}

	// endregion
}
