<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Rotates the private key in a DeployHQ project.
 */
#[AsCommand( name: 'deployhq:rotate-project-private-key' )]
final class DeployHQ_Project_Rotate_Private_Key extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether to process all projects or just a single given one.
	 *
	 * @var bool|null
	 */
	private ?bool $all = null;

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
			->setHelp( 'Use this command to rotate the private key in a DeployHQ project.' );

		$this->addArgument( 'project', InputArgument::OPTIONAL, 'The name of the project to rotate the private key for.' )
			->addOption( 'all', null, InputOption::VALUE_NONE, 'Whether to process all projects or just a single given one.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->all = (bool) $input->getOption( 'all' );

		if ( $this->all ) {
			$this->projects = get_deployhq_projects();
		} else {
			$this->projects = array( get_deployhq_project_input( $input, $output, fn() => $this->prompt_project_input( $input, $output ) ) );
			$input->setArgument( 'project', $this->projects[0] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = match ( $this->all ) {
			true => new ConfirmationQuestion( '<question>Are you sure you want to rotate the private key for <fg=red;options=bold>ALL</> projects? [y/N]</question> ', false ),
			false => new ConfirmationQuestion( "<question>Are you sure you want to rotate the private key for the project `{$this->projects[0]->name}` (permalink {$this->projects[0]->permalink})? [y/N]</question> ", false )
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
		$question->setAutocompleterValues( array_column( get_deployhq_projects() ?? array(), 'permalink' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
