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
 * Reports the status of the Atlantis plugin and its modules across all
 * Jetpack-connected sites.
 *
 * Proof-of-concept scope: surfaces plugin version and the Autoupdate Filter
 * module state per site. Sites without Atlantis return `not installed`.
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
	 * Columns included in the export and thus that can be excluded via options.
	 *
	 * @var array
	 */
	private array $export_columns = array( 'Site ID', 'Site URL', 'Atlantis Version', 'Autoupdates' );

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
		$this->setDescription( 'Reports Atlantis plugin and module status across all Jetpack-connected sites.' )
			->setHelp( 'Calls the OpsOasis batch endpoint for `a8csp-atlantis/v1/status` on every Jetpack-connected site and prints a per-site report. Sites without Atlantis installed are reported as `not installed`.' );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the report will be saved to this file in addition to the terminal.' )
			->addOption( 'export-format', null, InputOption::VALUE_REQUIRED, 'The format to export the report in. Accepted values are `json` and `csv`.', 'csv' )
			->addOption( 'export-exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude columns from the export. Possible values: `Site ID`, `Site URL`, `Atlantis Version`, `Autoupdates`.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->format      = get_enum_input( $input, 'export-format', array( 'json', 'csv' ) );
		$this->destination = maybe_get_string_input( $input, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! empty( $this->destination ) ) {
			$this->export_excluded_columns = $input->getOption( 'export-exclude' ) ?: $this->prompt_export_excluded_columns_input( $input, $output );
			$input->setOption( 'export-exclude', $this->export_excluded_columns );

			$this->stream = get_file_handle( $this->destination, $this->format );
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
		$output->writeln( '<fg=magenta;options=bold>Reporting Atlantis status across connected sites.</>' );

		$rows                       = array();
		$installed_count            = 0;
		$autoupdates_enabled_count  = 0;

		foreach ( $this->sites as $site_id => $site ) {
			$status = $this->statuses[ $site_id ] ?? null;

			$row = array(
				'Site ID'          => $site->userblog_id,
				'Site URL'         => $site->siteurl,
				'Atlantis Version' => 'not installed',
				'Autoupdates'      => '—',
			);

			if ( \is_object( $status ) ) {
				++$installed_count;

				$row['Atlantis Version'] = $status->plugin->version ?? 'unknown';

				$autoupdates_enabled = $status->modules->autoupdates->enabled ?? null;
				if ( true === $autoupdates_enabled ) {
					$row['Autoupdates'] = 'on';
					++$autoupdates_enabled_count;
				} elseif ( false === $autoupdates_enabled ) {
					$row['Autoupdates'] = 'off';
				}
			}

			$rows[] = $row;
		}

		$table_header = array_keys( $rows[0] ?? array( 'Site ID' => '', 'Site URL' => '', 'Atlantis Version' => '', 'Autoupdates' => '' ) );
		output_table(
			$output,
			array_map( 'array_values', $rows ),
			$table_header,
			'Atlantis status by site'
		);

		$summary_output = array(
			'REPORT SUMMARY'         => '',
			'Total sites queried'    => \count( $this->sites ),
			'Sites with Atlantis'    => $installed_count,
			'Autoupdates module ON'  => $autoupdates_enabled_count,
			'Sites that errored'     => \count( $this->errors ?? array() ),
		);

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
