<?php
/**
 * ONE-OFF: downloads exposed files found by fleet:scan-exposed-files, then removes them from the sites.
 *
 * @package WPCOMSpecialProjects\CLI\Command
 */

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Helper\Abstract_Fleet_Command;

/**
 * ONE-OFF / INCIDENT. Takes a CSV of exposed files (`site_id`, `path`, and optionally `priority`
 * columns, as written from the scan's results), copies each file down over SFTP, checks the copy
 * against a SHA-256 taken on the server, and only then deletes the file from the site. Every file is
 * recorded in a manifest next to the copies, so any of them can be handed back to the partner.
 * Link the incident's internal post in the PR, not here: this repository is public.
 *
 * A file is never deleted unless its copy matches. Symlinks, directories, PHP files and WordPress
 * core paths are refused. After a delete, the file is requested once over HTTPS to confirm it is no
 * longer served (after purging it from the edge cache, which keeps serving a deleted file).
 *
 * Examples:
 *   team51 --dev fleet:quarantine-exposed-files --files=root-files.csv --sites=example.com --dry-run
 *   team51 --dev fleet:quarantine-exposed-files --files=root-files.csv --priority=high --backup-dir=~/exposed-files --download-only
 *   team51 --dev fleet:quarantine-exposed-files --files=root-files.csv --priority=high --backup-dir=~/exposed-files
 */
#[AsCommand( name: 'fleet:quarantine-exposed-files' )]
final class Fleet_Exposed_Files_Quarantine extends Abstract_Fleet_Command {
	// region FIELDS AND CONSTANTS

	/**
	 * A site is settled once its files are gone. A download-only run settles nothing, so the run that
	 * deletes still visits the site; the copies already made are reused, not downloaded again.
	 *
	 * @var string[]
	 */
	protected const SETTLED_STATUSES = array( 'removed', 'unchanged' );

	/**
	 * {@inheritDoc}
	 */
	protected const FOLLOW_UP_STATUSES = array( 'partial' );

	/**
	 * {@inheritDoc}
	 */
	protected const REPORT_COLUMNS = array( 'files', 'handled', 'bytes', 'details' );

	/**
	 * Hashing a multi-gigabyte archive on the server takes a while.
	 *
	 * @var int
	 */
	protected const SSH_TIMEOUT = 600;

	/**
	 * Free space kept on the local disk on top of what a download needs, in bytes.
	 *
	 * @var int
	 */
	private const DISK_MARGIN = 2147483648;

	/**
	 * Paths that are part of WordPress itself and never removed, whatever the CSV says.
	 *
	 * @var string[]
	 */
	private const PROTECTED_PREFIXES = array( 'wp-admin/', 'wp-includes/', '__wp__/', 'wp-content/plugins/', 'wp-content/themes/', 'wp-content/mu-plugins/' );

	/**
	 * The files to handle, keyed by WPCOM site ID.
	 *
	 * @var array<string, string[]>
	 */
	private array $files = array();

	/**
	 * Where the copies go: `<backup-dir>/<host>/<path>`, with `manifest.jsonl` at the top.
	 *
	 * @var string
	 */
	private string $backup_dir = '';

