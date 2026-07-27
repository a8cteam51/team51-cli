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
	 * How many lookups may fail before the collaborator is assumed missing rather than still provisioning.
	 */
	private const SFTP_USER_LOOKUP_GRACE_ATTEMPTS = 3;

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
	 *
	 * The far more common reason for that error is simply that the clone's own SFTP user has not finished
	 * provisioning yet - it usually appears within a few seconds - so the first lookups are allowed to fail
	 * before concluding the collaborator is missing and creating one. Creation is then attempted only once
	 * per site, because callers retry `get_credentials()` in a loop.
	 *
	 * @param   string $site_identifier The site to provision the SFTP user on.
	 *
	 * @return  stdClass|null
	 */
	private static function provision_sftp_user( string $site_identifier ): ?stdClass {
		static $lookup_failures = array();

		$lookup_failures[ $site_identifier ] = ( $lookup_failures[ $site_identifier ] ?? 0 ) + 1;
		if ( self::SFTP_USER_LOOKUP_GRACE_ATTEMPTS !== $lookup_failures[ $site_identifier ] ) {
			return null;
		}

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
