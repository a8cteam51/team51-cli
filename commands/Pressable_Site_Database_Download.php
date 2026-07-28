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
 * Downloads a SQL dump of the database from a Pressable site.
 */
#[AsCommand( name: 'pressable:download-site-database' )]
final class Pressable_Site_Database_Download extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to download the database from.
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
		$this->setDescription( 'Download a SQL dump of the database from a Pressable site.' )
			->setHelp( 'Use this command to export and download the database of a Pressable site. The dump is gzipped on the server before transfer, and decompressed locally unless the destination ends in `.gz`.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or Pressable site ID to download the database from.' )
			->addOption( 'destination', null, InputOption::VALUE_REQUIRED, 'The destination path where the SQL dump will be saved. Ends in `.gz` to keep it compressed.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws  \InvalidArgumentException If the destination directory does not exist or is not writable.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->destination = get_string_input( $input, 'destination', fn() => $this->prompt_destination_input( $input, $output ) );
		$input->setOption( 'destination', $this->destination );

		// Fail here rather than after spending minutes on the remote export and transfer.
		if ( \is_dir( $this->destination ) ) {
			throw new \InvalidArgumentException( "The destination {$this->destination} is a directory. Pass the full path of the file to write." );
		}

		$destination_dir = \dirname( $this->destination );
		if ( ! \is_dir( $destination_dir ) || ! \is_writable( $destination_dir ) ) {
			throw new \InvalidArgumentException( "The destination directory $destination_dir does not exist or is not writable." );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Downloading the database from Pressable site {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) to {$this->destination}.</>" );

		$site_slug           = slugify( (string) $this->site->name );
		$timestamp           = \gmdate( 'Y-m-d-H-i-s' );
		$dump_filename       = "team51-pressable-db-$site_slug-$timestamp.sql.gz";
		$remote_dump_paths   = array(
			"/tmp/$dump_filename",
			"tmp/$dump_filename",
		);
		$dump_failed         = false;
		$download_successful = false;
		$download_valid      = false;
		$remote_size         = null;
		$used_remote_path    = $remote_dump_paths[0];

		// Always transfer into a process-scoped temp file and only move it onto the destination once the
		// download has been verified. Writing straight to the destination would let a dropped connection
		// replace an earlier dump - possibly the last copy of a deleted site - with a partial one.
		$keep_compressed = \str_ends_with( \strtolower( $this->destination ), '.gz' );
		$local_gz_path   = $this->destination . '.' . \getmypid() . '.gz';

		$dump_ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $this->site->id );
		if ( \is_null( $dump_ssh ) ) {
			$output->writeln( '<error>Failed to connect to the site via SSH.</error>' );
			return Command::FAILURE;
		}

		try {
			$dump_ssh->setTimeout( 0 );

			// Run wp from the login directory: wp-cli resolves the site path from the host's own
			// config there, and cd-ing into htdocs first breaks that resolution.
			// umask 077 because, unlike a plugin archive, this dump holds password hashes, auth tokens
			// and customer PII, and it sits in a shared /tmp for the whole export and transfer.
			// --single-transaction keeps the export from locking a live production database.
			$dump_command = 'umask 077; '
				. 'if [ -d /tmp ]; then dump_path=' . escapeshellarg( $remote_dump_paths[0] ) . '; else dump_path=' . escapeshellarg( $remote_dump_paths[1] ) . '; fi; '
				. 'wp db export "${dump_path%.gz}" --add-drop-table --single-transaction && '
				. 'gzip -f "${dump_path%.gz}"; '
				. 'exit $?';
			$dump_ssh->exec( $dump_command );
			$dump_failed = ( 0 !== $dump_ssh->getExitStatus() );
		} finally {
			$dump_ssh->disconnect();
		}

		if ( $dump_failed ) {
			// `wp db export` may have written the plaintext dump before `gzip` failed, so sweep before leaving.
			$output->writeln( '<error>Failed to create the remote database dump.</error>' );
			if ( ! $this->remove_remote_dumps( $remote_dump_paths ) ) {
				$output->writeln( "<error>Anything it left behind is still on the server at {$remote_dump_paths[0]} or its .sql counterpart; remove it manually.</error>" );
			}

			return Command::FAILURE;
		}

		$sftp = \Pressable_Connection_Helper::get_sftp_connection( (string) $this->site->id );
		if ( ! \is_null( $sftp ) ) {
			try {
				$sftp->setTimeout( 0 );

				foreach ( $remote_dump_paths as $sftp_path ) {
					$remote_stat = $sftp->stat( $sftp_path );
					if ( false === $remote_stat ) {
						continue;
					}

					// The dump is here, so this is the path to name in any recovery message from now on.
					$used_remote_path = $sftp_path;

					if ( $sftp->get( $sftp_path, $local_gz_path ) ) {
						\chmod( $local_gz_path, 0600 );
						$download_successful = true;
						$remote_size         = $remote_stat['size'] ?? null;
						break;
					}
				}

				// Compare against the remote size so a short transfer is caught while the remote dump,
				// which is only deleted once the local copy is known good, can still be re-fetched.
				$download_valid = $download_successful && \is_file( $local_gz_path ) && 0 < \filesize( $local_gz_path )
					&& ( \is_null( $remote_size ) || \filesize( $local_gz_path ) === $remote_size );
			} finally {
				$sftp->disconnect();
			}
		}

		if ( \is_null( $sftp ) ) {
			$output->writeln( "<error>Failed to connect to the site via SFTP. The dump is still at $used_remote_path on the server.</error>" );
			return Command::FAILURE;
		}

		if ( ! $download_successful ) {
			$this->discard_temp_archive( $output, $local_gz_path );
			$output->writeln( "<error>Failed to download the database dump via SFTP. The dump is still at $used_remote_path on the server.</error>" );
			return Command::FAILURE;
		}

		if ( \is_null( $remote_size ) ) {
			$output->writeln( '<comment>The server did not report the dump size, so the transfer could not be checked for truncation.</comment>' );
		}

		if ( ! $download_valid ) {
			$this->discard_temp_archive( $output, $local_gz_path );
			$output->writeln( "<error>Downloaded database dump is truncated. The remote dump has been kept at $used_remote_path so the transfer can be retried without re-exporting.</error>" );
			return Command::FAILURE;
		}

		if ( $keep_compressed ) {
			if ( ! \rename( $local_gz_path, $this->destination ) ) {
				$this->discard_temp_archive( $output, $local_gz_path );
				$output->writeln( "<error>Failed to move the downloaded archive to {$this->destination}. The remote dump is still at $used_remote_path.</error>" );
				return Command::FAILURE;
			}
		} else {
			if ( ! decompress_gzip_file( $local_gz_path, $this->destination ) ) {
				$output->writeln( "<error>Failed to decompress the downloaded dump. The compressed copy is at $local_gz_path and the remote dump at $used_remote_path.</error>" );
				return Command::FAILURE;
			}

			$this->discard_temp_archive( $output, $local_gz_path );
		}

		// Only now is the local copy known good, so the remote dump can go.
		if ( ! $this->remove_remote_dumps( $remote_dump_paths ) ) {
			$output->writeln( "<error>Database downloaded to {$this->destination}, but the temporary remote dump at $used_remote_path could not be removed. Delete it manually - it holds password hashes and customer data.</error>" );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Database downloaded successfully to {$this->destination}.</>" );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Removes the process-scoped compressed download.
	 *
	 * @param   OutputInterface $output        The output object.
	 * @param   string          $local_gz_path The path the archive was downloaded to.
	 *
	 * @return  void
	 */
	private function discard_temp_archive( OutputInterface $output, string $local_gz_path ): void {
		if ( \is_file( $local_gz_path ) && ! \unlink( $local_gz_path ) ) {
			$output->writeln( "<comment>Could not remove the temporary archive at $local_gz_path. Delete it manually - it holds a copy of the database.</comment>" );
		}
	}

	/**
	 * Removes the temporary dump from the server. Targets the plaintext `.sql` as well as the gzipped
	 * path, because a failure between `wp db export` and `gzip` leaves the uncompressed dump - password
	 * hashes, auth tokens and customer PII - sitting in a shared /tmp with nothing else to clean it up.
	 *
	 * @param   string[] $remote_dump_paths The candidate remote paths of the gzipped dump.
	 *
	 * @return  boolean
	 */
	private function remove_remote_dumps( array $remote_dump_paths ): bool {
		$cleanup_ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $this->site->id );
		if ( \is_null( $cleanup_ssh ) ) {
			return false;
		}

		try {
			$cleanup_ssh->setTimeout( 0 );

			$targets = array();
			foreach ( $remote_dump_paths as $path ) {
				$targets[] = escapeshellarg( $path );
				$targets[] = escapeshellarg( \preg_replace( '/\.gz$/', '', $path ) );
			}

			$cleanup_ssh->exec( 'rm -f ' . \implode( ' ', $targets ) . ' 2>&1' );
			return 0 === $cleanup_ssh->getExitStatus();
		} finally {
			$cleanup_ssh->disconnect();
		}
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to download the database from:</question> ' );
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
		$default   = get_user_folder_path( "Downloads/pressable-db-$site_slug-" . \gmdate( 'Y-m-d-H-i-s' ) . '.sql' );
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
