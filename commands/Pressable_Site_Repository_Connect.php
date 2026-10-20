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
 * Connects a Pressable site to a GitHub repository through Pressable's GitHub integration.
 */
#[AsCommand( name: 'pressable:connect-site-repository' )]
final class Pressable_Site_Repository_Connect extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The Pressable site to connect.
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
	 * The branch to deploy.
	 *
	 * @var string|null
	 */
	private ?string $gh_repo_branch = null;

	/**
	 * The path under the site's document root to deploy into.
	 *
	 * @var string|null
	 */
	private ?string $destination_path = null;

	/**
	 * The directory within the repository to deploy from, or an empty string for its root.
	 *
	 * @var string|null
	 */
	private ?string $repository_subdirectory = null;

	/**
	 * Whether to return once the connection is made rather than wait for the first deploy.
	 *
	 * @var bool|null
	 */
	private ?bool $no_wait = null;

	/**
	 * How long to look for a deploy Pressable did not confirm before concluding it queued none, in seconds.
	 *
	 * @var int
	 */
	private const UNCONFIRMED_DEPLOY_TIMEOUT = 60;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Connects a Pressable site to a GitHub repository through Pressable\'s GitHub integration.' )
			->setHelp( 'Use this command to connect a Pressable site to a GitHub repository. OpsOasis stores the GitHub deploy token on the Pressable account. The command resets any path Pressable kept from before, queues a deploy when the connection did not, and waits for the deploy to finish unless --no-wait is given.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site to connect.' )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the GitHub repository to connect.' );

		$this->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy.' )
			->addOption( 'destination-path', null, InputOption::VALUE_REQUIRED, 'The path under the site\'s document root to deploy into. Pass an empty value for the document root itself.', 'wp-content' )
			->addOption( 'repository-subdirectory', null, InputOption::VALUE_REQUIRED, 'The directory within the repository to deploy from. Defaults to the repository root, whatever the site had before.' )
			->addOption( 'no-wait', null, InputOption::VALUE_NONE, 'Return once the first deploy has started instead of waiting for it to finish.' );
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

		$branches = get_github_repository_branches( $this->gh_repository->name );
		if ( \is_array( $branches ) && ! \in_array( $this->gh_repo_branch, \array_column( $branches, 'name' ), true ) ) {
			$output->writeln( "<error>The repository {$this->gh_repository->full_name} has no branch `$this->gh_repo_branch`. Aborting!</error>" );
			exit( 1 );
		}

		$this->destination_path        = (string) $input->getOption( 'destination-path' );
		$this->repository_subdirectory = (string) $input->getOption( 'repository-subdirectory' );
		$this->no_wait                 = get_bool_input( $input, 'no-wait' );

		$output->writeln( "<comment>Pressable deletes files on the site that are not in the repository, except what the repository's .deployignore protects. Make sure the branch has one, and that no DeployHQ server still deploys to this site, before connecting.</comment>" );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to connect {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) to the branch `$this->gh_repo_branch` of {$this->gh_repository->full_name}, deploying " . self::describe_paths( $this->get_requested_paths() ) . '? [y/N]</question> ', false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Connecting {$this->site->displayName} (ID {$this->site->id}) to the branch `$this->gh_repo_branch` of {$this->gh_repository->full_name}.</>" );

		$previous_id = $this->get_newest_deploy_id();
		if ( \is_null( $previous_id ) ) {
			$output->writeln( '<comment>Failed to read the deploy history, so a deploy will be queued after connecting.</comment>' );
		}

		$requested = $this->get_requested_paths();
		$clone_url = $this->gh_repository->clone_url ?? "https://github.com/{$this->gh_repository->full_name}.git";
		$git       = connect_pressable_site_repository( $this->site->id, $clone_url, $this->gh_repo_branch, $requested['destination_path'], $requested['repository_subdirectory'] );
		if ( \is_null( $git ) ) {
			$output->writeln( '<error>Failed to connect the site to the repository.</error>' );
			return Command::FAILURE;
		}

		$corrected = self::get_configured_paths( $git ) !== $requested;
		if ( $corrected ) {
			$output->writeln( '<comment>Pressable kept the site deploying ' . self::describe_paths( self::get_configured_paths( $git ) ) . '. Correcting the paths...</comment>' );

			$kept_paths_warning = "The site stays connected with these paths and Pressable may deploy them, so fix them in MyPressable and check the deploys with `team51 pressable:list-site-git-deploys {$this->site->id}`.";

			$git = \is_null( update_pressable_site_git_config( $this->site->id, $requested ) ) ? null : get_pressable_site_git_config( $this->site->id );
			if ( \is_null( $git ) ) {
				$output->writeln( "<error>Failed to correct the paths. $kept_paths_warning</error>" );
				return Command::FAILURE;
			}
			if ( self::get_configured_paths( $git ) !== $requested ) {
				$output->writeln( '<error>Pressable still deploys ' . self::describe_paths( self::get_configured_paths( $git ) ) . ". $kept_paths_warning</error>" );
				return Command::FAILURE;
			}
		}

		$output->writeln( '<fg=green;options=bold>Connected. The site deploys ' . self::describe_paths( self::get_configured_paths( $git ) ) . '.</>' );

		$deploy = null;
		if ( ! $corrected && ! \is_null( $previous_id ) ) {
			$output->writeln( '<comment>Looking for the deploy the connection queued...</comment>' );
			$deploy = $this->wait_for_deploy( $output, null, $previous_id );
		}

		if ( \is_null( $deploy ) ) {
			$newest_id = $this->get_newest_deploy_id();
			$late_id   = \is_null( $previous_id ) || \is_null( $newest_id ) || $newest_id === $previous_id ? null : $newest_id;
			if ( ! \is_null( $late_id ) && ! $corrected ) {
				$deploy_id = $late_id;
			} else {
				if ( ! \is_null( $late_id ) ) {
					$output->writeln( "<comment>Deploy $late_id started before the paths were corrected, so it may use the paths Pressable kept.</comment>" );
				} elseif ( ! $corrected && ! \is_null( $previous_id ) ) {
					$output->writeln( '<comment>No deploy appeared after connecting.</comment>' );
				}

				$previous_id = $newest_id ?? $previous_id;
				$deploy_id   = $this->queue_deploy( $output );
			}

			if ( \is_null( $deploy_id ) && \is_null( $previous_id ) ) {
				$output->writeln( "<error>The deploy cannot be told apart from earlier ones, because the deploy history could not be read. Check it with `team51 pressable:list-site-git-deploys {$this->site->id}`.</error>" );
				return Command::FAILURE;
			}
			if ( \is_null( $deploy_id ) && $corrected && \is_null( $late_id ) ) {
				$output->writeln( "<error>The deploy cannot be told apart from the one the connection queued, which may use the paths Pressable kept. Check the deploys with `team51 pressable:list-site-git-deploys {$this->site->id}`.</error>" );
				return Command::FAILURE;
			}
			if ( $this->no_wait && ! \is_null( $deploy_id ) ) {
				$output->writeln( "<comment>Deploy $deploy_id is queued. Follow it with `team51 pressable:list-site-git-deploys {$this->site->id}`.</comment>" );
				return Command::SUCCESS;
			}

			$output->writeln( $this->no_wait ? '<comment>Looking for the deploy...</comment>' : '<comment>Waiting for the deploy to finish...</comment>' );
			$deploy = $this->wait_for_deploy( $output, $deploy_id, $previous_id );
		}

		return $this->report_deploy( $output, $deploy );
	}

	// endregion

	// region HELPERS

	/**
	 * Queues a deploy of the connected branch.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null The deploy's ID, or null when OpsOasis did not confirm the deploy.
	 */
	private function queue_deploy( OutputInterface $output ): ?string {
		$output->writeln( "<comment>Queueing a deploy of `$this->gh_repo_branch`...</comment>" );

		$deploy_id = trigger_pressable_site_git_deploy( $this->site->id )?->deploy_id ?? null;
		if ( \is_null( $deploy_id ) ) {
			$output->writeln( '<comment>OpsOasis did not confirm the deploy, but Pressable may have queued it anyway.</comment>' );
			return null;
		}

		return (string) $deploy_id;
	}

	/**
	 * Waits for a deploy to finish, or with `--no-wait`, only for it to appear.
	 *
	 * @param   OutputInterface $output      The output object.
	 * @param   string|null     $deploy_id   The deploy's ID, or null to follow the newest deploy.
	 * @param   string|null     $previous_id The newest deploy's ID before the one to wait for was queued.
	 *
	 * @return  \stdClass|null The deploy, as the history last listed it.
	 */
	private function wait_for_deploy( OutputInterface $output, ?string $deploy_id, ?string $previous_id ): ?\stdClass {
		return wait_for_pressable_site_git_deploy( $this->site->id, $output, $deploy_id, $previous_id, $this->no_wait ? 0 : 600, self::UNCONFIRMED_DEPLOY_TIMEOUT );
	}

	/**
	 * Reports how the deploy ended.
	 *
	 * @param   OutputInterface $output The output object.
	 * @param   \stdClass|null  $deploy The deploy, as the history last listed it.
	 *
	 * @return  int The command's exit code.
	 */
	private function report_deploy( OutputInterface $output, ?\stdClass $deploy ): int {
		$state = (string) ( $deploy->state ?? '' );
		if ( 'deployed' === $state ) {
			$output->writeln( "<fg=green;options=bold>Deployed `$this->gh_repo_branch` to {$this->site->url}.</>" );
			return Command::SUCCESS;
		}
		if ( $this->no_wait && ! \is_null( $deploy ) && 'failed' !== $state ) {
			$output->writeln( "<comment>Follow the deploy with `team51 pressable:list-site-git-deploys {$this->site->id}`.</comment>" );
			return Command::SUCCESS;
		}

		$output->writeln(
			match ( true ) {
				\is_null( $deploy ) => "<error>No deploy appeared in the site's history. Check it with `team51 pressable:list-site-git-deploys {$this->site->id}`.</error>",
				'failed' === $state => '<error>The deploy failed. Pressable\'s history gives no reason, so check the site in MyPressable.</error>',
				default             => "<error>The deploy did not finish within ten minutes. Check it with `team51 pressable:list-site-git-deploys {$this->site->id}`.</error>",
			}
		);
		return Command::FAILURE;
	}

	/**
	 * Returns the ID of the site's newest deploy.
	 *
	 * @return  string|null The ID, an empty string when the site has no deploys, or null when the history could not be read.
	 */
	private function get_newest_deploy_id(): ?string {
		$deploys = get_pressable_site_git_deploys( $this->site->id, 1 );
		return \is_null( $deploys ) ? null : (string) ( $deploys[0]->id ?? '' );
	}

	/**
	 * Returns the paths the site was asked to deploy from and into, keyed as Pressable's API names them.
	 *
	 * @return  array{destination_path: string, repository_subdirectory: string}
	 */
	private function get_requested_paths(): array {
		return array(
			'destination_path'        => self::normalize_path( $this->destination_path ),
			'repository_subdirectory' => self::normalize_path( $this->repository_subdirectory ),
		);
	}

	/**
	 * Returns the paths a site's git configuration deploys from and into, keyed as Pressable's API names them.
	 *
	 * @param   \stdClass $git The site's git configuration.
	 *
	 * @return  array{destination_path: string, repository_subdirectory: string}
	 */
	private static function get_configured_paths( \stdClass $git ): array {
		return array(
			'destination_path'        => self::normalize_path( $git->destinationPath ?? null ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'repository_subdirectory' => self::normalize_path( $git->repositorySubdirectory ?? null ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
	}

	/**
	 * Describes which directory of the repository is deployed into which path of the site.
	 *
	 * @param   array{destination_path: string, repository_subdirectory: string} $paths The paths.
	 *
	 * @return  string
	 */
	private static function describe_paths( array $paths ): string {
		$from = '' === $paths['repository_subdirectory'] ? 'the repository root' : "`{$paths['repository_subdirectory']}`";
		$into = '' === $paths['destination_path'] ? 'the document root' : "`{$paths['destination_path']}`";

		return "$from into $into";
	}

	/**
	 * Strips the slashes around a path, so that an empty string means the root.
	 *
	 * @param   string|null $path The path.
	 *
	 * @return  string
	 */
	private static function normalize_path( ?string $path ): string {
		return \trim( (string) $path, '/' );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or Pressable site ID to connect:</question> ' );
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
		$question = new Question( '<question>Enter the slug of the GitHub repository to connect:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( get_github_repository_autocomplete_values() );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a branch.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_branch_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the branch to deploy [trunk]:</question> ', 'trunk' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_github_repository_branches( $this->gh_repository->name ) ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
