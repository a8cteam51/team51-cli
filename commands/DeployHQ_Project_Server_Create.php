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
 * Creates a new server for a project on DeployHQ.
 */
#[AsCommand( name: 'deployhq:create-project-server' )]
final class DeployHQ_Project_Server_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The project to create the server for.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $project = null;

	/**
	 * The name of the server to create.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The GitHub repository connected to the project.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The branch to deploy from.
	 *
	 * @var string|null
	 */
	private ?string $gh_repo_branch = null;

	/**
	 * The site to connect the server to.
	 * Currently, we only support Pressable sites.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $pressable_site = null;

	/**
	 * The SFTP owner for the server.
	 * Currently, we only support Pressable sites.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $pressable_site_sftp_owner = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new server for a project on DeployHQ.' )
			->setHelp( 'Use this command to create a new server for a project on DeployHQ.' );

		$this->addArgument( 'project', InputArgument::REQUIRED, 'The permalink of the project to create the server for.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site to connect the server to.' )
			->addArgument( 'name', InputArgument::REQUIRED, 'The name of the server to create.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from.' )
			->addOption( 'branch-source', null, InputOption::VALUE_REQUIRED, 'The existing branch to create the new one off of if it does not exist.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->project = get_deployhq_project_input( $input, $output, fn() => $this->prompt_project_input( $input, $output ) );
		$input->setArgument( 'project', $this->project );

		if ( \is_null( $this->project->repository ) ) {
			$output->writeln( '<error>The project does not have a repository connected to it.</error>' );
			exit( 1 );
		}

		$this->gh_repository = get_github_repository_from_deployhq_project( $this->project->permalink );
		if ( \is_null( $this->gh_repository ) ) {
			$output->writeln( '<error>Failed to get the GitHub repository connected to the project or invalid connected repository. Aborting!</error>' );
			exit( 1 );
		}

		$this->gh_repo_branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->gh_repo_branch );

		$this->pressable_site = get_pressable_site_input( $input, $output, fn() => $this->prompt_pressable_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->pressable_site );

		$this->pressable_site_sftp_owner = get_pressable_site_sftp_owner( $this->pressable_site->id );
		if ( \is_null( $this->pressable_site_sftp_owner ) ) {
			$output->writeln( '<error>Could not find the SFTP owner for the site. Aborting!</error>' );
			exit( 1 );
		}

		$this->name = dashify( get_string_input( $input, $output, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create a new DeployHQ server `$this->name` for the project `{$this->project->name}` (permalink {$this->project->permalink}) deploying to {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) from GitHub branch $this->gh_repo_branch of the repository {$this->gh_repository->full_name}? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating new DeployHQ server `$this->name` for the project `{$this->project->name}` (permalink {$this->project->permalink}) deploying to {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url}) from GitHub branch $this->gh_repo_branch of the repository {$this->gh_repository->full_name}.</>" );

		$branches = get_github_repository_branches( $this->gh_repository->name )?->records ?? array();
		if ( ! \in_array( $this->gh_repo_branch, array_column( $branches, 'name' ), true ) ) {
			$output->writeln( "<comment>Branch `$this->gh_repo_branch` does not exist in repository {$this->gh_repository->full_name}. Creating...</comment>" );

			$branch_source = get_enum_input(
				$input,
				$output,
				'branch-source',
				array_column( get_github_repository_branches( $this->gh_repository->name )?->records ?? array(), 'name' ),
				fn() => $this->prompt_branch_source_input( $input, $output ),
				'trunk'
			);
			$output->writeln( "<comment>Creating branch $this->gh_repo_branch off of $branch_source...</comment>" );

			$branch = create_github_repository_branch( $this->gh_repository->name, $this->gh_repo_branch, $branch_source );
			if ( \is_null( $branch ) ) {
				$output->writeln( "<error>Failed to create branch $this->gh_repo_branch in the repository. Aborting!</error>" );
				return Command::FAILURE;
			}

			$output->writeln( "<fg=green;options=bold>Branch $this->gh_repo_branch created successfully.</>" );
		}

		$server = create_deployhq_project_server(
			$this->project->permalink,
			$this->name,
			array(
				'protocol_type'      => 'ssh',
				'server_path'        => 'wp-content',
				'email_notify_on'    => 'never',
				'root_path'          => '',
				'auto_deploy'        => true,
				'notification_email' => '',
				'branch'             => $this->gh_repo_branch,
				'environment'        => 'trunk' === $this->gh_repo_branch ? 'production' : 'development',

				'hostname'           => \Pressable_Connection_Helper::SSH_HOST,
				'username'           => $this->pressable_site_sftp_owner->username,
				'port'               => 22,
				'use_ssh_keys'       => true,
			)
		);
		if ( \is_null( $server ) ) {
			$output->writeln( '<error>Failed to create the server.</error>' );
			return Command::FAILURE;
		}

		dispatch_event(
			'deployhq.project.server.created',
			$server,
			array(
				'project'    => $this->project,
				'repository' => $this->gh_repository,
				'site'       => $this->pressable_site,
			)
		);
		$output->writeln( "<fg=green;options=bold>Server $this->name created successfully.</>" );

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
	 * @return  string|null
	 */
	private function prompt_project_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the slug of the project to create the server for:</question> ' );
		$question->setAutocompleterValues( array_column( get_deployhq_projects() ?? array(), 'permalink' ) );

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
		$question = new Question( '<question>Enter the branch to deploy from:</question> ' );
		$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository->name )?->records ?? array(), 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a branch source.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_branch_source_input( InputInterface $input, OutputInterface $output ): ?string {
		$existing_branches = array_column( get_github_repository_branches( $this->gh_repository->name )?->records ?? array(), 'name' );

		$question = new Question( '<question>Enter the branch to create the new one off of [trunk]:</question> ', 'trunk' );
		$question->setAutocompleterValues( array_column( $existing_branches, 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a Pressable site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_pressable_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or numeric Pressable ID of the site to connect the server to:</question> ' );
		$question->setAutocompleterValues( array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a server name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the server to create:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
