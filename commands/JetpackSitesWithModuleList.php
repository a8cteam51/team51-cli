<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Lists the connected Jetpack sites with a given module either enabled or disabled.
 */
#[AsCommand( name: 'jetpack:list-sites-with-module' )]
final class JetpackSitesWithModuleList extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The module to search the status for.
	 *
	 * @var string|null
	 */
	protected ?string $module = null;

	/**
	 * The status to search for.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'List all connected sites where a given Jetpack module is in a given status.' )
			->setHelp( 'Use this command to find which sites have a given Jetpack module in a given status. Only sites with an active Jetpack connection to WPCOM are searched through.' );

		$this->addArgument( 'module', InputArgument::REQUIRED, 'The module to check the status of.' )
			->addArgument( 'status', InputArgument::OPTIONAL, 'The status to check for. Must be one of \'on\' or \'off\'. By default, \'on\'.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->module = get_enum_input( $input, $output, 'module', array_keys( get_jetpack_modules() ?? array() ), fn() => $this->prompt_module_input( $input, $output ) );
		$input->setArgument( 'module', $this->module );

		$this->status = get_enum_input( $input, $output, 'status', array( 'on', 'off' ), fn() => $this->prompt_status_input( $input, $output ), 'on' );
		$input->setArgument( 'status', $this->status );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing connected sites where the status of the Jetpack module $this->module is $this->status.</>" );

		$sites = get_wpcom_jetpack_sites();
		if ( empty( $sites ) ) {
			$output->writeln( '<error>Failed to fetch sites.<error>' );
			return Command::FAILURE;
		}

		$sites_count = count( $sites );
		$output->writeln( "<info>$sites_count sites found.<info>" );
		$output->writeln( "<info>Checking each site for the Jetpack module: $this->module<info>" );
		$output->writeln( '<info>Expected duration: 20 - 30 minutes. Use Ctrl+C to abort.</info>' );

		$progress_bar = new ProgressBar( $output, $sites_count );
		$progress_bar->start();

		$sites_not_checked = array();
		$sites_found       = array();
		foreach ( $sites as $site ) {
			/* @noinspection DisconnectedForeachInstructionInspection */
			$progress_bar->advance();

			$site_modules = get_jetpack_site_modules( $site->userblog_id );
			if ( ! is_null( $site_modules ) ) {
				if ( 'on' === $this->status && $site_modules[ $this->module ]->activated ) {
					$sites_found[] = $site;
				} elseif ( 'off' === $this->status && ! $site_modules[ $this->module ]->activated ) {
					$sites_found[] = $site;
				}
			} else {
				$sites_not_checked[] = $site;
			}
		}

		$progress_bar->finish();

		output_table(
			$output,
			array_map(
				static fn( \stdClass $site ) => array( $site->userblog_id, $site->domain ),
				$sites_found
			),
			array( 'Site ID', 'Site URL' ),
			"Sites with the Jetpack module '$this->module' turned $this->status"
		);
		output_table(
			$output,
			array_map(
				static fn( \stdClass $site ) => array( $site->userblog_id, $site->domain ),
				$sites_not_checked
			),
			array( 'Site ID', 'Site URL' ),
			'Sites not checked either due to an error or due to the Jetpack API module being turned off'
		);

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a module.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_module_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the module to check the status of:</question> ' );
		$question->setAutocompleterValues( array_keys( get_jetpack_modules() ?? array() ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a status.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_status_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the status to check the module for [on]:</question> ' );
		$question->setAutocompleterValues( array( 'on', 'off' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
