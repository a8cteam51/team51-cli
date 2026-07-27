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

	/**
	 * How many times to re-check for the SFTP user after creating the collaborator.
	 */
	private const SFTP_USER_PROVISION_ATTEMPTS = 6;

	// endregion

	// region METHODS

	/**
	 * Makes sure the site has an SFTP user for the concierge collaborator, creating one if it does not.
	 *
	 * Opening a connection deliberately does not do this: creating a collaborator is a write, and granting
	 * access to a site should not be a side effect of a read-only command connecting to it. Commands that
	 * provision a site call this once, explicitly, before they start connecting.
	 *
	 * @param   string $site_identifier The site to provision the SFTP user on.
	 *
	 * @return  boolean  Whether the site has an SFTP user for the concierge collaborator.
	 */
	public static function ensure_sftp_user( string $site_identifier ): bool {
		if ( ! \is_null( get_pressable_site_sftp_user( $site_identifier, self::SFTP_USER_EMAIL ) ) ) {
			return true;
		}

		console_writeln( '⏳ No Pressable SFTP user found for ' . self::SFTP_USER_EMAIL . '. Creating the collaborator...' );
		if ( \is_null( create_pressable_site_collaborator( $site_identifier, self::SFTP_USER_EMAIL ) ) ) {
			console_writeln( '❌ Could not create the Pressable site collaborator.' );
			return false;
		}

		// Pressable provisions the SFTP user asynchronously, so it is not returned by the API right away.
		for ( $attempt = 1; $attempt <= self::SFTP_USER_PROVISION_ATTEMPTS; $attempt++ ) {
			\sleep( 5 );

			if ( ! \is_null( get_pressable_site_sftp_user( $site_identifier, self::SFTP_USER_EMAIL ) ) ) {
				return true;
			}
		}

		console_writeln( '❌ The Pressable site SFTP user was created but did not become available in time.' );
		return false;
	}

	// endregion

	// region HELPERS

	/**
	 * {@inheritdoc}
	 */
	protected static function get_credentials( string $site_identifier ): ?stdClass {
		static $cache    = array();
		static $reported = array();

		if ( empty( $cache[ $site_identifier ] ) ) {
			$sftp_user = get_pressable_site_sftp_user( $site_identifier, self::SFTP_USER_EMAIL );
			if ( \is_null( $sftp_user ) ) {
				// Reported once per site: the caller waiting on a new site retries this in a loop, and the
				// reason does not change between passes.
				if ( ! isset( $reported[ $site_identifier ] ) ) {
					$reported[ $site_identifier ] = true;
					console_writeln( '❌ Could not find the Pressable site SFTP user.' );
				}

				return null;
			}

			$cache[ $site_identifier ] = rotate_pressable_site_sftp_user_password( $site_identifier, $sftp_user->username );
		}

		return $cache[ $site_identifier ];
	}

	// endregion
}
