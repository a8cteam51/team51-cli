<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Saves a command's tabular output to a CSV or JSON file in addition to the terminal.
 *
 * A command using it calls `configure_export_options()` from `configure()`, `initialize_export()` from `initialize()`,
 * and `maybe_export()` once its rows are ready.
 */
trait ExportTrait {
	// region FIELDS AND CONSTANTS

	/**
	 * The format to export the output in.
	 *
	 * @var string|null
	 */
	private ?string $export_format = null;

	/**
	 * The path of the file to save the output to, if any.
	 *
	 * @var string|null
	 */
	private ?string $export_destination = null;

	/**
	 * The columns to leave out of the exported file.
	 *
	 * @var string[]
	 */
	private array $export_excluded_columns = array();

	/**
	 * The stream to write the exported file to.
	 *
	 * @var resource|null
	 */
	private $export_stream = null;

	// endregion

	// region METHODS

	/**
	 * Adds the `--export`, `--export-format`, and `--export-exclude` options to the command.
	 *
	 * @param   string[] $columns The columns the exported file can contain, listed in the help for `--export-exclude`.
	 *
	 * @return  void
	 */
	protected function configure_export_options( array $columns ): void {
		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file in addition to the terminal.' )
			->addOption( 'export-format', null, InputOption::VALUE_REQUIRED, 'The format to export the output in. Accepted values are `csv` and `json`.', 'csv' )
			->addOption( 'export-exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude columns from the exported file. Possible values: ' . \implode( ', ', \array_map( static fn( string $column ) => "`$column`", $columns ) ) . '.' );
	}

	/**
	 * Reads the export options, prompting for any that are missing, and opens the file to export to.
	 *
	 * The file is opened before the command fetches anything, so a path that cannot be written to fails straight away.
	 *
	 * @param   InputInterface  $input            The console input.
	 * @param   OutputInterface $output           The console output.
	 * @param   string          $default_filename The file name suggested in the Downloads folder, before the timestamp and extension.
	 * @param   string[]        $columns          The columns the exported file will contain.
	 *
	 * @throws  \InvalidArgumentException If a column to exclude is not one of the given columns.
	 * @return  void
	 */
	protected function initialize_export( InputInterface $input, OutputInterface $output, string $default_filename, array $columns ): void {
		$this->export_format      = get_enum_input( $input, 'export-format', array( 'csv', 'json' ) );
		$this->export_destination = maybe_get_string_input( $input, 'export', fn() => $this->prompt_export_destination_input( $input, $output, $default_filename ) );
		if ( empty( $this->export_destination ) ) {
			return;
		}

		// Column names are matched ignoring case and whitespace, and kept as the command spells them.
		$normalize     = static fn( string $column ): string => \strtoupper( \preg_replace( '/\s+/', '', $column ) );
		$known_columns = \array_combine( \array_map( $normalize, $columns ), $columns );

		$excluded_columns = $input->getOption( 'export-exclude' ) ?: $this->prompt_export_excluded_columns_input( $input, $output, $columns );
		foreach ( $excluded_columns as $column ) {
			if ( ! isset( $known_columns[ $normalize( $column ) ] ) ) {
				throw new \InvalidArgumentException( "Invalid value for input `export-exclude`: $column" );
			}

			$this->export_excluded_columns[] = $known_columns[ $normalize( $column ) ];
		}
		$input->setOption( 'export-exclude', $this->export_excluded_columns );

		// Appended here rather than by `get_file_handle()` so the path reported once the file is saved is the real one.
		if ( empty( \pathinfo( $this->export_destination, PATHINFO_EXTENSION ) ) ) {
			$this->export_destination .= ".$this->export_format";
		}
		$this->export_stream = get_file_handle( $this->export_destination, $this->export_format );
	}

	/**
	 * Writes the output to the export file, if one was requested, and closes it.
	 *
	 * @param   OutputInterface $output  The console output.
	 * @param   string[]        $headers The column headers.
	 * @param   array[]         $rows    The rows, each holding one value per header in header order. Keys are ignored.
	 * @param   array           $summary Lines written after the rows, as label => value.
	 *
	 * @return  void
	 */
	protected function maybe_export( OutputInterface $output, array $headers, array $rows, array $summary = array() ): void {
		if ( \is_null( $this->export_stream ) ) {
			return;
		}

		$included_columns = \array_flip( \array_diff( $headers, $this->export_excluded_columns ) );
		$rows             = \array_map(
			static fn( array $row ): array => \array_intersect_key( \array_combine( $headers, \array_values( $row ) ), $included_columns ),
			$rows
		);

		match ( $this->export_format ) {
			'csv' => $this->write_export_csv( \array_keys( $included_columns ), $rows, $summary ),
			'json' => $this->write_export_json( $rows, $summary ),
		};
		\fclose( $this->export_stream );
		$this->export_stream = null;

		$output->writeln( "<info>Output saved to $this->export_destination</info>" );
	}

	/**
	 * Prompts the user for the file to save the output to.
	 *
	 * @param   InputInterface  $input            The console input.
	 * @param   OutputInterface $output           The console output.
	 * @param   string          $default_filename The file name suggested in the Downloads folder, before the timestamp and extension.
	 *
	 * @return  string|null
	 */
	private function prompt_export_destination_input( InputInterface $input, OutputInterface $output, string $default_filename ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to save the output to a file? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$default  = get_user_folder_path( "Downloads/{$default_filename}_" . \gmdate( 'Y-m-d-H-i-s' ) . ".$this->export_format" );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Prompts the user to maybe exclude columns from the exported file.
	 *
	 * @param   InputInterface  $input   The console input.
	 * @param   OutputInterface $output  The console output.
	 * @param   string[]        $columns The columns the exported file will contain.
	 *
	 * @return  string[]
	 */
	private function prompt_export_excluded_columns_input( InputInterface $input, OutputInterface $output, array $columns ): array {
		$question = new ConfirmationQuestion( '<question>Would you like to exclude any columns from the exported file? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the columns you want to exclude from the exported file (comma-separated):</question> ', $columns );
			$question->setMultiselect( true );

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return array();
	}

	/**
	 * Writes the header, the rows, and the summary to the export file as CSV.
	 *
	 * @param   string[] $headers The column headers.
	 * @param   array[]  $rows    The rows.
	 * @param   array    $summary Lines written after the rows, as label => value.
	 *
	 * @return  void
	 */
	private function write_export_csv( array $headers, array $rows, array $summary ): void {
		\fputcsv( $this->export_stream, $headers, ',', '"', '' );
		foreach ( $rows as $row ) {
			\fputcsv( $this->export_stream, \array_values( $row ), ',', '"', '' );
		}
		foreach ( $summary as $label => $value ) {
			\fputcsv( $this->export_stream, array( $label, $value ), ',', '"', '' );
		}
	}

	/**
	 * Writes the rows to the export file as a JSON array of objects keyed by header, with the summary as the last object.
	 *
	 * @param   array[] $rows    The rows, keyed by header.
	 * @param   array   $summary Lines appended after the rows, as label => value.
	 *
	 * @return  void
	 */
	private function write_export_json( array $rows, array $summary ): void {
		if ( ! empty( $summary ) ) {
			$rows[] = $summary;
		}

		\fwrite( $this->export_stream, encode_json_content( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?? '' );
	}

	// endregion
}
