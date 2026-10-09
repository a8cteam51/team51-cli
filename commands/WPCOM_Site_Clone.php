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
#[AsCommand( name: 'wpcom:clone-site' )]
final class WPCOM_Site_Clone extends Command {
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

	/**
	 * Whether to keep the source site's users, orders and subscriptions on the staging site.
	 *
	 * @var bool|null
	 */
	private ?bool $keep_data = null;

	/**
	 * The YYYY-MM-DD date after which Safety Net deletes the kept data.
	 *
	 * @var string|null
	 */
	private ?string $keep_until = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new staging site for a WordPress.com site.' )
			->setHelp( 'Use this command to create a staging staging site for an existing WordPress.com site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The site for which to create the staging site.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch to deploy to the site from. Defaults to `develop`. Created off the repository default branch if it does not exist.' );

		$this->addOption( 'skip-safety-net', null, InputOption::VALUE_NONE, 'Skip the installation of SafetyNet as a mu-plugin.' )
			->addOption( 'keep-data', null, InputOption::VALUE_NONE, "Keep the source site's users, orders and subscriptions on the staging site (sets SAFETY_NET_DELETE_DATA to false). Safety Net still scrubs credentials, deactivates risky plugins, blocks emails and blocks logins of copied customer accounts." )
			->addOption( 'keep-until', null, InputOption::VALUE_REQUIRED, 'With --keep-data: delete the kept data on the first page load after this date, YYYY-MM-DD (sets SAFETY_NET_KEEP_UNTIL). Without it, the data is kept until SAFETY_NET_DELETE_DATA is removed.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws  \InvalidArgumentException If --keep-data or --keep-until is used in a combination that cannot work.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$wpcom_gh_repositories = get_wpcom_site_code_deployments( $this->site->ID );

		if ( empty( $wpcom_gh_repositories ) ) {
			$output->writeln( '<error>Unable to find a WPCOM GitHub Deployments for the site.</error>' );

			$question = new ConfirmationQuestion( '<question>Do you want to continue anyway? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 1 );
			}
		}

		if ( $wpcom_gh_repositories && 1 < count( $wpcom_gh_repositories ) ) {
			$output->writeln( '<comment>Found multiple WPCOM GitHub Deployments for the site.</comment>' );

			$question = new ChoiceQuestion(
				'<question>Choose from which repository you want to deploy to the staging site:</question> ',
				\array_column( $wpcom_gh_repositories, 'repository_name' ),
				0
			);
			$question->setErrorMessage( 'Repository %s is invalid.' );

			$this->gh_repository_name = $this->get_repository_slug_from_repository_name( $this->getHelper( 'question' )->ask( $input, $output, $question ) );
		} elseif ( $wpcom_gh_repositories && 1 === count( $wpcom_gh_repositories ) ) {
			$this->gh_repository_name = $this->get_repository_slug_from_repository_name( $wpcom_gh_repositories[0]->repository_name );
		}

		if ( $this->gh_repository_name ) {
			$this->gh_repo_branch = get_string_input( $input, 'branch', fn() => $this->prompt_branch_input( $input, $output ) );
			$input->setOption( 'branch', $this->gh_repo_branch );
		}

		$this->skip_safety_net = get_bool_input( $input, 'skip-safety-net' );
		$input->setOption( 'skip-safety-net', $this->skip_safety_net );

		$this->keep_data = get_bool_input( $input, 'keep-data' );
		$input->setOption( 'keep-data', $this->keep_data );

		// Not maybe_get_string_input(): it reads '' and '0' as no date, which would keep the data with no expiry.
		$keep_until       = $input->getOption( 'keep-until' );
		$this->keep_until = \is_null( $keep_until ) ? null : (string) $keep_until;
		$input->setOption( 'keep-until', $this->keep_until );

		if ( ! \is_null( $this->keep_until ) && ! $this->keep_data ) {
			throw new \InvalidArgumentException( '--keep-until only works together with --keep-data.' );
		}
		if ( $this->keep_data && $this->skip_safety_net ) {
			throw new \InvalidArgumentException( '--keep-data needs Safety Net, which --skip-safety-net does not install.' );
		}

		// Safety Net deletes the kept data on the first load after this day, counted in UTC, so a past date would
		// have it delete the data on the staging site's very first request instead of keeping it.
		if ( ! \is_null( $this->keep_until ) ) {
			validate_date_format( $this->keep_until, 'Y-m-d' );
			if ( $this->keep_until < \gmdate( 'Y-m-d' ) ) {
				throw new \InvalidArgumentException( 'The --keep-until date is in the past.' );
			}
		}
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

		if ( $this->keep_data ) {
			$question = new ConfirmationQuestion( "<question>Are you sure you want to <fg=red;options=bold>keep the real users, orders and subscriptions</> of the source site on the staging site ({$this->get_keep_data_duration()})? [y/N]</question> ", false );
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

		// Replace http with https in staging site URL
		$staging_site_https_url = str_replace( 'http://', 'https://', $staging_site->url );

		if ( isset( $staging_site->error ) ) {
			$output->writeln( "<error>$staging_site->error</error>" );
			return Command::FAILURE;
		}

		$transfer = wait_until_wpcom_site_transfer_state( $staging_site->id, 'complete', $output );
		if ( \is_null( $transfer ) ) {
			$output->writeln( '<error>Failed to check on site transfer status.</error>' );
			return Command::FAILURE;
		}

		$ssh_connection = wait_on_wpcom_site_ssh( $staging_site->id, $output );

		// Must run before anything loads WordPress on the staging site - the site name update and the password
		// rotation below may - because Safety Net's first run there decides whether the data is kept or deleted.
		$keep_data_applied = configure_safety_net_keep_data(
			static function ( string $command ) use ( $staging_site ): string {
				// An empty reply fails the keep request closed, where a thrown site lookup would skip the cleanup.
				try {
					run_wpcom_site_wp_cli_command( $staging_site->id, $command, true );
				} catch ( \Throwable ) {
					return '';
				}
				return (string) ( $GLOBALS['wp_cli_output'] ?? '' );
			},
			$this->keep_data,
			$this->keep_until,
			$output
		);

		$output->writeln( "<fg=magenta;options=bold>Updating site name to {$this->site->name}-staging.</>" );
		$update = update_wpcom_site( $staging_site->id, array( 'blogname' => "{$this->site->name}-staging" ) );
		if ( $update && isset( $update->blogname ) && "{$this->site->name}-staging" === $update->blogname ) {
			$output->writeln( "<fg=green;options=bold>Staging site $staging_site->id name successfully updated to {$this->site->name}-staging.</>" );
		} else {
			$output->writeln( '<error>Failed to set site name.</error>' );
		}

		// Run a few commands to set up the site.
		$rotate_status = run_app_command(
			WPCOM_Site_WP_User_Password_Rotate::getDefaultName(),
			array(
				'site'   => $staging_site->id,
				'--user' => 'concierge@wordpress.com',
			)
		);

		run_wpcom_site_wp_cli_command( $staging_site->id, 'config set WP_ENVIRONMENT_TYPE development --type=constant' );

		// This constant is what makes Safety Net treat the site as non-production and scrub it, so its
		// outcome is read from the reply - the runner's exit code only covers the connection - and named in
		// the final banner if it failed.
		$environment_output = (string) ( $GLOBALS['wp_cli_output'] ?? '' );
		$environment_set    = ! is_wp_cli_error_output( $environment_output ) && is_wp_cli_success_output( $environment_output );

		run_wpcom_site_wp_cli_command( $staging_site->id, "search-replace {$this->site->URL} $staging_site_https_url" );
		run_wpcom_site_wp_cli_command( $staging_site->id, 'cache flush' );

		if ( $this->skip_safety_net ) {
			$output->writeln( '<comment>Skipping the installation of SafetyNet as a mu-plugin.</comment>' );
		} else {
			// The wait above can expire on a site that becomes reachable moments later - the steps in between
			// open their own connections and may already have succeeded - so the install gets one fresh
			// attempt instead of inheriting a stale null.
			$ssh_connection ??= \WPCOM_Connection_Helper::get_ssh_connection( $staging_site->id );

			maybe_install_safety_net( $ssh_connection, $output );
		}

		$ssh_connection?->disconnect();

		$deployment_failed = false;

		// Ping site to regenerate Jetpack user token. This will cause an error and the token will be regenerated.
		$regenerated = wait_until_jetpack_token_regenerated( $staging_site->id, $output );

		if ( $regenerated ) {
			$output->writeln( '<fg=green;options=bold>Jetpack user token regenerated.</>' );
		}

		if ( ! \is_null( $this->gh_repository_name ) ) {
			if ( $regenerated ) {
				/* @noinspection PhpUnhandledExceptionInspection */
				$status = run_app_command(
					WPCOM_Site_Repository_Connect::getDefaultName(),
					array(
						'site'         => $staging_site->id,
						'repository'   => $this->gh_repository_name,
						'--branch'     => $this->gh_repo_branch,
						'--target_dir' => '/wp-content/',
						'--deploy'     => true,
					)
				);
				if ( Command::SUCCESS !== $status ) {
					// Reported here but returned at the end: the SafetyNet verdict below must run - and be
					// heard - even when the deployment failed.
					$output->writeln( '<error>Failed to connect the staging site to the repository.</error>' );
					$deployment_failed = true;
				}
			} else {
				// A requested deployment that was skipped is still a deployment that did not happen, and the
				// run must not exit 0 as though it had.
				$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
				$output->writeln( '<error>⚠  Jetpack user token not regenerated. Skipping deployment of the GitHub repository.</error>' );
				$output->writeln( '<error>    Deploy it manually.</error>' );
				$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
				$deployment_failed = true;
			}
		}

		if ( Command::SUCCESS !== $rotate_status ) {
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			$output->writeln( '<error>⚠  The WP user password rotation did not complete cleanly.</error>' );
			$output->writeln( '<error>    See the warning above for the password to record or the rotation to retry.</error>' );
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
		}

		// Checked last - after the Jetpack token regeneration and the repository deployment that writes into
		// wp-content - so the verdict reflects the site as it is handed off. The endpoint is the authoritative
		// signal that Safety Net actually booted and scrubbed, so it decides on every run.
		$expect_kept_data     = $this->keep_data && $keep_data_applied;
		$safety_net_problem   = null;
		$safety_net_installed = $this->skip_safety_net ? true : is_safety_net_confirmed_via_http(
			$staging_site_https_url,
			$output,
			expect_kept_data: $expect_kept_data,
			problem: $safety_net_problem
		);

		// Repeated here because the banner of the failed keep request has long scrolled away, and the run would
		// otherwise end without saying that the data was not kept as asked.
		if ( $this->keep_data && ! $keep_data_applied ) {
			$output->writeln( "<error>The source site's users, orders and subscriptions were NOT kept on the staging site: the request to keep them could not be applied.</error>" );
		}

		if ( true !== $safety_net_installed ) {
			$headline = \is_null( $safety_net_installed )
				? "⚠  Could not verify SafetyNet on $staging_site_https_url."
				: "⚠  SafetyNet did not confirm that $staging_site_https_url is safe to hand off.";

			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			$output->writeln( "<error>$headline</error>" );
			if ( ! \is_null( $safety_net_problem ) ) {
				$output->writeln( "<error>    $safety_net_problem</error>" );
			}
			if ( ! $environment_set ) {
				$output->writeln( '<error>    Setting WP_ENVIRONMENT_TYPE failed, which alone keeps Safety Net from scrubbing.</error>' );
			}
			if ( $expect_kept_data ) {
				$output->writeln( '<error>    The staging site was asked to keep customer data; check it before handing it off.</error>' );
			}
			$output->writeln( '<error>    Treat the staging site as holding unscrubbed production data until you have checked it.</error>' );
			$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
			return Command::FAILURE;
		}

		if ( $expect_kept_data ) {
			$output->writeln( '<comment>════════════════════════════════════════════════════════════════</comment>' );
			$output->writeln( "<comment>⚠  The staging site keeps the source site's real users, orders and subscriptions ({$this->get_keep_data_duration()}).</comment>" );
			$output->writeln( '<comment>    Safety Net blocks emails, payments and customer logins on it. To delete the data, remove the constant: wp config delete SAFETY_NET_DELETE_DATA --type=constant</comment>' );
			$output->writeln( '<comment>════════════════════════════════════════════════════════════════</comment>' );
		}

		// A staging site that does not keep the data it was asked to keep is safe, but not what was asked for.
		if ( $deployment_failed || ( $this->keep_data && ! $keep_data_applied ) ) {
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Staging site created successfully at $staging_site_https_url.</>" );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns how long the kept data stays on the staging site, for the messages that mention it.
	 *
	 * @return  string
	 */
	private function get_keep_data_duration(): string {
		return \is_null( $this->keep_until ) ? 'with no expiry date' : "until $this->keep_until";
	}

	/**
	 * Prompts the user for a site name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the domain or id of the site for which to create the staging site:</question> ' );
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
