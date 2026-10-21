<?php

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use WPCOMSpecialProjects\CLI\Command\Pressable_Site_Repository_Prepare;

const PRESSABLE_DEPLOYIGNORE_BEGIN = '# BEGIN Pressable deploy allowlist';
const PRESSABLE_DEPLOYIGNORE_END   = '# END Pressable deploy allowlist';
const PRESSABLE_DEPLOYIGNORE_NOTE  = '# Pressable deletes what this tree lacks unless a line here protects it; keep this block last.';

const PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS = array( 'themes', 'plugins', 'mu-plugins' );

const PRESSABLE_DEPLOYIGNORE_LEGACY_LINES = array( 'themes', 'plugins', 'mu-plugins', 'uploads' );

const PRESSABLE_DEPLOYIGNORE_SERVER_OWNED = array(
	'uploads',
	'upgrade',
	'upgrade-temp-backup',
	'cache',
	'languages',
	'fonts',
	'jetpack-waf',
	'jetpack-temp',
	'boost-cache',
	'jpcrm-storage',
	'wflogs',
	'ai1wm-backups',
	'updraft',
	'et-cache',
	'litespeed',
	'w3tc-config',
	'wp-rocket-config',
	'blogs.dir',
	'database',
	'index.php',
	'debug.log',
	'object-cache.php',
	'advanced-cache.php',
	'db.php',
	'db-error.php',
	'maintenance.php',
	'php-error.php',
	'fatal-error-handler.php',
	'install.php',
	'sunrise.php',
	'blog-deleted.php',
	'blog-inactive.php',
	'blog-suspended.php',
);

// The wp-content folders the snapshot leaves out: uploads for size, caches, and backup plugins' own archives.
const PRESSABLE_SNAPSHOT_EXCLUDED = array( 'uploads', 'cache', 'ai1wm-backups', 'updraft' );

// The wp-content folders the server listing skips, so the deploy preview cannot see them.
const PRESSABLE_LISTING_SKIPPED = array( 'uploads', 'cache', 'upgrade' );

// Pressable's own drop-ins, which unprotected live deploys left in place.
const PRESSABLE_DEPLOY_KEPT_BY_PRESSABLE = array( 'object-cache.php', 'advanced-cache.php' );

const PRESSABLE_DEPLOYIGNORE_SYNTHETIC_SERVER_PATHS = array(
	'zz-unknown-root/x.txt',
	'zz-unknown-root.php',
	'themes/zz-unknown/style.css',
	'themes/index.php',
	'plugins/zz-unknown/zz.php',
	'plugins/index.php',
	'plugins/a8csp-atlantis/a8csp-atlantis.php',
	'mu-plugins/zz-unknown.php',
	'mu-plugins/index.php',
	'mu-plugins/safety-net/safety-net.php',
	'mu-plugins/load-safety-net.php',
	'uploads/2026/01/zz.jpg',
	'jetpack-waf/rules/rules.php',
	'languages/zz.mo',
);

// region MATCHING

/**
 * Parses a .deployignore file into rules, one per pattern line.
 *
 * @param   string $contents The file's contents.
 *
 * @return  array[]
 */
function parse_pressable_deployignore( string $contents ): array {
	$rules = array();
	foreach ( preg_split( '/\R/', $contents ) as $index => $raw ) {
		$line = preg_replace( '/(?<!\\\\) +$/', '', $raw );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}

		$negate   = str_starts_with( $line, '!' );
		$body     = $negate ? substr( $line, 1 ) : $line;
		$dir_only = str_ends_with( $body, '/' );
		$body     = rtrim( $body, '/' );
		if ( '' === $body ) {
			continue;
		}

		$rules[] = array(
			'negate'   => $negate,
			'dir_only' => $dir_only,
			'bare'     => ! str_contains( $body, '/' ),
			'literal'  => 1 !== preg_match( '/(?<!\\\\)[*?\[]/', $body ),
			'regex'    => pressable_deployignore_glob_to_regex( str_starts_with( $body, '/' ) ? substr( $body, 1 ) : $body ),
			'line'     => $index + 1,
			'text'     => $raw,
		);
	}

	return $rules;
}

/**
 * Converts a .deployignore glob into a regex anchored at the deploy root, as Pressable anchors every pattern.
 *
 * @param   string $glob The glob, without its leading `!`, leading slash or trailing slash.
 *
 * @return  string
 */
