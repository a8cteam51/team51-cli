<?php
/**
 * Pre-autoload environment guard.
 *
 * Required from every entry point (team51-cli.php, mcp-server.php) before the Composer
 * autoloader, so an unsupported runtime fails with a clear message instead of a cryptic
 * fatal deep in a dependency (e.g. a package installed under --ignore-platform-reqs that
 * needs a newer PHP). Uses only built-in functions, since it runs before autoload.
 */

( static function (): void {
	$problems = array();

	if ( PHP_VERSION_ID < 80300 ) {
		$problems[] = 'PHP 8.3 or newer is required (running ' . PHP_VERSION . ').';
	}
	foreach ( array( 'gd', 'json', 'posix', 'readline' ) as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			$problems[] = "The '$extension' PHP extension is required but not loaded.";
		}
	}

	if ( $problems ) {
		fwrite( STDERR, "Team51 CLI cannot start due to an unsupported environment:\n" );
		foreach ( $problems as $problem ) {
			fwrite( STDERR, "  - $problem\n" );
		}
		fwrite( STDERR, "See the README for setup requirements.\n" );
		exit( 1 );
	}
} )();
