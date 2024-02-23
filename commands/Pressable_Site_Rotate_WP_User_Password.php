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

/**
 * Rotates the WP password of users on Pressable sites.
 */
#[AsCommand( name: 'pressable:rotate-site-wp-user-password' )]
final class Pressable_Site_Rotate_WP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all' or 'related', if set.
	 *
	 * @var string|null
	 */
	private ?string $multiple = null;

	/**
	 * The sites to rotate the password on.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The email of the WP user to rotate the password for.
	 *
	 * @var string|null
	 */
	private ?string $wp_user_email = null;

	/**
	 * Whether to actually rotate the password or just simulate doing so.
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
		$this->setDescription( 'Rotates the WordPress user password of a given user on Pressable sites.' )
			->setHelp( 'This command allows you to rotate the WP password of users on Pressable sites. Finally, it attempts to update the 1Password values of rotated passwords as well.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric Pressable ID of the site on which to rotate the WP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'The email of the site WP user for which to rotate the password. The default is concierge@wordpress.com.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepted values are \'related\' and \'all\'.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current WP user password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all', 'related' ) );

		// If processing a given site, retrieve it from the input.
		$site = match ( $this->multiple ) {
			'all' => null,
			default => get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) ),
		};
		$input->setArgument( 'site', $site );

		// Retrieve the WP user email.
		$this->wp_user_email = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
		$input->setOption( 'user', $this->wp_user_email );

		// Compile the lists of sites to process.
		$this->sites = match ( $this->multiple ) {
			'all' => get_pressable_sites(),
			'related' => \array_merge( ...get_pressable_related_sites( $site->id ) ),
			default => array( $site ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $this->multiple ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of {$input->getOption( 'user' )} on <fg=red;options=bold>ALL</> sites? [y/N]</question> ", false );
				break;
			case 'related':
				output_pressable_related_sites( $output, get_pressable_related_sites( $this->sites[0]->id ) );
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of {$input->getOption( 'user' )} on all the sites listed above? [y/N]</question> ", false );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the WP user password of {$input->getOption( 'user' )} on {$this->sites[0]->displayName} (ID {$this->sites[0]->id}, URL {$this->sites[0]->url})? [y/N]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( 'all' === $this->multiple && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the password of the WP user on all sites? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @noinspection DisconnectedForeachInstructionInspection
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->sites as $site ) {
			$output->writeln( "<fg=magenta;options=bold>Rotating the WP user password of $this->wp_user_email on $site->displayName (ID $site->id, URL $site->url).</>" );

			// Rotate the WP user password.
			$credentials = $this->rotate_site_wp_user_password( $output, $site->id );
			if ( \is_null( $credentials ) ) {
				$output->writeln( '<error>Failed to rotate the WP user password.</error>' );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>WP user password rotated successfully.</>' );
			$output->writeln( "<comment>New WP user password:</comment> <fg=green;options=bold>$credentials->password</>", OutputInterface::VERBOSITY_DEBUG );

			// Update the 1Password password value.
			$result = $this->update_1password_login( $output, $site, $credentials->password, $credentials->username );
			if ( true !== $result ) {
				$output->writeln( '<error>Failed to update 1Password entry.</error>' );
				$output->writeln( "<info>If needed, please update the 1Password entry manually to: $credentials->password</info>" );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>WP user password updated in 1Password.</>' );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the site ID or URL to rotate the WP user password on:</question> ' );
		$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for an email for the WP user.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_user_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email of the WP user to rotate the password for [concierge@wordpress.com]:</question> ', 'concierge@wordpress.com' );
		if ( 'all' !== $this->multiple ) {
			$site = get_pressable_site( $input->getArgument( 'site' ) );
			if ( ! \is_null( $site ) ) {
				$question->setAutocompleterValues( \array_map( static fn( object $wp_user ) => $wp_user->email, get_wpcom_site_users( $site->url ) ?? array() ) );
			}
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Rotates the WP password of the given user on a given site.
	 *
	 * @param   OutputInterface $output  The output instance.
	 * @param   string          $site_id The ID of the site to rotate the WP user password on.
	 *
	 * @return  \stdClass|null
	 */
	private function rotate_site_wp_user_password( OutputInterface $output, string $site_id ): ?\stdClass {
		if ( true === $this->dry_run ) {
			$credentials = (object) array(
				'username' => null,
				'password' => '********',
			);
			$output->writeln( '<comment>Dry run: WP user password rotation skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
		} else {
			$credentials = rotate_pressable_site_wp_user_password( $site_id, $this->wp_user_email );
		}

		return $credentials;
	}

	/**
	 * Updates the 1Password entry for the WP user and site.
	 *
	 * @param   OutputInterface $output   The output object.
	 * @param   object          $site     The Pressable site object.
	 * @param   string          $password The password to set.
	 * @param   string|null     $username The username of the WP user, if known.
	 *
	 * @return  boolean|null   True if the update was successful, null if the update was never attempted.
	 */
	private function update_1password_login( OutputInterface $output, object $site, string $password, ?string $username = null ): ?bool {
		// Find matching 1Password entries for the WP user and site.
		$op_login_entries = search_1password_items(
			fn( object $op_login ) => $this->match_1password_login_entry( $op_login, $site->url, $username ),
			array(
				'categories' => 'login',
				'tags'       => 'team51-cli',
			)
		);
		if ( 1 < \count( $op_login_entries ) ) {
			$output->writeln( "<error>Multiple 1Password login entries found for $this->wp_user_email on $site->displayName (ID $site->id, URL $site->url).</error>" );
			return false;
		}

		// Create or update the entry.
		if ( 0 === \count( $op_login_entries ) ) {
			$output->writeln( "<info>Creating 1Password login entry for <fg=cyan;options=bold>$this->wp_user_email</> on <fg=cyan;options=bold>$site->displayName</>.</info>", OutputInterface::VERBOSITY_DEBUG );

			$result = create_1password_item(
				array(
					'username' => $this->wp_user_email,
					'password' => $password,
				),
				\array_filter(
					array(
						'title'    => $site->displayName, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'url'      => "https://$site->url/wp-admin",
						'category' => 'login',
						'tags'     => 'team51-cli',
						// Store in the shared vault if the user is the concierge, otherwise default to the private vault.
						'vault'    => 'concierge@wordpress.com' === $this->wp_user_email ? 'kcwtp3hlkjj247dvqlriyopecu' : null,
					)
				),
				array(),
				$this->dry_run
			);
			if ( empty( $result ) ) {
				$output->writeln( '<error>1Password login entry could not be created.</error>' );
				return false;
			}
		} else {
			$op_login_entry = \reset( $op_login_entries );
			$output->writeln( "<info>Updating 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id).</info>", OutputInterface::VERBOSITY_DEBUG );

			$result = update_1password_item(
				$op_login_entry->id,
				array(
					'username' => $this->wp_user_email,
					'password' => $password,
				),
				array(
					'title' => $site->displayName, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'url'   => "https://$site->url/wp-admin",
				),
				array(),
				$this->dry_run
			);
			if ( empty( $result ) ) {
				$output->writeln( '<error>1Password login entry could not be updated.</error>' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns true if the given 1Password login entry matches the given site.
	 *
	 * @param   object      $op_login The 1Password login entry.
	 * @param   string      $site_url The site URL.
	 * @param   string|null $username The username of the WP user, if known.
	 *
	 * @return  boolean
	 */
	private function match_1password_login_entry( object $op_login, string $site_url, ?string $username ): bool {
		$result = false;

		if ( true === is_1password_item_url_match( $op_login, $site_url ) ) {
			$op_username = get_1password_item( $op_login->id, array( 'fields' => 'label=username' ) );
			if ( ! empty( $op_username ) && \property_exists( $op_username, 'value' ) ) {
				$op_username = \trim( $op_username->value );
			}

			if ( ! empty( $op_username ) ) {
				if ( true === is_case_insensitive_match( $this->wp_user_email, $op_username ) ) {
					$result = true;
				} elseif ( ! empty( $username ) && true === is_case_insensitive_match( $username, $op_username ) ) {
					$result = true;
				}
			}
		}

		return $result;
	}

	// endregion
}
