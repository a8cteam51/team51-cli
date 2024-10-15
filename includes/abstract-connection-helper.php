<?php

use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Encapsulates the logic for handling connections and authentications to remote servers.
 */
abstract class Abstract_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The SSH URL.
	 */
	public const SSH_HOST = null;

	/**
	 * The SFTP URL.
	 */
	public const SFTP_HOST = null;

	// endregion

	// region METHODS

	/**
	 * Opens a new SFTP connection to the given site.
	 *
	 * @param   string $site_identifier The site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_sftp_connection( string $site_identifier ): ?SFTP {
		$credentials = static::get_credentials( $site_identifier );
		if ( \is_null( $credentials ) ) {
			return null;
		}

		$connection = new SFTP( static::SFTP_HOST );
		if ( ! $connection->login( $credentials->username, $credentials->password ) ) {
			$connection->isConnected() && $connection->disconnect();
			return null;
		}

		return $connection;
	}

	/**
	 * Opens a new SSH connection to the given site.
	 *
	 * @param   string $site_identifier The site to open a connection to.
	 *
	 * @return  SFTP|null
	 */
	public static function get_ssh_connection( string $site_identifier ): ?SSH2 {
		$credentials = static::get_credentials( $site_identifier );
		if ( \is_null( $credentials ) ) {
			return null;
		}

		$connection = new SSH2( static::SSH_HOST );
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
	 * Returns the SFTP/SSH login data for the requested site.
	 *
	 * @param   string $site_identifier The site to retrieve the credentials for.
	 *
	 * @return  stdClass|null
	 */
	abstract protected static function get_credentials( string $site_identifier ): ?stdClass;

	// endregion
}
