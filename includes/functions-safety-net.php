<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Output\OutputInterface;

// region CONSTANTS

// The mu-plugins directory sits at the same absolute path on both Pressable and WordPress.com Atomic servers.
const SAFETY_NET_MU_PLUGINS_PATH = '/htdocs/wp-content/mu-plugins';

// The file the loader requires. Testing for it rather than the directory keeps a half-unpacked archive from
// reading as a working install.
const SAFETY_NET_PLUGIN_ENTRY_FILE = SAFETY_NET_MU_PLUGINS_PATH . '/safety-net/safety-net.php';

const SAFETY_NET_ZIP_URL = 'https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip';

// endregion

// region API

/**
 * Returns whether both Safety Net and its loader are present in the mu-plugins directory of a site.
 *
 * Both artifacts are checked separately because the loader alone is inert: it only requires Safety Net if the
 * plugin is there next to it, so a site carrying just the loader is not protected. The plugin is identified by
 * the exact entry file the loader requires, so a half-unpacked directory does not read as a working install.
 *
 * @param   SSH2 $ssh_connection The SSH connection to the site.
 *
 * @return  boolean|null  True/false if it could be determined, null if the check produced no readable result.
 */
function is_safety_net_installed( SSH2 $ssh_connection ): ?bool {
	$loader = SAFETY_NET_MU_PLUGINS_PATH . '/load-safety-net.php';
	$plugin = SAFETY_NET_PLUGIN_ENTRY_FILE;

	$result = $ssh_connection->exec( "test -f '$loader' && test -f '$plugin' && echo 'FILES:1' || echo 'FILES:0'" );
	$result = is_string( $result ) ? trim( $result ) : '';

	return match ( true ) {
		str_contains( $result, 'FILES:1' ) => true,
		str_contains( $result, 'FILES:0' ) => false,
		default => null,
	};
}

/**
 * Downloads Safety Net straight into the mu-plugins directory of a site.
 *
 * Nothing here boots WordPress. A freshly cloned site kills any WordPress-booting command with SIGSYS under
 * Pressable's SSH seccomp profile - `wp plugin install` included - so the release zip is unpacked in place.
 *
 * @param   SSH2 $ssh_connection The SSH connection to the site.
 *
 * @return  integer  The exit code of the download and unpack.
 */
function install_safety_net_files( SSH2 $ssh_connection ): int {
	// The command is silent until the trailing echo, and the connection's default read timeout is 10 seconds -
	// a download slower than that would truncate the output and lose the INSTALL: marker.
	$ssh_connection->setTimeout( 0 );

	$result = $ssh_connection->exec(
		"curl -fsSL '" . SAFETY_NET_ZIP_URL . "' -o /tmp/safety-net.zip 2>/dev/null"
		. ' && unzip -o /tmp/safety-net.zip -d ' . SAFETY_NET_MU_PLUGINS_PATH . '/ >/dev/null 2>&1'
		. ' ; INSTALL=$?'
		. ' ; rm -f /tmp/safety-net.zip'
		. ' ; echo "INSTALL:${INSTALL}"'
	);
	$result = is_string( $result ) ? $result : '';

	// The exit code is reported verbatim by the caller, so a missing `curl`/`unzip` (127) stays
	// distinguishable from a download that failed. -1 means the marker never came back, which is a different
	// problem again: the command did not run to completion.
	return preg_match( '/INSTALL:(\d+)/', $result, $matches ) ? (int) $matches[1] : -1;
}

/**
 * Writes the Safety Net loader into the mu-plugins directory of a site, or clears a stray one.
 *
 * The loader is only written when Safety Net itself is in place, and removed when it is not: on its own the
 * loader is inert but still lists as an enabled mu-plugin, which is what makes a failed install look like a
 * successful one. The decision is made on the server so an unreadable check never deletes a live loader. A
 * quoted heredoc is used so the scaffold lands byte for byte, without needing a second SFTP connection.
 *
 * @param   SSH2 $ssh_connection The SSH connection to the site.
 *
 * @return  boolean  False if the loader scaffold could not be read.
 */
function write_safety_net_loader( SSH2 $ssh_connection ): bool {
	$loader_contents = file_get_contents( __DIR__ . '/../scaffold/load-safety-net.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $loader_contents ) {
		return false;
	}

	$loader = SAFETY_NET_MU_PLUGINS_PATH . '/load-safety-net.php';
	$plugin = SAFETY_NET_PLUGIN_ENTRY_FILE;

	$ssh_connection->exec(
		"if test -f '$plugin' ; then cat > '$loader' <<'TEAM51_SAFETY_NET_LOADER'\n"
		. rtrim( $loader_contents ) . "\n"
		. "TEAM51_SAFETY_NET_LOADER\n"
		. "else rm -f '$loader' ; fi\n"
	);

	return true;
}

/**
 * Returns whether a site reports that Safety Net has actually run and scrubbed it.
 *
 * This is the authoritative check: unlike the file listing, it confirms that Safety Net booted and did its
 * work. Never follows a redirect off the site being verified.
 *
 * A site that could not be reached at all is reported as unknown rather than unprotected - a brand-new clone
 * hostname may not resolve yet - so callers can say they could not verify instead of asserting the site holds
 * unscrubbed data. A site that does answer fails closed on anything unexpected.
 *
 * @param   string $site_url The URL of the site to check.
 *
 * @return  boolean|null  Null if the site could not be reached.
 */
