<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Opens an SSH or SFTP shell to a given Pressable site.
 */
#[AsCommand( name: 'pressable:open-site-shell' )]
final class Pressable_Site_Shell_Open extends Command {
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
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->shell_type = get_enum_input( $input, $output, 'shell-type', array( 'ssh', 'sftp' ), null, 'ssh' );
		$input->setOption( 'shell-type', $this->shell_type );

		$this->email = OPSOASIS_WP_USERNAME;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Opening an interactive $this->shell_type shell for {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) as $this->email.</>" );

		// Retrieve the SFTP user for the current user.
		$sftp_user = get_pressable_site_sftp_user( $this->site->id, $this->email );
		if ( \is_null( $sftp_user ) ) {
			$output->writeln( "<comment>Could not find a Pressable SFTP user with the email $this->email on {$this->site->displayName}. Creating...</comment>", OutputInterface::VERBOSITY_VERBOSE );

			$sftp_user = create_pressable_site_collaborator( $this->site->id, $this->email );
			if ( \is_null( $sftp_user ) ) {
				$output->writeln( "<error>Could not create a Pressable SFTP user with the email $this->email on {$this->site->displayName}.</>" );
				return Command::FAILURE;
			}

			// SFTP users are different from collaborator users. We need to query the API again to get the SFTP user.
			$sftp_user = get_pressable_site_sftp_user( $this->site->id, $this->email );
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

		$output->writeln( "<comment>Connecting to $ssh_host...</comment>", OutputInterface::VERBOSITY_VERBOSE );
		if ( ! \is_null( \passthru( "$this->shell_type $ssh_host", $result_code ) ) ) {
			$output->writeln( "<error>Could not open an interactive $this->shell_type shell. Error code: $result_code</error>" );
			return Command::FAILURE;
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to connect to:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
