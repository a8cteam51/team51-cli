<?php
/**
 * Site type enum
 *
 * @package WPCOMSpecialProjects\CLI
 */

declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Enums;

/**
 * Site type enum
 */
enum Site_Type: string {
	case WPCOM     = 'wpcom';
	case PRESSABLE = 'pressable';
}