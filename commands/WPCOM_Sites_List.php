<?php declare( strict_types=1 );

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Outputs a summary of all sites managed by the WordPress.com Special Projects team.
 */
#[AsCommand( name: 'wpcom:list-sites' )]
final class WPCOM_Sites_List extends Command {

	use \WPCOMSpecialProjects\CLI\Helper\Autocomplete;

	// region FIELDS AND CONSTANTS

	/**
	 * Types of audits.
	 *
	 * @var string
	 */
	private array $audit_options = array(
		'full',
		'no-staging',
		'is_private',
		'is_coming_soon',
		'is_multisite',
		'is_domain_only',
		'other',
	);

	/**
	 * The type of audit to run.
	 *
	 * @var string|null
	 */
	private ?string $audit_type = null;

	/**
	 * Excludable columns.
	 */
	private array $export_columns = array( 'Site Name', 'Domain', 'Site ID', 'Host' );

	/**
	 * List of columns to exclude from the export.
	 *
	 * @var array|null
	 */
	private ?array $export_excluded_columns = null;

	/**
	 * List of url terms to trigger site exclusion.
	 *
	 * @var array
	 */
	private array $ignore = array( 'staging', 'testing', 'jurassic', 'wpengine', 'wordpress', 'develop', 'mdrovdahl', '/dev.', 'woocommerce.com', 'opsoasis' );

	/**
	 * List of url patterns to help identify multisite sites.
	 *
	 * @var array
	 */
	private array $multisite_patterns = array( 'com/', 'org/' );

