<?php
/**
 * ONE-OFF: finds database dumps, WordPress exports, logs and backups left in sites' web roots.
 *
 * @package WPCOMSpecialProjects\CLI\Command
 */

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Attribute\AsCommand;
use WPCOMSpecialProjects\CLI\Helper\Abstract_Fleet_Command;

/**
 * ONE-OFF / INCIDENT. Lists the database dumps, WordPress export files, logs, backups and CSV exports
 * in each site's web root, then checks from here whether each one is publicly downloadable.
 * Link the incident's internal post in the PR, not here: this repository is public.
 *
 * Read-only. On the site it only lists files and reads the first few KB of XML files to tell a
 * WordPress export from a sitemap. The public check asks for a single byte of each file.
 *
 * Examples:
 *   team51 --dev fleet:scan-exposed-files --sites=example.com --yes
 *   team51 --dev fleet:scan-exposed-files --sites=production --limit=5
 */
#[AsCommand( name: 'fleet:scan-exposed-files' )]
final class Fleet_Exposed_Files_Scan extends Abstract_Fleet_Command {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritDoc}
	 */
	protected const READ_ONLY = true;

	/**
	 * Every outcome settles a site, so an interrupted run picks up where it stopped. Pass a fresh
	 * `--log` to scan the same sites again.
	 *
	 * @var string[]
	 */
	protected const SETTLED_STATUSES = array( 'exposed', 'present', 'clean' );

	/**
	 * {@inheritDoc}
	 */
	protected const FOLLOW_UP_STATUSES = array( 'exposed' );

	/**
	 * {@inheritDoc}
	 */
	protected const REPORT_COLUMNS = array( 'found', 'public', 'public_files', 'other_files' );

	/**
	 * Listing a big uploads directory takes a while.
	 *
	 * @var int
	 */
	protected const SSH_TIMEOUT = 300;

	/**
	 * The file names worth a look, matched case-insensitively by `find -iname`.
	 *
	 * @var string[]
	 */
	private const NAME_PATTERNS = array(
		// Database dumps.
		'*.sql',
		'*.sql.*',
		'*.mysql',
		'*.dump',
		'*.sqlite',
		'*.sqlite3',
		'*.db',
		// WordPress exports, told apart from sitemaps and feeds on the site.
		'*.xml',
		// Logs.
		'*.log',
		'*.log.*',
		'error_log',
		'php_errorlog',
		// Backups and archives.
		'*.wpress',
		'*.bak',
		'*.backup',
		'*.old',
		'*.orig',
		'*.save',
		'*.swp',
		'*.tar',
		'*.tgz',
		'*.gz',
		'*.bz2',
		'*.xz',
		'*.7z',
		'*.rar',
		'*.zip',
		'wp-config.php?*',
		'wp-config*.txt',
		// Data exports.
		'*.csv',
	);

	/**
	 * Directories never descended into: dependencies, repositories and the shared core symlink.
	 *
	 * @var string[]
	 */
	private const PRUNED_DIRECTORIES = array( 'node_modules', 'vendor', '.git', '__wp__' );

	/**
	 * The most files listed per site, so a site with a huge uploads directory can't flood the run.
	 *
	 * @var int
	 */
	private const MAX_FILES = 5000;

	/**
	 * The most files checked over HTTP per site. Highest-risk categories are checked first.
	 *
	 * @var int
	 */
	private const MAX_PROBES = 200;

	/**
	 * Categories, most sensitive first. The report and the probes follow this order.
	 *
	 * @var string[]
	 */
	private const CATEGORIES = array( 'sql', 'wxr', 'backup', 'log', 'csv' );

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'ONE-OFF: finds database dumps, WordPress exports, logs and backups in sites\' web roots and checks which are public (read-only).' )
			->setHelp( 'Lists matching files under each site\'s web root over SSH, then requests one byte of each over HTTPS to see whether it is publicly served. Sites with a public file are marked `exposed` and go on the follow-up list; sites with files that are all blocked are `present`.' );

		parent::configure();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function process_site( \stdClass $site, SSH2 $ssh, string $platform ): array {
		$result = $this->exec( $ssh, $this->build_script() );
		$tags   = $this->parse_tagged( $result['output'] );

		if ( empty( $tags['ROOT'] ) ) {
			return $this->failure( self::REASON_ERROR, 'Could not find the web root over SSH (' . ( $result['error'] ?: 'no output' ) . ').' );
		}

		$files = $this->classify( $tags['F'] ?? array() );
		$total = (int) ( $tags['TOTAL'][0] ?? \count( $files ) );
		if ( empty( $files ) ) {
			return array(
				'status'  => 'clean',
				'note'    => 'nothing found',
				'columns' => array(
					'found'  => '0',
					'public' => '0',
				),
			);
		}

		$base   = $this->base_url( $site );
		$public = array();
		$other  = array();
		foreach ( $files as $index => $file ) {
			$state = $index < self::MAX_PROBES ? $this->probe( $base, $file['path'] ) : 'unchecked';
			$line  = \sprintf( '%s:%s (%s, %s, %s)', $file['category'], $file['path'], $this->human_size( $file['size'] ), \gmdate( 'Y-m-d', $file['mtime'] ), $state );
			if ( 'public' === $state ) {
				$public[] = $line;
			} else {
				$other[] = $line;
			}
		}

		$counts = \array_count_values( \array_column( $files, 'category' ) );
		$note   = \sprintf(
			'%d file(s) found (%s), %d public%s',
			\count( $files ),
			\implode( ', ', \array_map( static fn( string $category, int $count ) => "$count $category", \array_keys( $counts ), $counts ) ),
			\count( $public ),
			$total > self::MAX_FILES ? '; listing capped at ' . self::MAX_FILES . " of $total matches" : ''
		);
		if ( ! empty( $public ) ) {
			$note .= ': ' . \implode( ' | ', \array_slice( $public, 0, 5 ) ) . ( \count( $public ) > 5 ? ' | ...' : '' );
		}

		return array(
			'status'  => empty( $public ) ? 'present' : 'exposed',
			'note'    => $note,
			'columns' => array(
				'found'        => (string) \count( $files ),
				'public'       => (string) \count( $public ),
				'public_files' => \implode( "\n", $public ),
				'other_files'  => \implode( "\n", $other ),
			),
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Builds the script run on each site. Each line it prints is one fact, tagged by prefix:
	 *
	 *   ROOT=<dir>                         the web root it listed
	 *   F=<wxr|->\t<size>\t<mtime>\t<path>  a matching file, path relative to the web root
	 *   TOTAL=<n>                          how many files matched before the cap
	 *
	 * XML files are only reported when their first 4 KB look like a WordPress export.
	 *
	 * @return  string
	 */
	private function build_script(): string {
		// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- The export's namespace URL, matched literally.
		$script = <<<'SH'
ROOT=""
for d in /srv/htdocs htdocs /htdocs; do if [ -d "$d/wp-content" ]; then ROOT="$d"; break; fi; done
[ -z "$ROOT" ] && exit 0
cd "$ROOT" || exit 0
echo "ROOT=$ROOT"
find . \( -type d \( {PRUNE} \) -prune \) -o -type f \( {NAMES} \) -printf '%s\t%T@\t%p\n' 2>/dev/null \
	| awk -v max={MAX} 'NR <= max { print } END { print "TOTAL=" NR }' \
	| while IFS= read -r line; do
		case "$line" in
			TOTAL=*) echo "$line" ;;
			*)
				p="${line#*	*	}"
				case "$p" in
					*.[xX][mM][lL])
						if head -c 4096 -- "$p" 2>/dev/null | grep -qiE 'wxr_version|wordpress\.org/export'; then echo "F=wxr	$line"; fi ;;
					*) echo "F=-	$line" ;;
				esac ;;
		esac
	done
