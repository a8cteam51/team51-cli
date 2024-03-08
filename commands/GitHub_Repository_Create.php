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

/**
 * Create a new GitHub repository, optionally from a template.
 *
 * WORK IN PROGRESS
 */
#[AsCommand( name: 'github:create-repository' )]
final class GitHub_Repository_Create extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The name of the repository to create.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The type of repository to create aka the name of the template repository to use.
	 *
	 * @var string|null
	 */
	private ?string $type = null;

	/**
	 * A short, human-friendly description for this project.
	 *
	 * @var string|null
	 */
	private ?string $description = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new GitHub repository on github.com in the organization specified by the GITHUB_API_OWNER constant.' )
			->setHelp( 'This command allows you to create a new Github repository.' );

		$this->addArgument( 'name', InputArgument::REQUIRED, 'The name of the repository to create.' )
			->addOption( 'description', null, InputOption::VALUE_REQUIRED, 'A short, human-friendly description for this project.' )
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'The name of the template repository to use, if any. One of either `project`, `plugin`, or `issues`. Default empty repo.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, $output, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->type = get_enum_input( $input, $output, 'type', array( 'project', 'plugin', 'issues' ), fn() => $this->prompt_type_input( $input, $output ) );
		$input->setOption( 'type', $this->type );

		$this->description = $input->getOption( 'description' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$type     = $this->type ?? 'empty';
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create the $type repository $this->name? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$type = $this->type ?? 'empty';
		$output->writeln( "<fg=magenta;options=bold>Creating the $type repository $this->name.</>" );

		// Create the repository.
		$repository = create_github_repository( $this->name, $this->type, $this->description );
		if ( \is_null( $repository ) ) {
			$output->writeln( '<error>Failed to create the repository.</error>' );
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a repository name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the repository to create:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a repository type.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_type_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the type of repository to create or press enter for an empty repo:</question> ' );
		$question->setAutocompleterValues( array( 'project', 'plugin', 'issues' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
