<?php

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SSH2;
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
 * Marks a site as one the team manages, as far as the Atlantis plugin is concerned.
 *
 * Atlantis is a public plugin, so a site that runs it is treated as a stranger's until two options
 * say otherwise: one marking the site as managed, one holding the address its Autoupdates module
 * reads the fleet's settings from. The site-creation commands set both. This command is for the
 * sites they did not reach - a creation whose SSH step failed, or a site that joined the fleet some
 * other way - which `wpcom:atlantis-status` reports as unmanaged or as having no settings address.
 *
 * The settings address is fetched from OpsOasis on every run and never printed.
 *
 * Examples:
 *   # See what would change, then change it
 *   team51 atlantis:mark-managed example.com --dry-run
 *   team51 atlantis:mark-managed example.com
 *
 *   # Name the host when it cannot be worked out from the site
 *   team51 atlantis:mark-managed example.mystagingwebsite.com --host=pressable
 */
#[AsCommand( name: 'atlantis:mark-managed' )]
final class Atlantis_Mark_Managed extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The hosts a site can be reached over SSH on.
	 *
	 * @var string[]
	 */
	private const HOSTS = array( 'wpcom', 'pressable' );

	/**
	 * Per-site SSH command timeout, in seconds.
	 *
	 * @var int
	 */
	private const SSH_TIMEOUT = 30;

	/**
	 * The site, as given.
	 *
	 * @var string|null
	 */
	private ?string $site = null;

	/**
	 * The host to reach the site on, or null to work it out.
	 *
	 * @var string|null
	 */
	private ?string $host = null;

	/**
	 * Whether to run without writing any changes.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Marks a site as managed for the Atlantis plugin and gives it the fleet autoupdate settings address.' )
			->setHelp( 'Sets the two options that tell the Atlantis plugin a site is one the team manages: `a8csp_atlantis_managed_site` and `a8csp_atlantis_autoupdate_settings_url`. The address is fetched from OpsOasis rather than kept in this CLI. The site-creation commands do this already; use this command for a site they did not reach. It is idempotent: a site that already holds both values is left alone.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The site (URL, domain, or ID) to mark. Prompted for when omitted.' )
			->addOption( 'host', null, InputOption::VALUE_REQUIRED, 'Where the site is hosted: `wpcom` or `pressable`. Worked out from the site when omitted.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If --host names a host other than the supported ones.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run = (bool) $input->getOption( 'dry-run' );

		$host = $input->getOption( 'host' );
		if ( null !== $host && ! \in_array( $host, self::HOSTS, true ) ) {
			throw new \InvalidArgumentException( 'The --host must be one of: ' . \implode( ', ', self::HOSTS ) . '.' );
		}
		$this->host = $host;

		$this->site = get_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( $this->dry_run ) {
			return;
		}

		$question = new ConfirmationQuestion( "<question>Mark $this->site as a managed site for Atlantis? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run ) {
			$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: no changes will be written ---</>' );
		}

		// Asked first: without the address there is nothing to write, and no reason to open a
		// connection that on Pressable rotates the site's SFTP password.
		$settings_url = get_atlantis_autoupdate_settings_url();
		if ( \is_null( $settings_url ) ) {
			$output->writeln( '<error>OpsOasis did not provide the autoupdate settings address. Nothing was changed.</error>' );
			return Command::FAILURE;
		}

		$ssh = $this->connect( $output );
		if ( \is_null( $ssh ) ) {
			$output->writeln( "<error>Could not open an SSH connection to $this->site. Nothing was changed.</error>" );
			return Command::FAILURE;
		}

		try {
			$ssh->setTimeout( self::SSH_TIMEOUT );
			$result = mark_site_as_atlantis_managed( $ssh, $settings_url, $this->dry_run );
		} catch ( \Throwable $e ) {
			$result = array(
				'status' => 'failed',
				'note'   => 'Error: ' . \str_replace( $settings_url, '[settings address]', $e->getMessage() ),
			);
		} finally {
			$ssh->disconnect();
		}

		$style = match ( $result['status'] ) {
			'success', 'would-set' => 'info',
			'unchanged'            => 'comment',
			default                => 'error',
		};
		$output->writeln( "<$style>$this->site: {$result['note']}</$style>" );

		return 'failed' === $result['status'] ? Command::FAILURE : Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Opens an SSH connection to the site on the host it lives on.
	 *
	 * With no host given, WordPress.com is asked first because it knows every connected site: an
	 * Atomic site is reached there, and anything else it knows is looked for on Pressable.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  SSH2|null
	 */
	private function connect( OutputInterface $output ): ?SSH2 {
		if ( 'pressable' !== $this->host ) {
			$wpcom_site = get_wpcom_site( $this->site );
			if ( ! \is_null( $wpcom_site ) && ! empty( $wpcom_site->is_wpcom_atomic ) ) {
				$output->writeln( "Reaching $this->site on WordPress.com.", OutputInterface::VERBOSITY_VERBOSE );
				return \WPCOM_Connection_Helper::get_ssh_connection( (string) $wpcom_site->ID );
			}

			if ( 'wpcom' === $this->host ) {
				$output->writeln( "<error>$this->site is not a WordPress.com Atomic site.</error>" );
				return null;
			}
		}

		$pressable_site = get_pressable_site( $this->site );
		if ( \is_null( $pressable_site ) ) {
			$output->writeln( "<error>$this->site was not found on WordPress.com or Pressable.</error>" );
			return null;
		}

		$output->writeln( "Reaching $this->site on Pressable.", OutputInterface::VERBOSITY_VERBOSE );
		return \Pressable_Connection_Helper::get_ssh_connection( (string) $pressable_site->id );
	}

	/**
	 * Prompts for a site when the argument is omitted in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or site ID to mark as managed:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_wpcom_jetpack_sites() ?? array(), 'siteurl' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
