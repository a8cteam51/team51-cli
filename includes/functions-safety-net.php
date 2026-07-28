<?php

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Output\OutputInterface;

// region CONSTANTS

const SAFETY_NET_ZIP_URL = 'https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip';

// endregion

// region API

/**
 * Returns the site root directory as the SSH shell sees it.
 *
 * Every exec starts a fresh shell at the login directory, and both layouts exist in the wild: most servers
 * expose the home-relative `htdocs` the previous install commands used, others only the absolute `/htdocs`.
 * Probed once per connection so every command in an install run agrees on the prefix.
 *
 * @param   SSH2 $ssh_connection The SSH connection to the site.
 *
 * @return  string
 */
function get_ssh_site_root_path( SSH2 $ssh_connection ): string {
	// A WeakMap rather than an id-keyed array: PHP reuses object ids, so a later connection could otherwise
	// inherit the root probed for a different site.
	static $roots = null;

	$roots ??= new WeakMap();
	if ( ! isset( $roots[ $ssh_connection ] ) ) {
		$result = $ssh_connection->exec( "test -d htdocs/wp-content && echo 'ROOT:REL' || echo 'ROOT:ABS'" );
		$result = is_string( $result ) ? $result : '';

		// An unreadable probe is not cached, and falls back to the common layout rather than pinning the rare
		// one for the rest of the connection's life.
		if ( ! str_contains( $result, 'ROOT:' ) ) {
			return 'htdocs';
		}

		$roots[ $ssh_connection ] = str_contains( $result, 'ROOT:REL' ) ? 'htdocs' : '/htdocs';
	}

	return $roots[ $ssh_connection ];
}

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
	$mu_plugins = get_ssh_site_root_path( $ssh_connection ) . '/wp-content/mu-plugins';
	$loader     = "$mu_plugins/load-safety-net.php";
	$plugin     = "$mu_plugins/safety-net/safety-net.php";

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
 * @param   SSH2        $ssh_connection The SSH connection to the site.
 * @param   string|null $failure_output Receives whatever `curl`/`unzip` printed when the install failed.
 *
 * @return  integer  The exit code of the download and unpack.
 */
