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
 * Creates a new staging site for a WPCOM site.
 */
#[AsCommand( name: 'wpcom:create-staging-site', aliases: array( 'wpcom:create-development-site' ) )]
final class WPCOM_Site_Staging_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The site to create the staging site for.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The GitHub repository name to deploy to the site.
	 *
	 * @var string|null
	 */
	private ?string $gh_repository_name = null;

	/**
	 * The GitHub branch to deploy to the site from.
	 *
	 * @var string|null
	 */
	private ?string $gh_repo_branch = null;

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
		$this->setDescription( 'Creates a new staging site for a WordPress.com site.' )
			->setHelp( 'Use this command to create a staging staging site for an existing WordPress.com site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The site for which to create the staging site.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy to the site from. Defaults to `develop`.' );

		$this->addOption( 'skip-safety-net', null, InputOption::VALUE_NONE, 'Skip the installation of SafetyNet as a mu-plugin.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_name_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$wpcom_gh_repositories = get_code_deployments( $this->site->ID );

		if ( empty( $wpcom_gh_repositories ) ) {
			$output->writeln( '<error>Unable to find a WPCOM GitHub Deployments for the site.</error>' );

			$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 1 );
			}
		}

		if ( 1 < count( $wpcom_gh_repositories ) ) {
			$output->writeln( '<comment>Found multiple WPCOM GitHub Deployments for the site.</comment>' );

			$question = new ChoiceQuestion(
				'<question>Choose from which repository you want to deploy to the staging site:</question> ',
				\array_column( $wpcom_gh_repositories, 'repository_name' ),
				0
			);
			$question->setErrorMessage( 'Repository %s is invalid.' );

			$this->gh_repository_name = $this->get_repository_slug_from_repository_name( $this->getHelper( 'question' )->ask( $input, $output, $question ) );
		} elseif ( 1 === count( $wpcom_gh_repositories ) ) {
			$this->gh_repository_name = $this->get_repository_slug_from_repository_name( $wpcom_gh_repositories[0]->repository_name );
		}

		if ( $this->gh_repository_name ) {
			$this->gh_repo_branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
			$input->setOption( 'branch', $this->gh_repo_branch );
		}

		$this->skip_safety_net = get_bool_input( $input, $output, 'skip-safety-net' );
		$input->setOption( 'skip-safety-net', $this->skip_safety_net );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$repo_query = $this->gh_repo_branch ? "and connect it to the branch `$this->gh_repo_branch` of {$this->gh_repository_name}" : 'without connecting it to a GitHub repository';
		$question   = new ConfirmationQuestion( "<question>Are you sure you want to create a staging site for the WordPress.com site {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}) $repo_query? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( $this->skip_safety_net ) {
			$question = new ConfirmationQuestion( '<question>Are you sure you want to <fg=red;options=bold>skip the installation of SafetyNet</>? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$repo_text = $this->gh_repo_branch ? "and connect it to the branch `$this->gh_repo_branch` of {$this->gh_repository_name}" : 'without connecting it to a GitHub repository';
		$output->writeln( "<fg=magenta;options=bold>Creating a staging site of the WordPress.com site {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}) $repo_text.</>" );

		// Create the site and wait for it to be deployed+cloned.
		$staging_site = create_wpcom_staging_site( $this->site->ID );
		if ( \is_null( $staging_site ) ) {
			$output->writeln( '<error>Failed to create the staging site. Aborting!</error>' );
			return Command::FAILURE;
		}

		if ( isset( $staging_site->error ) ) {
			$output->writeln( "<error>$staging_site->error</error>" );
			return Command::FAILURE;
		}

		$transfer = wait_until_wpcom_site_transfer_state( $staging_site->id, 'complete', $output );
		if ( \is_null( $transfer ) ) {
			$output->writeln( '<error>Failed to check on site transfer status.</error>' );
			return Command::FAILURE;
		}

		$ssh_connection = wait_on_wpcom_site_ssh( $transfer->blog_id, $output, true );

		$staging_site_name = "{$this->site->name}-staging";
		if ( str_contains( $this->site->name, 'production' ) ) {
			$staging_site_name = str_replace( 'production', 'staging', $this->site->name );
		}

		$update = update_wpcom_site( $transfer->blog_id, array( 'blogname' => $staging_site_name ) );

		if ( $update && isset( $update->updated->blogname ) && $staging_site_name === $update->updated->blogname ) {
			$output->writeln( "<fg=green;options=bold>Staging site $transfer->blog_id name successfully updated to $staging_site_name.</>" );
		} else {
			$output->writeln( '<error>Failed to set site name.</error>' );
		}

		// Run a few commands to set up the site.
		run_app_command(
			WPCOM_Site_WP_User_Password_Rotate::getDefaultName(),
			array(
				'site'   => $transfer->blog_id,
				'--user' => 'concierge@wordpress.com',
			)
		);

		run_wpcom_site_wp_cli_command( $staging_site->id, 'config set WP_ENVIRONMENT_TYPE development --type=constant' );
		run_wpcom_site_wp_cli_command( $staging_site->id, "search-replace {$this->site->URL} $staging_site->url" );
		run_wpcom_site_wp_cli_command( $staging_site->id, 'cache flush' );

		if ( $this->skip_safety_net ) {
			$output->writeln( '<comment>Skipping the installation of SafetyNet as a mu-plugin.</comment>' );
		} elseif ( \is_null( $ssh_connection ) ) {
			$output->writeln( '<error>Failed to connect to the site via SSH. Cannot install SafetyNet!</error>' );
		} else {
			// SafetyNet could already be installed if the site was cloned from a template that had it installed.
			$safety_net_installed = false;
			$ssh_connection->exec(
				'ls htdocs/wp-content/mu-plugins',
				function ( $stream ) use ( &$safety_net_installed, $output ) {
					if ( str_contains( $stream, 'safety-net' ) ) {
						$output->writeln( '<comment>SafetyNet is already installed as a mu-plugin. Skipping installation...</comment>' );
						$safety_net_installed = true;
					}
				}
			);

			if ( ! $safety_net_installed ) {
				run_wpcom_site_wp_cli_command( $staging_site->id, 'plugin install https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip' );
				$ssh_connection->exec( 'mv -f htdocs/wp-content/plugins/safety-net htdocs/wp-content/mu-plugins/safety-net' );
				$ssh_connection->exec(
					'ls htdocs/wp-content/mu-plugins',
					function ( $stream ) use ( $staging_site, $output ) {
						if ( ! str_contains( $stream, 'safety-net' ) ) {
							$output->writeln( '<error>Failed to install SafetyNet!</error>' );
						}
						if ( ! str_contains( $stream, 'load-safety-net.php' ) ) {
							$sftp = \WPCOM_Connection_Helper::get_sftp_connection( $staging_site->id, true );
							if ( \is_null( $sftp ) ) {
								$output->writeln( '<error>Failed to connect to the site via SFTP. Cannot copy SafetyNet loader!</error>' );
							} else {
								$result = $sftp->put( '/htdocs/wp-content/mu-plugins/load-safety-net.php', file_get_contents( __DIR__ . '/../scaffold/load-safety-net.php' ) );
								if ( ! $result ) {
									$output->writeln( '<error>Failed to copy the SafetyNet loader!</error>' );
								}
							}
						}
					}
				);
			}
		}

		$ssh_connection?->disconnect();

		if ( ! \is_null( $this->gh_repository_name ) ) {
			/* @noinspection PhpUnhandledExceptionInspection */
			$status = run_app_command(
				WPCOM_GitHubDeployments_Project_Create::getDefaultName(),
				array(
					'--blog_id'    => $transfer->blog_id,
					'--repository' => $this->gh_repository_name,
					'--branch'     => $this->gh_repo_branch,
					'--target_dir' => '/wp-content/',
					'--deploy'     => 'y',
				),
			);
			if ( Command::SUCCESS !== $status ) {
				$output->writeln( '<error>Failed to create the repository.</error>' );
				exit( 1 );
			}
		}

		$output->writeln( "<fg=green;options=bold>Staging site {$update->updated->blogname} created successfully $staging_site->url.</>" );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the site for which to create the staging site:</question> ' );
		$question->setAutocompleterValues( \array_column( get_wpcom_agency_sites() ?? array(), 'url' ) );

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
		$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository_name ) ?? array(), 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Gets the repository slug from the owner/repo-slug name.
	 *
	 * @param string $repository_name The repository name.
	 *
	 * @return string|null
	 */
	private function get_repository_slug_from_repository_name( string $repository_name ): ?string {
		$repository_parts = explode( '/', $repository_name );

		return $repository_parts[1] ?? null;
	}

	// endregion
}
