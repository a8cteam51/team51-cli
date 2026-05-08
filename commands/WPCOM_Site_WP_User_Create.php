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
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Sends a WordPress.com site invitation to your authenticated 1Password identity.
 *
 * The invitee email is sourced from OPSOASIS_WP_USERNAME (the Team51 1Password account email)
 * so the caller can only ever invite themselves. If the OpsOasis user holds the `team51-contractor`
 * AAM role, the invite is automatically flagged as an external collaborator on WordPress.com.
 */
#[AsCommand( name: 'wpcom:create-site-wp-user' )]
final class WPCOM_Site_WP_User_Create extends Command {
	use AutocompleteTrait;

	private const ALLOWED_ROLES = array( 'contributor', 'author', 'editor', 'administrator' );

	// region FIELDS AND CONSTANTS

	/**
	 * The WPCOM site object to invite the user to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The email address of the invitee — sourced from OPSOASIS_WP_USERNAME.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	/**
	 * The role to grant the invited user.
	 *
	 * @var string|null
	 */
	private ?string $role = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Invites your authenticated identity to a WordPress.com site as a non-admin user.' )
			->setHelp( 'This command sends a WordPress.com invitation to the email associated with your Team51 1Password account (OPSOASIS_WP_USERNAME). Inviting any other email is intentionally not supported. Roles are limited to contributor, author, or editor.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric WPCOM ID of the site to invite yourself to.' )
			->addOption( 'role', null, InputOption::VALUE_REQUIRED, 'The role to grant. One of: ' . implode( ', ', self::ALLOWED_ROLES ) . '.', 'editor' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		if ( ! defined( 'OPSOASIS_WP_USERNAME' ) || empty( OPSOASIS_WP_USERNAME ) ) {
			$output->writeln( '<error>OPSOASIS_WP_USERNAME is not set. The CLI identity must be loaded from 1Password before running this command.</error>' );
			exit( Command::FAILURE );
		}
		$this->email = OPSOASIS_WP_USERNAME;

		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );

		$role = get_enum_input( $input, 'role', self::ALLOWED_ROLES, fn() => 'editor', 'editor' );
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			$output->writeln( '<error>Invalid role. Must be one of: ' . implode( ', ', self::ALLOWED_ROLES ) . '.</error>' );
			exit( Command::FAILURE );
		}
		$this->role = $role;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion(
			"<question>Send a `{$this->role}` invitation to `{$this->email}` for `{$this->site->URL}` (ID {$this->site->ID})? [y/N]</question> ",
			false
		);

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Inviting `{$this->email}` to `{$this->site->URL}` as `{$this->role}`.</>" );

		$result = invite_wpcom_site_user( (string) $this->site->ID, $this->email, $this->role );
		if ( \is_null( $result ) ) {
			$output->writeln( '<error>Failed to send the invitation. Check the logs above for the WordPress.com error.</error>' );
			return Command::FAILURE;
		}

		$sent = (array) ( $result->sent ?? array() );
		if ( empty( $sent ) ) {
			$output->writeln( '<error>WordPress.com accepted the request but returned no `sent` entries.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Invitation sent to `{$this->email}` for `{$this->site->URL}`.</>" );
		$output->writeln( '<comment>Check your inbox to accept the invitation.</comment>' );

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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to invite yourself to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_wpcom_sites() ?? array(), 'URL' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
