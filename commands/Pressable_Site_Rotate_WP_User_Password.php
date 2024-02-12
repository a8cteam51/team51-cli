<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Rotates the WP password of users on Pressable sites.
 *
 * WORK IN PROGRESS
 */
#[AsCommand( name: 'pressable:rotate-site-wp-user-password' )]
final class Pressable_Site_Rotate_WP_User_Password extends Command {
	// WIP
}
