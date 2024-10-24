<?php
/**
 * Pattern Extract Script
 *
 * Extracts and outputs a specified block pattern or post content as JSON, identified by name via WP-CLI.
 * Intended for use within a WordPress environment.
 */

// Check if an argument is provided, otherwise exit.
if ( empty( $args ) ) {
	exit( 1 );
}

$pattern_name = $args[0];

// Attempt to retrieve the registered block pattern.
$pattern_registry = WP_Block_Patterns_Registry::get_instance();
$pattern          = $pattern_registry->get_registered( $pattern_name );

$result = null;

// If a pattern is found, use it; otherwise, attempt to retrieve a post by the same name.
if ( ! empty( $pattern ) ) {
	$result = array(
		'title'   => $pattern['title'],
		'content' => $pattern['content'],
	);
} else {
	$the_post = get_posts(
		array(
			'name'           => $pattern_name,
			'posts_per_page' => 1,
			'post_type'      => 'wp_block',
			'post_status'    => 'publish',
		)
	);

	if ( ! empty( $the_post ) ) {
		$result = array(
			'title'   => $the_post[0]->post_title,
			'content' => $the_post[0]->post_content,
		);
	}
}

// Check if $result is populated and set default values. Don't output anything if it's empty.
if ( ! is_null( $result ) ) {
	$response = array(
		'__file'     => 'wp_block',
		'title'      => $result['title'],
		'content'    => $result['content'],
		'syncStatus' => '',
	);

	echo wp_json_encode( $response );
}
