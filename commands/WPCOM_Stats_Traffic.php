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
 * Outputs a list of sites under Team 51 management.
 */
#[AsCommand( name: 'wpcom:traffic-stats' )]
final class WPCOM_Stats_Traffic extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The number of periods to include in the results.
	 *
	 * @var string
	 */
	private $num = '1';

	/**
	 * The period options for the report.
	 *
	 * @var array
	 */
	private $period_options = array(
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
	private $period = 'day';

	/**
	 * The end date for the report. Format required is YYYY-MM-DD.
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
		$this->setDescription( 'Get wpcom traffic across all Team51 sites.' )
			->setHelp( "This command will output a summary of wpcom traffic stats across all of our sites.\nExample usage:\nstats:wpcom-traffic --period=year --date=2022-12-12\nstats:wpcom-traffic --num=3 --period=week --date=2021-10-25\nstats:wpcom-traffic --num=6 --period=month --date=2021-02-28\nstats:wpcom-traffic --period=day --date=2022-02-27\n\nThe stats come from: https://developer.wordpress.com/docs/api/1.1/get/sites/%24site/stats/summary/" );

		$this->addOption( 'num', null, InputOption::VALUE_OPTIONAL, 'Number of periods to include in the results Default: 1.' )
			->addOption( 'period', null, InputOption::VALUE_REQUIRED, "Options: day, week, month, year.\nday: The output will return results over the past [num] days, the last day being the date specified.\nweek: The output will return results over the past [num] weeks, the last week being the week containing the date specified.\nmonth: The output will return results over the past [num] months, the last month being the month containing the date specified.\nyear: The output will return results over the past [num] years, the last year being the year containing the date specified." )
			->addOption( 'date', null, InputOption::VALUE_REQUIRED, 'Date format: YYYY-MM-DD.' )
			->addOption( 'csv', null, InputOption::VALUE_NONE, 'Export stats to a CSV file.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->num = get_string_input( $input, $output, 'num', fn() => $this->prompt_num_input( $input, $output ) );
		$input->setOption( 'num', $this->num );

		$this->period = get_string_input( $input, $output, 'period', fn() => $this->prompt_period_input( $input, $output ) );
		$input->setOption( 'period', $this->period );

		$this->date = get_string_input( $input, $output, 'date', fn() => $this->prompt_date_input( $input, $output ) );
		$input->setOption( 'date', $this->date );

		$csv       = maybe_get_string_input( $input, $output, 'csv', fn() => $this->prompt_csv_input( $input, $output ) );
		$this->csv = $csv ? 'csv' : null;
		$input->setOption( 'csv', $this->csv );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<info>Checking for stats for Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . '<info>' );

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
			$output->writeln( '<error>Zero production sites to check.<error>' );
			exit;
		}

		$output->writeln( "<info>{$site_count} sites found.<info>" );

		// Get site stats for each site
		$output->writeln( '<info>Fetching site stats for Team51 production sites...<info>' );
		$progress_bar = new ProgressBar( $output, $site_count );
		$progress_bar->start();

		$team51_site_stats = array();
		foreach ( $site_list as $site ) {
			$progress_bar->advance();
			$stats = $this->get_site_stats( $site['blog_id'], $this->period, $this->date, $this->num );

			//Checking if stats are not null. If not, add to array
			if ( ! empty( $stats->views ) ) {
				array_push(
					$team51_site_stats,
					array(
						'blog_id'   => $site['blog_id'],
						'site_url'  => $site['site_url'],
						'views'     => $stats->views,
						'visitors'  => $stats->visitors,
						'comments'  => $stats->comments,
						'followers' => $stats->followers,
					)
				);
			}
		}

		if ( empty( $team51_site_stats ) ) {
			$output->writeln( '<error>Zero sites with stats.<error>' );
			exit;
		}

		$progress_bar->finish();
		$output->writeln( '<info>  Yay!</info>' );

		//Sort the array by total gross sales
		usort(
			$team51_site_stats,
			function ( $a, $b ) {
				return $b['views'] - $a['views'];
			}
		);

		//Sum the totals
		$sum_total_views = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['views'];
			},
			0
		);

		$sum_total_visitors = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['visitors'];
			},
			0
		);

		$sum_total_comments = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['comments'];
			},
			0
		);

		$sum_total_followers = array_reduce(
			$team51_site_stats,
			function ( $carry, $site ) {
				return $carry + $site['followers'];
			},
			0
		);

		$formatted_team51_site_stats = array();
		foreach ( $team51_site_stats as $site ) {
			$formatted_team51_site_stats[] = array( $site['blog_id'], $site['site_url'], number_format( $site['views'], 0 ), number_format( $site['visitors'], 0 ), number_format( $site['comments'], 0 ), number_format( $site['followers'], 0 ) );
		}

		$sum_total_views     = number_format( $sum_total_views, 0 );
		$sum_total_visitors  = number_format( $sum_total_visitors, 0 );
		$sum_total_comments  = number_format( $sum_total_comments, 0 );
		$sum_total_followers = number_format( $sum_total_followers, 0 );

		$output->writeln( '<info>Site stats for Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . '<info>' );
		// Output the stats in a table
		output_table(
			$output,
			$formatted_team51_site_stats,
			array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ),
			'Team 51 Site Stats',
		);

		$output->writeln( '<info>Total views across Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . ': ' . $sum_total_views . '<info>' );
		$output->writeln( '<info>Total visitors across Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . ': ' . $sum_total_visitors . '<info>' );
		$output->writeln( '<info>Total comments across Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . ': ' . $sum_total_comments . '<info>' );
		$output->writeln( '<info>Total followers across Team51 sites during the ' . $this->num . ' ' . $this->period . ' period ending ' . $this->date . ': ' . $sum_total_followers . '<info>' );

		// Output CSV if --csv flag is set
		if ( $this->csv ) {
			$output->writeln( '<info>Making the CSV...<info>' );
			$timestamp = gmdate( 'Y-m-d-H-i-s' );
			$fp        = fopen( 't51-traffic-stats-' . $timestamp . '.csv', 'w' );
			fputcsv( $fp, array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ) );
			foreach ( $formatted_team51_site_stats as $fields ) {
				fputcsv( $fp, $fields );
			}
			fclose( $fp );

			$output->writeln( '<info>Done, CSV saved to your current working directory: t51-traffic-stats-' . $timestamp . '.csv<info>' );
		}

		$output->writeln( '<info>All done! :)<info>' );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to for the number of periods. Defaults to 1.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_num_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the number of periods to include in the report (default is 1):</question> ', '1' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user to for the period, ie. day, week, month, year.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_period_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Enter the period for the report [' . $this->period_options[0] . ']:</question> ', $this->period_options, 'day' );
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
		$question = new Question( '<question>Enter the end date for the report [YYYY-MM-DD] (default: ' . gmdate( 'Y-m-d' ) . '):</question> ', gmdate( 'Y-m-d' ) );
		$question = $question->setValidator( fn( $value ) => validate_date_format( $value, 'Y-m-d' ) );
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
	 * Get site stats for a given site.
	 *
	 * @param integer $site_id The site ID.
	 * @param string  $period  The period to get stats for.
	 * @param string  $date    The date to get stats for.
	 * @param integer $num     The number of periods to get stats for.
	 *
	 * @return stdClass
	 */
	private function get_site_stats( $site_id, $period, $date, $num ): stdClass {
		$site_stats = get_wpcom_site_stats(
			$site_id,
			array(
				'period' => $period,
				'date'   => $date,
				'num'    => $num,
			),
		);
		return $site_stats;
	}

	// endregion
}
