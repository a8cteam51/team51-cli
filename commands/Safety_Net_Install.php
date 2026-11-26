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
 * Installs Safety Net on an existing Pressable or WordPress.com site.
 */
#[AsCommand( name: 'safety-net:install' )]
final class Safety_Net_Install extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The last line of every failure message.
	 *
	 * @var string
	 */
	private const UNSCRUBBED_WARNING = 'Until you check it, treat the site as holding unscrubbed production data.';

	/**
	 * Whether the site is a WordPress.com site.
	 *
	 * @var boolean|null
	 */
	private ?bool $wpcom = null;

	/**
	 * The site to install Safety Net on.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Installs Safety Net as a mu-plugin on an existing Pressable or WordPress.com site.' )
			->setHelp( 'Retries up to 3 times on a fresh SSH connection. If the site has an older Safety Net, asks whether to update it to the latest release. When WP_ENVIRONMENT_TYPE is production or not set, sets it to development: at once on a site that the host flags as staging, and on any other site only after you type the site URL. Then checks the Safety Net status endpoint. Use it first on a failed clone, before deleting the site.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric ID of the site to install Safety Net on.' )
			->addOption( 'wpcom', null, InputOption::VALUE_NONE, 'The site is a WordPress.com site. The default is a Pressable site.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->wpcom = get_bool_input( $input, 'wpcom' );
		$input->setOption( 'wpcom', $this->wpcom );

		$prompt     = fn() => $this->prompt_site_input( $input, $output );
		$this->site = $this->wpcom ? get_wpcom_site_input( $input, $prompt ) : get_pressable_site_input( $input, $prompt );
		$input->setArgument( 'site', (string) ( $this->wpcom ? $this->site->ID : $this->site->id ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WPCOM API field, not ours.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- API fields, not ours.
		$site_text = $this->wpcom ? "{$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL})" : "{$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url})";

		$output->writeln( '<comment>On a site that is not production, Safety Net deletes users, orders and subscriptions.</comment>' );
		$output->writeln(
			$this->is_staging_site()
				? '<comment>The host flags the site as staging, so the command sets WP_ENVIRONMENT_TYPE to development if it is production or not set.</comment>'
				: '<comment>The host does not flag the site as staging. If WP_ENVIRONMENT_TYPE is production or not set, the command asks you to type the site URL before it changes it.</comment>'
		);
		$question = new ConfirmationQuestion( "<question>Are you sure you want to install SafetyNet on $site_text? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$site_id  = (string) $input->getArgument( 'site' );
		$helper   = $this->wpcom ? \WPCOM_Connection_Helper::class : \Pressable_Connection_Helper::class;
		$site_url = (string) ( $this->wpcom ? $this->site->URL : $this->site->url ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WPCOM API field, not ours.

		$output->writeln( "<fg=magenta;options=bold>Installing SafetyNet on site $site_id.</>" );

		$connect           = static fn() => $helper::get_ssh_connection( $site_id );
		$installed_version = $this->read_installed_version( $connect );
		$latest_version    = \is_null( $installed_version ) ? null : get_latest_safety_net_version();
		$outdated          = ! \is_null( $latest_version ) && version_compare( $installed_version, $latest_version, '<' );
		$update            = $outdated && $this->confirm_update( $input, $output, $installed_version, $latest_version );

		if ( ! \is_null( $installed_version ) && ! $outdated ) {
			$output->writeln(
				\is_null( $latest_version )
					? "<comment>SafetyNet $installed_version is installed. Could not read the latest release from GitHub, so the command keeps this copy.</comment>"
					: "<comment>SafetyNet $installed_version is installed. The latest release is $latest_version.</comment>"
			);
		}

		if ( ! install_safety_net_with_retries( $connect, $output, replace: $update ) ) {
			write_console_box(
				$output,
				'red',
				'✗ SafetyNet is not installed',
				array( "Site: $site_url (ID $site_id)", '', 'The errors above say why.', 'Install Safety Net by hand before you do anything else with the site.' )
			);
			return Command::FAILURE;
		}

		if ( $update ) {
			$new_version = $this->read_installed_version( $connect );
			if ( $new_version === $latest_version ) {
				$output->writeln( "<fg=green;options=bold>Updated SafetyNet from $installed_version to $latest_version.</>" );
				$outdated = false;
			} else {
				$output->writeln( '<error>The update did not complete. The site runs SafetyNet ' . ( $new_version ?? 'of an unknown version' ) . ", and the latest release is $latest_version.</error>" );
			}
		}

		$wpcom      = $this->wpcom;
		$run_wp_cli = static function ( string $command ) use ( $wpcom, $site_id ): string {
			try {
				$wpcom ? run_wpcom_site_wp_cli_command( $site_id, $command, true ) : run_pressable_site_wp_cli_command( $site_id, $command, true );
			} catch ( \Throwable ) {
				return '';
			}
			return (string) ( $GLOBALS['wp_cli_output'] ?? '' );
		};

		$environment_problem = maybe_set_safety_net_environment_type(
			$run_wp_cli,
			fn( string $before ): bool => $this->is_staging_site() || $this->confirm_environment_change( $input, $output, $before, $site_url, $site_id ),
			$output
		);
		if ( null !== $environment_problem ) {
			$platform = $wpcom ? 'wpcom' : 'pressable';
			write_console_box(
				$output,
				'red',
				'✗ SafetyNet is installed but not active',
				array(
					"Site: $site_url (ID $site_id)",
					'',
					$environment_problem,
					'Safety Net does nothing while the site is production.',
					'',
					'If you are sure that the site is not production, run:',
					array( "team51 $platform:run-site-wp-cli-command \"config set WP_ENVIRONMENT_TYPE development --type=constant\" $site_id" ),
					'',
					self::UNSCRUBBED_WARNING,
				)
			);
			return Command::FAILURE;
		}

		$problem   = null;
		$confirmed = is_safety_net_confirmed_via_http( $site_url, $output, problem: $problem );
		if ( \is_null( $confirmed ) ) {
			write_console_box(
				$output,
				'red',
				'✗ Could not verify SafetyNet',
				array(
					"Site: $site_url (ID $site_id)",
					'',
					'The status endpoint did not return a Safety Net report.',
					...( $outdated && $this->lacks_status_endpoint( $installed_version ) ? array( "The site runs SafetyNet $installed_version. Versions before " . SAFETY_NET_STATUS_ENDPOINT_SINCE . " have no status endpoint. To update it to $latest_version, run the command again and answer yes." ) : array() ),
					'',
					'Open this URL to check the site:',
					array( ( preg_match( '#^https?://#i', $site_url ) ? rtrim( $site_url, '/' ) : "https://$site_url" ) . '/?rest_route=/safety-net/v1/status' ),
					'',
					self::UNSCRUBBED_WARNING,
				)
			);
			return Command::FAILURE;
		}
		if ( false === $confirmed ) {
			write_console_box(
				$output,
				'red',
				'✗ SafetyNet did not confirm that the site is safe',
				array( "Site: $site_url (ID $site_id)", '', (string) $problem, '', self::UNSCRUBBED_WARNING )
			);
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>SafetyNet is active on $site_url and scrubbed it.</>" );
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
		$question = new Question( '<question>Enter the site ID or URL to install SafetyNet on:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Returns the version of the Safety Net copy on the site, or null if the site has none or it cannot be read.
	 *
	 * @param   callable $connect Returns a new SSH connection to the site, or null if it cannot connect.
	 *
	 * @return  string|null
	 */
	private function read_installed_version( callable $connect ): ?string {
		$ssh_connection = null;
		try {
			$ssh_connection = $connect();
			return \is_null( $ssh_connection ) ? null : get_installed_safety_net_version( $ssh_connection );
		} catch ( \RuntimeException ) {
			return null;
		} finally {
			$ssh_connection?->disconnect();
		}
	}

	/**
	 * Explains that the installed Safety Net is out of date and asks whether to update it.
	 *
	 * @param   InputInterface  $input             The input interface.
	 * @param   OutputInterface $output            The output interface.
	 * @param   string          $installed_version The version on the site.
	 * @param   string          $latest_version    The version of the latest release.
	 *
	 * @return  boolean
	 */
	private function confirm_update( InputInterface $input, OutputInterface $output, string $installed_version, string $latest_version ): bool {
		write_console_box(
			$output,
			'yellow',
			'⚠ SafetyNet is out of date',
			array(
				"Installed: $installed_version",
				"Latest release: $latest_version",
				'',
				'The command can replace the installed copy with the latest release.',
				'It replaces only the plugin files. It does not change the database.',
				...( $this->lacks_status_endpoint( $installed_version ) ? array( 'Versions before ' . SAFETY_NET_STATUS_ENDPOINT_SINCE . ' have no status endpoint, so the command cannot verify this copy.' ) : array() ),
				'',
				'If you answer no, the command keeps the installed copy.',
			)
		);

		$question = new ConfirmationQuestion( "<question>Update SafetyNet from $installed_version to $latest_version? [y/N]</question> ", false );
		$update   = true === $this->getHelper( 'question' )->ask( $input, $output, $question );
		if ( ! $update ) {
			$output->writeln( "<comment>Keeping SafetyNet $installed_version.</comment>" );
		}

		return $update;
	}

	/**
	 * Explains the risk of activating Safety Net on a site that the host does not flag as staging, and asks for the site URL to confirm.
	 *
	 * @param   InputInterface  $input    The input interface.
	 * @param   OutputInterface $output   The output interface.
	 * @param   string          $before   The current WP_ENVIRONMENT_TYPE, as plain text.
	 * @param   string          $site_url The URL of the site.
	 * @param   string          $site_id  The ID of the site.
	 *
	 * @return  boolean
	 */
	private function confirm_environment_change( InputInterface $input, OutputInterface $output, string $before, string $site_url, string $site_id ): bool {
		$normalize = static fn( ?string $url ): string => strtolower( trim( (string) preg_replace( '#^\s*https?://#i', '', trim( (string) $url ) ), '/' ) );
		$host      = $normalize( $site_url );

		write_console_box(
			$output,
			'red',
			'⚠ Safety Net is not active on this site',
			array(
				"Site: $site_url (ID $site_id)",
				'',
				"WP_ENVIRONMENT_TYPE is $before, so Safety Net treats the site as production and does nothing.",
				'The host does not flag the site as staging.',
				'',
				'To activate Safety Net, the command can set WP_ENVIRONMENT_TYPE to development.',
				'Safety Net then deletes the users, orders and subscriptions on this site.',
				'On a production site, this destroys real customer data.',
				'',
				'Continue only if you are sure that the site is not production.',
			)
		);

		$question = new Question( "<question>To activate Safety Net, type the site URL ($host). To skip, press Enter:</question> " );
		if ( '' !== $host && $normalize( $this->getHelper( 'question' )->ask( $input, $output, $question ) ) === $host ) {
			return true;
		}

		$output->writeln( '<comment>The answer does not match the site URL, so the command does not change WP_ENVIRONMENT_TYPE.</comment>' );
		return false;
	}

	/**
	 * Returns whether a Safety Net version is older than the status endpoint.
	 *
	 * @param   string|null $version The version on the site.
	 *
	 * @return  boolean
	 */
	private function lacks_status_endpoint( ?string $version ): bool {
		return ! \is_null( $version ) && version_compare( $version, SAFETY_NET_STATUS_ENDPOINT_SINCE, '<' );
	}

	/**
	 * Returns whether the host flags the site as a staging site.
	 *
	 * @return  boolean
	 */
	private function is_staging_site(): bool {
		// Adding a domain converts a Pressable staging site to live, so a site on a custom domain is never flagged.
		return true === ( $this->wpcom ? ( $this->site->is_wpcom_staging_site ?? null ) : ( $this->site->staging ?? null ) );
	}

	// endregion
}
