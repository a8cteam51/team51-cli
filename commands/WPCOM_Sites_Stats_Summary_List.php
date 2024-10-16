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
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Outputs a list of sites managed by the WordPress.com Special Projects team.
 */
#[AsCommand( name: 'wpcom:list-sites-stats-summary' )]
final class WPCOM_Sites_Stats_Summary_List extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The number of periods to include in the results.
	 *
	 * @var int|null
	 */
	private ?int $num = null;

	/**
	 * The end date for the report. Format required is YYYY-MM-DD.
	 *
	 * @var string|null
	 */
	private ?string $date = null;

	/**
	 * The period options for the report.
	 *
	 * @var array
	 */
	private array $period_choices = array( 'day', 'week', 'month', 'year' );

	/**
	 * The period for the report.
	 *
	 * @var string|null
	 */
	private ?string $period = null;

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
	 * The deny list of sites to exclude from the report.
	 *
	 * @var string[]
	 */
	private array $deny_list = array(
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

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The list of summary stats for each connected site.
	 *
	 * @var array|null
	 */
	private ?array $sites_stats = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Exports summary statistics for all sites connected to the team\'s WPCOM account.' )
			->setHelp( 'This command will output a summary of WPCOM stats across all of our sites.' );

		$this->addOption( 'num', null, InputOption::VALUE_REQUIRED, 'Number of periods to include in the results.' )
			->addOption( 'date', null, InputOption::VALUE_REQUIRED, 'The date that determines the most recent period for which results are returned. Format is Y-m-d.' )
			->addOption( 'period', null, InputOption::VALUE_REQUIRED, 'The output will return results over the past [num] days/weeks/months/years, the last one being the one including [date].' );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file in CSV format in addition to the terminal.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->num = abs( (int) get_string_input( $input, 'num', fn() => $this->prompt_num_input( $input, $output ) ) );
		$input->setOption( 'num', $this->num );

		$this->date = get_date_input( $input, 'Y-m-d', fn() => $this->prompt_date_input( $input, $output ) );
		$input->setOption( 'date', $this->date );

		$this->period = get_enum_input( $input, 'period', $this->period_choices, fn() => $this->prompt_period_input( $input, $output ), $this->period_choices[0] );
		$input->setOption( 'period', $this->period );

		// Open the destination file if provided.
		$this->destination = maybe_get_string_input( $input, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! empty( $this->destination ) ) {
			$this->stream = get_file_handle( $this->destination, 'csv' );
		}

		// Fetch the sites and filter out non-production sites.
		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		$this->sites = \array_filter(
			\array_map(
				function ( \stdClass $site ) {
					foreach ( $this->deny_list as $deny ) {
						if ( \str_contains( $site->siteurl, $deny ) ) {
							return null;
						}
					}

					return $site;
				},
				$this->sites
			)
		);
		$output->writeln( '<comment>Production site(s) found: ' . \count( $this->sites ) . '</comment>' );

		// Fetch site stats for each site.
		$this->sites_stats = get_wpcom_site_stats_batch(
			\array_column( $this->sites, 'userblog_id' ),
			\array_combine(
				\array_column( $this->sites, 'userblog_id' ),
				\array_fill(
					0,
					\count( $this->sites ),
					array(
						'num'    => $this->num,
						'period' => $this->period,
						'date'   => $this->date,
					)
				)
			),
			'summary',
			$errors
		);
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites );

		$this->sites_stats = \array_filter( $this->sites_stats, static fn( $site_stats ) => 0 < $site_stats->views );
		$output->writeln( '<comment>Site with summary stats found: ' . \count( $this->sites_stats ) . '</comment>' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Compiling summary stats for WPCOMSP sites during the last $this->num $this->period(s) period ending $this->date.</>" );

		// Sort sites by total views and run some calculations.
		\uasort( $this->sites_stats, static fn( \stdClass $a, \stdClass $b ) => $b->views <=> $a->views );
		$sum_total_views     = \number_format( \array_sum( \array_column( $this->sites_stats, 'views' ) ) );
		$sum_total_visitors  = \number_format( \array_sum( \array_column( $this->sites_stats, 'visitors' ) ) );
		$sum_total_comments  = \number_format( \array_sum( \array_column( $this->sites_stats, 'comments' ) ) );
		$sum_total_followers = \number_format( \array_sum( \array_column( $this->sites_stats, 'followers' ) ) );

		// Format the site stats for output.
		$sites_stats_rows = \array_map(
			fn( \stdClass $site_stats, string $site_id ) => array(
				$this->sites[ $site_id ]->userblog_id,
				$this->sites[ $site_id ]->siteurl,
				\number_format( $site_stats->views ),
				\number_format( $site_stats->visitors ),
				\number_format( $site_stats->comments ),
				\number_format( $site_stats->followers ),
			),
			$this->sites_stats,
			\array_keys( $this->sites_stats )
		);

		output_table(
			$output,
			$sites_stats_rows,
			array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ),
			'WPCOMSP Sites Summary Stats',
		);
		$output->writeln( "<info>Total views across WPCOMSP sites during the last $this->num $this->period(s) period ending $this->date: $sum_total_views</info>" );
		$output->writeln( "<info>Total visitors across WPCOMSP sites during the last $this->num $this->period(s) period ending $this->date: $sum_total_visitors</info>" );
		$output->writeln( "<info>Total comments across WPCOMSP sites during the last $this->num $this->period(s) period ending $this->date: $sum_total_comments</info>" );
		$output->writeln( "<info>Total followers across WPCOMSP sites during the last $this->num $this->period(s) period ending $this->date: $sum_total_followers</info>" );

		// Output to file if destination is set.
		if ( ! \is_null( $this->stream ) ) {
			\fputcsv( $this->stream, array( 'Blog ID', 'Site URL', 'Total Views', 'Total Visitors', 'Total Comments', 'Total Followers' ) );
			foreach ( $sites_stats_rows as $row ) {
				\fputcsv( $this->stream, $row );
			}
			\fclose( $this->stream );

			$output->writeln( "<info>Output saved to $this->destination</info>" );
		}

		$output->writeln( '<fg=green;options=bold>Sites summary stats listed successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to for the number of periods.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_num_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the number of periods to include in the report [1]:</question> ', '1' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user to for the period.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_period_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Please select the period for the report [' . $this->period_choices[0] . ']:</question> ', $this->period_choices, $this->period_choices[0] );
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
		$question = new Question( '<question>Enter the end date for the report [' . gmdate( 'Y-m-d' ) . ']:</question> ', gmdate( 'Y-m-d' ) );
		$question = $question->setValidator( fn( $value ) => validate_date_format( $value, 'Y-m-d' ) );
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
			$default  = get_user_folder_path( 'Downloads/wpcom-summary-stats_' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	// endregion
}
