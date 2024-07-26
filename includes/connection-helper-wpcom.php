<?php

/**
 * Handles the connection and authentication to WordPress.com sites via SSH or SFTP.
 */
final class WPCOM_Connection_Helper extends Abstract_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	public const SSH_HOST = 'ssh.atomicsites.net';

	/**
	 * {@inheritdoc}
	 */
	public const SFTP_HOST = 'sftp.wp.com';

	// endregion

	// region HELPERS

	/**
	 * {@inheritdoc}
	 */
	protected static function get_credentials( string $site_identifier ): ?stdClass {
		static $cache = array();

		if ( empty( $cache[ $site_identifier ] ) ) {
			$ssh_username = get_wpcom_site_ssh_username( $site_identifier );
			if ( \is_null( $ssh_username ) ) {
				console_writeln( '❌ Could not find the WordPress.com site SSH username.' );
				return null;
			}

			$cache[ $site_identifier ] = rotate_wpcom_site_sftp_user_password( $site_identifier, $ssh_username );
		}

		return $cache[ $site_identifier ];
	}

	// endregion
}
