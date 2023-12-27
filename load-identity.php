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
	$team51_op_identities = list_1password_items(
		array(
			'categories' => 'Identity',
			'vault'      => 'Private',
		)
	);

	foreach ( $team51_op_identities as $op_identity ) {
		$op_identity = get_1password_item( $op_identity->id );
		foreach ( $op_identity->fields as $field ) {
			if ( 'OpsOasis App Password' === $field->label ) {
				define( 'OPSOASIS_APP_PASSWORD', $field->value );
				break 2;
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
	console_writeln( 'Could not find the Team51 identity\'s app password. Aborting!' );
	exit( 1 );
}