function install_safety_net_files( SSH2 $ssh_connection, ?string &$failure_output = null ): int {
	$mu_plugins = get_ssh_site_root_path( $ssh_connection ) . '/wp-content/mu-plugins';

	// The command produces no output before the trailing echo unless something fails, and the connection's
	// default read timeout is 10 seconds - a download slower than that would truncate the output and lose the
	// INSTALL: marker. The generous but finite budget is restored afterwards so later commands on this
	// connection keep a ceiling.
	$ssh_connection->setTimeout( 600 );

	// The archive lands at a mktemp-allocated path rather than a fixed, predictable one that anything else
	// with write access to /tmp could pre-create between the download and the unpack. If mktemp is missing the
	// fallback names are created with `set -C`/`mkdir`, so a pre-existing target aborts the allocation instead
	// of being written through; an unusable target reports 126 rather than silently failing the download.
	//
	// The archive is unpacked into a staging directory and swapped in only once the unpack has succeeded, so
	// a host without `unzip` - or a corrupt archive - never destroys an installed copy it cannot replace. The
	// swap also clears the destination first, because `unzip -o`/`mv` into an existing directory merge rather
	// than replace and would leave files from an older release behind.
	$result = $ssh_connection->exec(
		'ZIP=$(mktemp 2>/dev/null) || { ZIP=/tmp/safety-net.$$.zip ; ( set -C ; : > "$ZIP" ) 2>/dev/null || ZIP="" ; }'
		. ' ; DIR=$(mktemp -d 2>/dev/null) || { DIR=/tmp/safety-net-stage.$$ ; mkdir "$DIR" 2>/dev/null || DIR="" ; }'
		. ' ; if [ -z "$ZIP" ] || [ -z "$DIR" ] ; then INSTALL=126 ; rm -rf "$ZIP" "$DIR" 2>/dev/null ; else'
		. " { curl -fsSL '" . SAFETY_NET_ZIP_URL . '\' -o "$ZIP"'
		. ' && unzip -o -q "$ZIP" -d "$DIR/"'
		. " && rm -rf '$mu_plugins/safety-net'"
		. ' && mv -f "$DIR/safety-net" \'' . $mu_plugins . '/safety-net\' ; } 2>&1'
		. ' ; INSTALL=$?'
		. ' ; rm -rf "$ZIP" "$DIR"'
		. ' ; fi'
		. ' ; echo "INSTALL:${INSTALL}"'
	);

	$ssh_connection->setTimeout( Abstract_Connection_Helper::SSH_TIMEOUT );

	$result = is_string( $result ) ? $result : '';

	// The exit code is reported verbatim by the caller, so a missing `curl`/`unzip` (127) stays
	// distinguishable from a download that failed. -1 means the marker never came back, which is a different
	// problem again: the command did not run to completion.
	$exit_code = preg_match( '/INSTALL:(\d+)/', $result, $matches ) ? (int) $matches[1] : -1;

	if ( 0 !== $exit_code ) {
		$failure_output = trim( (string) preg_replace( '/INSTALL:\d+\s*$/', '', $result ) );
	}

	return $exit_code;
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

	$mu_plugins = get_ssh_site_root_path( $ssh_connection ) . '/wp-content/mu-plugins';
	$loader     = "$mu_plugins/load-safety-net.php";
	$plugin     = "$mu_plugins/safety-net/safety-net.php";

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
 * @param   string  $site_url     The URL of the site to check.
 * @param   integer $max_attempts The maximum number of probes, 5 seconds apart.
 *
 * @return  boolean|null  Null if the site could not be reached or did not answer with a readable report.
 */
function is_safety_net_confirmed_via_http( string $site_url, int $max_attempts = 12 ): ?bool {
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

	$body        = false;
	$status_code = 0;

	// Retried on any non-200 as well as on transport failure: a freshly created hostname may not resolve on
	// the first try, and a clone still warming up answers 502 - both usually clear within seconds. This check
	// is the sole authority on the clone's verdict, so it gets patience comparable to the other waits on the
	// path rather than failing a 20-minute provisioning run on one hiccup.
	for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
		if ( 1 < $attempt ) {
			sleep( 5 );
		}

		// The `?rest_route=` form routes regardless of the permalink structure; the pretty `/wp-json/` prefix
		// only exists when permalinks are enabled, and a clone of a plain-permalink site would 404 on it and
		// fail verification on every run.
		$body = @file_get_contents( rtrim( $site_url, '/' ) . '/?rest_route=/safety-net/v1/status&_=' . time(), false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$response_headers = function_exists( 'http_get_last_response_headers' )
			? ( http_get_last_response_headers() ?? array() )
			: ( $http_response_header ?? array() );
		$status_code      = parse_http_headers( $response_headers )['http_code'] ?? 0;

		if ( is_string( $body ) && 200 === $status_code ) {
			break;
		}
	}

	if ( ! is_string( $body ) ) {
		return null;
	}

	// A non-200 answer - a 403 from a private staging site, a 502 during warm-up - says the status could not
	// be read, not that the site is unprotected, so it reports unknown just like an unreachable site does.
	// Only a readable 200 report gets to say either way.
	if ( 200 !== $status_code ) {
		return null;
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

	$failure_output = null;

	$exit_code = install_safety_net_files( $ssh_connection, $failure_output );
	if ( 0 !== $exit_code ) {
		// -1 is the no-marker sentinel, not an exit status: the command did not run to completion.
		$output->writeln(
			-1 === $exit_code
				? '<comment>Downloading SafetyNet over SSH produced no readable result.</comment>'
				: "<comment>Downloading SafetyNet over SSH failed with exit code $exit_code.</comment>"
		);
		if ( '' !== (string) $failure_output ) {
			$output->writeln( '<comment>' . \Symfony\Component\Console\Formatter\OutputFormatter::escape( $failure_output ) . '</comment>' );
		}
		$output->writeln( '<comment>Falling back to installing SafetyNet through WP-CLI...</comment>' );

		// Downloading the release and booting WordPress regularly outlasts the 10-second read ceiling the
		// download step restored, so it is lifted for the fallback and put back after.
		$ssh_connection->setTimeout( 600 );

		// Run on the connection directly: the exit codes below are the remote ones, so each step reports its
		// own failure. getExitStatus() returns false when the channel closed without an exit-status message -
		// which is what a signal kill produces, the SIGSYS case this fallback exists for - so that outcome is
		// named rather than treated as success.
		$ssh_connection->exec( 'wp plugin install ' . SAFETY_NET_ZIP_URL );
		$install_code = $ssh_connection->getExitStatus();
		if ( false === $install_code ) {
			$output->writeln( '<error>Installing SafetyNet through WP-CLI returned no exit status; it may have been killed.</error>' );
		} elseif ( 0 !== $install_code ) {
			$output->writeln( "<error>Installing SafetyNet through WP-CLI failed with exit code $install_code.</error>" );
		}

		// `mv` moves the source *into* an existing destination directory rather than replacing it, which would
		// nest the plugin one level too deep - and exit 0 while doing so. The destination is cleared first,
		// but only once the source is known to exist, so a failed install never destroys what is already
		// there without a replacement. A missing source is reported as such, not as a failed move.
		$site_root   = get_ssh_site_root_path( $ssh_connection );
		$plugin_src  = "$site_root/wp-content/plugins/safety-net";
		$plugin_dest = "$site_root/wp-content/mu-plugins/safety-net";

		$move_output = $ssh_connection->exec( "if test -d '$plugin_src' ; then rm -rf '$plugin_dest' && mv -f '$plugin_src' '$plugin_dest' ; else echo 'MOVE:skipped' ; fi" );
		$move_code   = $ssh_connection->getExitStatus();
		if ( is_string( $move_output ) && str_contains( $move_output, 'MOVE:skipped' ) ) {
			$output->writeln( '<comment>Nothing to move into mu-plugins: WP-CLI did not produce the plugin directory.</comment>' );
		} elseif ( false !== $move_code && 0 !== $move_code ) {
			$output->writeln( "<error>Moving SafetyNet into mu-plugins failed with exit code $move_code.</error>" );
		}

		$ssh_connection->setTimeout( Abstract_Connection_Helper::SSH_TIMEOUT );
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
