<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Opens an SSH or SFTP shell to a given Pressable site.
 */
#[AsCommand( name: 'pressable:open-site-shell' )]
final class Pressable_Site_Shell_Open extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to open the shell for.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The email address of the Pressable collaborator to connect as.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	/**
	 * The interactive hell type to open.
	 *
	 * @var string|null
	 */
	private ?string $shell_type = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Opens an interactive SSH or SFTP shell to a given Pressable site.' )
			->setHelp( 'Use this command to open an interactive SSH or SFTP shell to a given Pressable site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site to open the shell to.' )
			->addOption( 'shell-type', null, InputArgument::OPTIONAL, 'The type of shell to open. Accepts either "ssh" or "sftp". Default "ssh".', 'ssh' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->shell_type = get_enum_input( $input, 'shell-type', array( 'ssh', 'sftp' ), null, 'ssh' );
		$input->setOption( 'shell-type', $this->shell_type );

		$this->email = OPSOASIS_WP_USERNAME;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Opening an interactive $this->shell_type shell for {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) as $this->email.</>" );

		// Retrieve the SFTP user for the current user, creating it if it does not exist yet.
		$user_was_created = false;
		$sftp_user        = $this->find_site_sftp_user();
		if ( \is_null( $sftp_user ) ) {
			$output->writeln( "<comment>No Pressable SFTP user with the email $this->email on {$this->site->displayName}. Creating...</comment>" );

			if ( \is_null( create_pressable_site_collaborator( $this->site->id, $this->email ) ) ) {
				$output->writeln( "<error>Could not create a Pressable SFTP user with the email $this->email on {$this->site->displayName}.</>" );
				return Command::FAILURE;
			}

			// Creating the collaborator provisions the SFTP user asynchronously on Pressable's side, so it is
			// not returned by the API right away. Poll until it shows up - this is what a manual re-run of the
			// command was implicitly relying on before.
			$output->writeln( '<comment>Waiting for the new SFTP user to be provisioned...</comment>' );
			for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
				$sftp_user = $this->find_site_sftp_user();
				if ( ! \is_null( $sftp_user ) ) {
					break;
				}

				$output->writeln( "<comment>Not ready yet (attempt $attempt). Retrying in 5 seconds...</comment>", OutputInterface::VERBOSITY_VERBOSE );
				\sleep( 5 );
			}

			if ( \is_null( $sftp_user ) ) {
				$output->writeln( "<error>The SFTP user for $this->email on {$this->site->displayName} was created but did not become available in time. Please try again in a minute.</>" );
				return Command::FAILURE;
			}

			$user_was_created = true;
		}

		// WPCOMSP users are logged-in through AutoProxxy, but for everyone else we must first reset their password and display it.
		if ( ! \str_ends_with( $this->email, '@automattic.com' ) ) {
			$output->writeln( "<comment>Resetting the SFTP password for $this->email on {$this->site->displayName}...</comment>", OutputInterface::VERBOSITY_VERBOSE );

			$credentials = rotate_pressable_site_sftp_user_password( $this->site->id, $sftp_user->username );
			if ( \is_null( $credentials ) ) {
				$output->writeln( "<error>Could not reset the SFTP password for $this->email on {$this->site->displayName}.</>" );
				return Command::FAILURE;
			}

			$output->writeln( "<comment>New SFTP user password:</comment> <fg=green;options=bold>$credentials->password</>" );
		}

		// Call the system SSH/SFTP application.
		$ssh_host = $sftp_user->username . '@' . \Pressable_Connection_Helper::SSH_HOST;

		// After a collaborator is created, Pressable still needs time to propagate the new user's access to the
		// SSH gateway (ssh.atomicsites.net). Until that finishes the gateway closes the connection (exit code
		// 255) even though the SFTP user already exists in the API. So for a freshly created user we retry with
		// a back-off for up to ~2 minutes. Existing users get a single attempt, so a genuine failure (e.g. a
		// wrong password) does not hang.
		$retry_delays = $user_was_created ? array( 5, 10, 15, 20, 20, 20, 30 ) : array();
		$attempt      = 0;

		do {
			$output->writeln( "<comment>Connecting to $ssh_host...</comment>", OutputInterface::VERBOSITY_VERBOSE );

			\passthru( "$this->shell_type $ssh_host", $result_code );

			// 255 is the SSH/SFTP "connection or authentication error" exit code. Any other code comes from the
			// interactive session itself (e.g. the user's last command), so it must not trigger a retry.
			if ( 255 !== $result_code ) {
				return Command::SUCCESS;
			}

			$delay = $retry_delays[ $attempt++ ] ?? null;
			if ( null !== $delay ) {
				$output->writeln( "<comment>The SSH gateway refused the connection - the new user's access may still be provisioning. Retrying in {$delay}s (Ctrl+C to abort)...</comment>" );
				\sleep( $delay );
			}
		} while ( null !== $delay );

		$output->writeln( "<error>Could not open an interactive $this->shell_type shell (exit code $result_code). The SFTP user exists but the SSH gateway is still refusing the connection; for a newly created user this usually clears within a couple of minutes. Please try again shortly.</error>" );
		return Command::FAILURE;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the site's SFTP user matching the current email, or null if none exists yet.
	 *
	 * Uses the list endpoint and matches locally rather than the single-user lookup: the latter logs an
	 * API error on every miss, which is noisy while polling for a freshly provisioned user.
	 *
	 * @return  \stdClass|null
	 */
	private function find_site_sftp_user(): ?\stdClass {
		foreach ( get_pressable_site_sftp_users( $this->site->id ) ?? array() as $sftp_user ) {
			if ( 0 === \strcasecmp( $sftp_user->email ?? '', $this->email ) ) {
				return $sftp_user;
			}
		}

		return null;
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to connect to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