function is_safety_net_confirmed_via_http( string $site_url ): ?bool {
	if ( ! preg_match( '#^https?://#i', $site_url ) ) {
		$site_url = "https://$site_url";
	}

	// A clone whose database still points at the production URL answers with a redirect, so following one here
	// would verify the production site instead of the clone.
	$context = stream_context_create(
		array(
			'http' => array(
				'header'          => 'Cache-Control: no-cache',
				'method'          => 'GET',
				'timeout'         => 10,
				'follow_location' => 0,
				'ignore_errors'   => true,
			),
		)
	);

	$body             = false;
	$response_headers = array();

	for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
		if ( 1 < $attempt ) {
			sleep( 5 ); // A freshly created hostname may not resolve on the first try.
		}

		$body = @file_get_contents( rtrim( $site_url, '/' ) . '/wp-json/safety-net/v1/status?_=' . time(), false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$response_headers = function_exists( 'http_get_last_response_headers' )
			? ( http_get_last_response_headers() ?? array() )
			: ( $http_response_header ?? array() );

		if ( is_string( $body ) ) {
			break;
		}
	}

	if ( ! is_string( $body ) ) {
		return null;
	}

	if ( 200 !== ( parse_http_headers( $response_headers )['http_code'] ?? 0 ) ) {
		return false;
	}

	$report = json_decode( $body, true );
	if ( ! is_array( $report ) ) {
		return false;
	}

	// Allowlist the environment rather than rejecting the literal `production`, so an empty or unrecognized
	// value fails closed too.
	return in_array( $report['environment'] ?? '', array( 'staging', 'development', 'local' ), true )
		&& ! empty( $report['options_scrubbed'] )
		&& ! empty( $report['data_deleted'] );
}

/**
 * Installs Safety Net on a site if it is not already installed, then verifies that the files are in place.
 *
 * If the download-and-unpack install fails - for example because the server has no `curl` or `unzip` - the
 * install is retried through WP-CLI over the same connection.
 *
 * @param   SSH2|null       $ssh_connection The SSH connection to the site, if one could be established.
 * @param   OutputInterface $output         The output instance.
 *
 * @return  boolean  Whether both Safety Net and its loader are in place afterwards.
 */
function maybe_install_safety_net( ?SSH2 $ssh_connection, OutputInterface $output ): bool {
	if ( is_null( $ssh_connection ) ) {
		$output->writeln( '<error>Failed to connect to the site via SSH. Cannot install SafetyNet!</error>' );
		return false;
	}

	$installed = is_safety_net_installed( $ssh_connection );
	if ( true === $installed ) {
		$output->writeln( '<comment>SafetyNet is already installed as a mu-plugin. Skipping installation...</comment>' );
		return true;
	}

	if ( is_null( $installed ) ) {
		$output->writeln( '<comment>Could not read the mu-plugins directory. Attempting the SafetyNet installation anyway...</comment>' );
	}

	$exit_code = install_safety_net_files( $ssh_connection );
	if ( 0 !== $exit_code ) {
		$output->writeln( "<comment>Downloading SafetyNet over SSH failed with exit code $exit_code.</comment>" );
		$output->writeln( '<comment>Falling back to installing SafetyNet through WP-CLI...</comment>' );

		// Run on the connection directly: the exit codes below are the remote ones, so each step reports its
		// own failure. getExitStatus() returns false when the server sent no exit-status message, which is
		// not a failure - reporting it as `exit code ` would read as one.
		$ssh_connection->exec( 'wp plugin install ' . SAFETY_NET_ZIP_URL );
		$install_code = $ssh_connection->getExitStatus();
		if ( false !== $install_code && 0 !== $install_code ) {
			$output->writeln( "<error>Installing SafetyNet through WP-CLI failed with exit code $install_code.</error>" );
		}

		$ssh_connection->exec( 'mv -f /htdocs/wp-content/plugins/safety-net ' . SAFETY_NET_MU_PLUGINS_PATH . '/safety-net' );
		$move_code = $ssh_connection->getExitStatus();
		if ( false !== $move_code && 0 !== $move_code ) {
			$output->writeln( "<error>Moving SafetyNet into mu-plugins failed with exit code $move_code.</error>" );
		}
	}

	if ( ! write_safety_net_loader( $ssh_connection ) ) {
		$output->writeln( '<error>Could not read the SafetyNet loader scaffold!</error>' );
	}

	$installed = is_safety_net_installed( $ssh_connection );
	if ( is_null( $installed ) ) {
		$output->writeln( '<error>Could not verify the SafetyNet installation.</error>' );
		return false;
	}
	if ( false === $installed ) {
		$output->writeln( '<error>Failed to install SafetyNet!</error>' );
		return false;
	}

	$output->writeln( '<fg=green;options=bold>SafetyNet installed as a mu-plugin.</>' );
	return true;
}

// endregion
