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
 * Creates a new server for a project on DeployHQ.
 */
#[AsCommand( name: 'deployhq:create-project-server' )]
final class DeployHQ_Project_Create_Server extends Command {
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
	 * The repository connected to the project.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $repository = null;

	/**
	 * The branch to deploy from.
	 *
	 * @var string|null
	 */
	private ?string $branch = null;

	/**
	 * The site to connect the server to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The SFTP owner for the server.
	 * Currently, we only support Pressable sites.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $sftp_owner = null;

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
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->project = get_deployhq_project_input( $input, $output, fn() => $this->prompt_project_input( $input, $output ) );
		$input->setArgument( 'project', $this->project->permalink );

		if ( \is_null( $this->project->repository ) ) {
			$output->writeln( '<error>The project does not have a repository connected to it.</error>' );
			exit( 1 );
		}

		$gh_repo_url = parse_github_remote_repository_url( $this->project->repository->url );
		if ( \is_null( $gh_repo_url ) ) {
			$output->writeln( '<error>Connected repository is not from GitHub. Aborting!</error>' );
			exit( 1 );
		}

		$this->repository = get_github_repository( $gh_repo_url->repo );
		if ( \is_null( $this->repository ) ) {
			$output->writeln( '<error>Connected repository is invalid. Aborting!</error>' );
			exit( 1 );
		}

		$this->branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->branch );

		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_pressable_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site->id );

		$this->sftp_owner = get_pressable_site_sftp_owner( $this->site->id );
		if ( \is_null( $this->sftp_owner ) ) {
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
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create a new DeployHQ server `$this->name` for the project `{$this->project->name}` (permalink {$this->project->permalink}) deploying to {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) from GitHub branch $this->branch of the repository {$this->repository->full_name}? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating new DeployHQ server `$this->name` for the project `{$this->project->name}` (permalink {$this->project->permalink}) deploying to {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) from GitHub branch $this->branch of the repository {$this->repository->full_name}.</>" );

		$branches = get_github_repository_branches( $this->repository->name );
		if ( ! \in_array( $this->branch, array_column( $branches, 'name' ), true ) ) {
			$output->writeln( "<info>Branch `$this->branch` does not exist in repository {$this->repository->full_name}. Creating...</info>" );

			$branch = create_github_repository_branch( $this->repository->name, $this->branch, 'trunk' );
			if ( \is_null( $branch ) ) {
				$output->writeln( "<error>Failed to create branch $this->branch in the repository. Aborting!</error>" );
				return Command::FAILURE;
			}

			$output->writeln( "<fg=green;options=bold>Branch $this->branch created successfully.</>" );
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
				'branch'             => $this->branch,
				'environment'        => 'trunk' === $this->branch ? 'production' : 'development',

				'hostname'           => \Pressable_Connection_Helper::SSH_HOST,
				'username'           => $this->sftp_owner->username,
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
				'input'      => $input,
				'project'    => $this->project,
				'repository' => $this->repository,
				'site'       => $this->site,
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
		$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->repository->name ) ?? array(), 'name' ) );

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
