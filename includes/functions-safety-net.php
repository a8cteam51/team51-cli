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
	$site_root  = get_ssh_site_root_path( $ssh_connection );
	$mu_plugins = "$site_root/wp-content/mu-plugins";

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
	// The archive is unpacked into a staging directory and swapped in only once the staged copy is proven to
	// be the replacement: `unzip` exiting 0 does not guarantee a `safety-net/` root (a re-rooted release
	// archive extracts cleanly without one), so the directory itself is tested before anything is destroyed.
	// The site root is required to already hold a `wp-content` before the mu-plugins parent is created inside
	// it - `mv`, unlike the `unzip -d` this replaced, creates nothing - so a mis-resolved prefix stops the
	// chain instead of fabricating a directory tree the site never reads. The swap clears the destination
	// because `unzip -o`/`mv` into an existing directory merge rather than replace and would leave files from
	// an older release behind.
	$result = $ssh_connection->exec(
		'ZIP=$(mktemp 2>/dev/null) || { ZIP=/tmp/safety-net.$$.zip ; ( set -C ; : > "$ZIP" ) 2>/dev/null || ZIP="" ; }'
		. ' ; DIR=$(mktemp -d 2>/dev/null) || { DIR=/tmp/safety-net-stage.$$ ; mkdir "$DIR" 2>/dev/null || DIR="" ; }'
		. ' ; if [ -z "$ZIP" ] || [ -z "$DIR" ] ; then INSTALL=126 ; rm -rf "$ZIP" "$DIR" 2>/dev/null ; else'
		. " { curl -fsSL '" . SAFETY_NET_ZIP_URL . '\' -o "$ZIP"'
		. ' && unzip -o -q "$ZIP" -d "$DIR/"'
		. ' && test -d "$DIR/safety-net"'
		. " && test -d '$site_root/wp-content'"
		. " && mkdir -p '$mu_plugins'"
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
 * Returns the value of a constant in a site's wp-config.php, as the JSON WP-CLI prints for it.
 *
 * Read as JSON because the plain format prints an empty line for `false`, which cannot be told apart from an
 * empty string - and `false` is the one value Safety Net acts on. `wp config has` answers only through its exit
 * code, which the runners do not pass on, so an undefined constant is recognised by the `Error:` WP-CLI prints
 * for it instead. Remote output can carry PHP notices and WP-CLI messages on either side of the value, so the
 * lines are read from the end, stepping over those, and the first other line is the value if it is JSON.
 *
 * @param   callable $run_wp_cli Runs one WP-CLI command on the site and returns its output.
 * @param   string   $name       The name of the constant.
 *
 * @return  string|false|null  Null if WP-CLI says the constant is not defined, false if the reply could not be read.
 */
function get_wp_config_constant_json( callable $run_wp_cli, string $name ): string|false|null {
	$reply = (string) $run_wp_cli( "config get $name --type=constant --format=json" );
	if ( is_wp_cli_error_output( $reply ) ) {
		// Any other error, such as an older WP-CLI rejecting --format, says nothing about whether it is defined.
		return str_contains( $reply, 'is not defined' ) ? null : false;
	}

	$lines = array_reverse( array_filter( array_map( 'trim', preg_split( '/\R/', $reply ) ), 'strlen' ) );
	foreach ( $lines as $line ) {
		foreach ( array( 'Warning:', 'Success:', 'Deprecated:', 'Notice:', 'PHP ' ) as $prefix ) {
			if ( str_starts_with( $line, $prefix ) ) {
				continue 2;
			}
		}

		return json_validate( $line ) ? $line : false;
	}

	return false;
}

/**
 * Writes a constant to a site's wp-config.php and reads it back.
 *
 * Booleans are written with `--raw`: without it WP-CLI writes the string 'false', which Safety Net ignores. A
 * new constant is inserted above the `That's all, stop editing!` comment, ahead of the wp-settings.php require.
 * A wp-config.php without that anchor fails with an `Error:` and is deliberately not retried with
 * `--anchor=EOF`: at the end of the file the constant would only be defined once WordPress has loaded.
 *
 * @param   callable       $run_wp_cli Runs one WP-CLI command on the site and returns its output.
 * @param   string         $name       The name of the constant.
 * @param   boolean|string $value      The value to write.
 *
 * @return  string|null  Why the constant is not in place, or null if it reads back as written.
 */
function set_wp_config_constant( callable $run_wp_cli, string $name, bool|string $value ): ?string {
	$command = is_bool( $value )
		? "config set $name " . ( $value ? 'true' : 'false' ) . ' --raw --type=constant'
		: "config set $name $value --type=constant";

	$reply = (string) $run_wp_cli( $command );
	if ( is_wp_cli_error_output( $reply ) ) {
		// WP-CLI explains a failed wp-config.php transformation, such as a missing anchor, on a `Reason:` line.
		preg_match( '/^\s*Error:\s*(.*?)\.?\s*$/m', $reply, $error );
		preg_match( '/^\s*Reason:\s*(.*?)\.?\s*$/m', $reply, $reason );
		$details = implode( ': ', array_filter( array( $error[1] ?? 'WP-CLI reported an error', $reason[1] ?? '' ), 'strlen' ) );
		return "writing $name failed ($details)";
	}

	$expected  = json_encode( $value );
	$read_back = get_wp_config_constant_json( $run_wp_cli, $name );

	return match ( true ) {
		$expected === $read_back => null,
		null === $read_back => "$name is not defined after writing it",
		false === $read_back => "$name could not be read back after writing it",
		default => "$name reads back as $read_back instead of $expected",
	};
}

/**
 * Writes or removes SAFETY_NET_DELETE_DATA and SAFETY_NET_KEEP_UNTIL in a new clone's wp-config.php, before anything loads WordPress there.
 *
 * Safety Net decides once, on its first run on the clone, whether the users, orders and subscriptions are kept,
 * and that run fires on whatever loads WordPress first. WP-CLI's `config` commands do not load it, so they can
 * settle the constants beforehand. A request that cannot be put in place fails closed: the constants are
 * removed again and Safety Net scrubs the clone as usual, rather than the run being aborted with production
 * data already copied and nothing left to scrub it.
 *
 * @param   callable        $run_wp_cli Runs one WP-CLI command on the clone and returns its output.
 * @param   boolean         $keep_data  Whether to keep the clone's users, orders and subscriptions.
 * @param   string|null     $keep_until The YYYY-MM-DD date until which to keep them, if any.
 * @param   OutputInterface $output     The output instance.
 *
 * @return  boolean  Whether the requested keep settings are in place; false when none were requested.
 */
function configure_safety_net_keep_data( callable $run_wp_cli, bool $keep_data, ?string $keep_until, OutputInterface $output ): bool {
	// An expiry date only qualifies a request to keep the data; on its own it means nothing to Safety Net.
	$keep_until = $keep_data ? $keep_until : null;

	// The copy carries the source site's wp-config.php over, so a keep constant defined there would decide the
	// clone's fate without anyone having asked for it on this run. A leftover expiry date is removed even when
	// the data is kept, or a request to keep it with no expiry would quietly inherit the source's date.
	$problem  = null;
	$unwanted = array_keys(
		array_filter(
			array(
				'SAFETY_NET_DELETE_DATA' => ! $keep_data,
				'SAFETY_NET_KEEP_UNTIL'  => null === $keep_until,
			)
		)
	);
	foreach ( $unwanted as $name ) {
		// An unreadable reply does not prove the constant absent, so it is deleted anyway and reported if unconfirmed.
		$value = get_wp_config_constant_json( $run_wp_cli, $name );
		if ( null === $value ) {
			continue;
		}

		$reply = (string) $run_wp_cli( "config delete $name --type=constant" );
		$gone  = null === get_wp_config_constant_json( $run_wp_cli, $name );
		$shown = \Symfony\Component\Console\Formatter\OutputFormatter::escape( false === $value ? 'unreadable value' : $value );
		if ( $gone && is_wp_cli_success_output( $reply ) ) {
			$output->writeln( "<comment>Removed $name ($shown) inherited from the source site's wp-config.php.</comment>" );
		} elseif ( $gone && false === $value ) {
			continue;
		} elseif ( $keep_data ) {
			$problem ??= false === $value
				? "$name in the clone's wp-config.php could not be read"
				: "$name ($value) inherited from the source site's wp-config.php could not be removed";
		} elseif ( false === $value ) {
			$output->writeln( "<error>Could not read $name in the clone's wp-config.php or confirm it is absent; the clone may keep the source site's customer data.</error>" );
		} else {
			$output->writeln( "<error>Could not remove $name ($shown) inherited from the source site's wp-config.php; the clone may keep the source site's customer data.</error>" );
		}
	}

	if ( ! $keep_data ) {
		return false;
	}

	$problem ??= set_wp_config_constant( $run_wp_cli, 'SAFETY_NET_DELETE_DATA', false );
	if ( null !== $keep_until ) {
		$problem ??= set_wp_config_constant( $run_wp_cli, 'SAFETY_NET_KEEP_UNTIL', $keep_until );
	}

	if ( null === $problem ) {
		$written = 'SAFETY_NET_DELETE_DATA=false' . ( null === $keep_until ? '' : " and SAFETY_NET_KEEP_UNTIL=$keep_until" );
		$output->writeln( "<fg=green;options=bold>Wrote $written to the clone's wp-config.php.</>" );
		return true;
	}

	// SAFETY_NET_DELETE_DATA goes even when only the date failed: on its own it keeps the data with no expiry at
	// all, which is more than was asked for. Best effort - whatever stays behind, the final verdict reports.
	$run_wp_cli( 'config delete SAFETY_NET_DELETE_DATA --type=constant' );
	$run_wp_cli( 'config delete SAFETY_NET_KEEP_UNTIL --type=constant' );
	$cleared = null === get_wp_config_constant_json( $run_wp_cli, 'SAFETY_NET_DELETE_DATA' );

	$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );
	$output->writeln( '<error>⚠  The request to keep customer data was NOT applied: ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape( $problem ) . '.</error>' );
	$output->writeln(
		$cleared
			? "<error>    Safety Net will delete the clone's users, orders and subscriptions as usual.</error>"
			: '<error>    Removing SAFETY_NET_DELETE_DATA again could not be confirmed; the Safety Net check at the end of the run reports what the clone keeps.</error>'
	);
	$output->writeln( '<error>════════════════════════════════════════════════════════════════</error>' );

	return false;
}

/**
 * Returns why a Safety Net status report does not confirm the clone is safe to hand off, or null when it does.
 *
 * A clone asked to keep its data is held to more than the absence of a deletion: the report has to say that
 * the keep step ran and that the constants still ask for it. Safety Net before 1.10.0 ignores the constants and
 * omits those keys, so a missing key is named as an outdated plugin rather than read as a falsy flag.
 *
 * @param   array   $report           The decoded status report.
 * @param   boolean $expect_kept_data Whether the clone was asked to keep its users, orders and subscriptions.
 *
 * @return  string|null
 */
function get_safety_net_report_problem( array $report, bool $expect_kept_data ): ?string {
	// Mirrors the plugin's own semantics rather than a narrower allowlist: Safety Net treats every environment
	// except `production` as non-production - including `sandbox`/`dev`/`develop`, which it reads from the
	// server environment and which core's allowlist would not pass - and it bails on production before the
	// status route is even registered, so the route answering already implies non-production. An empty or
	// missing value still fails closed.
	$environment = (string) ( $report['environment'] ?? '' );

	if ( empty( $report['active'] ) || '' === $environment || 'production' === $environment || empty( $report['options_scrubbed'] ) ) {
		return 'Safety Net has not run on the clone.';
	}

	// Once the data is deleted the clone holds none of it, whatever the constants say by now.
	if ( ! $expect_kept_data ) {
		return match ( true ) {
			! empty( $report['data_deleted'] ) => null,
			! empty( $report['data_deletion_disabled'] ) || ! empty( $report['data_kept'] ) => "The clone keeps the source site's customer data, which was not asked for: SAFETY_NET_DELETE_DATA is false in its wp-config.php.",
			default => "Safety Net has not deleted the clone's customer data yet.",
		};
	}

	$reports_keep_step = array_key_exists( 'data_kept', $report )
		&& array_key_exists( 'data_deletion_disabled', $report )
		&& array_key_exists( 'keep_until', $report );

	return match ( true ) {
		! $reports_keep_step => "The clone's Safety Net is older than 1.10.0, so it ignored SAFETY_NET_DELETE_DATA and deleted the customer data.",
		! empty( $report['data_deleted'] ) => 'Safety Net deleted the customer data before the keep request was applied, because something loaded the clone first. Clone again if you need the data.',
		true !== $report['data_kept'] || true !== $report['data_deletion_disabled'] => "Safety Net has not kept the clone's customer data as asked.",
		default => null,
	};
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
 * @param   string               $site_url         The URL of the site to check.
 * @param   OutputInterface|null $output           The output instance, for announcing the poll.
 * @param   integer              $max_attempts     The maximum number of probes, 5 seconds apart.
 * @param   boolean              $expect_kept_data Whether the clone was asked to keep its users, orders and subscriptions.
 * @param   string|null          $problem          Receives why the report does not confirm the clone, if it does not.
 *
 * @return  boolean|null  Null if the site could not be reached or did not answer with a readable report.
 */
function is_safety_net_confirmed_via_http( string $site_url, ?OutputInterface $output = null, int $max_attempts = 12, bool $expect_kept_data = false, ?string &$problem = null ): ?bool {
	$problem = null;

	// Announced because the poll is otherwise silent for up to a few minutes at the very end of a run, which
	// reads as a hang.
	$output?->writeln( "<comment>Checking the Safety Net status endpoint on $site_url (up to $max_attempts probes, 5 seconds apart).</comment>" );
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

	// A 200 whose body is not the JSON report - an HTML page from a cache layer or interceptor - means the
	// status could not be read, the same unknown the transport and non-200 branches report.
	$report = json_decode( $body, true );
	if ( ! is_array( $report ) ) {
		return null;
	}

	$problem = get_safety_net_report_problem( $report, $expect_kept_data );

	return null === $problem;
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

		$move_output = $ssh_connection->exec( "if test -d '$plugin_src' ; then test -d '$site_root/wp-content' && mkdir -p '$site_root/wp-content/mu-plugins' && rm -rf '$plugin_dest' && mv -f '$plugin_src' '$plugin_dest' ; else echo 'MOVE:skipped' ; fi" );
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
