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
 * Downloads a SQL dump of the database from a WPCOM site.
 */
#[AsCommand( name: 'wpcom:download-site-database' )]
final class WPCOM_Site_Database_Download extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to download the database from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Local path to save the dump to.
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
		$this->setDescription( 'Download a SQL dump of the database from a WPCOM site.' )
			->setHelp( 'Use this command to export and download the database of a WPCOM site. The dump is gzipped on the server before transfer, and decompressed locally unless the destination ends in `.gz`.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM site ID to download the database from.' )
			->addOption( 'destination', null, InputOption::VALUE_REQUIRED, 'The destination path where the SQL dump will be saved. Ends in `.gz` to keep it compressed.' );
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
		$output->writeln( "<fg=magenta;options=bold>Downloading the database from WPCOM site {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}) to {$this->destination}.</>" );

		$site_slug            = slugify( (string) $this->site->name );
		$timestamp            = \gmdate( 'Y-m-d-H-i-s' );
		$dump_filename        = "team51-wpcom-db-$site_slug-$timestamp.sql.gz";
		$remote_dump_paths    = array(
			"/tmp/$dump_filename",
			"tmp/$dump_filename",
		);
		$remote_cleanup_error = false;
		$dump_created         = false;
		$download_successful  = false;
		$download_valid       = false;

		// Keep the compressed dump next to the destination so the decompression step below is a plain
		// local file copy. Downloading straight to the destination would leave a gzipped file behind
		// under a `.sql` name if the process died between transfer and decompression.
		$keep_compressed = \str_ends_with( \strtolower( $this->destination ), '.gz' );
		$local_gz_path   = $keep_compressed ? $this->destination : $this->destination . '.gz';

		$dump_ssh = \WPCOM_Connection_Helper::get_ssh_connection( (string) $this->site->ID );
		if ( \is_null( $dump_ssh ) ) {
			$output->writeln( '<error>Failed to connect to the site via SSH.</error>' );
			return Command::FAILURE;
		}

		try {
			$dump_ssh->setTimeout( 0 );

			// Run wp from the login directory: wp-cli resolves the site path from the host's own
			// config there, and cd-ing into htdocs first breaks that resolution.
			// --single-transaction keeps the export from locking a live production database.
			$dump_command = 'if [ -d /tmp ]; then dump_path=' . escapeshellarg( $remote_dump_paths[0] ) . '; else dump_path=' . escapeshellarg( $remote_dump_paths[1] ) . '; fi; '
				. 'wp db export "${dump_path%.gz}" --add-drop-table --single-transaction && '
				. 'gzip -f "${dump_path%.gz}"; '
				. 'exit $? 2>&1';
			$dump_ssh->exec( $dump_command );
			if ( 0 !== $dump_ssh->getExitStatus() ) {
				$output->writeln( '<error>Failed to create the remote database dump.</error>' );
				return Command::FAILURE;
			}
		} finally {
			$dump_ssh->disconnect();
		}

		$dump_created = true;

		$sftp = \WPCOM_Connection_Helper::get_sftp_connection( (string) $this->site->ID );
		if ( ! \is_null( $sftp ) ) {
			try {
				$sftp->setTimeout( 0 );

				foreach ( $remote_dump_paths as $sftp_path ) {
					if ( $sftp->get( $sftp_path, $local_gz_path ) ) {
						$download_successful = true;
						break;
					}
				}

				$download_valid = $download_successful && \is_file( $local_gz_path ) && 0 < \filesize( $local_gz_path );
			} finally {
				$sftp->disconnect();
			}
		}

		if ( $dump_created ) { // Always cleanup after dump creation.
			$cleanup_ssh = \WPCOM_Connection_Helper::get_ssh_connection( (string) $this->site->ID );
			if ( \is_null( $cleanup_ssh ) ) {
				$remote_cleanup_error = true;
			} else {
				try {
					$cleanup_ssh->setTimeout( 0 );
					$cleanup_command = 'rm -f '
						. escapeshellarg( $remote_dump_paths[0] )
						. ' '
						. escapeshellarg( $remote_dump_paths[1] )
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
			$output->writeln( '<error>Failed to download the database dump via SFTP.</error>' );
			return Command::FAILURE;
		}

		if ( ! $download_valid ) {
			$output->writeln( '<error>Downloaded database dump appears invalid or empty.</error>' );
			return Command::FAILURE;
		}

		if ( ! $keep_compressed && ! decompress_gzip_file( $local_gz_path, $this->destination ) ) {
			$output->writeln( "<error>Failed to decompress the downloaded dump. The compressed file is still at $local_gz_path.</error>" );
			return Command::FAILURE;
		}

		if ( $remote_cleanup_error ) {
			$output->writeln( '<error>Database downloaded, but failed to clean up the temporary remote dump.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Database downloaded successfully to {$this->destination}.</>" );
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to download the database from:</question> ' );
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
		$default   = get_user_folder_path( "Downloads/wpcom-db-$site_slug-" . \gmdate( 'Y-m-d-H-i-s' ) . '.sql' );
		$question  = new Question( "<question>Please enter the path to save the database dump [$default]:</question> ", $default );

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