function pressable_deployignore_glob_to_regex( string $glob ): string {
	$regex  = '';
	$length = strlen( $glob );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $glob[ $i ];
		if ( '\\' === $char && $i + 1 < $length ) {
			$regex .= preg_quote( $glob[ ++$i ], '#' );
		} elseif ( '*' === $char && '*' === ( $glob[ $i + 1 ] ?? '' ) ) {
			$after_slash = 0 === $i || '/' === $glob[ $i - 1 ];
			if ( $after_slash && '/' === ( $glob[ $i + 2 ] ?? '' ) ) {
				$regex .= '(?:.*/)?';
				$i     += 2;
			} elseif ( $after_slash && $i + 2 === $length ) {
				$regex .= '.*';
				++$i;
			} else {
				$regex .= '[^/]*';
				++$i;
			}
		} elseif ( '*' === $char ) {
			$regex .= '[^/]*';
		} elseif ( '?' === $char ) {
			$regex .= '[^/]';
		} elseif ( '[' === $char && false !== strpos( $glob, ']', $i + 2 ) ) {
			$close  = strpos( $glob, ']', $i + 2 );
			$class  = substr( $glob, $i + 1, $close - $i - 1 );
			$class  = str_starts_with( $class, '!' ) ? '^' . substr( $class, 1 ) : $class;
			$regex .= '[' . str_replace( array( '\\', '#' ), array( '\\\\', '\\#' ), $class ) . ']';
			$i      = $close;
		} else {
			$regex .= preg_quote( $char, '#' );
		}
	}

	return "#^$regex$#";
}

/**
 * Returns whether a Pressable deploy ships a path, or deletes it when only the server has it, as observed live.
 *
 * @param   string  $path  The path relative to the deploy destination.
 * @param   array[] $rules The rules, as parse_pressable_deployignore() returns them.
 *
 * @return  boolean
 */
function is_path_deployed_by_pressable( string $path, array $rules ): bool {
	$parts = explode( '/', $path );
	$count = count( $parts );
	for ( $depth = 1; $depth <= $count; $depth++ ) {
		$entry  = implode( '/', array_slice( $parts, 0, $depth ) );
		$is_dir = $depth < $count;
		$best   = null;
		foreach ( $rules as $rule ) {
			if ( ( ! $is_dir && $rule['dir_only'] ) || 1 !== preg_match( $rule['regex'], $entry ) ) {
				continue;
			}
			if ( $rule['negate'] && $rule['bare'] ) {
				return true;
			}
			if ( is_null( $best ) || ( $rule['literal'] && ! $best['literal'] ) || $rule['literal'] === $best['literal'] ) {
				$best = $rule;
			}
		}
		if ( ! is_null( $best ) && ! $best['negate'] ) {
			return false;
		}
	}

	return true;
}

/**
 * Escapes a file or folder name for use as a literal .deployignore pattern.
 *
 * @param   string $name The name.
 *
 * @return  string
 */
function escape_pressable_deployignore_name( string $name ): string {
	$name = preg_replace( '/([\\\\*?\[\]])/', '\\\\$1', $name );
	$name = preg_replace( '/^([#!])/', '\\\\$1', $name );
	return preg_replace( '/( +)$/', '\\\\$1', $name );
}

/**
 * Reverses escape_pressable_deployignore_name() on a whole pattern.
 *
 * @param   string $pattern The pattern.
 *
 * @return  string
 */
function unescape_pressable_deployignore_pattern( string $pattern ): string {
	return preg_replace( '/\\\\(.)/s', '$1', $pattern );
}

/**
 * Parses the lines outside the block into the rules that say what the repository means to withhold.
 *
 * @param   string[] $kept The lines outside the block.
 *
 * @return  array[]
 */
function get_pressable_deployignore_intent_rules( array $kept ): array {
	$meant = array_filter( $kept, static fn( string $line ): bool => ! in_array( trim( rtrim( trim( $line ), '/' ), '/' ), PRESSABLE_DEPLOYIGNORE_LEGACY_LINES, true ) );

	return parse_pressable_deployignore( implode( "\n", $meant ) );
}

// endregion

// region FILE

/**
 * Splits a .deployignore file into the lines outside the allowlist block and the block itself.
 *
 * @param   string $contents The file's contents.
 *
 * @return  array{kept: string[], block: string[]|null, malformed: bool}
 */
