<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Rotates the SFTP password of users on Pressable sites.
 */
#[AsCommand( name: 'pressable:rotate-site-sftp-user-password' )]
final class Pressable_Site_Rotate_SFTP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all' or 'related', if set.
	 *
	 * @var string|null
	 */
	protected ?string $multiple = null;

	/**
	 * The sites to rotate the SFTP password for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The SFTP users to rotate the password for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sftp_users = null;

	/**
	 * Whether to actually rotate the SFTP password or just simulate doing so.
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
		$this->setDescription( 'Rotates the SFTP user password of a given user on Pressable sites.' )
			->setHelp( 'This command allows you to rotate the SFTP password of users on Pressable sites.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric Pressable ID of the site on which to rotate the SFTP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'The ID, email, or username of the site SFTP user for which to rotate the password.', 'concierge@wordpress.com' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepted values are \'related\' and \'all\'.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current SFTP password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all', 'related' ) );

		// If processing a given site, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site->id );
		}

		// If processing a given user, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$user = get_pressable_site_sftp_user_input( $input, $output, $input->getArgument( 'site' ), fn() => $this->prompt_user_input( $input, $output ) );
			$input->setOption( 'user', $user->email );
		} else {
			$user = get_email_input( $input, $output, fn() => $this->prompt_user_input( $input, $output ), 'user' );
			$input->setOption( 'user', $user );
		}
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
		$question = new Question( '<question>Enter the site ID or URL to rotate the SFTP password on:</question> ' );
		$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, get_pressable_sites() ?? array() ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	private function prompt_user_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the username or email of the SFTP user to rotate the password for:</question> ' );
		$question->setAutocompleterValues( \array_map( static fn( object $user ) => $user->email, get_pressable_site_sftp_users( $input->getArgument( 'site' ) ) ?? array() ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );

	}

	// endregion
}
