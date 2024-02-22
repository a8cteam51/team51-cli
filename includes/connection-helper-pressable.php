<?php

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Handles the connection and authentication to Pressable sites via SSH or SFTP.
 */
final class Pressable_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The SSH URL.
	 */
	public const SSH_HOST = 'ssh.atomicsites.net';

	/**
	 * The SFTP URL.
	 */
	public const SFTP_HOST = 'sftp.pressable.com';

	// endregion

	// region METHODS

	/**
	 * Opens a new SFTP connection to a Pressable site.
	 *
	 * @param   string $site_id_or_url The ID or URL of the Pressable site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_sftp_connection( string $site_id_or_url ): ?SFTP {
		$credentials = self::get_credentials( $site_id_or_url );
		if ( \is_null( $credentials ) ) {
			return null;
		}

		$connection = new SFTP( self::SFTP_HOST );
		if ( ! $connection->login( $credentials->username, $credentials->password ) ) {
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		return $connection;
	}

	/**
	 * Opens a new SSH connection to a Pressable site.
	 *
	 * @param   string $site_id_or_url The ID or URL of the Pressable site to open a connection to.
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
	 * Returns the SFTP/SSH login data for the concierge user on a given Pressable site.
	 *
	 * @param   string $site_id_or_url The ID or URL of the Pressable site to get the login data for.
	 *
	 * @return  stdClass|null
	 */
	private static function get_credentials( string $site_id_or_url ): ?stdClass {
		static $cache = array();

		if ( empty( $cache[ $site_id_or_url ] ) ) {
			$collaborator = get_pressable_site_sftp_user_by_email( $site_id_or_url, 'concierge@wordpress.com' );
			if ( \is_null( $collaborator ) ) {
				console_writeln( '❌ Could not find the Pressable site collaborator.' );
				return null;
			}

			$cache[ $site_id_or_url ] = rotate_pressable_site_sftp_user_password( $site_id_or_url, $collaborator->username );
		}

		return $cache[ $site_id_or_url ];
	}

	// endregion
}
