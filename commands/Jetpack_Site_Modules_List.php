<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Lists the status of Jetpack modules on a given site.
 */
#[AsCommand( name: 'jetpack:list-site-modules' )]
final class Jetpack_Site_Modules_List extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to fetch the information for.
	 *
	 * @var \stdClass|null
	 */
	protected ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists the status of Jetpack modules on a given site.' )
			->setHelp( 'Use this command to show a list of Jetpack modules on a given site together with their status. This command requires that the given site has an active Jetpack connection to WPCOM.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to fetch the information for.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing Jetpack modules information for {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$module_data = get_jetpack_site_modules( $this->site->ID );
		if ( \is_null( $module_data ) ) {
			return Command::FAILURE;
		}

		output_table(
			$output,
			array_map(
				static fn( $module ) => array( $module->module, ( $module->activated ? 'on' : 'off' ) ),
				$module_data
			),
			array( 'Module', 'Status' )
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to fetch the information for:</question> ' );
		$question->setAutocompleterValues( array_column( get_wpcom_jetpack_sites() ?? array(), 'domain' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
