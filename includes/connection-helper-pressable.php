<?php

/**
 * Handles the connection and authentication to Pressable sites via SSH or SFTP.
 */
final class Pressable_Connection_Helper extends Abstract_Connection_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * {@inheritdoc}
	 */
	public const SSH_HOST = 'ssh.atomicsites.net';

	/**
	 * {@inheritdoc}
	 */
	public const SFTP_HOST = 'sftp.pressable.com';

	// endregion

	// region HELPERS

	/**
	 * {@inheritdoc}
	 */
	protected static function get_credentials( string $site_identifier ): ?stdClass {
		static $cache = array();

		if ( empty( $cache[ $site_identifier ] ) ) {
			$sftp_user = get_pressable_site_sftp_user( $site_identifier, 'concierge@wordpress.com' );
			if ( \is_null( $sftp_user ) ) {
				console_writeln( '❌ Could not find the Pressable site SFTP user.' );
				return null;
			}

			$cache[ $site_identifier ] = rotate_pressable_site_sftp_user_password( $site_identifier, $sftp_user->username );
		}

		return $cache[ $site_identifier ];
	}

	// endregion
}
