<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;
use WPCOMSpecialProjects\CLI\Helper\ExportTrait;

/**
 * Exports a list of plugins installed on Jetpack sites.
 */
#[AsCommand( name: 'jetpack:export-site-plugins' )]
final class Jetpack_Site_Plugins_Export extends Command {
	use AutocompleteTrait;
	use ExportTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Columns of the exported file.
	 *
	 * @var string[]
	 */
	private const COLUMNS = array( 'Site ID', 'Site URL', 'Plugin Name', 'Plugin Slug', 'Plugin Version', 'Plugin Status' );

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

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Export a list of plugins installed on WPCOM or Jetpack-connected sites.' )
			->setHelp( 'Use this command to export a list of plugins installed on sites. Only sites with an active Jetpack connection to WPCOM are included.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'Domain or WPCOM ID of the site to list the plugins for.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all` or a comma-separated list of site IDs or domains.' );

		$this->configure_export_options( self::COLUMNS );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Exporting is the point of this command, so the file is saved to Downloads unless --export says otherwise.
		$this->initialize_export( $input, $output, 'plugins-on-wpcomsp-sites', self::COLUMNS, get_user_folder_path( 'Downloads/plugins-on-wpcomsp-sites-' . \gmdate( 'Y-m-d-H-i-s' ) ) );

		$multiple = $input->getOption( 'multiple' );
		if ( null === $multiple ) {
			// Single site operation
			$site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );
			$this->sites = array(
				$site->ID => (object) array(
					'userblog_id' => $site->ID,
					'siteurl'     => $site->URL, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				),
			);
		} elseif ( 'all' === $multiple ) {
			// All sites
			$this->sites = $this->get_all_sites();
		} else {
			// Comma-separated list of sites
			$this->sites = $this->get_sites_from_multiple_input( $multiple );
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
		$output->writeln( '<fg=magenta;options=bold>Exporting plugins installed on ' . count( $this->plugins ) . " Jetpack site(s) to $this->export_destination.</>" );

		$rows = array();
		foreach ( $this->plugins as $site_id => $plugins ) {
			foreach ( $plugins as $plugin => $plugin_data ) {
				$rows[] = array(
					$this->sites[ $site_id ]->userblog_id,
					$this->sites[ $site_id ]->siteurl,
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$plugin_data->Name,
					\dirname( $plugin ),
					$plugin_data->Version,
					$plugin_data->active ? 'Active' : 'Inactive',
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}
		$this->maybe_export( $output, self::COLUMNS, $rows );

		$output->writeln( '<fg=green;options=bold>Plugins list exported successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

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
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_wpcom_jetpack_sites() ?? array(), 'domain' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get all sites, excluding certain domains.
	 *
	 * @return array
	 */
	private function get_all_sites(): array {
		return \array_filter(
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

	/**
	 * Get sites from the multiple input option.
	 *
	 * @param   string $multiple The multiple input option.
	 *
	 * @return  array
	 */
	private function get_sites_from_multiple_input( string $multiple ): array {
		// Comma-separated list of sites
		$site_identifiers = array_map( 'trim', explode( ',', $multiple ) );
		$sites            = array();
		foreach ( $site_identifiers as $identifier ) {
			$site = get_wpcom_site( $identifier );
			if ( $site ) {
				$sites[ $site->ID ] = (object) array(
					'userblog_id' => $site->ID,
					'siteurl'     => $site->URL, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		return $sites;
	}

	// endregion
}
