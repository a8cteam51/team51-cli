<?php
/**
 * Load the Team51 identity from 1Password.
 */

// Set the OPSOASIS_WP_USERNAME to the Team51 1Password account email.
$team51_op_account = array_filter(
	list_1password_accounts(),
	static fn( object $account ) => 'ZVYA3AB22BC37JPJZJNSGOPYEQ' === $account->account_uuid
);
$team51_op_account = empty( $team51_op_account ) ? null : reset( $team51_op_account );

define( 'OPSOASIS_WP_USERNAME', $team51_op_account->email ?? null );

// Set the OPSOASIS_APP_PASSWORD either from the environment or from 1Password.
if ( ! empty( getenv( 'TEAM51_OPSOASIS_APP_PASSWORD' ) ) ) {
	define( 'OPSOASIS_APP_PASSWORD', getenv( 'TEAM51_OPSOASIS_APP_PASSWORD' ) );
} else {
	$team51_op_logins = list_1password_items(
		array(
			'categories' => 'Login',
			'vault'      => 'Private',
		)
	);

	foreach ( $team51_op_logins as $op_login ) {
		foreach ( $op_login->urls ?? array() as $url ) {
			if ( 'opsoasis.wpspecialprojects.com' !== parse_url( $url->href, PHP_URL_HOST ) ) {
				continue;
			}

			$op_login = get_1password_item( $op_login->id ); // Hydrate the custom fields.
			foreach ( $op_login->fields as $field ) {
				if ( 'App Password' === $field->label ) {
					define( 'OPSOASIS_APP_PASSWORD', $field->value );
					break 3;
				}
			}
		}
	}
}

// Abort if we don't have the identity.
if ( ! defined( 'OPSOASIS_WP_USERNAME' ) || empty( OPSOASIS_WP_USERNAME ) ) {
	console_writeln( 'Could not find the Team51 1Password account. Aborting!' );
	exit( 1 );
}
if ( ! defined( 'OPSOASIS_APP_PASSWORD' ) || empty( OPSOASIS_APP_PASSWORD ) ) {
	console_writeln( 'Could not find the OpsOasis app password. Aborting!' );
	exit( 1 );
}