	/**
	 * Whether to copy the files without removing them from the sites.
	 *
	 * @var bool
	 */
	private bool $download_only = false;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'ONE-OFF: downloads exposed files listed in a CSV, verifies each copy, then removes the file from the site.' )
			->setHelp( 'Reads a CSV with `site_id` and `path` columns (paths relative to the web root) and an optional `priority` column. For each file: hashes it on the server, downloads it over SFTP to `<backup-dir>/<host>/<path>`, checks the copy\'s SHA-256, deletes the file on the server, and confirms over HTTPS that it is no longer served. Every file is recorded in `<backup-dir>/manifest.jsonl`.' )
			->addOption( 'files', null, InputOption::VALUE_REQUIRED, 'The CSV of files to handle, with `site_id` and `path` columns.' )
			->addOption( 'priority', null, InputOption::VALUE_REQUIRED, 'Only the rows with this priority (comma-separated, e.g. `high,medium`), or `all`. Needs a `priority` column.', 'all' )
			->addOption( 'backup-dir', null, InputOption::VALUE_REQUIRED, 'Where the copies and the manifest go. Required unless this is a dry run.' )
			->addOption( 'download-only', null, InputOption::VALUE_NONE, 'Copy and verify the files, but leave them on the sites.' );

		parent::configure();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function prepare( InputInterface $input, OutputInterface $output ): bool {
		$this->download_only = (bool) $input->getOption( 'download-only' );

		$csv = (string) $input->getOption( 'files' );
		if ( '' === $csv || ! \is_readable( $csv ) ) {
			$output->writeln( '<error>Pass the CSV of files with --files.</error>' );
			return false;
		}

		$backup_dir = (string) $input->getOption( 'backup-dir' );
		if ( ! $this->dry_run ) {
			if ( '' === $backup_dir ) {
				$output->writeln( '<error>Pass --backup-dir: no file is removed without a copy.</error>' );
				return false;
			}
			$backup_dir = \rtrim( (string) \preg_replace( '#^~(?=/|$)#', (string) \getenv( 'HOME' ), $backup_dir ), '/' );
			if ( ! \is_dir( $backup_dir ) && ! \mkdir( $backup_dir, 0700, true ) ) {
				$output->writeln( "<error>Could not create the backup directory $backup_dir.</error>" );
				return false;
			}
			if ( ! \is_writable( $backup_dir ) ) {
				$output->writeln( "<error>The backup directory $backup_dir cannot be written.</error>" );
				return false;
			}
			$this->backup_dir = (string) \realpath( $backup_dir );
		}

		$priorities = \array_filter( \array_map( 'trim', \explode( ',', \strtolower( (string) $input->getOption( 'priority' ) ) ) ) );
		$rows       = $this->read_csv( $csv, $output );
		if ( null === $rows ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( ! \in_array( 'all', $priorities, true ) && ! \in_array( \strtolower( $row['priority'] ?? '' ), $priorities, true ) ) {
				continue;
			}
			$this->files[ $row['site_id'] ][] = $row['path'];
		}
		$this->files = \array_map( 'array_unique', $this->files );

		$count = \array_sum( \array_map( 'count', $this->files ) );
		$output->writeln( \sprintf( '<comment>%d file(s) on %d site(s) selected from %s.</comment>', $count, \count( $this->files ), $csv ) );

		return 0 < $count;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function filter_sites( array $sites, OutputInterface $output ): array {
		$missing = \array_diff( \array_keys( $this->files ), \array_map( 'strval', \array_keys( $sites ) ) );
		if ( ! empty( $missing ) && null === $this->sites_spec ) {
			$output->writeln( '<comment>In the CSV but not in the connected fleet, left for a person: ' . \implode( ', ', $missing ) . '</comment>' );
		}

		return \array_filter( $sites, fn( \stdClass $site ) => isset( $this->files[ (string) $site->userblog_id ] ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function confirmation_message( int $count ): string {
		return match ( true ) {
			$this->dry_run       => "Preview the files on $count site(s)? Nothing will be downloaded or removed.",
			$this->download_only => "Download the files from $count site(s) to $this->backup_dir? Nothing will be removed.",
			default              => "Download the files from $count site(s) to $this->backup_dir, then DELETE them from the sites once each copy is verified?",
		};
	}

	/**
	 * {@inheritDoc}
	 */
	protected function process_site( \stdClass $site, SSH2 $ssh, string $platform ): array {
		$root = $this->parse_tagged( $this->exec( $ssh, 'for d in /srv/htdocs htdocs /htdocs; do if [ -d "$d/wp-content" ]; then echo "ROOT=$d"; break; fi; done' )['output'] )['ROOT'][0] ?? '';
		if ( '' === $root ) {
			return $this->failure( self::REASON_ERROR, 'Could not find the web root over SSH.' );
		}

		$host    = normalize_wpcom_site_host( (string) ( $site->siteurl ?? '' ) );
		$base    = $this->base_url( $site );
		$paths   = $this->files[ (string) $site->userblog_id ];
		$sftp    = null;
		$details = array();
		$tally   = array();
		$bytes   = 0;

		$problems = array();
		try {
			foreach ( $paths as $path ) {
				try {
					[ $outcome, $size ] = $this->handle_file( $site, $ssh, $sftp, $platform, $root, $host, $base, $path );
					$message            = 'still-served' === $outcome ? 'deleted, but still served after an edge cache purge' : '';
				} catch ( \Throwable $e ) {
					[ $outcome, $size, $message ] = array( 'failed', 0, $e->getMessage() );
				}

				$tally[ $outcome ] = ( $tally[ $outcome ] ?? 0 ) + 1;
				$bytes            += $size;
				$line              = "$path: $outcome" . ( 0 < $size ? ' (' . $this->human_size( $size ) . ')' : '' ) . ( '' !== $message ? " - $message" : '' );
				$details[]         = $line;
				if ( '' !== $message ) {
					$problems[] = $line;
				}
			}
		} finally {
			$sftp?->disconnect();
		}

		$failed   = ( $tally['failed'] ?? 0 ) + ( $tally['still-served'] ?? 0 );
		$gone     = $tally['gone'] ?? 0;
		$all_gone = \count( $paths ) === $gone;
		$status   = match ( true ) {
			$this->dry_run       => 'previewed',
			0 < $failed          => 'partial',
			$all_gone            => 'unchanged',
			$this->download_only => 'downloaded',
			default              => 'removed',
		};

		return array(
			'status'  => $status,
			'note'    => \sprintf( '%d file(s), %s', \count( $paths ), \implode( ', ', \array_map( static fn( string $outcome, int $count ) => "$count $outcome", \array_keys( $tally ), $tally ) ) )
				. ( empty( $problems ) ? '' : ': ' . \implode( ' | ', $problems ) ),
			'columns' => array(
				'files'   => (string) \count( $paths ),
				'handled' => (string) ( \count( $paths ) - $failed - $gone ),
				'bytes'   => $this->human_size( $bytes ),
				'details' => \implode( "\n", $details ),
			),
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Copies, verifies and removes one file.
	 *
	 * @param   \stdClass $site     The fleet site record.
	 * @param   SSH2      $ssh      The open SSH connection.
	 * @param   SFTP|null $sftp     The SFTP connection, opened on first use and reused for the site.
	 * @param   string    $platform The site's platform.
	 * @param   string    $root     The web root as SSH sees it.
	 * @param   string    $host     The site's host, used as the local folder name.
	 * @param   string    $base     The scheme and host the file is served from.
	 * @param   string    $path     The file's path relative to the web root.
	 *
	 * @throws  \RuntimeException When a step fails; the file is left on the site.
	 *
	 * @return  array{ 0: string, 1: int } The outcome (`gone`, `would-download`, `would-remove`, `downloaded`, `removed`, `still-served`) and the file's size.
	 */
	private function handle_file( \stdClass $site, SSH2 $ssh, ?SFTP &$sftp, string $platform, string $root, string $host, string $base, string $path ): array {
		$refusal = $this->refusal( $path );
		if ( null !== $refusal ) {
			throw new \RuntimeException( $refusal );
		}

		// Every command starts in the web root with the path in $f, quoted once here.
		$in_root = 'cd ' . \escapeshellarg( $root ) . ' && f=' . \escapeshellarg( $path );
		$facts   = $this->parse_tagged(
			$this->exec(
				$ssh,
				"$in_root && if [ -L \"\$f\" ]; then echo STATE=symlink; elif [ ! -e \"\$f\" ]; then echo STATE=missing; elif [ ! -f \"\$f\" ]; then echo STATE=not-a-file; else echo STATE=file; echo SIZE=\$(stat -c %s -- \"\$f\")"
				. ( $this->dry_run ? '' : '; echo SHA=$(sha256sum -- "$f" | cut -d" " -f1)' ) . '; fi'
			)['output']
		);

		$state = $facts['STATE'][0] ?? '';
		$size  = (int) ( $facts['SIZE'][0] ?? 0 );
		if ( 'missing' === $state ) {
			return array( 'gone', 0 );
		}
		if ( 'file' !== $state ) {
			throw new \RuntimeException( '' === $state ? 'could not read the file on the server' : "refused, it is a $state" );
		}
		if ( $this->dry_run ) {
			return array( $this->download_only ? 'would-download' : 'would-remove', $size );
		}

		$sha = $facts['SHA'][0] ?? '';
		if ( 1 !== \preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			throw new \RuntimeException( 'could not hash the file on the server' );
		}

		$local = $this->download( $site, $sftp, $platform, $host, $path, $size, $sha );
		if ( $this->download_only ) {
			$this->record( $site, $path, $size, $sha, $local, false, '' );
			return array( 'downloaded', $size );
		}

		$gone = $this->exec( $ssh, "$in_root && rm -f -- \"\$f\" && [ ! -e \"\$f\" ] && echo GONE=1" );
		if ( 'GONE=1' !== $gone['output'] ) {
			$this->record( $site, $path, $size, $sha, $local, false, '' );
			throw new \RuntimeException( 'downloaded, but the delete failed' . ( '' !== $gone['error'] ? ' (' . $gone['error'] . ')' : '' ) );
		}

		// The edge cache keeps serving a deleted file until it is purged.
		$this->wp( $ssh, 'edge-cache purge ' . \escapeshellarg( $this->file_url( $base, $path ) ) );
		$served = $this->probe( $base, $path );
		$this->record( $site, $path, $size, $sha, $local, true, $served );

		return array( 'public' === $served ? 'still-served' : 'removed', $size );
	}

	/**
	 * Downloads a file over SFTP, unless a verified copy is already there, and checks it against the server's hash.
	 *
	 * @param   \stdClass $site     The fleet site record.
	 * @param   SFTP|null $sftp     The SFTP connection, opened here on first use.
	 * @param   string    $platform The site's platform.
	 * @param   string    $host     The site's host.
	 * @param   string    $path     The path relative to the web root.
	 * @param   int       $size     The size on the server.
	 * @param   string    $sha      The SHA-256 on the server.
	 *
	 * @throws  \RuntimeException When the download fails or the copy does not match.
	 *
	 * @return  string The local path of the verified copy.
	 */
	private function download( \stdClass $site, ?SFTP &$sftp, string $platform, string $host, string $path, int $size, string $sha ): string {
		$local = "$this->backup_dir/$host/$path";
		if ( \is_file( $local ) ) {
			if ( \filesize( $local ) === $size && \hash_file( 'sha256', $local ) === $sha ) {
				return $local;
			}
			// A different file was copied to this path before; keep it and store this one beside it.
			$local .= '.' . \substr( $sha, 0, 12 );
			if ( \is_file( $local ) && \hash_file( 'sha256', $local ) === $sha ) {
				return $local;
			}
		}

		$free = \disk_free_space( $this->backup_dir );
		if ( false !== $free && $free < $size + self::DISK_MARGIN ) {
			throw new \RuntimeException( 'not enough free disk space for ' . $this->human_size( $size ) );
		}

		if ( null === $sftp ) {
			// The same identifier the SSH connection used, so the cached credentials are reused and the
			// password is not rotated a second time.
			$sftp = match ( $platform ) {
				self::PLATFORM_WPCOM     => \WPCOM_Connection_Helper::get_sftp_connection( (string) $site->userblog_id ),
				self::PLATFORM_PRESSABLE => \Pressable_Connection_Helper::get_sftp_connection( $this->pressable_identifier( $site ) ),
			};
			if ( null === $sftp ) {
				throw new \RuntimeException( 'could not open an SFTP connection' );
			}
			$sftp->setTimeout( self::SSH_TIMEOUT );
		}

		$remote = $this->sftp_path( $sftp, $path );
		if ( ! \is_dir( \dirname( $local ) ) && ! \mkdir( \dirname( $local ), 0700, true ) ) {
			throw new \RuntimeException( 'could not create the local folder' );
		}

		$partial = "$local.part";
		if ( ! $sftp->get( $remote, $partial ) ) {
			\is_file( $partial ) && \unlink( $partial );
			throw new \RuntimeException( 'the SFTP download failed' );
		}
		\chmod( $partial, 0600 );

		if ( \hash_file( 'sha256', $partial ) !== $sha ) {
			\unlink( $partial );
			throw new \RuntimeException( 'the copy does not match the file on the server' );
		}
		if ( ! \rename( $partial, $local ) ) {
			throw new \RuntimeException( 'could not move the verified copy into place' );
		}

		return $local;
	}

	/**
	 * The path of a file as the SFTP server sees it. The SFTP root differs from the SSH login directory.
	 *
	 * @param   SFTP   $sftp The connection.
	 * @param   string $path The path relative to the web root.
	 *
	 * @throws  \RuntimeException When the web root cannot be found over SFTP.
	 *
	 * @return  string
	 */
	private function sftp_path( SFTP $sftp, string $path ): string {
		foreach ( array( '/htdocs', 'htdocs', '/srv/htdocs' ) as $root ) {
			if ( $sftp->is_dir( "$root/wp-content" ) ) {
				return "$root/$path";
			}
		}

		throw new \RuntimeException( 'could not find the web root over SFTP' );
	}

	/**
	 * Why a path must not be touched, or null when it may be.
	 *
	 * @param   string $path The path relative to the web root.
	 *
	 * @return  string|null
	 */
	private function refusal( string $path ): ?string {
		$name = \strtolower( \basename( $path ) );

		return match ( true ) {
			'' === $path || \str_starts_with( $path, '/' ) || 1 === \preg_match( '#(^|/)\.\.?(/|$)|[\x00-\x1f]#', $path ) => 'refused, not a plain relative path',
			\str_ends_with( $name, '.php' ) || \in_array( $name, array( '.htaccess', '.user.ini', 'php.ini' ), true ) => 'refused, a file the site runs on',
			! empty( \array_filter( self::PROTECTED_PREFIXES, static fn( string $prefix ) => \str_starts_with( $path, $prefix ) ) ) => 'refused, inside WordPress core, a plugin or a theme',
			default => null,
		};
	}

	/**
	 * Appends one file to the manifest next to the copies.
	 *
	 * @param   \stdClass $site    The fleet site record.
	 * @param   string    $path    The path relative to the web root.
	 * @param   int       $size    The size in bytes.
	 * @param   string    $sha     The SHA-256.
	 * @param   string    $local   The local copy.
	 * @param   bool      $removed Whether the file was deleted from the site.
	 * @param   string    $served  What a request for it returned after the delete.
	 *
	 * @throws  \RuntimeException If the manifest cannot be written, since the copy would then be unaccounted for.
	 *
	 * @return  void
	 */
	private function record( \stdClass $site, string $path, int $size, string $sha, string $local, bool $removed, string $served ): void {
		$entry = \json_encode(
			array(
				'site_id'    => $site->userblog_id,
				'site'       => $site->siteurl ?? null,
				'path'       => $path,
				'size'       => $size,
				'sha256'     => $sha,
				'local'      => $local,
				'removed'    => $removed,
				'http_after' => $served,
				'timestamp'  => \gmdate( 'c' ),
			),
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		$manifest = "$this->backup_dir/manifest.jsonl";
		if ( false === $entry || false === \file_put_contents( $manifest, $entry . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( 'could not write the manifest' );
		}
		\chmod( $manifest, 0600 );
	}

	/**
	 * Reads the CSV of files, keeping the rows with a site ID and a path.
	 *
	 * @param   string          $csv    The CSV path.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array<int, array<string, string>>|null
	 */
	private function read_csv( string $csv, OutputInterface $output ): ?array {
		$handle = \fopen( $csv, 'r' );
		$header = false === $handle ? false : \fgetcsv( $handle, null, ',', '"', '' );
		if ( false === $header || ! \in_array( 'site_id', $header, true ) || ! \in_array( 'path', $header, true ) ) {
			$output->writeln( '<error>The CSV needs a header row with `site_id` and `path` columns.</error>' );
			false !== $handle && \fclose( $handle );
			return null;
		}

		$rows = array();
		while ( false !== ( $line = \fgetcsv( $handle, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( \count( $line ) !== \count( $header ) ) {
				continue;
			}
			$row = \array_combine( $header, $line );
			if ( '' !== \trim( $row['site_id'] ) && '' !== \trim( $row['path'] ) ) {
				$rows[] = $row;
			}
		}
		\fclose( $handle );

		return $rows;
	}

	/**
	 * The scheme and host files are requested from: the site URL without any subdirectory.
	 *
	 * @param   \stdClass $site The fleet site record.
	 *
	 * @return  string
	 */
	private function base_url( \stdClass $site ): string {
		$url    = (string) ( $site->siteurl ?? '' );
		$scheme = \parse_url( $url, PHP_URL_SCHEME ) ?: 'https';
		$host   = \parse_url( $url, PHP_URL_HOST ) ?: normalize_wpcom_site_host( $url );

		return "$scheme://$host";
	}

	/**
	 * The public URL of a file.
	 *
	 * @param   string $base The scheme and host.
	 * @param   string $path The path relative to the web root.
	 *
	 * @return  string
	 */
	private function file_url( string $base, string $path ): string {
		return $base . '/' . \implode( '/', \array_map( 'rawurlencode', \explode( '/', $path ) ) );
	}

	/**
	 * Asks for the first byte of a file and says whether the site still serves it.
	 *
	 * @param   string $base The scheme and host.
	 * @param   string $path The path relative to the web root.
	 *
	 * @return  string `public`, `blocked (<code>)`, `html (<code>)` or `unreachable`.
	 */
	private function probe( string $base, string $path ): string {
		$header = array();
		$handle = \curl_init( $this->file_url( $base, $path ) );
		\curl_setopt_array(
			$handle,
			array(
				\CURLOPT_RANGE          => '0-0',
				\CURLOPT_WRITEFUNCTION  => static fn( $curl, string $chunk ): int => 0, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				\CURLOPT_FOLLOWLOCATION => true,
				\CURLOPT_MAXREDIRS      => 3,
				\CURLOPT_CONNECTTIMEOUT => 10,
				\CURLOPT_TIMEOUT        => 20,
				\CURLOPT_USERAGENT      => 'team51-exposed-files-scan',
				\CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$header ): int {
					$parts = \explode( ':', $line, 2 );
					if ( 2 === \count( $parts ) ) {
						$header[ \strtolower( \trim( $parts[0] ) ) ] = \trim( $parts[1] );
					}
					return \strlen( $line );
				},
			)
		);
		\curl_exec( $handle );
		$status = (int) \curl_getinfo( $handle, \CURLINFO_RESPONSE_CODE );
		\curl_close( $handle );

		if ( 0 === $status ) {
			return 'unreachable';
		}

		$is_html = \str_contains( \strtolower( $header['content-type'] ?? '' ), 'text/html' );
		return match ( true ) {
			206 === $status && isset( $header['content-range'] ) && ! $is_html => 'public',
			200 === $status && ! $is_html                                      => 'public',
			$is_html && $status < 400                                          => "html ($status)",
			default                                                            => "blocked ($status)",
		};
	}

	/**
	 * A byte count, rounded for people.
	 *
	 * @param   int $bytes The size.
	 *
	 * @return  string
	 */
	private function human_size( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$power = $bytes > 0 ? \min( (int) \floor( \log( $bytes, 1024 ) ), \count( $units ) - 1 ) : 0;

		return \round( $bytes / ( 1024 ** $power ), 1 ) . ' ' . $units[ $power ];
	}

	// endregion
}
