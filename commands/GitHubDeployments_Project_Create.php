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
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Creates a new project on GitHubDeployments at WordPress.com.
 */
#[AsCommand( name: 'wpcom:create-code-project' )]
final class GitHubDeployments_Project_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The ID of the blog to create the project in.
	 *
	 * @var int|null
	 */
	private ?int $blog_id = null;

	/**
	 * The GitHub repository to connect the project to, if any.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The branch to deploy from.
	 *
	 * @var string|null
	 */
	private ?string $branch = null;

	/**
	 * If should deploy after connect.
	 *
	 * @var bool|null
	 */
	private ?bool $deploy_after_connect = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new project on GitHub Deployments at WordPress.com.' )
			->setHelp( 'Use this command to create a new project on GitHub Deployments at WordPress.com.' );

		$this->addOption( 'blog_id', null, InputOption::VALUE_REQUIRED, 'The ID of the blog to create the project in.' )
			->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'The slug of the GitHub repository to connect the project to, if any.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from.' )
			->addOption( 'deploy_after_connect', null, InputOption::VALUE_REQUIRED, 'Y or N for deploying the repository after the connection is complete.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {

		$this->blog_id = get_string_input( $input, $output, 'blog_id', fn() => $this->prompt_server_id_input( $input, $output ) );
		$input->setOption( 'blog_id', $this->blog_id );

		$this->gh_repository = maybe_get_github_repository_input( $input, $output, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setOption( 'repository', $this->gh_repository );

		$this->branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->branch );

		$this->deploy_after_connect = strtoupper( get_string_input( $input, $output, 'deploy_after_connect', fn() => $this->prompt_deploy_after_connect( $input, $output ) ) ) === 'Y' ? true : false;
		$input->setOption( 'deploy_after_connect', $this->deploy_after_connect );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$server   = $this->blog_id;
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create a GitHub Deployments project on blog_id $server? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$server = $this->blog_id;
		$output->writeln( "<fg=magenta;options=bold>Creating GitHub Deployments project on blog_id $server.</>" );

		// TODO: remove after integrate with opsoasis server
		$wpcom_url = 'https://public-api.wordpress.com/';
		putenv( "TEAM51_OPSOASIS_BASE_URL=$wpcom_url" );

		$installation_id = get_wpcom_installation_for_repository( $this->gh_repository );

		if ( \is_null( $installation_id ) ) {
			$output->writeln( '<error>Failed to get the installation ID for the repository. Did you connected it to your WordPress.com GitHub Deployments?</error>' );
			return Command::FAILURE;
		}

		$code_deployment = create_code_deployment(
			$this->blog_id,
			array(
				'external_repository_id' => $this->gh_repository->id,
				'branch_name'            => $this->branch,
				'installation_id'        => $installation_id,
				// TODO: ask for a target_dir
				'target_dir'             => '/wp-content/plugins/' . $this->gh_repository->name,
			)
		);

		if ( \is_null( $code_deployment ) ) {
			$output->writeln( '<error>Failed to create the project.</error>' );
			return Command::FAILURE;
		}

		if ( $this->deploy_after_connect ) {
			$code_deployment_run = create_code_deployment_run( $this->blog_id, $code_deployment->id );
			if ( \is_null( $code_deployment_run ) ) {
				$output->writeln( '<error>Failed to deploy the project.</error>' );
				return Command::FAILURE;
			}

			$code_deployment_run = wait_until_wpcom_code_deployment_run_state( $code_deployment, 'success', $output );
			if ( \is_null( $code_deployment_run ) ) {
				$output->writeln( '<error>Failed to check on project deployment status.</error>' );
				return Command::FAILURE;
			}
		}

		putenv( 'TEAM51_OPSOASIS_BASE_URL=https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/' );

		$output->writeln( "<fg=green;options=bold>Project `$code_deployment->repository_name` created successfully. Repository link: {$this->gh_repository->html_url}</>" );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a branch name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_branch_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the branch name to deploy the code from:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a blog_id.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  integer|null
	 */
	private function prompt_server_id_input( InputInterface $input, OutputInterface $output ): ?int {
		$question = new Question( '<question>Please enter the blog_id for the server to deploy the project in:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for deploying after connect repository or not.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  integer|null
	 */
	private function prompt_deploy_after_connect( InputInterface $input, OutputInterface $output ): ?int {
		$question = new Question( '<question>Deploy code after connecting the repository to the server? [y/N]</question></question> ' );
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
		$question = new Question( '<question>Please enter the slug of the GitHub repository to connect the project to:</question> ' );
		$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