function split_pressable_deployignore( string $contents ): array {
	$kept      = array();
	$block     = null;
	$in        = false;
	$malformed = false;
	foreach ( preg_split( '/\R/', rtrim( $contents, "\r\n" ) ) as $line ) {
		if ( PRESSABLE_DEPLOYIGNORE_BEGIN === trim( $line ) ) {
			$malformed = $malformed || $in || ! is_null( $block );
			$in        = true;
			$block     = array();
			continue;
		}
		if ( $in && PRESSABLE_DEPLOYIGNORE_END === trim( $line ) ) {
			$in = false;
			continue;
		}
		if ( $in ) {
			$block[] = $line;
		} else {
			$kept[] = $line;
		}
	}

	return array(
		'kept'      => $kept,
		'block'     => $block,
		'malformed' => $malformed || $in,
	);
}

/**
 * Removes the negations from the lines kept outside the block, since there they beat every other rule.
 *
 * @param   string[] $kept The lines outside the block.
 *
 * @return  array{kept: string[], removed: string[]}
 */
function strip_pressable_deployignore_negations( array $kept ): array {
	$is_negation = static fn( string $line ): bool => str_starts_with( ltrim( $line ), '!' );
	$drop        = array();
	$count       = count( $kept );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! $is_negation( $kept[ $i ] ) || ( $i > 0 && $is_negation( $kept[ $i - 1 ] ) ) ) {
			continue;
		}
		$end = $i;
		while ( $end + 1 < $count && $is_negation( $kept[ $end + 1 ] ) ) {
			++$end;
		}
		for ( $j = $i; $j <= $end; $j++ ) {
			$drop[ $j ] = true;
		}
		// A heading that introduces only these negations goes with them.
		if ( $end + 1 >= $count || '' === trim( $kept[ $end + 1 ] ) ) {
			$j = $i - 1;
			while ( $j >= 0 && str_starts_with( ltrim( $kept[ $j ] ), '#' ) ) {
				$drop[ $j ] = true;
				--$j;
			}
		}
	}

	$result = array(
		'kept'    => array(),
		'removed' => array(),
	);
	foreach ( $kept as $index => $line ) {
		if ( ! isset( $drop[ $index ] ) ) {
			$after_drop = isset( $drop[ $index - 1 ] ) && $result['kept'] && '' === trim( (string) end( $result['kept'] ) );
			if ( '' !== trim( $line ) || ! $after_drop ) {
				$result['kept'][] = $line;
			}
		} elseif ( $is_negation( $line ) ) {
			$result['removed'][] = $line;
		}
	}

	return $result;
}

/**
 * Builds the allowlist block.
 *
 * @param   string[]               $root_entries The root files and folders to deploy; folders end with a slash.
 * @param   array<string,string[]> $children     The children to deploy per content folder; folders end with a slash.
 * @param   string[]               $kept_safe    The tracked children kept protected on purpose, like `plugins/x`.
 * @param   string[]               $tombstones   The removed components to delete on the server; folders end with a slash.
 *
 * @return  string[]
 */
function build_pressable_deployignore_block( array $root_entries, array $children, array $kept_safe, array $tombstones ): array {
	$escape = static fn( string $path ): string => implode( '/', array_map( 'escape_pressable_deployignore_name', explode( '/', $path ) ) );
	$sorted = static function ( array $items ): array {
		$items = array_values( array_unique( $items ) );
		sort( $items, SORT_STRING );
		return $items;
	};

	$lines = array( PRESSABLE_DEPLOYIGNORE_BEGIN, PRESSABLE_DEPLOYIGNORE_NOTE, '/*' );
	foreach ( $sorted( $root_entries ) as $entry ) {
		$lines[] = '!/' . $escape( $entry );
	}

	$used = array();
	foreach ( PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS as $dir ) {
		$has_tombstone = (bool) array_filter( $tombstones, static fn( string $t ): bool => str_starts_with( $t, "$dir/" ) );
		if ( ! empty( $children[ $dir ] ) || $has_tombstone ) {
			$used[]  = $dir;
			$lines[] = "!/$dir/";
		}
	}
	foreach ( $used as $dir ) {
		$lines[] = "/$dir/*";
		foreach ( $sorted( $children[ $dir ] ?? array() ) as $child ) {
			$lines[] = "!/$dir/" . $escape( $child );
		}
	}

	foreach ( $sorted( $kept_safe ) as $path ) {
		$lines[] = "# protected: $path";
	}
	if ( $tombstones ) {
		$lines[] = '# Removed from the repository: delete on the server';
		foreach ( $sorted( $tombstones ) as $path ) {
			$lines[] = '!/' . $escape( $path );
		}
	}
	$lines[] = PRESSABLE_DEPLOYIGNORE_END;

	return $lines;
}

/**
 * Assembles a .deployignore file from the kept lines and the block, the block last.
 *
 * @param   string[] $kept  The lines outside the block.
 * @param   string[] $block The block, markers included.
 *
 * @return  string
 */
