<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Exports a list of plugins installed on Jetpack sites.
 */
#[AsCommand( name: 'jetpack:export-site-plugins' )]
final class Jetpack_Site_Plugins_Export extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM sites to list plugins for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The plugins installed on the sites.
	 *
	 * @var array|null
	 */
	private ?array $plugins = null;

	/**
	 * The destination to save the output to.
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
		$this->setDescription( 'Export a list of plugins installed on WPCOM or Jetpack-connected sites.' )
			->setHelp( 'Use this command to export a list of plugins installed on sites. Only sites with an active Jetpack connection to WPCOM are included.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'Domain or WPCOM ID of the site to list the plugins for.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted value is `all`.' )
			->addOption( 'destination', 'd', InputOption::VALUE_REQUIRED, 'The destination file to export the plugins to.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Open the destination file for writing.
		$this->destination = get_string_input( $input, $output, 'destination', fn() => $this->prompt_destination_input( $input, $output ) );
		$this->stream      = get_file_handle( $this->destination, 'csv' );

		// If processing a given site, retrieve it from the input.
		$multiple = get_enum_input( $input, $output, 'multiple', array( 'all' ) );
		if ( 'all' !== $multiple ) {
			$site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );

			$this->sites = array(
				$site->ID => (object) array(
					'userblog_id' => $site->ID,
					'siteurl'     => $site->URL, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				),
			);
		} else {
			$this->sites = \array_filter(
				\array_map(
					static function ( \stdClass $site ) {
						$exclude_domains = array(
							'mystagingwebsite.com',
							'go-vip.co',
							'wpcomstaging.com',
							'wpengine.com',
							'jurassic.ninja',
							'atomicsites.blog',
							'woocommerce.com',
							'woo.com',
						);

						foreach ( $exclude_domains as $exclude_domain ) {
							if ( \str_contains( $site->siteurl, $exclude_domain ) ) {
								return null;
							}
						}

						return $site;
					},
					get_wpcom_jetpack_sites()
				)
			);
		}
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		// Compile the list of plugins to process.
		$this->plugins = get_wpcom_site_plugins_batch( \array_column( $this->sites, 'userblog_id' ), $errors );
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites, 'Sites that could NOT be searched' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( '<fg=magenta;options=bold>Exporting plugins installed on ' . count( $this->plugins ) . " Jetpack site(s) to $this->destination.</>" );

		\fputcsv( $this->stream, array( 'Site ID', 'Site URL', 'Plugin Name', 'Plugin Slug', 'Plugin Version', 'Plugin Status' ) );
		foreach ( $this->plugins as $site_id => $plugins ) {
			foreach ( $plugins as $plugin => $plugin_data ) {
				\fputcsv(
					$this->stream,
					array(
						$this->sites[ $site_id ]->userblog_id,
						$this->sites[ $site_id ]->siteurl,
						// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$plugin_data->Name,
						\dirname( $plugin ),
						$plugin_data->Version,
						$plugin_data->active ? 'Active' : 'Inactive',
						// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					)
				);
			}
		}
		\fclose( $this->stream );

		$output->writeln( '<fg=green;options=bold>Plugins list exported successfully.</>' );
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
		$default  = get_user_folder_path( 'Downloads/plugins-on-wpcomsp-sites-' . \gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
		$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or WPCOM site ID to export the plugins for:</question> ' );
		$question->setAutocompleterValues( \array_column( get_wpcom_jetpack_sites() ?? array(), 'domain' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
