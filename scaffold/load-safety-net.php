<?php
/**
 * This file is for loading Safety Net in the mu-plugins folder.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name: Safety Net Loader
 * Description: Used to load Safety Net in the mu-plugins folder.
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/safety-net/safety-net.php' ) ) {
	require_once __DIR__ . '/safety-net/safety-net.php';
}