	/**
	 * List of sites that are allowed to pass the ignore list.
	 *
	 * @var array
	 */
	private array $free_pass = array(
		'wpspecialprojects.wordpress.com',
		'tumblr.wordpress.com',
		'tonyconrad.wordpress.com',
		'killscreen.com/previously',
	);

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

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The list of Pressable sites.
	 *
	 * @var array|null
	 */
	private ?array $pressable_sites = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists all the sites connected to the team\'s WPCOM account.' )
			->setHelp( 'This command will output a summary of the sites connected to WPCOM.' );

		$this->addOption( 'audit', null, InputOption::VALUE_OPTIONAL, "Produces a full list of sites, with reasons why they were or were not filtered. Audit values include `full`, for including all sites, `no-staging` to exclude staging sites, as well as\na general column/text based exclusive filter, eg. `is_private` will include only private sites." );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file in addition to the terminal.' )
			->addOption( 'export-format', null, InputOption::VALUE_REQUIRED, 'The format to export the sites in. Accepted values are `json`, and `csv`.', 'csv' )
			->addOption( 'export-exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude columns from the export option. Possible values: `Site Name`, `Domain`, `Site ID`, and `Host`.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->audit_type = maybe_get_string_input( $input, $output, 'audit', fn() => $this->prompt_audit_input( $input, $output ) );
		$input->setOption( 'audit', $this->audit_type );

		// Open the destination file if provided.
		$this->format      = get_enum_input( $input, $output, 'export-format', array( 'json', 'csv' ) );
		$this->destination = maybe_get_string_input( $input, $output, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! empty( $this->destination ) ) {
			$this->export_excluded_columns = $input->getOption( 'export-exclude' ) ?: $this->prompt_export_excluded_columns_input( $input, $output );
			$input->setOption( 'export-exclude', $this->export_excluded_columns );

			$this->stream = get_file_handle( $this->destination, $this->format );
		}

		// Fetch the sites.
		$this->sites = get_wpcom_sites(
			array(
				'include_domain_only' => 'true',
				'fields'              => 'ID,name,URL,is_private,is_coming_soon,is_wpcom_atomic,jetpack,is_multisite,options',
			),
		);
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' WPCOM site(s).</comment>' );

		$this->pressable_sites = get_pressable_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->pressable_sites ) . ' Pressable site(s).</comment>' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Compiling list of WPCOMSP sites.</>' );

		$site_list = array_map(
			fn( \stdClass $site ) => array(
				'Site ID'        => $site->ID,
				'Site Name'      => \preg_replace( '/[^a-zA-Z0-9\s&!\/|\'#.()-:]/', '', $site->name ),
				'Domain'         => $site->URL, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'Host'           => $this->eval_which_host( $site ),
				'ignore'         => $this->eval_ignore_list( $site ),
				'free_pass'      => $this->eval_pass_list( $site ),
				'is_private'     => $this->eval_is_private( $site ),
				'is_coming_soon' => $this->eval_is_coming_soon( $site ),
				'is_multisite'   => $this->eval_is_multisite( $site ),
				'is_domain_only' => $this->eval_is_domain_only( $site ),
			),
			$this->sites
		);

		if ( empty( $this->audit_type ) ) {
			$final_site_list = $this->filter_public_sites( $site_list );

			$table_header = array_keys( $final_site_list[0] );
			output_table( $output, $final_site_list, $table_header, 'WPCOMSP Sites', );
		} else {
			$audited_site_list = $this->filter_audit_sites( $site_list );
			if ( empty( $audited_site_list ) ) {
				$output->writeln( "<error>Failed to find any sites using the search parameter $this->audit_type.<error>" );
				return Command::SUCCESS;
			}

			$table_header = array_keys( $audited_site_list[0] );
			output_table( $output, $audited_site_list, $table_header, 'WPCOMSP Sites - Audit Mode' );

			$filters_output = array(
				'MANUAL FILTERS:' => '',
				'The following filters are used to exclude sites from the live site count list.' => '',
				'It works by searching for the term in the site url and if found,' => '',
				'the site is excluded unless explicitly overridden.' => '',
				'Term list:'      => '',
			);
			foreach ( $this->ignore as $term ) {
				$filters_output[ $term ] = '';
			}

			$filters_output['The following sites are allowed to pass the above filtered terms and'] = '';
			$filters_output['counted as live sites:'] = '';
			foreach ( $this->free_pass as $pass ) {
				$filters_output[ $pass ] = '';
			}

			$summary_output = array(
				'REPORT SUMMARY'         => '',
				'Private sites'          => $this->count_sites( $audited_site_list, 'is_private', 'is_private' ),
				"'Coming Soon' sites"    => $this->count_sites( $audited_site_list, 'is_coming_soon', 'is_coming_soon' ),
				'Multisite parent sites' => $this->count_sites( $audited_site_list, 'is_parent', 'is_multisite' ),
				'Multisite subsites'     => $this->count_sites( $audited_site_list, 'is_subsite', 'is_multisite' ),
				'Domain only sites'      => $this->count_sites( $audited_site_list, 'is_domain_only', 'is_domain_only' ),
				'Atomic sites'           => $this->count_sites( $audited_site_list, 'Atomic', 'Host' ),
				'Pressable sites'        => $this->count_sites( $audited_site_list, 'Pressable', 'Host' ),
				'Simple sites'           => $this->count_sites( $audited_site_list, 'Simple', 'Host' ),
				'Other hosts'            => $this->count_sites( $audited_site_list, 'Other', 'Host' ),
				'PASSED sites'           => $this->count_sites( $audited_site_list, 'PASS', 'Result' ),
				'FAILED sites'           => $this->count_sites( $audited_site_list, 'FAIL', 'Result' ),
				'Total sites'            => count( $audited_site_list ),
				'AUDIT TYPE/FILTER'      => $this->audit_type,
			);
			foreach ( $filters_output as $key => $value ) {
				$output->writeln( "<info>{$key}<info>" );
			}
			$output->writeln( "\n" );

			foreach ( $summary_output as $key => $value ) {
				$output->writeln( "<info>$key: {$value}<info>" );
			}

			$summary_output  = array_merge( $filters_output, $summary_output );
			$final_site_list = $audited_site_list;
		}

		$summary_output = array(
			'REPORT SUMMARY'  => '',
			'Atomic sites'    => $this->count_sites( $final_site_list, 'Atomic', 'Host' ),
			'Pressable sites' => $this->count_sites( $final_site_list, 'Pressable', 'Host' ),
			'Simple sites'    => $this->count_sites( $final_site_list, 'Simple', 'Host' ),
			'Other hosts'     => $this->count_sites( $final_site_list, 'Other', 'Host' ),
			'Total sites'     => count( $final_site_list ),
		);
		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>$key: $value<info>" );
		}

		if ( ! \is_null( $this->stream ) ) {
			match ( $this->format ) {
				'csv' => $this->create_csv( $table_header, $final_site_list, $summary_output ),
				'json' => $this->create_json( $table_header, $final_site_list, $summary_output )
			};
			$output->writeln( "<info>Output saved to $this->destination</info>" );
		}

		$output->writeln( '<fg=green;options=bold>Sites listed successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to maybe run as an audit (excludes only specific types of sites, if any).
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_audit_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to run an audit of the sites? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please enter the type of audit you want to run [' . $this->audit_options[0] . ']:</question> ', $this->audit_options, $this->audit_options[0] );
			$response = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( 'other' === $response ) {
				$question = new Question( '<question>Please enter the search term to use for the audit:</question> ' );
				return $this->getHelper( 'question' )->ask( $input, $output, $question );
			}

			return $response;
		}

		return null;
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
			$default  = get_user_folder_path( 'Downloads/wpcom-sites_' . gmdate( 'Y-m-d-H-i-s' ) . ".$this->format" );
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
		$question = new ConfirmationQuestion( '<question>Would you like to exclude any columns from exported site list? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the columns you want to exclude from the exported file [' . $this->export_columns[0] . ']:</question> ', $this->export_columns );
			$question->setMultiselect( true );

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Tries to determine the host of the site.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string
	 */
	protected function eval_which_host( \stdClass $site ): string {
		if ( true === $site->is_wpcom_atomic ) {
			$server = 'Atomic';
		} elseif ( true === $site->jetpack ) {
			$pressable_urls = array_column( $this->pressable_sites, 'url' );
			if ( in_array( parse_url( $site->URL, PHP_URL_HOST ), $pressable_urls, true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$server = 'Pressable';
			} else {
				$server = 'Other';
			}
		} else {
			$server = 'Simple'; // Need a better way to determine if site is simple. For example, 410'd Jurassic Ninja sites will show as Simple.
		}

		return $server;
	}

	/**
	 * Evaluates if a site should be marked as ignored from the final list of sites.
	 *
	 * @param   \stdClass $site The site object to be evaluated.
	 *
	 * @return  string
	 */
	protected function eval_ignore_list( \stdClass $site ): string {
		$filtered_on = array();
		foreach ( $this->ignore as $word ) {
			if ( \str_contains( $site->URL, $word ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$filtered_on[] = $word;
			}
		}

		return \implode( ',', $filtered_on );
	}

	/**
	 * Determines if a site should be in the final list even if other filters will reject it.
	 *
	 * @param   \stdClass $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_pass_list( \stdClass $site ): string {
		$filtered_on = '';
		foreach ( $this->free_pass as $pass ) {
			if ( str_contains( $site->URL, $pass ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$filtered_on = $pass;
				break;
			}
		}

		return $filtered_on;
	}

	/**
	 * Evaluate if a site is marked as private.
	 *
	 * @param   \stdClass $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_private( \stdClass $site ): string {
		return $site->is_private ? 'is_private' : '';
	}

	/**
	 * Evaluate if a site is marked as coming soon.
	 *
	 * @param   \stdClass $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_coming_soon( \stdClass $site ): string {
		return $site->is_coming_soon ? 'is_coming_soon' : '';
	}

	/**
	 * Evaluates if a site is single or multisite.
	 *
	 * @param   \stdClass $site Site object to be evaluated.
	 *
	 * @return  string
	 */
	protected function eval_is_multisite( \stdClass $site ): string {
		/**
		 * An alternative to this implementation is to compare $site->URL against
		 * $site->options->main_network_site, however all simple sites are returned
		 * as multisites. More investigation required.
		 */
		if ( true === $site->is_multisite ) {
			foreach ( $this->multisite_patterns as $pattern ) {
				if ( str_contains( $site->URL, $pattern ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return 'is_subsite';
				}
				if ( 'Simple' !== $this->eval_which_host( $site ) ) {
					return 'is_parent';
				}
			}
		}

		return '';
	}

	/**
	 * Evaluate if a site is marked as domain only.
	 *
	 * @param   \stdClass $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_domain_only( \stdClass $site ): string {
		return ( $site->options->is_domain_only ?? false ) ? 'is_domain_only' : '';
	}

	/**
	 * Filters the list of sites and returns all public sites.
	 *
	 * @param   array $site_list The list of sites.
	 *
	 * @return  array
	 */
	protected function filter_public_sites( array $site_list ): array {
		$filtered_site_list = array();
		foreach ( $site_list as $site ) {
			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || '' !== $site['free_pass'] ) {
					$filtered_site_list[] = array(
						'Site Name' => $site['Site Name'],
						'Domain'    => $site['Domain'],
						'Site ID'   => $site['Site ID'],
						'Host'      => $site['Host'],
					);
				}
			}
		}

		return $filtered_site_list;
	}

	/**
	 * Filters the final list of sites depending on audit typ.
	 *
	 * @param   array $site_list The input object.
	 *
	 * @return  array
	 */
	protected function filter_audit_sites( array $site_list ): array {
		$audit_site_list = array();
		foreach ( $site_list as $site ) {
			if ( 'no-staging' === $this->audit_type && str_contains( $site['Domain'], 'staging' ) ) {
				continue;
			}
			if ( 'full' !== $this->audit_type && 'no-staging' !== $this->audit_type && ! in_array( $this->audit_type, $site, true ) ) {
				continue;
			}

			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || '' !== $site['free_pass'] ) {
					$result = 'PASS';
				} else {
					$result = 'FAIL';
				}
			} else {
				$result = 'FAIL';
			}

			$audit_site_list[] = array(
				'Site ID'        => $site['Site ID'],
				'Site Name'      => $site['Site Name'],
				'Domain'         => $site['Domain'],
				'Host'           => $site['Host'],
				'ignore'         => $site['ignore'],
				'free_pass'      => $site['free_pass'],
				'is_private'     => $site['is_private'],
				'is_coming_soon' => $site['is_coming_soon'],
				'is_multisite'   => $site['is_multisite'],
				'is_domain_only' => $site['is_domain_only'],
				'Result'         => $result,
			);
		}

		return $audit_site_list;
	}

	/**
	 * Counts the number of sites that match a given term.
	 *
	 * @param   array  $site_list The list of sites to count.
	 * @param   string $term      The term to match.
	 * @param   string $column    The column to match against.
	 *
	 * @return  integer
	 */
	protected function count_sites( array $site_list, string $term, string $column ): int {
		$sites = array_filter( $site_list, static fn ( array $site ) => $term === $site[ $column ] );
		return count( $sites );
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
				$column_name  = $headers[ $column_index ];
				unset( $headers[ $column_index ] );
				foreach ( $rows as &$site ) {
					unset( $site[ $column_name ] );
				}
				unset( $site );
			}
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
				$column_name  = $headers[ $column_index ];
				unset( $headers[ $column_index ] );
				foreach ( $rows as &$site ) {
					unset( $site[ $column_name ] );
				}
				unset( $site );
			}
		}

		$rows[] = $summary;
		\fwrite( $this->stream, encode_json_content( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		\fclose( $this->stream );
	}

	// endregion
}
