<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Outputs a list of sites under Team 51 management.
 */
#[AsCommand( name: 'wpcom:list-sites' )]
final class WPCOM_Sites_List extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Types of audits.
	 *
	 * @var string
	 */
	private $audit_options = array(
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
	 * @var string
	 */
	private $audit_type = null;

	/**
	 * Types of export options.
	 *
	 * @var string
	 */
	private $export_options = array(
		'csv',
		'json',
	);

	/**
	 * The type of export to run.
	 *
	 * @var string
	 */
	private $export_type = null;

	/**
	 * Excludable columns.
	 */
	private $export_columns = array(
		'None',
		'Site Name',
		'Domain',
		'Site ID',
		'Host',
	);

	/**
	 * List of columns to exclude from the export.
	 *
	 * @var array
	 */
	private $ex_columns = null;

	/**
	 * List of url terms to trigger site exclusion.
	 *
	 * @var array
	 */
	private $ignore = array(
		'staging',
		'testing',
		'jurassic',
		'wpengine',
		'wordpress',
		'develop',
		'mdrovdahl',
		'/dev.',
		'woocommerce.com',
		'opsoasis',
	);

	/**
	 * List of url patterns to help identify multisite sites.
	 *
	 * @var array
	 */
	private $multisite_patterns = array(
		'com/',
		'org/',
	);

	/**
	 * List of sites that are allowed to pass the ignore list.
	 *
	 * @var array
	 */
	private $free_pass = array(
		'wpspecialprojects.wordpress.com',
		'tumblr.wordpress.com',
		'tonyconrad.wordpress.com',
		'killscreen.com/previously',
	);

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'List the sites under Team 51 management.' )
			->setHelp( 'Use this command to list the sites under Team 51 management.' );

		$this->addOption( 'audit', null, InputOption::VALUE_OPTIONAL, "Optional.\nProduces a full list of sites, with reasons why they were or were not filtered.\nCurrently works with the csv export and --exclude options.\nAudit values include 'full', for including all sites, 'no-staging' to exclude staging sites, as well as\na general column/text based exclusive filter, eg. 'is_private' will include only private sites. \nExample usage:\nsite-list --audit='full'\nsite-list --audit='no-staging' --export='csv'\nsite-list --audit='is_private' --export='csv' --exclude='is_multisite'\n" )
			->addOption( 'export', null, InputOption::VALUE_OPTIONAL, "Optional.\nExports the results to a csv or json file saved in the team51-cli folder as sites.csv or sites.json. \nExample usage:\nsite-list --export='csv'\nsite-list --export='json'\n" )
			->addOption( 'exclude', null, InputOption::VALUE_OPTIONAL, "Optional.\nExclude columns from the export option. Possible values: Site Name, Domain, Site ID, and Host. Letter case is not important.\nExample usage:\nsite-list --export='csv' --exclude='Site name, Host'\nsite-list --export='json' --exclude='site id,host'\n" );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->audit_type = maybe_get_string_input( $input, $output, 'audit', fn() => $this->prompt_audit_input( $input, $output ) );
		$input->setOption( 'audit', $this->audit_type );

		$this->export_type = maybe_get_string_input( $input, $output, 'export', fn() => $this->prompt_export_input( $input, $output ) );
		$input->setOption( 'export', $this->export_type );

		if ( $this->export_type ) {
			$this->ex_columns = maybe_get_string_input( $input, $output, 'exclude', fn() => $this->prompt_excluded_columns_input( $input, $output ) );
			$input->setOption( 'exclude', $this->ex_columns );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<info>Fetching sites...<info>' );

		$all_sites = get_wpcom_sites(
			array(
				'include_domain_only' => 'true',
				'fields'              => 'ID,name,URL,is_private,is_coming_soon,is_wpcom_atomic,jetpack,is_multisite,options',
			),
		);

		if ( empty( $all_sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		$site_count = count( $all_sites );
		$output->writeln( "<info>{$site_count} sites found in total. Filtering...<info>" );

		$pressable_data = get_pressable_sites();

		if ( empty( $pressable_data ) ) {
			$output->writeln( '<error>Failed to retrieve Pressable sites. Aborting!</error>' );
			exit;
		}

		$pressable_sites = array();
		foreach ( $pressable_data as $_pressable_site ) {
			$pressable_sites[] = $_pressable_site->url;
		}

		$full_site_list = array();
		foreach ( $all_sites as $site ) {
			$full_site_list[] = array(
				'Site Name'      => preg_replace( '/[^a-zA-Z0-9\s&!\/|\'#.()-:]/', '', $site->name ),
				'Domain'         => $site->URL, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'ignore'         => $this->eval_ignore_list( $site, $this->ignore ),
				'free_pass'      => $this->eval_pass_list( $site, $this->free_pass ),
				'is_private'     => $this->eval_is_private( $site ),
				'is_coming_soon' => $this->eval_is_coming_soon( $site ),
				'Host'           => $this->eval_which_host( $site, $pressable_sites ),
				'is_multisite'   => $this->eval_is_multisite( $site, $this->multisite_patterns, $pressable_sites ),
				'Site ID'        => $site->ID,
				'is_domain_only' => $this->eval_is_domain_only( $site ),
			);
		}

		if ( $this->audit_type ) {
			$audited_site_list = $this->eval_site_list( $full_site_list, $this->audit_type );

			if ( empty( $audited_site_list ) ) {
				$output->writeln( "<error>Failed to find any sites using the search parameter {$this->audit_type}.<error>" );
				exit;
			}

			$table_header = array_keys( $audited_site_list[0] );

			output_table(
				$output,
				$audited_site_list,
				$table_header,
				'Team 51 Sites - Audit Mode',
			);

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
				$output->writeln( "<info>{$key}: {$value}<info>" );
			}

			$summary_output  = array_merge( $filters_output, $summary_output );
			$final_site_list = $audited_site_list;
		} else {
			$final_site_list = $this->filter_public_sites( $full_site_list );

			$table_header = array_keys( $final_site_list[0] );

			output_table(
				$output,
				$final_site_list,
				$table_header,
				'Team 51 Sites',
			);
		}

		// Maintain for JSON output compatibility.
		$atomic_count        = $this->count_sites( $final_site_list, 'Atomic', 'Host' );
		$pressable_count     = $this->count_sites( $final_site_list, 'Pressable', 'Host' );
		$other_count         = $this->count_sites( $final_site_list, 'Other', 'Host' );
		$simple_count        = $this->count_sites( $final_site_list, 'Simple', 'Host' );
		$filtered_site_count = count( $final_site_list );

		$summary_output = array(
			'REPORT SUMMARY'  => '',
			'Atomic sites'    => $this->count_sites( $final_site_list, 'Atomic', 'Host' ),
			'Pressable sites' => $this->count_sites( $final_site_list, 'Pressable', 'Host' ),
			'Simple sites'    => $this->count_sites( $final_site_list, 'Simple', 'Host' ),
			'Other hosts'     => $this->count_sites( $final_site_list, 'Other', 'Host' ),
			'Total sites'     => count( $final_site_list ),
		);

		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>{$key}: {$value}<info>" );
		}

		if ( 'csv' === $this->export_type ) {
			$this->create_csv( $table_header, $final_site_list, $summary_output, $this->ex_columns );
			$output->writeln( '<info>Exported to sites.csv in the current folder.<info>' );
		} elseif ( 'json' === $this->export_type ) {
			$this->create_json( $final_site_list, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $this->ex_columns );
			$output->writeln( '<info>Exported to sites.json in the current folder.<info>' );
		}

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
		$question = new ConfirmationQuestion( '<question>Would you like to run a full audit of sites? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please enter the type of audit you want to run [' . $this->audit_options[0] . ']:</question> ', $this->audit_options, 'full' );
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
	 * Prompts the user to maybe export the list or report.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_export_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to also export the list sites? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the type of export you want to run [' . $this->export_options[0] . ']:</question> ', $this->export_options, 'csv' );
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
	 * @return  string|null
	 */
	private function prompt_excluded_columns_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to exclude any columns from exported site list? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new ChoiceQuestion( '<question>Please select the columns you want to exclude from the exported file [' . $this->export_columns[0] . ']:</question> ', $this->export_columns, 'None' );
			$question->setMultiselect( true );
			$answer = $this->getHelper( 'question' )->ask( $input, $output, $question );
			if ( in_array( 'None', $answer, true ) ) {
				return null;
			}
			return implode( ',', $answer );
		}
		return null;
	}


	/**
	 * Filters the final list of sites depending on audit typ.
	 *
	 * @param   array  $site_list  The input object.
	 * @param   string $audit_type The output object.
	 *
	 * @return  array
	 */
	protected function eval_site_list( $site_list, $audit_type ): array {
		$audit_site_list = array();
		foreach ( $site_list as $site ) {
			if ( 'no-staging' === $audit_type && false !== strpos( $site[1], 'staging' ) ) {
				continue;
			}
			if ( 'full' !== $audit_type && 'no-staging' !== $audit_type && ! in_array( $audit_type, $site, true ) ) {
				continue;
			}
			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || ( '' !== $site['ignore'] && '' !== $site['free_pass'] ) ) {
					$result = 'PASS';
				} else {
					$result = 'FAIL';
				}
			} else {
				$result = 'FAIL';
			}
			$audit_site_list[] = array(
				'Site Name'      => $site['Site Name'],
				'Domain'         => $site['Domain'],
				'ignore'         => $site['ignore'],
				'free_pass'      => $site['free_pass'],
				'is_private'     => $site['is_private'],
				'is_coming_soon' => $site['is_coming_soon'],
				'is_multisite'   => $site['is_multisite'],
				'is_domain_only' => $site['is_domain_only'],
				'Host'           => $site['Host'],
				'Result'         => $result,
				'Site ID'        => $site['Site ID'],
			);
		}
		return $audit_site_list;
	}

	/**
	 * Tries to determine the host of the site.
	 *
	 * @param   object $site            The site object.
	 * @param   array  $pressable_sites The list of Pressable sites.
	 *
	 * @return  string
	 */
	protected function eval_which_host( $site, $pressable_sites ): string {
		if ( true === $site->is_wpcom_atomic ) {
			$server = 'Atomic';
		} elseif ( true === $site->jetpack ) {
			if ( in_array( parse_url( $site->URL, PHP_URL_HOST ), $pressable_sites, true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$server = 'Pressable';
			} else {
				$server = 'Other';
			}
		} else {
			$server = 'Simple'; // Need a better way to determine if site is simple. eg. 410'd Jurrasic Ninja sites will show as Simple.
		}
		return $server;
	}

	/**
	 * Filters the list of sites and returns all public sites.
	 *
	 * @param   array $site_list The list of sites.
	 *
	 * @return  array
	 */
	protected function filter_public_sites( $site_list ): array {
		$filtered_site_list = array();
		foreach ( $site_list as $site ) {
			if ( '' === $site['is_domain_only'] && '' === $site['is_private'] && '' === $site['is_coming_soon'] && ( 'is_subsite' !== $site['is_multisite'] || '' !== $site['free_pass'] ) ) {
				if ( '' === $site['ignore'] || ( '' !== $site['ignore'] && '' !== $site['free_pass'] ) ) {
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
	 * Evaluates if a site should be marked as ignored from the final list of sites.
	 *
	 * @param   object $site   The site object to be evaluated.
	 * @param   array  $ignore Array of ignore terms to filter on.
	 *
	 * @return  string
	 */
	protected function eval_ignore_list( $site, $ignore ): string {
		$filtered_on = array();
		foreach ( $ignore as $word ) {
			if ( false !== strpos( $site->URL, $word ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$filtered_on[] = $word;
			}
		}
		return implode( ',', $filtered_on );
	}

	/**
	 * Evaluates if a site is single or multisite.
	 *
	 * @param   object $site            Site object to be evaluated.
	 * @param   array  $patterns        Array of patterns to eveluate against.
	 * @param   array  $pressable_sites List of Pressable sites.
	 *
	 * @return  string
	 */
	protected function eval_is_multisite( $site, $patterns, $pressable_sites ): string {
		/**
		 * An alternative to this implementation is to compare $site->URL against
		 * $site->options->main_network_site, however all simple sites are returned
		 * as multisites. More investigation required.
		 */
		if ( true === $site->is_multisite ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $site->URL, $pattern ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return 'is_subsite';
				} elseif ( 'Simple' !== $this->eval_which_host( $site, $pressable_sites ) ) {
					return 'is_parent';
				}
			}
		}
		return '';
	}

	/**
	 * Determines if a site should be in the final list even if other filters will reject it.
	 *
	 * @param   object $site      The site to evaluate.
	 * @param   array  $free_pass List of sites to be always listed.
	 *
	 * @return  string
	 */
	protected function eval_pass_list( $site, $free_pass ): string {
		$filtered_on = '';
		foreach ( $free_pass as $pass ) {
			if ( false !== strpos( $site->URL, $pass ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$filtered_on = $pass;
				break;
			}
		}
		return $filtered_on;
	}

	/**
	 * Evaluate if a site is marked as private.
	 *
	 * @param   object $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_private( $site ): string {
		if ( true === $site->is_private ) {
			return 'is_private';
		} else {
			return '';
		}
	}

	/**
	 * Evaluate if a site is marked as coming soon.
	 *
	 * @param   object $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_coming_soon( $site ): string {
		if ( true === $site->is_coming_soon ) {
			return 'is_coming_soon';
		} else {
			return '';
		}
	}

	/**
	 * Evaluate if a site is marked as domain only.
	 *
	 * @param   object $site The site to evaluate.
	 *
	 * @return  string
	 */
	protected function eval_is_domain_only( $site ): string {
		if ( isset( $site->options->is_domain_only ) && true === $site->options->is_domain_only ) {
			return 'is_domain_only';
		} else {
			return '';
		}
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
	protected function count_sites( $site_list, $term, $column ): int {
		$sites = array_filter(
			$site_list,
			function ( $site ) use ( $term, $column ) {
				return $term === $site[ $column ];
			}
		);
		return count( $sites );
	}

	/**
	 * Creates a CSV file from the final list of sites.
	 *
	 * @param   array  $csv_header      The header for the CSV file.
	 * @param   array  $final_site_list The final list of sites.
	 * @param   array  $csv_summary     The summary of the report.
	 * @param   string $ex_columns      The columns to exclude from the export.
	 *
	 * @return  void
	 */
	protected function create_csv( $csv_header, $final_site_list, $csv_summary, $ex_columns ): void {
		$csv_header_compare = array_map(
			function ( $column ) {
				return strtoupper( preg_replace( '/\s+/', '', $column ) );
			},
			$csv_header
		);

		if ( null !== $ex_columns ) {
			$exclude_columns = explode( ',', strtoupper( preg_replace( '/\s+/', '', $ex_columns ) ) );
			foreach ( $exclude_columns as $column ) {
				$column_index = array_search( $column, $csv_header_compare, true );
				$column_name  = $csv_header[ $column_index ];
				unset( $csv_header[ $column_index ] );
				foreach ( $final_site_list as &$site ) {
					unset( $site[ $column_name ] );
				}
				unset( $site );
			}
		}
		array_unshift( $final_site_list, $csv_header );
		foreach ( $csv_summary as $key => $item ) {
			$final_site_list[] = array( $key, $item );
		}

		$fp = fopen( 'sites.csv', 'w' );
		foreach ( $final_site_list as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );
	}

	/**
	 * Creates a JSON file from the final list of sites.
	 *
	 * @param   array   $site_list_array     The final list of sites.
	 * @param   integer $atomic_count        The number of atomic sites.
	 * @param   integer $pressable_count     The number of Pressable sites.
	 * @param   integer $simple_count        The number of simple sites.
	 * @param   integer $other_count         The number of other hosts.
	 * @param   integer $filtered_site_count The total number of sites.
	 * @param   string  $ex_columns          The columns to exclude from the export.
	 *
	 * @return  void
	 */
	protected function create_json( $site_list_array, $atomic_count, $pressable_count, $simple_count, $other_count, $filtered_site_count, $ex_columns ): void {
		// To-do: After stripping columns, re-index, then build as an associative array.
		// The above is no longer required as the passed array is now and associative array.
		// Improved logic/handling required in L411 to L419, and perhaps others.
		// Reformat summary as a proper pair.
		$json_header         = array( 'Site Name', 'Domain', 'Site ID', 'Host' );
		$json_header_compare = array_map(
			function ( $column ) {
				return strtoupper( preg_replace( '/\s+/', '', $column ) );
			},
			$json_header
		);

		$json_summary = array(
			'Atomic sites'    => $atomic_count,
			'Pressable sites' => $pressable_count,
			'Simple sites'    => $simple_count,
			'Other hosts'     => $other_count,
			'Total sites'     => $filtered_site_count,
		);

		if ( null !== $ex_columns ) {
			$exclude_columns = explode( ',', strtoupper( preg_replace( '/\s+/', '', $ex_columns ) ) );
		} else {
			$exclude_columns = array();
		}
		$site_list       = array();
		$final_site_list = array();

		foreach ( $site_list_array as &$site ) {
			foreach ( $json_header as $column ) {
				$column_index = array_search( $column, $json_header, true );
				if ( in_array( $json_header_compare[ $column_index ], $exclude_columns, true ) ) {
					continue;
				}
				$site_list[ $json_header[ $column_index ] ] = $site[ $json_header[ $column_index ] ];
			}
			$final_site_list[] = $site_list;
		}

		$final_site_list[] = $json_summary;

		$fp = fopen( 'sites.json', 'w' );
		fwrite( $fp, json_encode( $final_site_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		fclose( $fp );
	}

	// endregion
}
