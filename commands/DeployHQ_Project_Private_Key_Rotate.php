<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Rotates the private key in a DeployHQ project.
 */
#[AsCommand( name: 'deployhq:rotate-project-private-key' )]
final class DeployHQ_Project_Private_Key_Rotate extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple projects or just a single given one.
	 * Can be one of 'all' or a comma-separated list of project permalinks.
	 *
	 * @var string|null
	 */
	private ?string $multiple = null;

	/**
	 * The project(s) to rotate the private key for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $projects = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Rotates the private key in a DeployHQ project.' )
			->setHelp( 'Use this command to rotate the private key in one or more DeployHQ projects.' );

		$this->addArgument( 'project', InputArgument::OPTIONAL, 'The permalink of the project to rotate the private key for.' )
			->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether to process multiple projects. Accepted values are `all` or a comma-separated list of project permalinks.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->multiple = $input->getOption( 'multiple' );

		if ( null === $this->multiple ) {
			// If multiple is not set, treat it as a single project operation
			$project = get_deployhq_project_input( $input, fn() => $this->prompt_project_input( $input, $output ) );
			$input->setArgument( 'project', $project->permalink );
			$this->projects = array( $project );
		} elseif ( 'all' === $this->multiple ) {
			$this->projects = get_deployhq_projects();
		} else {
			$this->projects = $this->get_projects_from_multiple_input();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = match ( true ) {
			'all' === $this->multiple => new ConfirmationQuestion( '<question>Are you sure you want to rotate the private key for <fg=red;options=bold>ALL</> DeployHQ projects? [y/N]</question> ', false ),
			null !== $this->multiple => new ConfirmationQuestion( '<question>Are you sure you want to rotate the private key for <fg=red;options=bold>' . count( $this->projects ) . ' selected</> DeployHQ projects? [y/N]</question> ', false ),
			default => new ConfirmationQuestion( "<question>Are you sure you want to rotate the private key for the project `{$this->projects[0]->name}` (permalink {$this->projects[0]->permalink})? [y/N]</question> ", false ),
		};

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->projects as $project ) {
			$output->writeln( "<fg=magenta;options=bold>Rotating the private key for `$project->name` (permalink $project->permalink).</>" );

			$response = rotate_deployhq_project_private_key( $project->permalink );
			if ( is_null( $response ) ) {
				$output->writeln( "<error>Failed to rotate the private key for `$project->name` (permalink $project->permalink).</error>" );
				continue;
			}

			$output->writeln( "<fg=green;options=bold>Rotated the private key for `$project->name` (permalink $project->permalink) successfully.</>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to input a project.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_project_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the slug of the project to rotate the private key on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_deployhq_projects() ?? array(), 'permalink' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get projects from the multiple input option.
	 *
	 * @return array
	 */
	private function get_projects_from_multiple_input(): array {
		$project_permalinks = array_map( 'trim', explode( ',', $this->multiple ) );
		return array_filter( array_map( fn( $permalink ) => get_deployhq_project( $permalink ), $project_permalinks ) );
	}

	// endregion
}
