<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Rotates the SFTP password of users on Pressable sites.
 */
#[AsCommand( name: 'pressable:rotate-site-sftp-user-password' )]
final class Pressable_Site_Rotate_SFTP_User_Password extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The sites to rotate the SFTP password for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	// endregion
}