SH;
		// phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInText

		return \strtr(
			$script,
			array(
				'{PRUNE}' => \implode( ' -o ', \array_map( static fn( string $name ) => '-name ' . \escapeshellarg( $name ), self::PRUNED_DIRECTORIES ) ),
				'{NAMES}' => \implode( ' -o ', \array_map( static fn( string $name ) => '-iname ' . \escapeshellarg( $name ), self::NAME_PATTERNS ) ),
				'{MAX}'   => (string) self::MAX_FILES,
			)
		);
	}

	/**
	 * Turns the `F=` lines into categorised files, drops the expected noise, and sorts the rest by risk.
	 *
	 * @param   string[] $lines The `F=` values: `<wxr|->\t<size>\t<mtime>\t<path>`.
	 *
	 * @return  array<int, array{ category: string, size: int, mtime: int, path: string }>
	 */
	private function classify( array $lines ): array {
		$files = array();
		foreach ( $lines as $line ) {
			$parts = \explode( "\t", $line, 4 );
			if ( 4 !== \count( $parts ) ) {
				continue;
			}

			[ $flag, $size, $mtime, $path ] = $parts;

			$path     = \ltrim( \preg_replace( '#^\./#', '', $path ), '/' );
			$category = $this->category( $path, 'wxr' === $flag, (int) $size );
			if ( null === $category ) {
				continue;
			}

			$files[] = array(
				'category' => $category,
				'size'     => (int) $size,
				'mtime'    => (int) $mtime,
				'path'     => $path,
			);
		}

		\usort(
			$files,
			static fn( array $a, array $b ) => array( \array_search( $a['category'], self::CATEGORIES, true ), -$a['size'] )
				<=> array( \array_search( $b['category'], self::CATEGORIES, true ), -$b['size'] )
		);

		return $files;
	}

	/**
	 * The category of one file, or null when it is expected noise.
	 *
	 * Inside plugins and themes, only logs, full backups and large dumps count: those directories ship
	 * schema files, sample data and translations. Pre-compressed assets, cache files and media archives
	 * in the dated uploads folders are left out.
	 *
	 * @param   string $path   The path relative to the web root.
	 * @param   bool   $is_wxr Whether the file looked like a WordPress export.
	 * @param   int    $size   The size in bytes.
	 *
	 * @return  string|null
	 */
	private function category( string $path, bool $is_wxr, int $size ): ?string {
		$name = \strtolower( \basename( $path ) );

		if ( \str_ends_with( $name, '.xml' ) ) {
			$category = $is_wxr ? 'wxr' : null;
		} elseif ( 'thumbs.db' === $name || \str_ends_with( $name, '.php' ) || \preg_match( '/\.(js|css|html?|svg|json|map|txt|woff2?|ttf|eot)\.(gz|br)$/', $name ) ) {
			// Pre-compressed assets, and logs kept as PHP files so they execute instead of being served.
			$category = null;
		} elseif ( \preg_match( '/\.(sql(\..+)?|mysql|dump|sqlite3?|db)$/', $name ) ) {
			$category = 'sql';
		} elseif ( \preg_match( '/(\.log(\.[^\/]+)?|^error_log|^php_errorlog)$/', $name ) ) {
			$category = 'log';
		} elseif ( \str_ends_with( $name, '.csv' ) ) {
			$category = 'csv';
		} else {
			$category = 'backup';
		}

		if ( null === $category ) {
			return null;
		}

		// Page caches hold compressed copies of pages, but a log written there is still a log.
		if ( 'log' !== $category && \preg_match( '#^wp-content/(cache|boost-cache|et-cache|litespeed)/#', $path ) ) {
			return null;
		}

		// A zip in a dated media folder (any uploads directory, old ones included) came in through the media
		// library, as a download meant to be public.
		if ( 'backup' === $category && \preg_match( '#^wp-content/[^/]*uploads[^/]*/(sites/\d+/)?\d{4}/\d{2}/[^/]+\.zip$#', $path ) ) {
			return null;
		}

		// Feeds published on purpose for Facebook to fetch, and WooCommerce's bundled sample products.
		if ( 'csv' === $category && ( \str_contains( $path, '/facebook_for_woocommerce/' ) || 'sample_products.csv' === $name ) ) {
			return null;
		}

		if ( \preg_match( '#^wp-content/(plugins|themes|mu-plugins)/#', $path ) ) {
			$is_code_noise = match ( $category ) {
				'csv', 'wxr' => true,
				'sql'        => $size < 1048576,
				'backup'     => ! \preg_match( '/(\.wpress|wp-config.*)$/', $name ),
				default      => false,
			};
			if ( $is_code_noise ) {
				return null;
			}
		}

		return $category;
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
	 * Asks for the first byte of a file and says whether the site serves it.
	 *
	 * A file served as-is answers 206 with a Content-Range, or 200 with a non-HTML body. Anything
	 * else (403, 404, a login page, an HTML error page) is not public.
	 *
	 * @param   string $base The scheme and host.
	 * @param   string $path The path relative to the web root.
	 *
	 * @return  string `public`, `blocked (<code>)`, `html (<code>)` or `unreachable`.
	 */
	private function probe( string $base, string $path ): string {
		$url    = $base . '/' . \implode( '/', \array_map( 'rawurlencode', \explode( '/', $path ) ) );
		$header = array();
		$handle = \curl_init( $url );
		\curl_setopt_array(
			$handle,
			array(
				\CURLOPT_RANGE          => '0-0',
				// Some servers ignore the range and send the whole file, so stop at the first chunk.
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
			// A redirect to a landing page also answers a range request, so HTML never counts.
			206 === $status && isset( $header['content-range'] ) && ! $is_html => 'public',
			200 === $status && ! $is_html                                      => 'public',
			$is_html && $status < 400                            => "html ($status)",
			default                                              => "blocked ($status)",
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