function assemble_pressable_deployignore( array $kept, array $block ): string {
	while ( $kept && '' === trim( end( $kept ) ) ) {
		array_pop( $kept );
	}

	return implode( "\n", array_merge( $kept, $kept ? array( '' ) : array(), $block ) ) . "\n";
}

// endregion

// region CHECKS

/**
 * Lists the tombstones of a block: the negations under its "Removed from the repository" heading.
 *
 * @param   string[] $block The block's lines, markers excluded.
 *
 * @return  string[] The tombstoned paths, folders ending with a slash.
 */
function get_pressable_deployignore_tombstones( array $block ): array {
	$tombstones = array();
	$in         = false;
	foreach ( $block as $line ) {
		if ( str_starts_with( $line, '# Removed from the repository' ) ) {
			$in = true;
		} elseif ( $in && str_starts_with( $line, '!/' ) ) {
			$tombstones[] = unescape_pressable_deployignore_pattern( substr( $line, 2 ) );
		}
	}

	return $tombstones;
}

/**
 * Checks a .deployignore file against the tree it ships with, under Pressable's matching.
 *
 * @param   string   $contents The file.
 * @param   string[] $tracked  The tracked file paths.
 * @param   string[] $gitlinks The submodule paths.
 *
 * @return  string[] The problems found, empty when the file passes.
 */
