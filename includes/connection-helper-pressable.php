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

	/**
	 * The email of the collaborator whose SFTP user the CLI connects as.
	 */
	private const SFTP_USER_EMAIL = 'concierge@wordpress.com';

	// endregion

	// region HELPERS

	/**
	 * {@inheritdoc}
	 */
	protected static function get_credentials( string $site_identifier ): ?stdClass {
		static $cache = array();

		if ( empty( $cache[ $site_identifier ] ) ) {
			$sftp_user = get_pressable_site_sftp_user( $site_identifier, self::SFTP_USER_EMAIL );
			if ( \is_null( $sftp_user ) ) {
				// Callers retry in a loop, so the reason is reported once by the provisioning attempt rather
				// than on every pass.
				$sftp_user = self::provision_sftp_user( $site_identifier );
			}
			if ( \is_null( $sftp_user ) ) {
				return null;
			}

			$cache[ $site_identifier ] = rotate_pressable_site_sftp_user_password( $site_identifier, $sftp_user->username );
		}

		return $cache[ $site_identifier ];
	}

	/**
	 * Creates the concierge collaborator on a site and waits for its SFTP user to be provisioned.
	 *
	 * A freshly cloned site does not always inherit the collaborator, which used to leave every SSH and SFTP
	 * connection to it failing with `SFTP user not found.` until someone added the collaborator by hand.
	 * Creation is attempted only once per site because callers retry `get_credentials()` in a loop.
	 *
	 * @param   string $site_identifier The site to provision the SFTP user on.
	 *
	 * @return  stdClass|null
	 */
	private static function provision_sftp_user( string $site_identifier ): ?stdClass {
		static $attempted = array();

		if ( isset( $attempted[ $site_identifier ] ) ) {
			return null;
		}
		$attempted[ $site_identifier ] = true;

		console_writeln( '⏳ No Pressable SFTP user found for ' . self::SFTP_USER_EMAIL . '. Creating the collaborator...' );
		if ( \is_null( create_pressable_site_collaborator( $site_identifier, self::SFTP_USER_EMAIL ) ) ) {
			console_writeln( '❌ Could not create the Pressable site collaborator.' );
			return null;
		}

		// Pressable provisions the SFTP user asynchronously, so it is not returned by the API right away.
		for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
			\sleep( 5 );

			$sftp_user = get_pressable_site_sftp_user( $site_identifier, self::SFTP_USER_EMAIL );
			if ( ! \is_null( $sftp_user ) ) {
				return $sftp_user;
			}
		}

		console_writeln( '❌ The Pressable site SFTP user was created but did not become available in time.' );
		return null;
	}

	// endregion
}
