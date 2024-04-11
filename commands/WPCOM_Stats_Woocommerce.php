<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Outputs top grossing WooCommerce sites we support.
 */
#[AsCommand( name: 'wpcom:woocommerce-orders' )]
final class WPCOM_Stats_Woocommerce extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The period options for the report.
	 *
	 * @var array
	 */
	private $unit_options = array(
		'day',
		'week',
		'month',
		'year',
	);

	/**
	 * The period for the report.
	 *
	 * @var string
	 */
	private $unit = 'day';

	/**
	 * Date format options.
	 *
	 * @var array
	 */
	private $date_format_options = array(
		'day'   => array( 'YYYY-MM-DD', 'Y-m-d' ),
		'week'  => array( 'YYYY-W##', 'Y-\WW-N' ),
		'month' => array( 'YYYY-MM', 'Y-m' ),
		'year'  => array( 'YYYY', 'Y' ),
	);

	/**
	 * The end date for the report.
	 *
	 * @var string
	 */
	private $date = '';

	/**
	 * Export to csv option.
	 *
	 * @var string|null
	 */
	private $csv = null;

	/**
	 * Maybe check production sites.
	 *
	 * @var string
	 */
	private $check_production_sites = null;

	/**
	 * The plugin slug for WooCommerce.
	 *
	 * @var string
	 */
	private $plugin_slug = 'woocommerce';

	/**
	 * The deny list of sites to exclude from the report.
	 *
	 * @var array
	 */
	private $deny_list = array(
		'mystagingwebsite.com',
		'go-vip.co',
		'wpcomstaging.com',
		'wpengine.com',
		'jurassic.ninja',
		'woocommerce.com',
		'atomicsites.blog',
		'ninomihovilic.com',
		'team51.blog',
	);

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Get WooCommerce order stats across all Team51 sites.' )
			->setHelp( "This command will output the top grossing WooCommerce sites we support with dollar amounts and an over amount summed across all of our sites.\nExample usage:\nstats:woocommerce-orders --unit=year --date=2022\nstats:woocommerce-orders --unit=week --date=2022-W12\nstats:woocommerce-orders --unit=month --date=2021-10\nstats:woocommerce-orders --unit=day --date=2022-02-27" );

		$this->addOption( 'unit', null, InputOption::VALUE_REQUIRED, 'Options: day, week, month, year.' )
			->addOption( 'date', null, InputOption::VALUE_REQUIRED, "Options:\nFor --unit=day: YYYY-MM-DD\nFor --unit=week: YYYY-W##\nFor --unit=month: YYYY-MM\nFor --unit=year: YYYY." )
			->addOption( 'check-production-sites', null, InputOption::VALUE_NONE, "Checks production sites instead of the Jetpack Profile for the sites. Takes much longer to run. You might want to check the production sites if you suspect that the Jetpack cache isn't up to date for your purposes and a newly connected site with lots of sales has WooCommerce installed." )
			->addOption( 'csv', null, InputOption::VALUE_NONE, 'Export stats to a CSV file.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->unit = get_string_input( $input, $output, 'unit', fn() => $this->prompt_unit_input( $input, $output ) );
		$input->setOption( 'unit', $this->unit );

		$this->date = get_string_input( $input, $output, 'date', fn() => $this->prompt_date_input( $input, $output ) );
		$input->setOption( 'date', $this->date );

		$csv       = maybe_get_string_input( $input, $output, 'csv', fn() => $this->prompt_csv_input( $input, $output ) );
		$this->csv = $csv ? 'csv' : null;
		$input->setOption( 'csv', $this->csv );

		$check_production_sites       = maybe_get_string_input( $input, $output, 'csv', fn() => $this->prompt_check_production_sites_input( $input, $output ) );
		$this->check_production_sites = $check_production_sites ? 'check_production_sites' : null;
		$input->setOption( 'csv', $this->check_production_sites );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<info>Fetching production sites connected to a8cteam51...<info>' );

		// Fetching sites connected to a8cteam51
		$sites = get_wpcom_jetpack_sites();

		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			exit;
		}

		// Filter out non-production sites
		$site_list = array();

		foreach ( $sites as $site ) {
			$matches = false;
			foreach ( $this->deny_list as $deny ) {
				if ( strpos( $site->siteurl, $deny ) !== false ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				$site_list[] = array(
					'blog_id'  => $site->userblog_id,
					'site_url' => $site->siteurl,
				);
			}
		}

		$site_count = count( $site_list );

		if ( empty( $site_count ) ) {
			$output->writeln( '<error>No production sites found.<error>' );
			exit;
		}

		$output->writeln( "<info>{$site_count} sites found.<info>" );

		if ( $this->check_production_sites ) {
			$output->writeln( '<info>Checking production sites for WooCommerce...<info>' );
			$progress_bar = new ProgressBar( $output, $site_count );
			$progress_bar->start();
			// Checking each site for the plugin slug: woocommerce, and only saving the sites that have it active
			foreach ( $site_list as $site ) {
				$progress_bar->advance();
				$plugin_list = get_wpcom_site_plugins( $site['blog_id'] ); // May be too verbose on failed connections, also slower then previous command.
				if ( ! empty( $plugin_list ) ) {
					$plugins_array = (array) $plugin_list;
					foreach ( $plugins_array as $plugin_path => $plugin ) {
						$folder_name = strstr( $plugin_path, '/', true );
						$file_name   = str_replace( array( '/', '.php' ), '', strrchr( $plugin_path, '/' ) );
						if ( ( $this->plugin_slug === $plugin->TextDomain || $this->plugin_slug === $folder_name || $this->plugin_slug === $file_name ) && $plugin->active ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							$sites_with_woocommerce[] = array(
								'site_url' => $site['site_url'],
								'blog_id'  => $site['blog_id'],
							);
						}
					}
				}
			}
			$progress_bar->finish();
			$output->writeln( '<info>  Yay!</info>' );
		} else {
			// Get plugin lists from Jetpack profile data
			$output->writeln( '<info>Checking Jetpack site profiles for WooCommerce...<info>' );
			$jetpack_sites_plugins = get_wpcom_jetpack_sites_plugins();

			if ( empty( $jetpack_sites_plugins->sites ) ) {
				$output->writeln( '<error>Fetching plugins from Jetpack site profiles failed.<error>' );
				exit;
			}

			foreach ( $site_list as $site ) {
				//check if the site exists in the jetpack_sites_plugins object
				if ( ! empty( $jetpack_sites_plugins->sites->{$site['blog_id']} ) ) {
					// loop through the plugins and check for WooCommerce
					foreach ( $jetpack_sites_plugins->sites->{$site['blog_id']} as $site_plugin ) {
						if ( $site_plugin->slug === $this->plugin_slug && true === $site_plugin->active ) {
							$sites_with_woocommerce[] = array(
								'site_url' => $site['site_url'],
								'blog_id'  => $site['blog_id'],
							);
							break;
						}
					}
				}
			}
		}

		$woocommerce_count = count( $sites_with_woocommerce );
		$output->writeln( "<info>{$woocommerce_count} sites have WooCommerce installed and active.<info>" );

		// Get WooCommerce stats for each site
		$output->writeln( '<info>Fetching WooCommerce stats for Team51 production sites...<info>' );
		$progress_bar = new ProgressBar( $output, $woocommerce_count );
		$progress_bar->start();

		$team51_woocommerce_stats = array();
		foreach ( $sites_with_woocommerce as $site ) {
			$progress_bar->advance();
			$stats = $this->get_woocommerce_stats( $site['blog_id'], $this->unit, $this->date );

			//Checking if stats are not zero. If not, add to array
			if ( isset( $stats->total_gross_sales ) && $stats->total_gross_sales > 0 && $stats->total_orders > 0 ) {
				array_push(
					$team51_woocommerce_stats,
					array(
						'site_url'          => $site['site_url'],
						'blog_id'           => $site['blog_id'],
						'total_gross_sales' => $stats->total_gross_sales,
						'total_net_sales'   => $stats->total_net_sales,
						'total_orders'      => $stats->total_orders,
						'total_products'    => $stats->total_products,
					)
				);
			}
		}
		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		//Sort the array by total gross sales
		usort(
			$team51_woocommerce_stats,
			function ( $a, $b ) {
				return $b['total_gross_sales'] - $a['total_gross_sales'];
			}
		);

		// Format sales as money
		$formatted_team51_woocommerce_stats = array();
		foreach ( $team51_woocommerce_stats as $site ) {
			array_push(
				$formatted_team51_woocommerce_stats,
				array(
					'site_url'          => $site['site_url'],
					'blog_id'           => $site['blog_id'],
					'total_gross_sales' => '$' . number_format( $site['total_gross_sales'], 2 ),
					'total_net_sales'   => '$' . number_format( $site['total_net_sales'], 2 ),
					'total_orders'      => $site['total_orders'],
					'total_products'    => $site['total_products'],
				)
			);
		}

		//Sum the total gross sales
		$sum_total_gross_sales = array_reduce(
			$team51_woocommerce_stats,
			function ( $carry, $site ) {
				return $carry + $site['total_gross_sales'];
			},
			0
		);

		//round the sum
		$sum_total_gross_sales = number_format( $sum_total_gross_sales, 2 );

		$output->writeln( '<info>Site stats for the selected time period: ' . $this->unit . ' ' . $this->date . '<info>' );
		// Output the stats in a table
		output_table(
			$output,
			$formatted_team51_woocommerce_stats,
			array( 'Site URL', 'Blog ID', 'Total Gross Sales', 'Total Net Sales', 'Total Orders', 'Total Products' ),
			'Team 51 Site WooCommerce Sales Stats',
		);

		$output->writeln( '<info>Total Gross Sales across Team51 sites in ' . $this->unit . ' ' . $this->date . ': $' . $sum_total_gross_sales . '<info>' );

		// Output CSV if --csv flag is set
		if ( $this->csv ) {
			$output->writeln( '<info>Making the CSV...<info>' );
			$timestamp = gmdate( 'Y-m-d-H-i-s' );
			$fp        = fopen( 't51-woocommerce-stats-' . $timestamp . '.csv', 'w' );
			fputcsv( $fp, array( 'Site URL', 'Blog ID', 'Total Gross Sales', 'Total Net Sales', 'Total Orders', 'Total Products' ) );
			foreach ( $formatted_team51_woocommerce_stats as $fields ) {
				fputcsv( $fp, $fields );
			}
			fclose( $fp );

			$output->writeln( '<info>Done, CSV saved to your current working directory: t51-woocommerce-stats-' . $timestamp . '.csv<info>' );
		}

		$output->writeln( '<info>All done! :)<info>' );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to for the unit/period, ie. day, week, month, year.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_unit_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Enter the units for the report [' . $this->unit_options[0] . ']:</question> ', $this->unit_options, 'day' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user to for the end date of the report.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_date_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the end date for the report [' . $this->date_format_options[ $this->unit ][0] . ']:</question> ' );
		$question = $question->setValidator( fn( $value ) => validate_date_format( 'week' === $this->unit ? $value . '-1' : $value, $this->date_format_options[ $this->unit ][1] ) );
		// Validator for week format fails. Need to fix.
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user to maybe export the report to a csv file.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_csv_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to export the report as a CSV file? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			return true;
		}
		return null;
	}

	/**
	 * Prompts the user to maybe check production sites.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_check_production_sites_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to check production sites? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			return true;
		}
		return null;
	}

	/**
	 * Get WooCommerce stats for a given site.
	 *
	 * @param integer $site_id The site ID.
	 * @param string  $unit    The period to get stats for.
	 * @param string  $date    The date to get stats for.
	 *
	 * @return stdClass
	 */
	private function get_woocommerce_stats( $site_id, $unit, $date ): stdClass {

		$woocommerce_stats = $this->api_helper->call_wpcom_api( '/wpcom/v2/sites/' . $site_id . '/stats/orders?unit=' . $unit . '&date=' . $date . '&quantity=1', array() );
		return $woocommerce_stats;
	}

	// endregion
}
