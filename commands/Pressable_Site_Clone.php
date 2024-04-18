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
 * Creates a development clone of an existing Pressable site.
 */
#[AsCommand( name: 'pressable:clone-site', aliases: array( 'pressable:create-development-site' ) )]
final class Pressable_Site_Clone extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The site to clone.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The root name of the site to clone.
	 *
	 * @var string|null
	 */
	private ?string $site_root_name = null;

	/**
	 * The DeployHQ project for the given site.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $deployhq_project = null;

	/**
	 * The DeployHQ project server for the given site.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site_deployhq_project_server = null;

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
	private ?string $label = null;

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
			->addArgument( 'label', InputArgument::OPTIONAL, 'The suffix to append to the site name. Defaults to `development`.' )
			->addOption( 'datacenter', null, InputArgument::OPTIONAL, 'The datacenter to clone the site in. Defaults to the datacenter of the given site.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy to the site from. Defaults to `develop`.' );

		$this->addOption( 'skip-safety-net', null, InputOption::VALUE_NONE, 'Skip the installation of SafetyNet as a mu-plugin.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->site_root_name = get_pressable_site_root_name( $this->site->id );
		if ( \is_null( $this->site_root_name ) ) {
			$output->writeln( '<error>Failed to get the root name of the site. Aborting!</error>' );
			exit( 1 );
		}

		$deployhq_config = get_pressable_site_deployhq_config( $this->site->id );
		if ( \is_null( $deployhq_config ) ) {
			$output->writeln( '<error>Unable to find a DeployHQ project for the site.</error>' );

			$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 1 );
			}
		} else {
			$this->deployhq_project = $deployhq_config->project;
			$output->writeln( "<comment>Found DeployHQ project {$this->deployhq_project->name} (permalink {$this->deployhq_project->permalink}) for the given site.</comment>", OutputInterface::VERBOSITY_VERBOSE );

			$this->site_deployhq_project_server = $deployhq_config->server;
			if ( \is_null( $this->site_deployhq_project_server ) ) {
				$output->writeln( '<error>Failed to get the DeployHQ project server connected to the site. Aborting!</error>' );
				exit( 1 );
			}

			$this->gh_repository = get_github_repository_from_deployhq_project( $this->deployhq_project->permalink );
			if ( \is_null( $this->gh_repository ) ) {
				$output->writeln( '<error>Failed to get the GitHub repository connected to the project or invalid connected repository. Aborting!</error>' );
				exit( 1 );
			}

			$this->gh_repo_branch = get_string_input( $input, $output, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
			$input->setOption( 'branch', $this->gh_repo_branch );
		}

		$this->label = slugify( get_string_input( $input, $output, 'label', fn() => $this->prompt_label_input( $input, $output ) ) );
		$input->setArgument( 'label', $this->label );

		$this->datacenter = get_enum_input( $input, $output, 'datacenter', array_keys( get_pressable_datacenters() ), fn() => $this->prompt_datacenter_input( $input, $output ), $this->site->datacenterCode );
		$input->setOption( 'datacenter', $this->datacenter );

		$this->skip_safety_net = (bool) $input->getOption( 'skip-safety-net' );
		$input->setOption( 'skip-safety-net', $this->skip_safety_net );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$repo_query = $this->gh_repo_branch ? "and connect it to the branch `$this->gh_repo_branch` of {$this->gh_repository->full_name}" : 'without connecting it to a GitHub repository';
		$question   = new ConfirmationQuestion( "<question>Are you sure you want to create a development clone with the suffix `$this->label` of the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) in the $this->datacenter datacenter $repo_query? [y/N]</question> ", false );
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
		$repo_text = $this->gh_repo_branch ? "and connect it to the branch `$this->gh_repo_branch` of {$this->gh_repository->full_name}" : 'without connecting it to a GitHub repository';
		$output->writeln( "<fg=magenta;options=bold>Creating a development clone with the suffix `$this->label` of the Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) in the $this->datacenter datacenter $repo_text.</>" );

		// Create the site and wait for it to be deployed+cloned.
		$site_clone = create_pressable_site_clone( $this->site->id, "$this->site_root_name-$this->label", $this->datacenter );
		if ( \is_null( $site_clone ) ) {
			$output->writeln( '<error>Failed to clone the site. Aborting!</error>' );
			return Command::FAILURE;
		}

		$site_clone = wait_on_pressable_site_state( $site_clone->id, 'deploying', $output );
		if ( \is_null( $site_clone ) ) {
			$output->writeln( '<error>Failed to check on site deployment status.</error>' );
			return Command::FAILURE;
		}

		$site_clone = wait_on_pressable_site_state( $site_clone->id, 'cloning', $output );
		if ( \is_null( $site_clone ) ) {
			$output->writeln( '<error>Failed to check on site deployment status.</error>' );
			return Command::FAILURE;
		}

		$ssh_connection = wait_on_pressable_site_ssh( $site_clone->id, $output );

		// Run a few commands to set up the site.
		run_app_command(
			Pressable_Site_WP_User_Password_Rotate::getDefaultName(),
			array(
				'site'   => $site_clone->id,
				'--user' => 'concierge@wordpress.com',
			)
		);
		run_pressable_site_wp_cli_command( $site_clone->id, 'config set WP_ENVIRONMENT_TYPE development --type=constant' );
		run_pressable_site_wp_cli_command( $site_clone->id, "search-replace {$this->site->url} $site_clone->url" );
		run_pressable_site_wp_cli_command( $site_clone->id, 'cache flush' );

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
				run_pressable_site_wp_cli_command( $site_clone->id, 'plugin install https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip' );
				$ssh_connection->exec( 'mv -f htdocs/wp-content/plugins/safety-net htdocs/wp-content/mu-plugins/safety-net' );
				$ssh_connection->exec(
					'ls htdocs/wp-content/mu-plugins',
					function ( $stream ) use ( $site_clone, $output ) {
						if ( ! str_contains( $stream, 'safety-net' ) ) {
							$output->writeln( '<error>Failed to install SafetyNet!</error>' );
						}
						if ( ! str_contains( $stream, 'load-safety-net.php' ) ) {
							$sftp = \Pressable_Connection_Helper::get_sftp_connection( $site_clone->id );
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

		// Create a DeployHQ server for the site.
		if ( ! \is_null( $this->deployhq_project ) ) {
			create_deployhq_project_server_for_pressable_site(
				$site_clone,
				$this->deployhq_project,
				'Development' . ( 'development' !== $this->label ? "-$this->label" : '' ),
				$this->gh_repo_branch,
				$this->site_deployhq_project_server->branch,
			);
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to clone:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

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
		$question->setAutocompleterValues( array_column( get_github_repository_branches( $this->gh_repository->name )?->records ?? array(), 'name' ) );

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
	private function prompt_label_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the label to append to the site name [development]:</question> ', 'development' );
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
