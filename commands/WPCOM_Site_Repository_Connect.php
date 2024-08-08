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
 * Connects a WordPress.com site to a GitHub repository for deployments.
 */
#[AsCommand( name: 'wpcom:connect-site-repository' )]
final class WPCOM_Site_Repository_Connect extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to connect the repository to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

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
	private ?string $gh_repo_branch = null;

	/**
	 * The target directory to deploy to.
	 *
	 * @var string|null
	 */
	private ?string $wpcom_target_dir = null;

	/**
	 * If a deployment should be triggered after the connection is complete.
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
		$this->setDescription( 'Connects a WordPress.com site to a GitHub repository for deployments.' )
			->setHelp( 'Use this command to connect a WordPress.com site to a GitHub repository for deployments.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to connect the repository to.' )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the GitHub repository to connect.' );

		$this->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from.' )
			->addOption( 'target_dir', null, InputOption::VALUE_REQUIRED, 'The target directory to deploy to.' )
			->addOption( 'deploy', null, InputOption::VALUE_NONE, 'Trigger a deployment after the connection is complete.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->gh_repository = get_github_repository_input( $input, $output, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setArgument( 'repository', $this->gh_repository );

		$this->gh_repo_branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->gh_repo_branch );

		$this->wpcom_target_dir = get_string_input( $input, $output, 'target_dir', fn() => $this->prompt_target_dir_input( $input, $output ) );
		$input->setOption( 'target_dir', $this->wpcom_target_dir );

		$this->deploy = get_bool_input( $input, $output, 'deploy' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to connect the WPCOM site `{$this->site->name}` (ID {$this->site->ID}, URL {$this->site->URL}) to the GitHub repository `{$this->gh_repository->full_name}`? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Connecting the WPCOM site `{$this->site->name}` (ID {$this->site->ID}, URL {$this->site->URL}) to the GitHub repository `{$this->gh_repository->full_name}`.</>" );

		$code_deployment = create_wpcom_site_code_deployment( $this->site->ID, $this->gh_repository->id, $this->gh_repo_branch, $this->wpcom_target_dir );
		if ( \is_null( $code_deployment ) ) {
			$output->writeln( '<error>Failed to connect the site with the repository.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Site `{$this->site->name}` connected to repository `{$this->gh_repository->full_name}` successfully.</>" );

		if ( $this->deploy ) {
			$output->writeln( "<fg=magenta;options=bold>Deploying $this->gh_repo_branch to $this->wpcom_target_dir on `{$this->site->name}` (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

			$code_deployment_run = create_wpcom_site_code_deployment_run( $this->site->ID, $code_deployment->id );
			if ( \is_null( $code_deployment_run ) ) {
				$output->writeln( '<error>Failed to deploy the project.</error>' );
				return Command::FAILURE;
			}

			$code_deployment_run = wait_until_wpcom_code_deployment_run_state( $code_deployment, 'success', $output );
			if ( \is_null( $code_deployment_run ) ) {
				$output->writeln( '<error>Failed to check on project deployment status.</error>' );
				return Command::FAILURE;
			}

			$output->writeln( "<fg=green;options=bold>Successfully deployed $this->gh_repo_branch to $this->wpcom_target_dir on `{$this->site->name}` (ID {$this->site->ID}, URL {$this->site->URL}).</>" );
		}

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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to connect the repository to to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

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
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );
		}

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
		$question = new Question( '<question>Enter the branch to deploy from [trunk]:</question> ', 'trunk' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository->name ) ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a target directory.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_target_dir_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the target directory to deploy to [/wp-content/]:</question> ', '/wp-content/' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
