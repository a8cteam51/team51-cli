<?php

declare(strict_types=1);

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
 * Downloads all regular plugins from a Pressable site.
 */
#[AsCommand( name: 'pressable:download-site-plugins' )]
final class Pressable_Site_Plugins_Download extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to download plugins from.
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
		$this->setDescription( 'Download plugins from a Pressable site.' )
			->setHelp( 'Use this command to download all plugins from wp-content/plugins on a Pressable site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or Pressable site ID to download plugins from.' )
			->addOption( 'destination', null, InputOption::VALUE_REQUIRED, 'The destination path where the plugin archive will be saved.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->destination = get_string_input( $input, 'destination', fn() => $this->prompt_destination_input( $input, $output ) );
		$input->setOption( 'destination', $this->destination );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Downloading plugins from Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) to {$this->destination}.</>" );

		$site_slug            = slugify( (string) $this->site->name );
		$timestamp            = \gmdate( 'Y-m-d-H-i-s' );
		$archive_filename     = "team51-pressable-plugins-$site_slug-$timestamp.tar.gz";
		$remote_archive_paths = array(
			"/tmp/$archive_filename",
			"tmp/$archive_filename",
		);
		$remote_cleanup_error = false;
		$archive_created      = false;
		$download_successful  = false;
		$download_valid       = false;

		$archive_ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $this->site->id );
		if ( \is_null( $archive_ssh ) ) {
			$output->writeln( '<error>Failed to connect to the site via SSH.</error>' );
			return Command::FAILURE;
		}

		try {
			$archive_ssh->setTimeout( 0 );

			$archive_command = 'if [ -d htdocs/wp-content/plugins ]; then '
				. 'if [ -d /tmp ]; then archive_path=' . escapeshellarg( "/tmp/$archive_filename" ) . '; else archive_path=' . escapeshellarg( "tmp/$archive_filename" ) . '; fi; '
				. 'tar -czf "$archive_path" -C htdocs/wp-content plugins; '
				. 'elif [ -d /htdocs/wp-content/plugins ]; then '
				. 'if [ -d /tmp ]; then archive_path=' . escapeshellarg( "/tmp/$archive_filename" ) . '; else archive_path=' . escapeshellarg( "tmp/$archive_filename" ) . '; fi; '
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

		$sftp = \Pressable_Connection_Helper::get_sftp_connection( (string) $this->site->id );
		if ( ! \is_null( $sftp ) ) {
			try {
				$sftp->setTimeout( 0 );

				foreach ( $remote_archive_paths as $sftp_path ) {
					if ( $sftp->get( $sftp_path, $this->destination ) ) {
						$download_successful = true;
						break;
					}
				}

				$download_valid = $download_successful && \is_file( $this->destination ) && 0 < \filesize( $this->destination );
			} finally {
				$sftp->disconnect();
			}
		}

		if ( $archive_created ) { // Always cleanup after archive creation.
			$cleanup_ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $this->site->id );
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

		if ( \is_null( $sftp ) ) {
			$output->writeln( '<error>Failed to connect to the site via SFTP.</error>' );
			return Command::FAILURE;
		}

		if ( ! $download_successful ) {
			$output->writeln( '<error>Failed to download the plugin archive via SFTP.</error>' );
			return Command::FAILURE;
		}

		if ( ! $download_valid ) {
			$output->writeln( '<error>Downloaded archive appears invalid or empty.</error>' );
			return Command::FAILURE;
		}

		if ( $remote_cleanup_error ) {
			$output->writeln( '<error>Plugin archive downloaded, but failed to clean up the temporary remote archive.</error>' );
			return Command::FAILURE;
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to download plugins from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
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
		$default   = get_user_folder_path( "Downloads/pressable-plugins-$site_slug-" . \gmdate( 'Y-m-d-H-i-s' ) . '.tar.gz' );
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
