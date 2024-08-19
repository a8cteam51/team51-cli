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
	 * The list of user objects to process using SSH to connect to the site.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $ssh_users = null;

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

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all`.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the user email.
		$this->email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );

		// If processing a given site, retrieve it from the input.
		$multiple = get_enum_input( $input, $output, 'multiple', array( 'all' ) );
		if ( 'all' !== $multiple ) {
			$site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );

			$sites = array( $site->ID => $site );
		} else {
			$sites = \array_filter(
				get_wpcom_sites( array( 'fields' => 'ID,URL,is_wpcom_atomic' ) ),
				static function ( \stdClass $site ) {
					$exclude_sites = array( 'woocommerce.com', 'woo.com' );
					$site_domain   = \parse_url( $site->URL, PHP_URL_HOST );

					return ! \in_array( $site_domain, $exclude_sites, true );
				}
			);
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
			$errors
		);

		if ( $errors ) {
			$number_of_errors = count( $errors );
			$output->writeln( "<comment>There are $number_of_errors sites that could NOT be searched.</comment>" );
			$output->writeln( '<fg=magenta;options=bold>Trying to connect to those sites using SSH.</>' );

			$errors = $this->maybe_get_user_using_ssh( $output, $errors, $sites );
		}

		maybe_output_wpcom_failed_sites_table( $output, $errors, $sites, 'Sites that could NOT be searched' );

		$this->users = \array_filter(
			\array_map(
				static function ( string $site_id, mixed $site_users ) use ( $sites ) {
					if ( ! \is_array( $site_users ) || empty( $site_users ) ) {
						return null;
					}

					$site = $sites[ $site_id ];
					$user = \current( $site_users );

					return (object) \array_merge(
						(array) $user,
						array(
							'site_ID'  => $site->ID,
							'site_URL' => $site->URL,
						)
					);
				},
				\array_keys( $this->users ),
				$this->users
			)
		);

		if ( empty( $this->users ) ) {
			$output->writeln( '<error>No users found with the given email address.</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		output_table(
			$output,
			array_map(
				static fn( \stdClass $user ) => array(
					$user->site_ID,
					$user->site_URL,
					$user->ID,
				),
				$this->users
			),
			array( 'Site ID', 'Site URL', 'WP User ID' ),
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

		$output->writeln( "<fg=magenta;options=bold>Deleting user `$this->email` from " . count( $this->users ) . ' WPCOM site(s).</>' );

		foreach ( $this->users as $user ) {
			if ( isset( $this->ssh_users[ $user->site_ID ] ) ) {
				$ssh_user_data = $this->ssh_users[ $user->site_ID ];
				$output->writeln( "<fg=magenta;options=bold>Connecting to site ID {$ssh_user_data['id']} using SSH.</>" );
				$ssh = 'pressable' === $ssh_user_data['type'] ? \Pressable_Connection_Helper::get_ssh_connection( $ssh_user_data['id'] ) : \WPCOM_Connection_Helper::get_ssh_connection( $ssh_user_data['id'] );

				if ( ! $ssh ) {
					$output->writeln( "<error>Failed to connect to site ID {$ssh_user_data['id']} using SSH.</error>" );
					continue;
				}

				try {
					$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
					$ssh->exec(
						"wp user delete $user->ID --yes",
						function ( string $str ) use ( $output ): void {
							$GLOBALS['wp_cli_output'] = $str;
						}
					);

					if ( ! is_string( $GLOBALS['wp_cli_output'] ) || ! str_contains( $GLOBALS['wp_cli_output'], 'Success' ) ) {
						$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
						continue;
					}

					$ssh->disconnect();
				} catch ( \RuntimeException $exception ) {
					$output->writeln( "<error>SSH command failed for site ID {$user->site_ID}: {$exception->getMessage()}</error>" );
					continue;
				}
			} else {
				$result = delete_wpcom_site_user( $user->site_ID, $user->ID );
				if ( true !== $result ) {
					$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
					continue;
				}
			}

			$output->writeln( "<fg=green;options=bold>Deleted user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID) successfully.</>" );
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
	 * Check if we can get the user with SSH on Jetpack API failed sites.
	 *
	 * @param OutputInterface $output            The output interface.
	 * @param array           $sites_with_errors Sites with errors when using Jetpack API.
	 * @param array           $sites             The sites.
	 *
	 * @return array
	 */
	private function maybe_get_user_using_ssh( OutputInterface $output, array $sites_with_errors, array $sites ): array {

		$sites_hosted     = array_reduce( $sites, static fn( $carry, $site ) => $carry + array( $site->ID => $site ), array() );
		$failed_ssh_sites = array();
		$ssh_sites        = 0;
		$progress_bar     = new ProgressBar( $output, count( $sites_with_errors ) );
		$progress_bar->start();

		foreach ( $sites_with_errors as $site_id => $site_error_data ) {
			$progress_bar->advance();
			$output->writeln( '' );
			$site      = $sites_hosted[ $site_id ];
			$is_atomic = $site->is_wpcom_atomic;
			$ssh       = $is_atomic ? \WPCOM_Connection_Helper::get_ssh_connection( $site_id ) : null;

			if ( ! $is_atomic ) {
				$pressable_site = get_pressable_site( $site->URL );
				$ssh            = $pressable_site ? \Pressable_Connection_Helper::get_ssh_connection( $pressable_site->id ) : null;
			}

			if ( $ssh ) {
				try {
					$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
					$ssh->exec(
						"wp user get $this->email --fields=ID,email --format=json",
						function ( string $str ) use ( $output ): void {
							$GLOBALS['wp_cli_output'] = $str;
						}
					);
					++$ssh_sites;
					$user = json_decode( $GLOBALS['wp_cli_output'] );
					if ( $user ) {
						$this->users[ $site_id ]     = array( $user );
						$ssh_user_data['type']       = ! empty( $pressable_site ) ? 'pressable' : 'wpcom';
						$ssh_user_data['id']         = ! empty( $pressable_site ) ? $pressable_site->id : $site_id;
						$this->ssh_users[ $site_id ] = $ssh_user_data;
					}
				} catch ( \RuntimeException $exception ) {
					$output->writeln( "<error>SSH command failed for site ID {$site_id}: {$exception->getMessage()}</error>" );
					$failed_ssh_sites[ $site_id ] = $site_error_data;
				} finally {
					$ssh->disconnect();
				}
			} else {
				$error_message = $is_atomic ? 'NO SSH WPCOM connection available' : 'NO Pressable SSH connection available';
				$output->writeln( "<error>{$error_message} for site ID {$site_id}</error>" );
				$failed_ssh_sites[ $site_id ] = $site_error_data;
			}
		}

		$progress_bar->finish();
		$output->writeln( '' );
		$output->writeln( "<comment>Connected to $ssh_sites sites using SSH.</comment>" );

		return $failed_ssh_sites;
	}

	// endregion
}
