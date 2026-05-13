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
 * Rotates the SFTP/SSH user password on WPCOM Atomic sites.
 */
#[AsCommand( name: 'wpcom:rotate-site-sftp-user-password' )]
final class WPCOM_Site_SFTP_User_Password_Rotate extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be 'all' or a comma-separated list of site IDs or URLs.
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
	 * Explicit SSH username override. When null, the SSH username is auto-resolved per site.
	 *
	 * @var string|null
	 */
	private ?string $ssh_username = null;

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
		$this->setDescription( 'Rotates the SFTP/SSH user password on a WPCOM Atomic site.' )
			->setHelp( 'This command rotates the SFTP/SSH user password on one or more WordPress.com Atomic sites. By default the site\'s primary SSH user is resolved automatically; use --user to override.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric WPCOM ID of the site on which to rotate the SFTP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'The SSH username to rotate the password for. Defaults to the site\'s primary SSH user.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all` or a comma-separated list of site IDs or URLs.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current SFTP user password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run  = get_bool_input( $input, 'dry-run' );
		$this->multiple = $input->getOption( 'multiple' );

		$site = match ( true ) {
			'all' === $this->multiple   => null,
			null !== $this->multiple    => null,
			default                     => get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) ),
		};
		$input->setArgument( 'site', $site );

		$this->ssh_username = maybe_get_string_input( $input, 'user' );
		$input->setOption( 'user', $this->ssh_username );

		$this->sites = match ( true ) {
			'all' === $this->multiple => get_wpcom_sites( array( 'fields' => 'ID,URL,name,is_wpcom_atomic,jetpack' ) ),
			null !== $this->multiple  => array_map( fn( $s ) => get_wpcom_site( trim( $s ) ), explode( ',', $this->multiple ) ),
			default                   => array( $site ),
		};
		$this->sites = array_values( array_filter( $this->sites, static fn( $site ) => $site && $site->is_wpcom_atomic ) );

		if ( empty( $this->sites ) ) {
			$output->writeln( '<error>No valid WordPress.com Atomic sites found.</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$site_count   = count( $this->sites );
		$user_display = $this->ssh_username ?? 'the primary SSH user';

		$question = match ( true ) {
			'all' === $this->multiple => new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP password of $user_display on <fg=red;options=bold>ALL</> sites? [y/N]</question> ", false ),
			$site_count > 1           => new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP password of $user_display on <fg=red;options=bold>$site_count</> sites? [y/N]</question> ", false ),
			default                   => new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP password of $user_display on {$this->sites[0]->name} (ID {$this->sites[0]->ID}, URL {$this->sites[0]->URL})? [y/N]</question> ", false ),
		};

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( ( 'all' === $this->multiple || $site_count > 1 ) && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( "<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the SFTP password on $site_count sites? [y/N]</question> ", false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->sites as $site ) {
			if ( empty( $site->name ) ) {
				$site->name = $site->URL; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}

			$username = $this->ssh_username ?? get_wpcom_site_ssh_username( $site->ID );
			if ( \is_null( $username ) ) {
				$output->writeln( "<error>Could not resolve the SSH username on $site->name (ID $site->ID, URL $site->URL). Skipping...</error>" );
				continue;
			}

			$output->writeln( "<fg=magenta;options=bold>Rotating the SFTP password of $username on $site->name (ID $site->ID, URL $site->URL).</>" );

			$credentials = $this->rotate_site_sftp_user_password( $output, (string) $site->ID, $username );
			if ( \is_null( $credentials ) ) {
				$output->writeln( '<error>Failed to rotate the SFTP user password.</error>' );
				continue;
			}

			$output->writeln( '<fg=green;options=bold>SFTP user password rotated.</>' );
			$output->writeln( "<comment>New SFTP user password:</comment> <fg=green;options=bold>$credentials->password</>" );
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
		$question = new Question( '<question>Enter the site ID or URL to rotate the SFTP user password on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Rotates the SFTP user password on a site, honouring the dry-run flag.
	 *
	 * @param   OutputInterface $output   The output interface.
	 * @param   string          $site_id  The ID of the site to rotate the SFTP user password on.
	 * @param   string          $username The SSH username to rotate the password for.
	 *
	 * @return  \stdClass|null
	 */
	private function rotate_site_sftp_user_password( OutputInterface $output, string $site_id, string $username ): ?\stdClass {
		if ( true === $this->dry_run ) {
			$output->writeln( '<comment>Dry run: SFTP user password rotation skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
			return (object) array(
				'username' => $username,
				'password' => '********',
			);
		}

		try {
			return rotate_wpcom_site_sftp_user_password( $site_id, $username );
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	// endregion
}
