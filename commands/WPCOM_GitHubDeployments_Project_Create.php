<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Creates a new project on GitHubDeployments at WordPress.com.
 */
#[AsCommand( name: 'wpcom:create-github-deployment' )]
final class WPCOM_GitHubDeployments_Project_Create extends Command {
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
	 * The target directory to deploy to.
	 *
	 * @var string|null
	 */
	private ?string $target_dir = null;

	/**
	 * If should deploy after connect.
	 *
	 * @var bool|null
	 */
	private ?bool $deploy = null;

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
			->addOption( 'target_dir', null, InputOption::VALUE_REQUIRED, 'The target directory to deploy to.' )
			->addOption( 'deploy', null, InputOption::VALUE_REQUIRED, 'Y or N for deploying the repository after the connection is complete.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->blog_id = intval( get_string_input( $input, $output, 'blog_id', fn() => $this->prompt_text( $input, $output, 'Please enter the blog_id for the server to deploy the project in:' ) ) );
		$input->setOption( 'blog_id', $this->blog_id );

		$this->gh_repository = maybe_get_github_repository_input( $input, $output, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setOption( 'repository', $this->gh_repository );

		$branch = maybe_get_string_input( $input, $output, 'branch', fn() => $this->prompt_text( $input, $output, 'Please enter the branch name to deploy the code from (trunk):' ) );

		$this->branch = $branch ?: 'trunk';
		$input->setOption( 'branch', $this->branch );

		$default_target_dir = '/wp-content/';
		$target_dir         = maybe_get_string_input( $input, $output, 'target_dir', fn() => $this->prompt_text( $input, $output, "Please enter the directory to deploy the code to ($default_target_dir):" ) );
		$this->target_dir   = $target_dir ?: $default_target_dir;
		$input->setOption( 'target_dir', $this->target_dir );

		$this->deploy = strtoupper( get_string_input( $input, $output, 'deploy', fn() => $this->prompt_text( $input, $output, 'Deploy code after connecting the repository to the server? [y/N]' ) ) ) === 'Y';
		$input->setOption( 'deploy', $this->deploy );
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

		$installation_id = get_wpcom_installation_for_repository( $this->gh_repository );

		if ( \is_null( $installation_id ) ) {
			$output->writeln( '<error>Failed to get the installation ID for the repository. Did you connect to your WordPress.com GitHub Deployments?</error>' );
			return Command::FAILURE;
		}

		$code_deployment = create_code_deployment(
			$this->blog_id,
			array(
				'external_repository_id' => $this->gh_repository->id,
				'branch_name'            => $this->branch,
				'installation_id'        => $installation_id,
				'target_dir'             => $this->target_dir,
			)
		);

		if ( \is_null( $code_deployment ) ) {
			$output->writeln( '<error>Failed to create the project.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Project `$code_deployment->repository_name` created successfully. Repository link: {$this->gh_repository->html_url}</>" );

		if ( $this->deploy ) {
			$output->writeln( "<fg=magenta;options=bold>Deploying $this->branch to $this->target_dir on blog_id $server.</>" );

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

			$output->writeln( "<fg=green;options=bold>Successfully deployed $this->branch to $this->target_dir on blog_id $server.</>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for some text and receive the input.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 * @param   string          $text   The text to prompt the user with.
	 *
	 * @return  string|null
	 */
	private function prompt_text( InputInterface $input, OutputInterface $output, string $text ): ?string {
		$question = new Question( "<question>$text</question> " );
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
