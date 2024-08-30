<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Outputs a list of plugins installed on a given WPCOM or Jetpack-connected site.
 */
#[AsCommand( name: 'wpcom:list-site-plugins' )]
final class WPCOM_Site_Plugins_List extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to list plugins for.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'List the plugins installed on a WPCOM site.' )
			->setHelp( 'Use this command to list the plugins installed on a WPCOM site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to list the plugins for.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing plugins installed on {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$site_plugins = get_wpcom_site_plugins( $this->site->ID );
		if ( \is_null( $site_plugins ) ) {
			$output->writeln( '<error>Failed to fetch site plugins.</error>' );
			return Command::FAILURE;
		}

		output_table(
			$output,
			\array_map(
				static fn( string $plugin, \stdClass $plugin_data ) => array(
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$plugin_data->Name,
					\dirname( $plugin ),
					$plugin_data->Version,
					$plugin_data->active ? 'Active' : 'Inactive',
					// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				),
				\array_keys( $site_plugins ),
				$site_plugins
			),
			array( 'Name', 'Slug', 'Version', 'Status' ),
			"Plugins installed on {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL})"
		);

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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to list the plugins for:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
