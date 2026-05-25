<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Reports the status of the Atlantis plugin and its modules across
 * Jetpack-connected sites.
 *
 * By default, reports every site and every module. Use `--site` to inspect a
 * single site, or `--module` to narrow the report to one module column.
 * Sites without Atlantis return `not installed`.
 */
#[AsCommand( name: 'wpcom:atlantis-status' )]
final class WPCOM_Atlantis_Status extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The per-site status payloads keyed by site ID. Sites that failed to
	 * respond are absent from this map and present in $errors instead.
	 *
	 * @var array|null
	 */
	private ?array $statuses = null;

	/**
	 * Per-site errors from the batch call.
	 *
	 * @var array|null
	 */
	private ?array $errors = null;

	/**
	 * Module keys known to be reported by the Atlantis status endpoint.
	 *
	 * Ordered to control the column order in the report.
	 *
	 * @var string[]
	 */
	private const KNOWN_MODULE_KEYS = array( 'messages', 'colophon', 'tracking', 'autoupdates' );

	/**
	 * Module keys included in the current report.
	 *
	 * Either every key in KNOWN_MODULE_KEYS or a single key when --module is set.
	 *
	 * @var string[]
	 */
	private array $module_keys = self::KNOWN_MODULE_KEYS;

	/**
	 * The single site to report on, when --site is set.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $single_site = null;

	/**
	 * Columns included in the export and thus that can be excluded via options.
	 *
	 * Populated in initialize() once the module set is known.
	 *
	 * @var array
	 */
	private array $export_columns = array();

	/**
	 * List of columns to exclude from the export.
	 *
	 * @var array|null
	 */
	private ?array $export_excluded_columns = null;

	/**
	 * The format to export the report in.
	 *
	 * @var string|null
	 */
	private ?string $format = null;

	/**
	 * The destination file path for the export.
	 *
	 * @var string|null
	 */
	private ?string $destination = null;

	/**
	 * The stream to write the export to.
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
		$this->setDescription( 'Reports Atlantis plugin and module status across Jetpack-connected sites.' )
			->setHelp( 'Calls the OpsOasis batch endpoint for `a8csp-atlantis/v1/status` and prints a per-site report. By default every connected site is queried; pass `--site` to inspect a single site. By default every Atlantis module is shown as its own column; pass `--module` to narrow to one. Sites without Atlantis installed are reported as `not installed`.' );

		$this->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single site, identified by its WPCOM ID or URL. Skips the full fleet fetch.' )
			->addOption( 'module', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single module column. Accepted values: ' . \implode( ', ', self::KNOWN_MODULE_KEYS ) . '.' )
			->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the report will be saved to this file in addition to the terminal.' )
			->addOption( 'export-format', null, InputOption::VALUE_REQUIRED, 'The format to export the report in. Accepted values are `json` and `csv`.', 'csv' )
			->addOption( 'export-exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude columns from the export. Possible values depend on the active --module flag; defaults to `Site ID`, `Site URL`, `Atlantis Version`, plus one column per Atlantis module.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If --module is set to a value outside KNOWN_MODULE_KEYS, or --site cannot be resolved.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$module_option = $input->getOption( 'module' );
		if ( ! \is_null( $module_option ) ) {
			$module_option = \strtolower( (string) $module_option );
			if ( ! \in_array( $module_option, self::KNOWN_MODULE_KEYS, true ) ) {
				throw new \InvalidArgumentException(
					"Unknown module '$module_option'. Accepted values: " . \implode( ', ', self::KNOWN_MODULE_KEYS ) . '.'
				);
			}
			$this->module_keys = array( $module_option );
		}

		$this->export_columns = \array_merge(
			array( 'Site ID', 'Site URL', 'Atlantis Version' ),
			\array_map( fn( string $key ) => \ucfirst( $key ), $this->module_keys )
		);

		$this->format      = get_enum_input( $input, 'export-format', array( 'json', 'csv' ) );
		$this->destination = maybe_get_string_input( $input, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! empty( $this->destination ) ) {
			$this->export_excluded_columns = $input->getOption( 'export-exclude' ) ?: $this->prompt_export_excluded_columns_input( $input, $output );
			$input->setOption( 'export-exclude', $this->export_excluded_columns );

			$this->stream = get_file_handle( $this->destination, $this->format );
		}

		$site_option = $input->getOption( 'site' );
		if ( ! \is_null( $site_option ) ) {
			$this->initialize_single_site( (string) $site_option, $output );
			return;
		}

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		$this->statuses = get_wpcom_sites_atlantis_status_batch( \array_column( $this->sites, 'userblog_id' ), $this->errors );
		maybe_output_wpcom_failed_sites_table( $output, $this->errors ?? array(), $this->sites, 'Sites that could NOT be queried for Atlantis status' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$scope_label = \is_null( $this->single_site )
			? 'across connected sites'
			: "for site {$this->single_site->siteurl}";
		$output->writeln( "<fg=magenta;options=bold>Reporting Atlantis status $scope_label.</>" );

		$rows                  = array();
		$installed_count       = 0;
		$module_enabled_counts = \array_fill_keys( $this->module_keys, 0 );

		foreach ( $this->sites as $site_id => $site ) {
			$status = $this->statuses[ $site_id ] ?? null;

			$row = array(
				'Site ID'          => $site->userblog_id,
				'Site URL'         => $site->siteurl,
				'Atlantis Version' => 'not installed',
			);
			foreach ( $this->module_keys as $module_key ) {
				$row[ \ucfirst( $module_key ) ] = '—';
			}

			if ( \is_object( $status ) ) {
				++$installed_count;

				$row['Atlantis Version'] = $status->plugin->version ?? 'unknown';

				foreach ( $this->module_keys as $module_key ) {
					$enabled = $status->modules->$module_key->enabled ?? null;
					if ( true === $enabled ) {
						$row[ \ucfirst( $module_key ) ] = 'on';
						++$module_enabled_counts[ $module_key ];
					} elseif ( false === $enabled ) {
						$row[ \ucfirst( $module_key ) ] = 'off';
					}
				}
			}

			$rows[] = $row;
		}

		$table_header = array_keys( $rows[0] ?? \array_fill_keys( $this->export_columns, '' ) );
		output_table(
			$output,
			array_map( 'array_values', $rows ),
			$table_header,
			'Atlantis status by site'
		);

		$summary_output = array(
			'REPORT SUMMARY'      => '',
			'Total sites queried' => \count( $this->sites ),
			'Sites with Atlantis' => $installed_count,
		);
		foreach ( $module_enabled_counts as $module_key => $count ) {
			$summary_output[ \ucfirst( $module_key ) . ' module ON' ] = $count;
		}
		$summary_output['Sites that errored'] = \count( $this->errors ?? array() );

		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>$key: $value</info>" );
		}

		if ( ! \is_null( $this->stream ) ) {
			$rows_for_export = array_map( 'array_values', $rows );
			match ( $this->format ) {
				'csv' => $this->create_csv( $table_header, $rows_for_export, $summary_output ),
				'json' => $this->create_json( $table_header, $rows_for_export, $summary_output ),
			};
			$output->writeln( "<info>Output saved to $this->destination</info>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves a single site from $site_id_or_url, fetches its Atlantis status, and
	 * populates $this->sites and $this->statuses for the single-site report path.
	 *
	 * @param   string          $site_id_or_url Site URL or WPCOM numeric ID.
	 * @param   OutputInterface $output         Console output (for status messages).
	 *
	 * @throws \InvalidArgumentException If the site cannot be resolved or has no usable ID.
	 *
	 * @return  void
	 */
	private function initialize_single_site( string $site_id_or_url, OutputInterface $output ): void {
		$site = get_wpcom_site( $site_id_or_url );
		if ( ! \is_object( $site ) ) {
			throw new \InvalidArgumentException( "Could not resolve site '$site_id_or_url'." );
		}

		$site_id = (int) ( $site->ID ?? $site->userblog_id ?? 0 );
		if ( 0 === $site_id ) {
			throw new \InvalidArgumentException( "Resolved site '$site_id_or_url' has no usable ID." );
		}

		// Normalise into the same shape get_wpcom_jetpack_sites() returns so the rest of execute() needs no branching.
		$normalised_site              = clone $site;
		$normalised_site->userblog_id = $site_id;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WPCOM API returns a `URL` property.
		$normalised_site->siteurl = $site->URL ?? $site->siteurl ?? $site_id_or_url;

		$this->single_site = $normalised_site;
		$this->sites       = array( $site_id => $normalised_site );

		$output->writeln( "<comment>Querying single site {$normalised_site->siteurl} (ID $site_id).</comment>" );

		$this->statuses = get_wpcom_sites_atlantis_status_batch( array( $site_id ), $this->errors );
		maybe_output_wpcom_failed_sites_table( $output, $this->errors ?? array(), $this->sites, 'Sites that could NOT be queried for Atlantis status' );
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
			$default  = get_user_folder_path( 'Downloads/wpcom-atlantis-status_' . gmdate( 'Y-m-d-H-i-s' ) . ".$this->format" );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Prompts the user to maybe exclude columns from the exported file.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array|null
	 */
	private function prompt_export_excluded_columns_input( InputInterface $input, OutputInterface $output ): ?array {
		$question = new ConfirmationQuestion( '<question>Would you like to exclude any columns from the exported report? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the columns you want to exclude from the exported file [' . $this->export_columns[0] . ']:</question> ', $this->export_columns );
			$question->setMultiselect( true );

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Creates a CSV file from the final report.
	 *
	 * @param   array $headers Header row.
	 * @param   array $rows    Data rows.
	 * @param   array $summary Summary lines.
	 *
	 * @return  void
	 */
	private function create_csv( array $headers, array $rows, array $summary ): void {
		$filtered_data = $this->filter_export_columns( $headers, $rows );

		\fputcsv( $this->stream, $filtered_data['headers'] );
		foreach ( $filtered_data['rows'] as $fields ) {
			\fputcsv( $this->stream, $fields );
		}
		foreach ( $summary as $key => $item ) {
			\fputcsv( $this->stream, array( $key, $item ) );
		}
		\fclose( $this->stream );
	}

	/**
	 * Creates a JSON file from the final report.
	 *
	 * @param   array $headers Header row.
	 * @param   array $rows    Data rows.
	 * @param   array $summary Summary lines.
	 *
	 * @return  void
	 */
	private function create_json( array $headers, array $rows, array $summary ): void {
		$filtered_data = $this->filter_export_columns( $headers, $rows );

		$filtered_data['rows'][] = $summary;
		\fwrite( $this->stream, encode_json_content( $filtered_data['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		\fclose( $this->stream );
	}

	/**
	 * Filters out excluded columns from headers and rows.
	 *
	 * @param   array $headers Headers.
	 * @param   array $rows    Rows.
	 *
	 * @return  array
	 */
	private function filter_export_columns( array $headers, array $rows ): array {
		if ( empty( $this->export_excluded_columns ) ) {
			return array(
				'headers' => $headers,
				'rows'    => $rows,
			);
		}

		$header_compare = array_map(
			static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
			$headers
		);

		$excluded_columns = array_map(
			static fn ( $column ) => strtoupper( preg_replace( '/\s+/', '', $column ) ),
			$this->export_excluded_columns
		);

		foreach ( $excluded_columns as $column ) {
			$column_index = array_search( $column, $header_compare, true );
			if ( false !== $column_index ) {
				unset( $headers[ $column_index ] );
				foreach ( $rows as &$row ) {
					unset( $row[ $column_index ] );
				}
				unset( $row );
			}
		}

		$headers = array_values( $headers );
		$rows    = array_map( 'array_values', $rows );

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	// endregion
}
