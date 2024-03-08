<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Lists the connected Jetpack sites with a given plugin.
 */
#[AsCommand( name: 'jetpack:plugin-search' )]
final class Jetpack_Plugin_Search extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The plugin to search for.
	 *
	 * @var string|null
	 */
	private ?string $plugin = null;

	/**
	 * Whether to do a partial search.
	 *
	 * @var bool|null
	 */
	private ?bool $partial = null;

	/**
	 * The version of the plugin to search for.
	 *
	 * @var string|null
	 */
	private ?string $version = null;

	/**
	 * The version comparison operator to use.
	 *
	 * @var string|null
	 */
	private ?string $version_operator = null;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The list of plugins installed on the sites.
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
		$this->setDescription( 'List all connected sites where a given plugin is installed.' )
			->setHelp( 'Use this command to find which sites have a given plugin installed. Only sites with an active Jetpack connection to WPCOM are searched through.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin to search for. The term will be matched against the folder name, the main file name, and the textdomain.' )
			->addOption( 'partial', null, InputOption::VALUE_NONE, 'Whether to do a partial search. If set, the plugin term will be matched against partial strings.' );

		$this->addOption( 'version-search', null, InputOption::VALUE_REQUIRED, 'The version of the plugin to search for.' )
			->addOption( 'version-operator', null, InputOption::VALUE_REQUIRED, 'The operator to use for the version comparison.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, $output, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		// Set the search parameters.
		$this->partial = (bool) $input->getOption( 'partial' );
		$this->version = (string) $input->getOption( 'version-search' );
		if ( ! empty( $this->version ) ) {
			$this->version_operator = get_enum_input(
				$input,
				$output,
				'version-operator',
				array( '<', '<=', '>', '>=', '==', '=', '!=', '<>' ),
				fn() => $this->prompt_version_operator_input( $input, $output )
			);
		}

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		// Compile the list of plugins to process.
		$this->plugins = get_wpcom_site_plugins_batch( \array_column( $this->sites, 'userblog_id' ) );

		$failed_sites = \array_filter( $this->plugins, static fn( $plugins ) => \is_object( $plugins ) );
		maybe_output_wpcom_failed_sites_table( $output, $failed_sites, $this->sites, 'Sites that could NOT be searched' );

		$this->plugins = \array_filter( $this->plugins, static fn( $plugins ) => \is_array( $plugins ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$partial_match_text = $this->partial ? 'partial' : 'exact';
		$output->writeln( "<fg=magenta;options=bold>Listing connected sites where the plugin `$this->plugin` is found ($partial_match_text match).</>" );

		// Search for the plugin.
		$matches = array();
		foreach ( $this->plugins as $site_id => $plugins ) {
			foreach ( $plugins as $plugin => $plugin_data ) {
				$plugin_folder = \dirname( $plugin );
				$plugin_file   = \basename( $plugin, '.php' );

				if ( $this->is_exact_match( $plugin_data, $plugin_folder, $plugin_file ) || ( $this->partial && $this->is_partial_match( $plugin_data, $plugin_folder, $plugin_file ) ) ) {
					if ( $this->is_version_match( $plugin_data ) ) {
						$matches[ $site_id ][ $plugin ] = $plugin_data;
					}
				}
			}
		}

		// Output the results.
		$output->writeln( '<fg=green;options=bold>Found ' . \count( $matches ) . ' sites with the plugin installed.</>', OutputInterface::VERBOSITY_VERBOSE );

		$rows = array();
		foreach ( $matches as $site_id => $plugins ) {
			$site = $this->sites[ $site_id ];
			foreach ( $plugins as $plugin => $plugin_data ) {
				$rows[] = array(
					$site->userblog_id,
					$site->siteurl,
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$plugin_data->Name,
					\dirname( $plugin ),
					$plugin_data->Version,
					$plugin_data->active ? 'Active' : 'Inactive',
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		output_table(
			$output,
			$rows,
			array( 'Site ID', 'Site URL', 'Plugin Name', 'Plugin Slug', 'Plugin Version', 'Plugin Status' ),
			'Sites found with plugins matching the given term'
		);

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for the plugin term to search for.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_plugin_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the plugin term to search for:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for the version comparison operator to use.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_version_operator_input( InputInterface $input, OutputInterface $output ): string {
		$choices = array( '<', '<=', '>', '>=', '==', '=', '!=', '<>' );

		$question = new ChoiceQuestion( '<question>Select the version comparison operator to use [=]:</question> ', $choices, '=' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Checks if the plugin data matches the search term exactly.
	 *
	 * @param   \stdClass $plugin_data   The plugin data.
	 * @param   string    $plugin_folder The plugin folder.
	 * @param   string    $plugin_file   The plugin file.
	 *
	 * @return  boolean
	 */
	private function is_exact_match( \stdClass $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return $this->plugin === $plugin_data->TextDomain // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			|| $this->plugin === $plugin_folder
			|| $this->plugin === $plugin_file;
	}

	/**
	 * Checks if the plugin data matches the search term partially.
	 *
	 * @param   \stdClass $plugin_data   The plugin data.
	 * @param   string    $plugin_folder The plugin folder.
	 * @param   string    $plugin_file   The plugin file.
	 *
	 * @return  boolean
	 */
	private function is_partial_match( \stdClass $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return str_contains( $plugin_data->TextDomain, $this->plugin ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			|| str_contains( $plugin_folder, $this->plugin )
			|| str_contains( $plugin_file, $this->plugin );
	}

	/**
	 * Checks if the plugin data matches the version comparison.
	 *
	 * @param   \stdClass $plugin_data The plugin data.
	 *
	 * @return  boolean
	 */
	private function is_version_match( \stdClass $plugin_data ): bool {
		return empty( $this->version )
			|| \version_compare( $plugin_data->Version, $this->version, $this->version_operator ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// endregion
}
