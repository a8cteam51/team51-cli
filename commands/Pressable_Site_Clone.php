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
 * Creates a development clone of an existing Pressable site.
 */
#[AsCommand( name: 'pressable:clone-site', aliases: array( 'pressable:create-development-site' ) )]
final class Pressable_Site_Clone extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The site to clone.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The DeployHQ project for the main site.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $deployhq_project = null;

	/**
	 * The GitHub repository connected to the DeployHQ project.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The GitHub branch to deploy to the site from.
	 *
	 * @var string|null
	 */
	private ?string $gh_repo_branch = null;

	/**
	 * The suffix to append to the site name.
	 *
	 * @var string|null
	 */
	private ?string $suffix = null;

	/**
	 * The datacenter to create the site in.
	 *
	 * @var string|null
	 */
	private ?string $datacenter = null;



	/**
	 * Whether to skip the installation of SafetyNet as a mu-plugin.
	 *
	 * @var bool|null
	 */
	private ?bool $skip_safety_net = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a development clone of an existing Pressable site.' )
			->setHelp( 'Use this command to create a development clone of an existing Pressable site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The site to clone.' )
			->addArgument( 'suffix', InputArgument::OPTIONAL, 'The suffix to append to the site name.' )
			->addOption( 'datacenter', null, InputArgument::OPTIONAL, 'The datacenter to clone the site in.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy to the site from. Defaults to `develop`.' )
			->addOption( 'skip-safety-net', null, InputOption::VALUE_NONE, 'Skip the installation of SafetyNet as a mu-plugin.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->deployhq_project = get_deployhq_project_for_pressable_site( $this->site->id );
		if ( \is_null( $this->deployhq_project ) ) {
			$output->writeln( '<error>Unable to find a DeployHQ project for the site.</error>' );

			$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 1 );
			}
		} else {
			$output->writeln( "<info>Found DeployHQ project {$this->deployhq_project->name} (permalink {$this->deployhq_project->permalink}) for the site.</info>" );

			$this->gh_repository = get_github_repository_from_deployhq_project( $this->deployhq_project->permalink );
			if ( \is_null( $this->gh_repository ) ) {
				$output->writeln( '<error>Failed to get the GitHub repository connected to the project or invalid connected repository. Aborting!</error>' );
				exit( 1 );
			}

			$this->gh_repo_branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
			$input->setOption( 'branch', $this->gh_repo_branch );
		}

		/*
		$this->suffix = get_string_input( $input, $output, 'suffix', fn() => $this->prompt_suffix_input( $input, $output ) );
		$input->setArgument( 'suffix', $this->suffix );

		$this->datacenter = get_enum_input( $input, $output, 'datacenter', array_keys( get_pressable_datacenters() ), fn() => $this->prompt_datacenter_input( $input, $output ), $this->site->datacenterCode );
		$input->setOption( 'datacenter', $this->datacenter );

		$this->branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->branch );

		$this->skip_safety_net = (bool) $input->getOption( 'skip-safety-net' );
		*/
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create a development clone with the suffix $this->suffix of the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) in the $this->datacenter datacenter? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating a development clone with the suffix $this->suffix of the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) in the $this->datacenter datacenter.</>" );

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
		$question = new Question( '<question>Enter the domain or Pressable site ID to clone:</question> ' );
		$question->setAutocompleterValues( array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a branch name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_branch_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the branch to deploy from [develop]:</question> ', 'develop' );
		$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository->name ) ?? array(), 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}



	/**
	 * Prompts the user for a suffix.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_suffix_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the suffix to append to the site name [development]:</question> ', 'development' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a datacenter.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_datacenter_input( InputInterface $input, OutputInterface $output ): ?string {
		$choices = get_pressable_datacenters();

		$question = new ChoiceQuestion( '<question>Please select the datacenter to create the site in [' . $choices[ $this->site->datacenterCode ] . ']:</question> ', get_pressable_datacenters(), $this->site->datacenterCode );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, $choices ) );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
