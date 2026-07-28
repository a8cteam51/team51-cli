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
 * Creates a new production site on Pressable.
 */
#[AsCommand( name: 'pressable:create-site', aliases: array( 'pressable:create-production-site' ) )]
final class Pressable_Site_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The name of the site to create.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The datacenter to create the site in.
	 *
	 * @var string|null
	 */
	private ?string $datacenter = null;

	/**
	 * The project template to use for the site.
	 *
	 * @var string
	 */
	private string $project_template = 'project';

	/**
	 * The no-code theme to use for the site.
	 *
	 * @var string|null
	 */
	private ?string $no_code_theme = null;

	/**
	 * The list of available themes.
	 *
	 * @var array|null
	 */
	private ?array $themes = null;

	/**
	 * The GitHub repository to deploy to the site from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new production site on Pressable.' )
			->setHelp( 'Use this command to create a new production site on Pressable.' );

		$this->addArgument( 'name', InputArgument::REQUIRED, 'The name of the site to create. Probably the same as the project name.' )
			->addOption( 'datacenter', null, InputArgument::OPTIONAL, 'The datacenter to create the site in. Defaults to `Dallas, Texas`.' )
			->addOption( 'project-template', null, InputArgument::OPTIONAL, 'The project template to use for the site. Either `project` or `no-code-project`. Defaults to `project`.' )
			->addOption( 'no-code-theme', null, InputOption::VALUE_OPTIONAL, 'The name of the no-code theme to use for the repository if using the `no-code-project` template.' )
			->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'The GitHub repository to deploy to the site from.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->datacenter = get_enum_input( $input, 'datacenter', array_keys( get_pressable_datacenters() ), fn() => $this->prompt_datacenter_input( $input, $output ), 'DFW' );
		$input->setOption( 'datacenter', $this->datacenter );

		$repository          = maybe_get_string_input( $input, 'repository', fn() => $this->prompt_repository_input( $input, $output ) );
		$this->gh_repository = $repository ? $this->create_or_get_repository( $input, $output, $repository ) : null;
		$input->setOption( 'repository', $this->gh_repository->name ?? null );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$template_text = 'project' === $this->project_template ? 'using the `project` template' : 'using the `no-code-project` template';
		$repo_query    = $this->gh_repository ? "and to connect it to the `{$this->gh_repository->full_name}` repository via DeployHQ {$template_text}" : 'without connecting it to a GitHub repository';
		$question      = new ConfirmationQuestion( "<question>Are you sure you want to create a new Pressable site named `$this->name` in the $this->datacenter datacenter $repo_query? [y/N]</question> ", false );
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
		$template_text = 'project' === $this->project_template ? 'using the `project` template' : 'using the `no-code-project` template';
		$repo_text     = $this->gh_repository ? "and connecting it to the `{$this->gh_repository->full_name}` repository via DeployHQ {$template_text}" : 'without connecting it to a GitHub repository';
		$output->writeln( "<fg=magenta;options=bold>Creating new Pressable site named `$this->name` in the $this->datacenter datacenter $repo_text.</>" );

		// Create the site and wait for it to be deployed.
		$site = create_pressable_site( "$this->name-production", $this->datacenter );
		if ( \is_null( $site ) ) {
			$output->writeln( '<error>Failed to create the site.</error>' );
			return Command::FAILURE;
		}

		$site = wait_on_pressable_site_state( $site->id, 'deploying', $output );
		if ( \is_null( $site ) ) {
			$output->writeln( '<error>Failed to check on site deployment status.</error>' );
			return Command::FAILURE;
		}

		// This wait used to block until the site answered, so everything below it could assume SSH worked. It
		// is bounded now, so the outcome has to be carried to the end: the setup steps that need SSH cannot
		// succeed without it, and the command must not report success as though they had.
		$ssh_connection = wait_on_pressable_site_ssh( $site->id, $output );
		$ssh_connection?->disconnect();

		// Run a few commands to set up the site.
		$rotate_status = run_app_command(
			Pressable_Site_WP_User_Password_Rotate::getDefaultName(),
			array(
				'site'   => $site->id,
				'--user' => 'concierge@wordpress.com',
			)
		);
		run_pressable_site_wp_cli_command(
			$site->id,
			'plugin install https://github.com/a8cteam51/a8csp-atlantis/releases/latest/download/a8csp-atlantis.zip --activate',
		);

		// Create a DeployHQ project and server for the site.
		if ( ! \is_null( $this->gh_repository ) ) {
			$deployhq_project = create_deployhq_project_for_pressable_site( $site, $this->gh_repository, $this->name );
			if ( ! \is_null( $deployhq_project ) ) {
				$deployhq_server = create_deployhq_project_server_for_pressable_site( $site, $deployhq_project, 'Production', 'trunk' );

				// Trigger initial deployment
				if ( ! \is_null( $deployhq_server ) ) {
					$output->writeln( '<fg=magenta;options=bold>Triggering initial deployment...</>' );

					$deployment_success = trigger_deployhq_deployment( $deployhq_project, $this->gh_repository, 'trunk' );
					if ( $deployment_success ) {
						$output->writeln( '<fg=green;options=bold>Initial deployment triggered successfully.</>' );
					} else {
						$output->writeln( '<error>Failed to trigger initial deployment. You may need to manually deploy from the DeployHQ dashboard.</error>' );
					}
				}
			}
		}

		// Printed before the reachability verdict: an unreachable site is exactly when the rotation is most
		// likely to have failed, and its pointer to a possibly-unrecorded password must not be skipped.
		if ( Command::SUCCESS !== $rotate_status ) {
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			$output->writeln( '<error>⚠  The WP user password rotation did not complete cleanly.</error>' );
			$output->writeln( '<error>    See the warning above for the password to record or the rotation to retry.</error>' );
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
		}

		if ( \is_null( $ssh_connection ) ) {
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			$output->writeln( "<error>⚠  Site $this->name (ID $site->id) was created but never became reachable over SSH.</error>" );
			$output->writeln( '<error>    The setup steps that need SSH did not run. Finish them manually.</error>' );
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Site $this->name created successfully.</>" );

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
		$question = new Question( '<question>Please enter the name of the site to create:</question> ' );
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

		$question = new ChoiceQuestion( '<question>Please select the datacenter to create the site in [' . $choices['DFW'] . ']:</question> ', get_pressable_datacenters(), 'DFW' );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, $choices ) );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a project template.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_project_template_input( InputInterface $input, OutputInterface $output ): ?string {
		$choices = array(
			'project'         => 'Project',
			'no-code-project' => 'No-Code Project',
		);

		$question = new ChoiceQuestion( '<question>Please select the project template to use for the site [project]:</question> ', $choices, 'project' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a no-code theme.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 * @param   array           $themes The list of available themes.
	 *
	 * @return  string|null
	 */
	private function prompt_no_code_theme_input( InputInterface $input, OutputInterface $output, array $themes ): ?string {
		return prompt_wporg_theme_input( $input, $output, $this->getHelper( 'question' ), $themes );
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
		$question = new ConfirmationQuestion( '<question>Would you like to deploy to the site from a GitHub repository? [Y/n]</question> ', true );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$question = new Question( "<question>Please enter the slug of the GitHub repository to deploy from [$this->name]:</question> ", $this->name );
			if ( ! $input->getOption( 'no-autocomplete' ) ) {
				$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );
			}

			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	/**
	 * Creates or gets a GitHub repository.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 * @param   string          $name   The name of the repository to create or get.
	 *
	 * @return  \stdClass|null
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function create_or_get_repository( InputInterface $input, OutputInterface $output, string $name ): ?\stdClass {
		$repository = get_github_repository( $name );

		if ( \is_null( $repository ) ) {
			$question = new ConfirmationQuestion( "<question>Could not find GitHub repository `$name`. Would you like to create it? [Y/n]</question> ", true );

			if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$php_globals_long_prefix = \str_replace( '-', '_', $name );
				if ( 2 <= \substr_count( $php_globals_long_prefix, '_' ) ) {
					$php_globals_short_prefix = '';
					foreach ( \explode( '_', $php_globals_long_prefix ) as $part ) {
						$php_globals_short_prefix .= $part[0];
					}
				} else {
					$php_globals_short_prefix = \explode( '_', $php_globals_long_prefix )[0];
				}

				$this->project_template = get_enum_input( $input, 'project-template', array( 'project', 'no-code-project' ), fn() => $this->prompt_project_template_input( $input, $output ), 'project' );
				$input->setOption( 'project-template', $this->project_template );

				if ( 'no-code-project' === $this->project_template ) {
					$this->setup_no_code_theme( $input, $output );
					$input->setOption( 'no-code-theme', $this->no_code_theme );
				}

				/* @noinspection PhpUnhandledExceptionInspection */
				$status = run_app_command(
					GitHub_Repository_Create::getDefaultName(),
					array(
						'name'                => $name,
						'--homepage'          => "https://$name-production.mystagingwebsite.com",
						'--type'              => $this->project_template,
						'--no-code-theme'     => $this->no_code_theme,
						'--custom-properties' => array(
							"php-globals-long-prefix=$php_globals_long_prefix",
							"php-globals-short-prefix=$php_globals_short_prefix",
						),
					),
				);
				if ( Command::SUCCESS !== $status ) {
					$output->writeln( '<error>Failed to create the repository.</error>' );
					exit( 1 );
				}

				$repository = get_github_repository( $name );
			}
		}

		return $repository;
	}

	/**
	 * Sets up the no-code theme by cloning/pulling the themes repo and prompting for theme selection.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @return void
	 */
	private function setup_no_code_theme( InputInterface $input, OutputInterface $output ): void {
		$output->writeln( '<fg=magenta;options=bold>Fetching WordPress.org themes...</>' );

		$this->themes = get_wporg_theme_choices();

		// Inject the "wpcom-theme" option
		$this->themes[] = (object) array(
			'slug' => 'wpcom-theme',
			'name' => 'WPCOM theme, other theme',
		);

		if ( empty( $this->themes ) ) {
			$output->writeln( '<error>Failed to fetch .org themes.</error>' );
			return;
		}

		if ( ! empty( $this->no_code_theme ) ) {
			if ( ! in_array( $this->no_code_theme, $this->themes, true ) ) {
				$output->writeln( '<error>The selected no-code theme is not available.</error>' );
				$output->writeln( '<error>Please select a different theme or press enter to skip.</error>' );
				$this->no_code_theme = null;
			} else {
				return;
			}
		}

		$this->no_code_theme = $this->prompt_no_code_theme_input( $input, $output, $this->themes );

		if ( 'wpcom-theme' === $this->no_code_theme ) {
			$question            = new Question( '<question>Please enter the slug of the WPCOM theme to use:</question> ' );
			$theme_slug          = $this->getHelper( 'question' )->ask( $input, $output, $question );
			$this->no_code_theme = $theme_slug;
		}
	}

	// endregion
}
