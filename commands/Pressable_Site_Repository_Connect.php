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
 * Connects a Pressable site to a GitHub repository for deployments via the Pressable Git API.
 */
#[AsCommand( name: 'pressable:connect-site-repository' )]
final class Pressable_Site_Repository_Connect extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to connect the repository to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The GitHub repository to connect the site to.
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
	 * The destination path on the site to deploy to, if any.
	 *
	 * @var string|null
	 */
	private ?string $destination_path = null;

	/**
	 * The repository subdirectory to deploy from, if any.
	 *
	 * @var string|null
	 */
	private ?string $repository_subdirectory = null;

	/**
	 * If a deployment should be triggered after the connection is complete. The connect call already
	 * triggers an initial deploy, so this is only useful to queue an additional deploy.
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
		$this->setDescription( 'Connects a Pressable site to a GitHub repository for deployments via the Pressable Git API.' )
			->setHelp( 'Use this command to connect a Pressable site to a GitHub repository for deployments. OpsOasis stores the GitHub access token on the site and the connection triggers an initial deploy.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or numeric Pressable ID of the site to connect the repository to.' )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the GitHub repository to connect.' );

		$this->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy from. Defaults to `trunk`.' )
			->addOption( 'destination-path', null, InputOption::VALUE_REQUIRED, 'The destination path on the site to deploy to. Defaults to `wp-content/`.', 'wp-content/' )
			->addOption( 'repository-subdirectory', null, InputOption::VALUE_REQUIRED, 'The repository subdirectory to deploy from. Defaults to the repository root.' )
			->addOption( 'deploy', null, InputOption::VALUE_NONE, 'Queue an additional deploy after the connection is complete (the connection already triggers an initial deploy).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->gh_repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setArgument( 'repository', $this->gh_repository );

		$this->gh_repo_branch = get_string_input( $input, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
		$input->setOption( 'branch', $this->gh_repo_branch );

		$this->destination_path        = maybe_get_string_input( $input, 'destination-path' );
		$this->repository_subdirectory = maybe_get_string_input( $input, 'repository-subdirectory' );

		$this->deploy = get_bool_input( $input, 'deploy' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to connect the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) to the branch `$this->gh_repo_branch` of the GitHub repository `{$this->gh_repository->full_name}`? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Connecting the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) to the branch `$this->gh_repo_branch` of the GitHub repository `{$this->gh_repository->full_name}` and triggering an initial deploy.</>" );

		$git_config = connect_pressable_site_repository_for_site( $this->site, $this->gh_repository, $this->gh_repo_branch, $this->destination_path, $this->repository_subdirectory );
		if ( \is_null( $git_config ) ) {
			$output->writeln( '<error>Failed to connect the site with the repository.</error>' );
			return Command::FAILURE;
		}

		output_table(
			$output,
			array(
				array(
					(string) $this->site->id,
					$this->gh_repository->full_name,
					$git_config->branch ?? $this->gh_repo_branch,
					! empty( $git_config->connected ) ? 'yes' : 'no',
					! empty( $git_config->hasToken ) ? 'yes' : 'no', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				),
			),
			array( 'Site ID', 'Repository', 'Branch', 'Connected', 'Has token' ),
			'Pressable Git configuration'
		);

		$output->writeln( "<fg=green;options=bold>Site {$this->site->displayName} connected to repository `{$this->gh_repository->full_name}` successfully.</>" );

		if ( $this->deploy ) {
			$output->writeln( "<fg=magenta;options=bold>Queueing an additional deploy on {$this->site->displayName} (ID {$this->site->id}).</>" );

			$deploy = trigger_pressable_site_git_deployment( $this->site->id );
			if ( \is_null( $deploy ) ) {
				$output->writeln( '<error>Failed to queue the deploy.</error>' );
				return Command::FAILURE;
			}

			$output->writeln( "<fg=green;options=bold>Deploy queued on {$this->site->displayName} (ID {$this->site->id}).</>" );
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to connect the repository to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
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
		$question = new Question( '<question>Please enter the slug of the GitHub repository to connect the site to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_github_repositories() ?? array(), 'name' ) );
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
			$question->setAutocompleterValues( \array_column( get_github_repository_branches( $this->gh_repository->name ) ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
