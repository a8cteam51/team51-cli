<?php
/**
 * Load the Team51 identity from 1Password.
 */

$team51_op_account = array_filter(
	list_1password_accounts(),
	static fn( object $account ) => 'ZVYA3AB22BC37JPJZJNSGOPYEQ' === $account->account_uuid
);
$team51_op_account = empty( $team51_op_account ) ? null : reset( $team51_op_account );
if ( empty( $team51_op_account ) ) {
	console_writeln( 'Could not find the Team51 1Password account. Aborting!' );
	exit( 1 );
}

$team51_op_identities = list_1password_items(
	array(
		'categories' => 'Identity',
		'vault'      => 'Private',
	)
);
if ( empty( $team51_op_identities ) ) {
	console_writeln( 'Could not find the Team51 identity in 1Password. Aborting!' );
	exit( 1 );
}

define( 'OPSOASIS_WP_USERNAME', $team51_op_account->email );
foreach ( $team51_op_identities as $op_identity ) {
	$op_identity = get_1password_item( $op_identity->id );
	foreach ( $op_identity->fields as $field ) {
		if ( 'OpsOasis App Password' === $field->label ) {
			define( 'OPSOASIS_APP_PASSWORD', $field->value );
			break;
		}
	}
}

if ( ! defined( 'OPSOASIS_APP_PASSWORD' ) ) {
	console_writeln( 'Could not find the Team51 identity\'s app password. Aborting!' );
	exit( 1 );
}
