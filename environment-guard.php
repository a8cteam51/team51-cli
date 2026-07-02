<?php
/**
 * Pre-autoload environment guard.
 *
 * Runs from every entry point (team51-cli.php, mcp-server.php) before the Composer autoloader,
 * enforcing the project's declared floor — PHP 8.3+ and the gd/json/posix/readline extensions —
 * so an unsupported runtime fails with a clear message instead of a cryptic fatal deep in a
 * dependency. Install and self-update run `composer dump-autoload --ignore-platform-reqs`, which
 * drops composer's generated platform_check.php, so this is the sole platform gate at startup.
 * It checks the project floor only, not the PHP version any installed dependency requires.
 * Uses only built-in functions, since it runs before autoload.
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
