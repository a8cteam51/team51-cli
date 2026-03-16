<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Downloads all regular plugins from a WPCOM site.
 */
#[AsCommand( name: 'wpcom:download-site-plugins' )]
final class WPCOM_Site_Plugins_Download extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to download plugins from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Local path to save the archive to.
	 *
	 * @var string|null
	 */
	private ?string $destination = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Download plugins from a WPCOM site.' )
			->setHelp( 'Use this command to download all plugins from wp-content/plugins on a WPCOM site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM site ID to download plugins from.' )
			->addOption( 'destination', null, InputOption::VALUE_REQUIRED, 'The destination path where the plugin archive will be saved.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->destination = get_string_input( $input, 'destination', fn() => $this->prompt_destination_input( $input, $output ) );
		$input->setOption( 'destination', $this->destination );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Downloading plugins from WPCOM site {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}) to {$this->destination}.</>" );

		$site_slug            = slugify( (string) $this->site->name );
		$timestamp            = \gmdate( 'Y-m-d-H-i-s' );
		$archive_filename     = "team51-wpcom-plugins-$site_slug-$timestamp.tar.gz";
		$remote_archive_paths = array(
			"htdocs/wp-content/$archive_filename",
			"/htdocs/wp-content/$archive_filename",
		);
		$remote_cleanup_error = false;
		$archive_created      = false;

		$archive_ssh = \WPCOM_Connection_Helper::get_ssh_connection( (string) $this->site->ID );
		if ( \is_null( $archive_ssh ) ) {
			$output->writeln( '<error>Failed to connect to the site via SSH.</error>' );
			return Command::FAILURE;
		}

		try {
			$archive_ssh->setTimeout( 0 );

			$archive_command = 'if [ -d htdocs/wp-content/plugins ]; then '
				. 'archive_path=' . escapeshellarg( "htdocs/wp-content/$archive_filename" ) . '; '
				. 'tar -czf "$archive_path" -C htdocs/wp-content plugins; '
				. 'elif [ -d /htdocs/wp-content/plugins ]; then '
				. 'archive_path=' . escapeshellarg( "/htdocs/wp-content/$archive_filename" ) . '; '
				. 'tar -czf "$archive_path" -C /htdocs/wp-content plugins; '
				. 'else '
				. 'false; '
				. 'fi; '
				. 'exit $? 2>&1';
			$archive_ssh->exec( $archive_command );
			if ( 0 !== $archive_ssh->getExitStatus() ) {
				$output->writeln( '<error>Failed to create the remote plugin archive from wp-content/plugins.</error>' );
				return Command::FAILURE;
			}
		} finally {
			$archive_ssh->disconnect();
		}

		$archive_created = true;

		$sftp = \WPCOM_Connection_Helper::get_sftp_connection( (string) $this->site->ID );
		if ( \is_null( $sftp ) ) {
			$output->writeln( '<error>Failed to connect to the site via SFTP.</error>' );
			return Command::FAILURE;
		}

		try {
			$sftp->setTimeout( 0 );

			$download_successful = false;
			foreach ( $remote_archive_paths as $sftp_path ) {
				if ( $sftp->get( $sftp_path, $this->destination ) ) {
					$download_successful = true;
					break;
				}
			}

			if ( ! $download_successful ) {
				$output->writeln( '<error>Failed to download the plugin archive via SFTP.</error>' );
				return Command::FAILURE;
			}

			if ( ! \is_file( $this->destination ) || 0 === \filesize( $this->destination ) ) {
				$output->writeln( '<error>Downloaded archive appears invalid or empty.</error>' );
				return Command::FAILURE;
			}
		} finally {
			$sftp->disconnect();
		}

		if ( $archive_created ) {
			$cleanup_ssh = \WPCOM_Connection_Helper::get_ssh_connection( (string) $this->site->ID );
			if ( \is_null( $cleanup_ssh ) ) {
				$remote_cleanup_error = true;
			} else {
				try {
					$cleanup_ssh->setTimeout( 0 );
					$cleanup_command = 'rm -f '
						. escapeshellarg( $remote_archive_paths[0] )
						. ' '
						. escapeshellarg( $remote_archive_paths[1] )
						. ' 2>&1';
					$cleanup_ssh->exec( $cleanup_command );
					if ( 0 !== $cleanup_ssh->getExitStatus() ) {
						$remote_cleanup_error = true;
					}
				} finally {
					$cleanup_ssh->disconnect();
				}
			}
		}

		if ( $remote_cleanup_error ) {
			$output->writeln( '<comment>Plugin archive downloaded, but failed to clean up the temporary remote archive.</comment>' );
		}

		$output->writeln( '<fg=green;options=bold>Plugins downloaded successfully.</>' );
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to download plugins from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

		/**
		 * The Symfony question helper.
		 *
		 * @var QuestionHelper $question_helper
		 */
		$question_helper = $this->getHelper( 'question' );
		return $question_helper->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for destination path.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_destination_input( InputInterface $input, OutputInterface $output ): ?string {
		$site_slug = \is_null( $this->site ) ? 'unknown-site' : slugify( (string) $this->site->name );
		$default   = get_user_folder_path( "Downloads/wpcom-plugins-$site_slug-" . \gmdate( 'Y-m-d-H-i-s' ) . '.tar.gz' );
		$question  = new Question( "<question>Please enter the path to save the plugins archive [$default]:</question> ", $default );

		/**
		 * The Symfony question helper.
		 *
		 * @var QuestionHelper $question_helper
		 */
		$question_helper = $this->getHelper( 'question' );
		return $question_helper->ask( $input, $output, $question );
	}

	// endregion
}
