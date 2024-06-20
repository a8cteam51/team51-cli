<?php

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Handles the connection and authentication to WordPress.com sites via SSH or SFTP.
 */
final class WPCOM_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The SSH URL.
	 */
	public const SSH_HOST = 'ssh.atomicsites.net';

	// endregion

	// region METHODS

	/**
	 * Opens a new SSH connection to a WordPress.com site.
	 *
	 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_ssh_connection( string $site_id_or_url ): ?SSH2 {
		$credentials = self::get_credentials( $site_id_or_url );
		if ( \is_null( $credentials ) ) {
			return null;
		}

		$connection = new SSH2( self::SSH_HOST );
		if ( ! $connection->login( $credentials->username, $credentials->password ) ) {
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		// Shortly after a new site is created, the server does not support SSH commands yet, but it will still accept
		// and authenticate the connection. We need to wait a bit before we can actually run commands. So the following
		// lines are a short hack to check if the server is indeed ready.
		$response = $connection->exec( 'ls -la' );
		if ( "This service allows sftp connections only.\n" === $response || 0 !== $connection->getExitStatus() ) {
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		return $connection;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the SFTP/SSH login data for the concierge user on a given WordPress.com site.
	 *
	 * @param   string $site_id_or_url The ID or URL of the WordPress.com site to get the login data for.
	 *
	 * @return  stdClass|null
	 */
	private static function get_credentials( string $site_id_or_url ): ?stdClass {
		static $cache = array();

		if ( empty( $cache[ $site_id_or_url ] ) ) {
			$ssh_username = get_wpcom_site_ssh_username( $site_id_or_url );
			if ( \is_null( $ssh_username ) ) {
				console_writeln( '❌ Could not find the WordPress.com site SSH username.' );
				return null;
			}

			$cache[ $site_id_or_url ] = rotate_wpcom_site_sftp_user_password( $site_id_or_url, $ssh_username );
		}

		return $cache[ $site_id_or_url ];
	}

	// endregion
}
