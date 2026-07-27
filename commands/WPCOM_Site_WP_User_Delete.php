<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;
use WPCOMSpecialProjects\CLI\Helper\Parallel_Process;

/**
 * Deletes a WP user from WPCOM sites.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 */
#[AsCommand( name: 'wpcom:delete-site-wp-user' )]
final class WPCOM_Site_WP_User_Delete extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The email address of the user to delete.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	/**
	 * The list of user objects to process.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $users = null;

	/**
	 * The list of users to reassign content to.
	 *
	 * @var array|null
	 */
	private ?array $reassign_users = null;

	/**
	 * The list of user objects to process using SSH to connect to the site.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $ssh_users = null;

	/**
	 * Whether to actually delete the user or just simulate doing so.
	 *
	 * @var bool|null
	 */
	private ?bool $dry_run = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Deletes a WP user from WPCOM sites.' )
			->setHelp( 'Use this command to delete a WP user from WPCOM sites.' );

		$this->addArgument( 'email', InputArgument::REQUIRED, 'The email address of the user to delete.' )
			->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or WPCOM ID of the site to delete the user from.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all` or a comma-separated list of site IDs or domains.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without actually deleting users' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the user email.
		$this->email = get_email_input( $input, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );

		// Retrieve the dry run option.
		$this->dry_run = get_bool_input( $input, 'dry-run' );

		// If processing a given site, retrieve it from the input.
		$multiple = $input->getOption( 'multiple' );
		if ( 'all' !== $multiple ) {
			if ( $multiple ) {
				$sites = $this->get_sites_from_multiple_input( $multiple, $output );
			} else {
				$site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
				$input->setArgument( 'site', $site );
				$sites = array( $site->ID => $site );
			}
		} else {
			$sites = $this->get_filtered_wpcom_sites();
		}

		// Compile the list of users to process.
		$this->users = get_wpcom_site_users_batch(
			array_column( $sites, 'ID' ),
			array_combine(
				array_column( $sites, 'ID' ),
				array_fill(
					0,
					count( $sites ),
					array(
						'search'         => $this->email,
						'search_columns' => 'user_email',
						'fields'         => 'ID,email,site_ID',
					)
				)
			),
			$find_user_errors
		);

		if ( $find_user_errors ) {
			$number_of_errors = count( $find_user_errors );
			$output->writeln( "<comment>There are $number_of_errors sites that could NOT be searched.</comment>" );
			$output->writeln( '<fg=magenta;options=bold>Trying to connect to those sites using SSH.</>' );

			$find_user_errors = $this->get_users_using_ssh( $output, $find_user_errors, $sites );
		}

		maybe_output_wpcom_failed_sites_table( $output, $find_user_errors, $sites, 'Sites that could NOT be searched' );

		foreach ( $this->users as $site_id => $site_users ) {
			if ( ! is_array( $site_users ) || empty( $site_users ) ) {
				unset( $this->users[ $site_id ] );
				continue;
			}

			$site = $sites[ $site_id ];
			$user = \current( $site_users );

			$this->users[ $site_id ] = (object) \array_merge(
				(array) $user,
				array(
					'site_ID'  => $site->ID,
					'site_URL' => $site->URL,
				)
			);
		}

		if ( empty( $this->users ) ) {
			$output->writeln( '<error>No users found with the given email address.</error>' );
			exit( 1 );
		}

		// Compile the list of users to reassign content to.
		$api_site_ids    = array_keys( array_diff_key( $this->users, $this->ssh_users ?? array() ) );
		$api_site_admins = get_wpcom_site_users_batch(
			$api_site_ids,
			array_combine(
				$api_site_ids,
				array_fill(
					0,
					count( $api_site_ids ),
					array(
						'role'   => 'administrator',
						'fields' => 'ID,email',
						'number' => 999, // Arbitrary large number to ensure we get all admins.
					)
				)
			),
			$find_reassign_errors
		);
		if ( $find_reassign_errors ) {
			$output->writeln( '<error>There are sites that could NOT be searched for administrators.</error>' );
			exit( 1 );
		}

		foreach ( $api_site_admins as $site_id => $site_users ) {
			$site = $sites[ $site_id ];
			if ( ! is_array( $site_users ) || empty( $site_users ) ) {
				$output->writeln( "<error>No administrators found on site {$site['site_URL']} (ID $site_id).</error>" );
				exit( 1 );
			}

			$concierge_user = null;
			$oldest_user    = null;
			foreach ( $site_users as $site_user ) {
				if ( ( null === $oldest_user || $site_user->ID < $oldest_user->ID ) && $site_user->ID !== $this->users[ $site_id ]->ID ) {
					$oldest_user = $site_user;
				}
				if ( in_array( $site_user->email, array( 'concierge@wordpress.com', 'concierge@automattic.com', 'concierge@a8c.com' ), true ) ) {
					$concierge_user = $site_user;
				}
			}

			$this->reassign_users[ $site_id ] = $concierge_user ?? $oldest_user;
		}

		foreach ( $this->ssh_users ?? array() as $site_id => $ssh_user_data ) {
			if ( 'pressable' === $ssh_user_data['type'] ) {
				$result = run_pressable_site_wp_cli_command( $ssh_user_data['id'], 'user list --role=administrator --skip-themes --skip-plugins --format=json', true );
			} else {
				$result = run_wpcom_site_wp_cli_command( $ssh_user_data['id'], 'user list --role=administrator --skip-themes --skip-plugins --format=json', true );
			}

			if ( 0 !== $result ) {
				$output->writeln( "<error>Failed to retrieve administrator users for site ID {$ssh_user_data['id']} using SSH.</error>" );
				exit( 1 );
			}

			$site_admins = decode_json_content( $GLOBALS['wp_cli_output'] ?? '' );
			if ( null === $site_admins ) {
				$output->writeln( "<error>Failed to decode administrator users for site ID {$ssh_user_data['id']} using SSH.</error>" );
				exit( 1 );
			}

			if ( empty( $site_admins ) ) {
				$output->writeln( "<error>No administrators found on site ID {$ssh_user_data['id']} using SSH.</error>" );
				exit( 1 );
			}

			$concierge_user = null;
			$oldest_user    = null;
			foreach ( $site_admins as $site_admin ) {
				if ( ( null === $oldest_user || $site_admin->ID < $oldest_user->ID ) && $site_admin->ID !== $this->users[ $site_id ]->ID ) {
					$oldest_user = $site_admin;
				}
				if ( in_array( $site_admin->user_email, array( 'concierge@wordpress.com', 'concierge@automattic.com', 'concierge@a8c.com' ), true ) ) {
					$concierge_user = $site_admin;
				}
			}

			$this->reassign_users[ $site_id ] = (object) array(
				'ID'    => $concierge_user?->ID ?? $oldest_user?->ID,
				'email' => $concierge_user?->user_email ?? $oldest_user?->user_email,
			);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		output_table(
			$output,
			array_map(
				fn ( \stdClass $user ) => array(
					$user->site_ID,
					$user->site_URL,
					$user->ID,
					$this->reassign_users[ $user->site_ID ]->email . " (ID {$this->reassign_users[ $user->site_ID ]->ID})",
				),
				$this->users
			),
			array( 'Site ID', 'Site URL', 'WP User ID', 'Reassign User' ),
			'WPCOM sites on which the user was found'
		);

		$question = new ConfirmationQuestion( "<question>Are you sure you want to delete the user $this->email from all the sites above? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$action_verb = $this->dry_run ? 'Would delete' : 'Deleting';

		$output->writeln( "<fg=magenta;options=bold>$action_verb user `$this->email` from " . count( $this->users ) . ' WPCOM site(s).</>' );

		foreach ( $this->users as $user ) {
			if ( isset( $this->ssh_users[ $user->site_ID ] ) ) {
				$ssh_user_data = $this->ssh_users[ $user->site_ID ];
				if ( $this->dry_run ) {
					$output->writeln( "<comment>Dry run: Would delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID) using SSH.</comment>", OutputInterface::VERBOSITY_VERBOSE );
					continue;
				}

				if ( 'pressable' === $ssh_user_data['type'] ) {
					$result = run_pressable_site_wp_cli_command( $ssh_user_data['id'], "user delete $user->ID --reassign={$this->reassign_users[ $user->site_ID ]->ID} --yes", true );
				} else {
					$result = run_wpcom_site_wp_cli_command( $ssh_user_data['id'], "user delete $user->ID --reassign={$this->reassign_users[ $user->site_ID ]->ID} --yes", true );
				}

				if ( 0 !== $result ) {
					$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
					continue;
				}
			} else {
				if ( $this->dry_run ) {
					$output->writeln( "<comment>Dry run: Would delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</comment>", OutputInterface::VERBOSITY_VERBOSE );
					continue;
				}

				$result = delete_wpcom_site_user( $user->site_ID, $user->ID, $this->reassign_users[ $user->site_ID ]->ID );
				if ( true !== $result ) {
					$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
					continue;
				}
			}

			$output->writeln( "<fg=green;options=bold>Deleted user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID) successfully.</>" );
		}

		if ( $this->dry_run ) {
			$output->writeln( '<info>Dry run completed. No users were actually deleted.</info>', OutputInterface::VERBOSITY_VERBOSE );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for an email address.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @throws \RuntimeException If the email address is invalid.
	 *
	 * @return  string|null
	 */
	private function prompt_email_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email address of the user to delete:</question> ' );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : throw new \RuntimeException( 'Invalid email address.' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or WPCOM site ID to remove the user from:</question> ' );
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
	 * Get the user using SSH.
	 *
	 * @param   OutputInterface $output The output interface.
	 * @param   array           $sites_with_errors The sites with errors.
	 * @param   array           $sites The sites.
	 *
	 * @return  array
	 */
	private function get_users_using_ssh( OutputInterface $output, array $sites_with_errors, array $sites ): array {
		$email                = $this->email;
		$site_ids_with_errors = array_keys( $sites_with_errors );
		$sites_index          = array_filter(
			array_column( $sites, null, 'ID' ),
			function ( $site ) use ( $site_ids_with_errors ) {
				return in_array( $site->ID, $site_ids_with_errors, true );
			}
		);
		$users                = &$this->users;
		$ssh_users            = &$this->ssh_users;
		$user_count           = 0;

		$output->writeln( '' );

		$progress_bar = $this->initialize_progress_bar( $output, count( $site_ids_with_errors ) );

		$failed_tasks = Parallel_Process::create( $output, $site_ids_with_errors )
			->configure(
				array(
					'max_parallel' => 10,
					'ssh_timeout'  => 60,
				)
			)
			->add_callback(
				'shell_command',
				static function () use ( $email ): string {
					return sprintf(
						'wp user get %s --fields=ID,email --format=json',
						escapeshellarg( $email )
					);
				}
			)
			->add_callback(
				'command_args',
				static function ( int $site_id ) use ( $sites_index ): string {
					$site = $sites_index[ $site_id ];
					return sprintf(
						'--site-id=%s --site-type=%s --site-url=%s',
						escapeshellarg( $site_id ),
						$site->is_wpcom_atomic ? 'wpcom' : 'pressable',
						escapeshellarg( $site->URL )
					);
				}
			)
			->add_callback(
				'parse_result',
				static function ( array $result ) use ( $sites_index ): array {
					$site_url = $sites_index[ $result['site_id'] ]->URL;

					// Test for invalid users.
					if ( isset( $result['details'] ) && str_contains( $result['details'], 'Error: Invalid user' ) ) {
						return array(
							'code'              => 'user_not_found',
							'site_id'           => $result['site_id'] ?? 0,
							'details'           => isset( $result['type'] ) ? sprintf( 'User not found on %s', $site_url ) : $result['details'],
							'type'              => $result['type'] ?? 'unknown_type',
							'pressable_site_id' => $result['pressable_site_id'] ?? 0,
						);
					}
					// Test for valid users.
					if ( isset( $result['code'] ) && 'success' === $result['code'] ) {
						return array(
							'code'              => 'user_found',
							'site_id'           => $result['site_id'],
							'details'           => (object) $result['data'],
							'type'              => $result['type'],
							'pressable_site_id' => $result['pressable_site_id'],
						);
					}
					return $result;
				}
			)
			->add_callback(
				'process_complete',
				static function (
					int $site_id,
					mixed $result,
					int $failed_count,
				) use (
					$sites_index,
					&$users,
					&$ssh_users,
					&$progress_bar,
					&$user_count
				): void {
					$site_url    = $sites_index[ $site_id ]->URL;
					$result_code = $result['code'] ?? null;

					if ( 'user_found' === $result_code ) {
						++$user_count;
						$users[ $site_id ]     = array(
							(object) array(
								'ID'       => (int) $result['details']->ID,
								'site_ID'  => $site_id,
								'site_URL' => $site_url,
							),
						);
						$ssh_users[ $site_id ] = array(
							'type' => $result['type'],
							'id'   => 'pressable' === $result['type'] ? $result['pressable_site_id'] : $result['site_id'],
						);
					}

					$progress_bar->setMessage(
						sprintf(
							'<fg=red>Errors: %d</fg=red> • <fg=green>Found: %d</fg=green>',
							$failed_count,
							$user_count,
						)
					);

					$progress_bar->advance();
				}
			)
			->process_tasks();

		$progress_bar->finish();

		return $failed_tasks;
	}

	/**
	 * Get sites from the multiple input option.
	 *
	 * @param   string          $multiple The multiple input option.
	 * @param   OutputInterface $output   The output interface.
	 *
	 * @return  array
	 */
	private function get_sites_from_multiple_input( string $multiple, OutputInterface $output ): array {
		$site_list = explode( ',', $multiple );
		$sites     = array();
		foreach ( $site_list as $site_identifier ) {
			$site = get_wpcom_site( trim( $site_identifier ) );
			if ( $site ) {
				$sites[ $site->ID ] = $site;
			} else {
				$output->writeln( "<error>Invalid site identifier: $site_identifier</error>" );
			}
		}

		return $sites;
	}

	/**
	 * Get filtered WPCOM sites, excluding specific domains.
	 *
	 * @return array Filtered WPCOM sites.
	 */
	private function get_filtered_wpcom_sites(): array {
		return \array_filter(
			get_wpcom_sites( array( 'fields' => 'ID,URL,is_wpcom_atomic' ) ),
			static function ( \stdClass $site ) {
				$exclude_sites = array( 'woocommerce.com', 'woo.com' );
				$site_domain   = \parse_url( $site->URL, PHP_URL_HOST );

				return ! \in_array( $site_domain, $exclude_sites, true );
			}
		);
	}

	/**
	 * Initialize progress bar for SSH operations.
	 *
	 * @param   OutputInterface $output The output interface.
	 * @param   int             $total_items The total number of items to process.
	 *
	 * @return  ProgressBar
	 */
	private function initialize_progress_bar( OutputInterface $output, int $total_items ): ProgressBar {
		$progress_bar = new ProgressBar( $output, $total_items );
		$progress_bar->setFormat( '(%current%/%max%) [%bar%] %percent:3s%% • %message%' );
		$progress_bar->setMessage( 'Initializing...' );
		$progress_bar->start();

		return $progress_bar;
	}

	// endregion
}
