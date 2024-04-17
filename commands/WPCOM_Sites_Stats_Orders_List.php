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
 * Outputs a summary of WC orders for sites managed by the WordPress.com Special Projects team.
 */
#[AsCommand( name: 'wpcom:list-sites-stats-orders' )]
final class WPCOM_Sites_Stats_Orders_List extends Command {

	use \WPCOMSpecialProjects\CLI\Helper\Autocomplete;

	// region FIELDS AND CONSTANTS

	/**
	 * The unit options for the report.
	 *
	 * @var array
	 */
	private array $unit_choices = array(
		'day',
		'week',
		'month',
		'year',
	);

	/**
	 * The unit for the report.
	 *
	 * @var string|null
	 */
	private ?string $unit = null;

	/**
	 * Date format options.
	 *
	 * @var array
	 */
	private array $date_format_choices = array(
		'day'   => 'Y-m-d',
		'week'  => 'Y-\WW',
		'month' => 'Y-m',
		'year'  => 'Y',
	);

	/**
	 * The end date for the report.
	 *
	 * @var string|null
	 */
	private ?string $date = null;

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
	 * @var array
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
	 * The list of orders stats for each connected and relevant site.
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
		$this->setDescription( 'Exports WooCommerce order statistics for all sites connected to the team\'s WPCOM account.' )
			->setHelp( 'This command will output the top grossing WooCommerce sites we support with dollar amounts and an over amount summed across all of our sites.' );

		$this->addOption( 'unit', null, InputOption::VALUE_REQUIRED, 'Options: day, week, month, year.' )
			->addOption( 'date', null, InputOption::VALUE_REQUIRED, "Options:\nFor --unit=day: YYYY-MM-DD\nFor --unit=week: YYYY-W##\nFor --unit=month: YYYY-MM\nFor --unit=year: YYYY." );

		$this->addOption( 'export', null, InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file in CSV format in addition to the terminal.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->unit = get_enum_input( $input, $output, 'unit', $this->unit_choices, fn() => $this->prompt_unit_input( $input, $output ) );
		$input->setOption( 'unit', $this->unit );

		$this->date = get_date_input( $input, $output, $this->date_format_choices[ $this->unit ], fn() => $this->prompt_date_input( $input, $output ) );
		$input->setOption( 'date', $this->date );

		// Open the destination file if provided.
		$this->destination = maybe_get_string_input( $input, $output, 'export', fn() => $this->prompt_destination_input( $input, $output ) );
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

		// Filter out sites that don't have WooCommerce installed and active.
		$sites_plugins = get_wpcom_site_plugins_batch( \array_column( $this->sites, 'userblog_id' ), $errors );
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites, 'Sites that could NOT be searched for WooCommerce' );

		$this->sites = \array_filter( $this->sites, static fn( $site ) => \array_key_exists( $site->userblog_id, $sites_plugins ) );
		$this->sites = \array_filter(
			$this->sites,
			static fn( $site ) => \array_reduce(
				$sites_plugins[ $site->userblog_id ],
				static fn( $carry, $plugin ) => $carry || ( 'woocommerce' === $plugin->TextDomain && true === $plugin->active ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				false
			)
		);
		$output->writeln( '<comment>Sites with WooCommerce installed and active: ' . \count( $this->sites ) . '</comment>' );

		// Fetch site stats for each site.
		$this->sites_stats = get_wpcom_site_stats_batch(
			\array_column( $this->sites, 'userblog_id' ),
			\array_combine(
				\array_column( $this->sites, 'userblog_id' ),
				\array_fill(
					0,
					\count( $this->sites ),
					array(
						'unit'     => $this->unit,
						'date'     => $this->date,
						'quantity' => 1,
					)
				)
			),
			'orders',
			$errors
		);
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites );

		$this->sites_stats = \array_filter( $this->sites_stats, static fn( $stats ) => 0 < $stats->total_gross_sales && 0 < $stats->total_orders );
		$output->writeln( '<comment>Sites with WooCommerce orders stats found: ' . \count( $this->sites_stats ) . '</comment>' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Compiling WC orders stats for WPCOMSP sites per $this->unit until $this->date.</>" );

		// Sort sites by total sales and run some calculations.
		\uasort( $this->sites_stats, static fn( $a, $b ) => $b->total_gross_sales <=> $a->total_gross_sales );

		// Format the site stats for output.
		$sites_stats_rows      = \array_map(
			fn( \stdClass $site_stats, string $site_id ) => array(
				$this->sites[ $site_id ]->userblog_id,
				$this->sites[ $site_id ]->siteurl,
				'$' . number_format( $site_stats->total_gross_sales, 2 ),
				'$' . number_format( $site_stats->total_net_sales, 2 ),
				$site_stats->total_orders,
				$site_stats->total_products,
			),
			$this->sites_stats,
			\array_keys( $this->sites_stats )
		);
		$sum_total_gross_sales = \number_format( \array_sum( \array_column( $this->sites_stats, 'total_gross_sales' ) ), 2 );

		output_table(
			$output,
			$sites_stats_rows,
			array( 'Blog ID', 'Site URL', 'Total Gross Sales', 'Total Net Sales', 'Total Orders', 'Total Products' ),
			'WPCOMSP Sites WooCommerce Orders Stats'
		);
		$output->writeln( "<info>Total gross sales across WPCOMSP sites during $this->unit $this->date: $$sum_total_gross_sales</info>" );

		// Output to file if destination is set.
		if ( ! \is_null( $this->stream ) ) {
			\fputcsv( $this->stream, array( 'Blog ID', 'Site URL', 'Total Gross Sales', 'Total Net Sales', 'Total Orders', 'Total Products' ) );
			foreach ( $sites_stats_rows as $row ) {
				\fputcsv( $this->stream, $row );
			}
			\fclose( $this->stream );

			$output->writeln( "<info>Output saved to $this->destination</info>" );
		}

		$output->writeln( '<fg=green;options=bold>Sites WooCommerce orders stats listed successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to for the unit/period.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_unit_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Enter the units for the report [' . $this->unit_choices[0] . ']:</question> ', $this->unit_choices, $this->unit_choices[0] );
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
		$default  = gmdate( $this->date_format_choices[ $this->unit ] );
		$question = new Question( '<question>Enter the end date for the report [' . $default . ']:</question> ', $default );
		$question = $question->setValidator( fn( $value ) => validate_date_format( $value, $this->date_format_choices[ $this->unit ] ) );
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
			$default  = get_user_folder_path( 'Downloads/wpcom-wc-orders-stats_' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	// endregion
}
