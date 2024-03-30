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
 * Creates a new project on DeployHQ.
 */
#[AsCommand( name: 'deployhq:create-project' )]
final class DeployHQ_Project_Create extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The name of the project to create.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The ID of the zone to create the project in.
	 *
	 * @var int|null
	 */
	private ?int $zone_id = null;

	/**
	 * The ID of the template to use for the project, if any.
	 *
	 * @var string|null
	 */
	private ?string $template_id = null;

	/**
	 * The GitHub repository to connect the project to, if any.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new project on DeployHQ.' )
			->setHelp( 'Use this command to create a new project on DeployHQ.' );

		$this->addArgument( 'name', InputArgument::REQUIRED, 'The name of the project to create.' )
			->addOption( 'zone-id', null, InputOption::VALUE_REQUIRED, 'The ID of the zone to create the project in. Defaults to `North America (East)`.' )
			->addOption( 'template-id', null, InputOption::VALUE_REQUIRED, 'The ID of the template to use for the project.', 'pressable-included-integration' )
			->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'The slug of the GitHub repository to connect the project to, if any.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, $output, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->zone_id = get_enum_input( $input, $output, 'zone-id', array( 3, 6, 9 ), fn() => $this->prompt_zone_input( $input, $output ), 6 );
		$input->setOption( 'zone-id', $this->zone_id );

		$this->template_id = get_string_input( $input, $output, 'template-id' );
		$input->setOption( 'template-id', $this->template_id );

		$this->gh_repository = maybe_get_github_repository_input( $input, $output, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setOption( 'repository', $this->gh_repository );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$zone_name = get_deployhq_zones()[ $this->zone_id ];
		$question  = new ConfirmationQuestion( "<question>Are you sure you want to create the DeployHQ project `$this->name` in $zone_name? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$zone_name = get_deployhq_zones()[ $this->zone_id ];
		$output->writeln( "<fg=magenta;options=bold>Creating DeployHQ project `$this->name` in $zone_name.</>" );

		$project = create_deployhq_project( $this->name, $this->zone_id, array_filter( array( 'template_id' => $this->template_id ) ) );
		if ( \is_null( $project ) ) {
			$output->writeln( '<error>Failed to create the project.</error>' );
			return Command::FAILURE;
		}

		dispatch_event( 'deployhq.project.created', $project );
		$output->writeln( "<fg=green;options=bold>Project `$project->name` created successfully. Permalink: $project->permalink</>" );

		if ( ! \is_null( $this->gh_repository ) ) {
			/* @noinspection PhpUnhandledExceptionInspection */
			run_app_command(
				DeployHQ_Project_Repository_Connect::getDefaultName(),
				array(
					'project'    => $project->permalink,
					'repository' => $this->gh_repository->name,
				),
				$input->isInteractive()
			);
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a project name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the project to create:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a zone ID.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  integer|null
	 */
	private function prompt_zone_input( InputInterface $input, OutputInterface $output ): ?int {
		$choices = get_deployhq_zones();
		$default = 6;

		$question = new ChoiceQuestion( '<question>Please select the zone to create the project in [' . $choices[ $default ] . ']:</question> ', $choices, $default );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, $choices ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a GitHub repository slug.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to connect the project to a GitHub repository? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new Question( '<question>Please enter the slug of the GitHub repository to connect the project to:</question> ' );
			$question->setAutocompleterValues( array_column( get_github_repositories()?->records ?? array(), 'name' ) );

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	// endregion
}