function check_pressable_deployignore( string $contents, array $tracked, array $gitlinks ): array {
	$problems = array();
	$parts    = split_pressable_deployignore( $contents );
	if ( $parts['malformed'] ) {
		return array( 'The file has an unterminated or repeated Pressable allowlist block.' );
	}
	if ( is_null( $parts['block'] ) ) {
		return array( 'The file has no Pressable allowlist block.' );
	}

	$lines = preg_split( '/\R/', rtrim( $contents, "\r\n" ) );
	$end   = array_search( PRESSABLE_DEPLOYIGNORE_END, array_map( 'trim', $lines ), true );
	foreach ( array_slice( $lines, (int) $end + 1 ) as $line ) {
		if ( '' !== trim( $line ) && ! str_starts_with( ltrim( $line ), '#' ) ) {
			$problems[] = "A line follows the block, where it can override it: `$line`.";
		}
	}
	foreach ( $parts['kept'] as $line ) {
		if ( str_starts_with( ltrim( $line ), '!' ) ) {
			$problems[] = "A negation outside the block beats every rule in it on Pressable: `$line`.";
		}
	}

	$rules      = parse_pressable_deployignore( $contents );
	$kept_rules = get_pressable_deployignore_intent_rules( $parts['kept'] );
	$tracked    = array_values( array_diff( $tracked, $gitlinks ) );
	$is_tracked = array_flip( $tracked );

	foreach ( PRESSABLE_DEPLOYIGNORE_SYNTHETIC_SERVER_PATHS as $path ) {
		if ( ! isset( $is_tracked[ $path ] ) && is_path_deployed_by_pressable( $path, $rules ) ) {
			$problems[] = "A server-only path would be deleted by the first deploy: `$path`.";
		}
	}
	foreach ( PRESSABLE_DEPLOYIGNORE_SERVER_OWNED as $name ) {
		$path = str_contains( $name, '.' ) && ! str_ends_with( $name, '.dir' ) ? $name : "$name/zz.txt";
		if ( ! isset( $is_tracked[ $path ] ) && is_path_deployed_by_pressable( $path, $rules ) ) {
			$problems[] = "A server-owned path would be deleted by the first deploy: `$path`.";
		}
	}

	$block_rules = array_filter( parse_pressable_deployignore( implode( "\n", $parts['block'] ) ), static fn( array $rule ): bool => $rule['negate'] );
	$tombstones  = get_pressable_deployignore_tombstones( $parts['block'] );
	$entries     = array();
	foreach ( $tracked as $path ) {
		$segments = explode( '/', $path );
		$count    = count( $segments );
		for ( $depth = 1; $depth <= $count; $depth++ ) {
			$entries[ implode( '/', array_slice( $segments, 0, $depth ) ) ] = $depth < $count;
		}
	}
	foreach ( $gitlinks as $gitlink ) {
		$entries[ $gitlink ] = true;
	}
	foreach ( $block_rules as $rule ) {
		$target = rtrim( ltrim( substr( $rule['text'], 1 ), '/' ), '/' );
		if ( in_array( unescape_pressable_deployignore_pattern( $target ) . ( $rule['dir_only'] ? '/' : '' ), $tombstones, true ) ) {
			continue;
		}
		$matched = false;
		foreach ( $entries as $entry => $is_dir ) {
			if ( ( $is_dir || ! $rule['dir_only'] ) && 1 === preg_match( $rule['regex'], $entry ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			$problems[] = "The negation `{$rule['text']}` matches nothing in the repository.";
		}
	}

	foreach ( $gitlinks as $gitlink ) {
		if ( is_path_deployed_by_pressable( "$gitlink/zz-server-copy.txt", $rules ) ) {
			$problems[] = "The submodule `$gitlink` is re-included, but Pressable does not check out submodules, so its server copy would be deleted.";
		}
	}

	foreach ( $tracked as $path ) {
		$segments = explode( '/', $path );
		$top      = $segments[0];
		$deployed = is_path_deployed_by_pressable( $path, $rules );
		if ( ! $deployed && is_path_deployed_by_pressable( $path, $kept_rules ) && in_array( $top, PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS, true ) && count( $segments ) > 2 ) {
			$child = "$top/{$segments[1]}";
			if ( is_path_deployed_by_pressable( "$child/zz-probe", $rules ) ) {
				$problems[] = "A tracked file inside a re-included component does not deploy: `$path`.";
			}
		}
	}

	$protected = array();
	foreach ( $parts['block'] as $line ) {
		if ( str_starts_with( $line, '# protected: ' ) ) {
			$protected[] = substr( $line, strlen( '# protected: ' ) );
		}
	}
	$children = array();
	foreach ( $tracked as $path ) {
		$segments = explode( '/', $path );
		if ( in_array( $segments[0], PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS, true ) && count( $segments ) > 1 ) {
			$children[ "{$segments[0]}/{$segments[1]}" ][] = $path;
		}
	}
	foreach ( $children as $child => $paths ) {
		$name = basename( $child );
		if ( str_starts_with( $name, '.' ) || 'index.php' === $name || in_array( $child, $protected, true ) ) {
			continue;
		}
		$any_deployed = (bool) array_filter( $paths, static fn( string $p ): bool => is_path_deployed_by_pressable( $p, $rules ) );
		$any_intended = (bool) array_filter( $paths, static fn( string $p ): bool => is_path_deployed_by_pressable( $p, $kept_rules ) );
		if ( ! $any_deployed && $any_intended ) {
			$problems[] = "The tracked component `$child` neither deploys nor is listed as protected.";
		}
	}

	return array_values( array_unique( $problems ) );
}

/**
 * Lists the tracked paths a slash-free kept line withholds under git but not on Pressable, which matches the root only.
 *
 * @param   string[] $kept    The lines outside the block.
 * @param   string[] $tracked The tracked file paths.
 *
 * @return  array<string,string[]> The paths per line.
 */
function find_pressable_deployignore_root_only_lines( array $kept, array $tracked ): array {
	$found = array();
	foreach ( parse_pressable_deployignore( implode( "\n", $kept ) ) as $rule ) {
		if ( $rule['negate'] || ! $rule['bare'] ) {
			continue;
		}
		$pattern = rtrim( trim( $rule['text'] ), '/' );
		foreach ( $tracked as $path ) {
			$segments = explode( '/', $path );
			array_shift( $segments );
			foreach ( $segments as $index => $segment ) {
				$is_dir = $index < count( $segments ) - 1;
				if ( ( $is_dir || ! $rule['dir_only'] ) && fnmatch( $pattern, $segment ) ) {
					$found[ $rule['text'] ][] = $path;
					break;
				}
			}
		}
	}

	return $found;
}

/**
 * Predicts what the first Pressable deploy does to a site's files.
 *
 * @param   string   $contents The .deployignore file.
 * @param   string[] $tracked  The tracked file paths.
 * @param   string[] $server   The paths on the server, relative to the deploy destination.
 *
 * @return  array{deleted: string[], overwritten: string[], added: string[]}
 */
function predict_pressable_deploy( string $contents, array $tracked, array $server ): array {
	$rules     = parse_pressable_deployignore( $contents );
	$is_server = array_flip( $server );
	$is_repo   = array_flip( $tracked );
	$result    = array(
		'deleted'     => array(),
		'overwritten' => array(),
		'added'       => array(),
	);
	foreach ( $server as $path ) {
		if ( ! isset( $is_repo[ $path ] ) && ! in_array( $path, PRESSABLE_DEPLOY_KEPT_BY_PRESSABLE, true ) && is_path_deployed_by_pressable( $path, $rules ) ) {
			$result['deleted'][] = $path;
		}
	}
	foreach ( $tracked as $path ) {
		if ( is_path_deployed_by_pressable( $path, $rules ) ) {
			$result[ isset( $is_server[ $path ] ) ? 'overwritten' : 'added' ][] = $path;
		}
	}

	return $result;
}

// endregion

// region SITE

/**
 * Returns the shell command that enters a Pressable site's wp-content folder over SSH.
 *
 * @return  string
 */
function get_pressable_wp_content_cd_command(): string {
	return 'if [ -d htdocs/wp-content ]; then cd htdocs/wp-content; elif [ -d /htdocs/wp-content ]; then cd /htdocs/wp-content; else exit 3; fi';
}

/**
 * Lists a Pressable site's wp-content files over SSH, leaving out uploads and caches.
 *
 * @param   string $site_id The site's ID.
 *
 * @return  array{files: string[], symlinks: string[]}|null The paths relative to wp-content, or null when the listing failed.
 */
function list_pressable_site_wp_content( string $site_id ): ?array {
	$ssh = \Pressable_Connection_Helper::get_ssh_connection( $site_id );
	if ( is_null( $ssh ) ) {
		return null;
	}

	try {
		$ssh->setTimeout( 0 );
		$skipped = implode( ' -o ', array_map( static fn( string $dir ): string => '-path ' . escapeshellarg( "./$dir" ), PRESSABLE_LISTING_SKIPPED ) );
		$listing = $ssh->exec( get_pressable_wp_content_cd_command() . " && find . -mindepth 1 \\( $skipped \\) -prune -o \\( -type f -o -type l \\) -printf '%y %P\\n'" );
		if ( 0 !== $ssh->getExitStatus() ) {
			return null;
		}
	} finally {
		$ssh->disconnect();
	}

	$result = array(
		'files'    => array(),
		'symlinks' => array(),
	);
	foreach ( array_filter( explode( "\n", (string) $listing ) ) as $line ) {
		[ $type, $path ] = explode( ' ', $line, 2 ) + array( '', '' );
		if ( '' === $path ) {
			continue;
		}
		$result['files'][] = $path;
		if ( 'l' === $type ) {
			$result['symlinks'][] = $path;
		}
	}

	return $result;
}

/**
 * Saves a snapshot of a Pressable site's wp-content, uploads and caches left out, to a local folder.
 *
 * @param   string          $site_id   The site's ID.
 * @param   string          $directory The local folder.
 * @param   OutputInterface $output    The output to report progress to.
 *
 * @return  string|null The local path, or null on failure.
 */
function save_pressable_site_wp_content_snapshot( string $site_id, string $directory, OutputInterface $output ): ?string {
	$name   = "pressable-$site_id-wp-content-" . gmdate( 'Ymd-His' ) . '.tgz';
	$remote = "/tmp/$name";
	$local  = rtrim( $directory, '/' ) . "/$name";

	$excludes = '';
	foreach ( PRESSABLE_SNAPSHOT_EXCLUDED as $excluded ) {
		$excludes .= ' --exclude=' . escapeshellarg( "wp-content/$excluded" );
	}

	$ok = false;
	try {
		$ssh = \Pressable_Connection_Helper::get_ssh_connection( $site_id );
		if ( is_null( $ssh ) ) {
			return null;
		}
		try {
			$ssh->setTimeout( 0 );
			$output->writeln( '<comment>Saving a snapshot of wp-content...</comment>' );
			$ssh->exec( get_pressable_wp_content_cd_command() . ' && cd .. && tar -czf ' . escapeshellarg( $remote ) . $excludes . ' wp-content' );
			// GNU tar exits 1 when a file changed while it was read, which a live site does constantly.
			if ( ! in_array( $ssh->getExitStatus(), array( 0, 1 ), true ) ) {
				return null;
			}
		} finally {
			$ssh->disconnect();
		}

		$sftp = \Pressable_Connection_Helper::get_sftp_connection( $site_id );
		if ( ! is_null( $sftp ) ) {
			try {
				$sftp->setTimeout( 0 );
				$ok = $sftp->get( $remote, $local ) && is_file( $local ) && 0 < filesize( $local );
			} finally {
				$sftp->disconnect();
			}
		}
	} finally {
		$cleanup = \Pressable_Connection_Helper::get_ssh_connection( $site_id );
		$cleanup?->exec( 'rm -f ' . escapeshellarg( $remote ) );
		$cleanup?->disconnect();
	}

	return $ok ? $local : null;
}

/**
 * Shows what a first Pressable deploy does to a site's files, grouped by component.
 *
 * @param   OutputInterface $output     The output object.
 * @param   array           $prediction The prediction, as predict_pressable_deploy() returns it.
 *
 * @return  void
 */
function output_pressable_deploy_preview( OutputInterface $output, array $prediction ): void {
	$rows = array();
	foreach ( $prediction as $kind => $paths ) {
		$groups = array();
		foreach ( $paths as $path ) {
			$segments         = explode( '/', $path );
			$group            = in_array( $segments[0], PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS, true ) && count( $segments ) > 2 ? "{$segments[0]}/{$segments[1]}" : $segments[0];
			$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + 1;
		}
		foreach ( $groups as $group => $count ) {
			$rows[] = array( $kind, $group, $count );
		}
	}

	output_table( $output, $rows, array( 'First deploy', 'Path', 'Files' ), 'What the first Pressable deploy does, uploads and caches aside' );
}

/**
 * Reads a branch's tree and .deployignore from a shallow clone that downloads no other file contents.
 *
 * @param   string $clone_url The repository's clone URL.
 * @param   string $branch    The branch.
 *
 * @return  array{files: string[], gitlinks: string[], deployignore: string}|null Null when the branch could not be read.
 */
function read_github_branch_for_pressable( string $clone_url, string $branch ): ?array {
	$directory = sys_get_temp_dir() . '/team51-branch-' . bin2hex( random_bytes( 6 ) );
	$run       = static function ( array $command ) use ( $directory ): Process {
		$process = new Process( $command, sys_get_temp_dir(), null, null, 600 );
		$process->run();
		return $process;
	};

	try {
		if ( ! $run( array( 'git', 'clone', '--quiet', '--filter=blob:none', '--no-checkout', '--depth=1', '--single-branch', '--branch', $branch, $clone_url, $directory ) )->isSuccessful() ) {
			return null;
		}
		$tree = $run( array( 'git', '-C', $directory, 'ls-tree', '-r', '-z', '--full-tree', 'HEAD' ) );
		if ( ! $tree->isSuccessful() ) {
			return null;
		}

		$result = array(
			'files'        => array(),
			'gitlinks'     => array(),
			'deployignore' => '',
		);
		foreach ( explode( "\0", $tree->getOutput() ) as $entry ) {
			if ( preg_match( '/^\d+ (blob|commit) [0-9a-f]+\t(.+)$/s', $entry, $matches ) ) {
				$result[ 'blob' === $matches[1] ? 'files' : 'gitlinks' ][] = $matches[2];
			}
		}
		if ( in_array( '.deployignore', $result['files'], true ) ) {
			$file = $run( array( 'git', '-C', $directory, 'show', 'HEAD:.deployignore' ) );
			if ( ! $file->isSuccessful() ) {
				return null;
			}
			$result['deployignore'] = str_replace( array( "\r\n", "\r" ), "\n", $file->getOutput() );
		}

		return $result;
	} finally {
		$run( array( 'rm', '-rf', $directory ) );
	}
}

// endregion

// region CONNECT CHECKS

/**
 * Describes an enabled DeployHQ server that deploys to a site, as a problem that blocks connecting it.
 *
 * @param   stdClass $server  The server.
 * @param   string   $project The project's permalink.
 *
 * @return  string
 */
function describe_enabled_deployhq_server( stdClass $server, string $project ): string {
	return 'The DeployHQ server `' . ( $server->name ?? '?' ) . "` of project `$project` still deploys `" . ( $server->branch ?? '?' ) . '` to the site' . ( empty( $server->auto_deploy ) ? '' : ' on every push' ) . '. Disable it in DeployHQ first.';
}

/**
 * Lists the enabled DeployHQ servers, on any project for a repository, that deploy as a Pressable site's SFTP owner.
 *
 * @param   stdClass $site       The Pressable site.
 * @param   stdClass $repository The GitHub repository.
 *
 * @return  array<string,string>|null The servers' problems keyed by server identifier, or null when DeployHQ or the site's SFTP owner could not be read.
 */
function find_enabled_deployhq_servers_for_pressable_site( stdClass $site, stdClass $repository ): ?array {
	$owner    = get_pressable_site_sftp_owner( (string) $site->id );
	$projects = get_deployhq_projects();
	if ( empty( $owner->username ) || is_null( $projects ) ) {
		return null;
	}

	$found = array();
	foreach ( $projects as $project ) {
		if ( 1 !== preg_match( '#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#i', (string) ( $project->repository->url ?? '' ), $matches ) || 0 !== strcasecmp( $matches[1], (string) $repository->full_name ) ) {
			continue;
		}
		$servers = get_deployhq_project_servers( (string) $project->permalink );
		if ( is_null( $servers ) ) {
			return null;
		}
		foreach ( $servers as $server ) {
			if ( 0 === strcasecmp( (string) ( $server->username ?? '' ), (string) $owner->username ) && (bool) ( $server->enabled ?? true ) ) {
				$found[ (string) ( $server->identifier ?? $server->name ?? '' ) ] = describe_enabled_deployhq_server( $server, (string) $project->permalink );
			}
		}
	}

	return $found;
}

/**
 * Checks whether a Pressable site can be connected to a branch without its first deploy deleting what it must keep.
 *
 * @param   stdClass $site       The Pressable site.
 * @param   stdClass $repository The GitHub repository.
 * @param   string   $branch     The branch.
 * @param   array    $paths      The requested paths, as `destination_path` and `repository_subdirectory`, slashes trimmed.
 *
 * @return  array{blocking: string[], overridable: string[]} Problems nothing may override, and ones --force may.
 */
function check_pressable_site_before_connect( stdClass $site, stdClass $repository, string $branch, array $paths ): array {
	$problems = array(
		'blocking'    => array(),
		'overridable' => array(),
	);

	$git = get_pressable_site_git_config( $site->id );
	if ( is_null( $git ) ) {
		$problems['blocking'][] = 422 === API_Helper::$last_http_code
			? 'The site is not enrolled in Pressable\'s new GitHub integration, which Pressable Support enables.'
			: 'Could not read the site\'s GitHub integration settings.';
	} else {
		if ( ! empty( $git->connected ) ) {
			$problems['blocking'][] = 'The site is already connected to ' . ( $git->repository ?? 'a repository' ) . ' on `' . ( $git->branch ?? '?' ) . '`. Disconnect it first.';
		}
		$stored = array(
			'destination_path'        => $git->destinationPath ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'repository_subdirectory' => $git->repositorySubdirectory ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		);
		foreach ( $stored as $key => $value ) {
			if ( ! is_null( $value ) && trim( (string) $value, '/' ) !== $paths[ $key ] ) {
				$problems['blocking'][] = 'Pressable has `' . $value . "` stored as the site's $key, and the deploy that connecting starts would use it before it can be corrected. Correct it in MyPressable first.";
			}
		}
	}

	$enabled = array();
	$config  = API_Helper::make_pressable_request( "sites/$site->id/deployhq" );
	if ( is_null( $config ) && 404 === API_Helper::$last_http_code ) {
		console_writeln( '<comment>The 404 above means OpsOasis found no DeployHQ server for the site; its projects for the repository are checked next.</comment>' );
	} elseif ( is_null( $config ) ) {
		$problems['blocking'][] = 'Could not confirm that no DeployHQ server deploys to the site.';
	} else {
		$server = $config->server ?? null;
		if ( isset( $server->errors ) ) {
			if ( ! isset( $server->errors->{'404'} ) ) {
				$problems['blocking'][] = 'Could not read the site\'s DeployHQ server, so it cannot be confirmed stopped.';
			}
		} elseif ( is_object( $server ) && (bool) ( $server->enabled ?? true ) ) {
			$enabled[ (string) ( $server->identifier ?? $server->name ?? '' ) ] = describe_enabled_deployhq_server( $server, (string) ( $config->project->permalink ?? '?' ) );
		}
	}

	$servers = find_enabled_deployhq_servers_for_pressable_site( $site, $repository );
	if ( is_null( $servers ) ) {
		$problems['blocking'][] = "Could not read DeployHQ's projects or the site's SFTP owner, so the site's DeployHQ servers cannot be confirmed stopped.";
	}
	$problems['blocking'] = array_merge( $problems['blocking'], array_values( ( $servers ?? array() ) + $enabled ) );

	if ( array( 'wp-content', '' ) !== array( $paths['destination_path'], $paths['repository_subdirectory'] ) ) {
		$problems['overridable'][] = 'Only the repository root deployed into wp-content can be checked, so the first deploy of this layout is not.';
	} elseif ( ! $problems['blocking'] ) {
		$status = run_app_command(
			Pressable_Site_Repository_Prepare::getDefaultName(),
			array(
				'site'         => (string) $site->id,
				'--repository' => $repository->name,
				'--branch'     => $branch,
				'--check'      => true,
			)
		);
		if ( 0 !== $status ) {
			$problems['overridable'][] = "The branch's .deployignore is not ready, as reported above. Run `team51 pressable:prepare-site-repository $site->id --repository=$repository->name --branch=$branch` first.";
		}
	}

	return $problems;
}

// endregion
