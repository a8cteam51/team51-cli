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
			->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'The GitHub repository to deploy to the site from.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, $output, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->datacenter = get_enum_input( $input, $output, 'datacenter', array_keys( get_pressable_datacenters() ), fn() => $this->prompt_datacenter_input( $input, $output ), 'DFW' );
		$input->setOption( 'datacenter', $this->datacenter );

		$repository          = maybe_get_string_input( $input, $output, 'repository', fn() => $this->prompt_repository_input( $input, $output ) );
		$this->gh_repository = $repository ? $this->create_or_get_repository( $input, $output, $repository ) : null;
		$input->setOption( 'repository', $this->gh_repository->name ?? null );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$repo_query = $this->gh_repository ? "and to connect it to the `{$this->gh_repository->full_name}` repository via DeployHQ" : 'without connecting it to a GitHub repository';
		$question   = new ConfirmationQuestion( "<question>Are you sure you want to create a new Pressable site named `$this->name` in the $this->datacenter datacenter $repo_query? [y/N]</question> ", false );
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
		$repo_text = $this->gh_repository ? "and connecting it to the `{$this->gh_repository->full_name}` repository via DeployHQ" : 'without connecting it to a GitHub repository';
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

		wait_on_pressable_site_ssh( $site->id, $output )?->disconnect();

		// Run a few commands to set up the site.
		run_app_command(
			Pressable_Site_WP_User_Password_Rotate::getDefaultName(),
			array(
				'site'   => $site->id,
				'--user' => 'concierge@wordpress.com',
			)
		);
		run_pressable_site_wp_cli_command(
			$site->id,
			'plugin install https://github.com/a8cteam51/plugin-autoupdate-filter/releases/latest/download/plugin-autoupdate-filter.zip --activate',
		);

		// Create a DeployHQ project and server for the site.
		if ( ! \is_null( $this->gh_repository ) ) {
			$deployhq_project = create_deployhq_project_for_pressable_site( $site, $this->gh_repository, $this->name );
			if ( ! \is_null( $deployhq_project ) ) {
				create_deployhq_project_server_for_pressable_site( $site, $deployhq_project, 'Production', 'trunk' );
			}
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
			$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );

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

				/* @noinspection PhpUnhandledExceptionInspection */
				$status = run_app_command(
					GitHub_Repository_Create::getDefaultName(),
					array(
						'name'                => $name,
						'--homepage'          => "https://$name-production.mystagingwebsite.com",
						'--type'              => 'project',
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

	// endregion
}
