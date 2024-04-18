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

/**
 * Lists the connected Jetpack sites with a given module either enabled or disabled.
 */
#[AsCommand( name: 'jetpack:module-search' )]
final class Jetpack_Module_Search extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The module to search the status for.
	 *
	 * @var string|null
	 */
	private ?string $module = null;

	/**
	 * The status to search for.
	 *
	 * @var string|null
	 */
	private ?string $status = null;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'List all connected sites where a given Jetpack module is in a given status.' )
			->setHelp( 'Use this command to find which sites have a given Jetpack module in a given status. Only sites with an active Jetpack connection to WPCOM are searched through.' );

		$this->addArgument( 'module', InputArgument::REQUIRED, 'The module to check the status of.' )
			->addOption( 'status', null, InputOption::VALUE_REQUIRED, 'The status to check for. Must be one of \'on\' or \'off\'. By default, \'on\'.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->module = get_enum_input( $input, $output, 'module', \array_keys( get_jetpack_modules() ?? array() ), fn() => $this->prompt_module_input( $input, $output ) );
		$input->setArgument( 'module', $this->module );

		$this->status = get_enum_input( $input, $output, 'status', array( 'on', 'off' ), fn() => $this->prompt_status_input( $input, $output ), 'on' );
		$input->setOption( 'status', $this->status );

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing connected sites where the status of the Jetpack module $this->module is $this->status.</>" );

		// If we try to retrieve the modules for all sites at once, we might hit an out-of-memory error.
		$sites_found  = array();
		$failed_sites = array();

		foreach ( array_chunk( $this->sites, 100, true ) as $sites_chunk ) {
			$modules = get_jetpack_site_modules_batch( \array_column( $sites_chunk, 'userblog_id' ), $errors );
			foreach ( $modules as $site_id => $site_modules ) {
				$module_data = $site_modules[ $this->module ] ?? null;
				if ( is_null( $module_data ) ) {
					continue; // Module not found. Maybe Jetpack version too old?
				}

				if ( 'on' === $this->status && $module_data->activated ) {
					$sites_found[] = $this->sites[ $site_id ];
				} elseif ( 'off' === $this->status && ! $module_data->activated ) {
					$sites_found[] = $this->sites[ $site_id ];
				}
			}

			$failed_sites[] = $errors;
		}
		$failed_sites = \array_replace( ...$failed_sites ); // Flatten the array while keeping the keys.

		// Output the results.
		maybe_output_wpcom_failed_sites_table( $output, $failed_sites, $this->sites, 'Sites that could NOT be searched' );
		output_table(
			$output,
			array_map(
				fn( \stdClass $site ) => array( $site->userblog_id, $site->domain, $this->status ),
				$sites_found
			),
			array( 'Site ID', 'Site URL', 'Status' ),
			"Sites with the Jetpack module '$this->module' turned $this->status"
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
