<?php

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
		if ( ! isset( $is_repo[ $path ] ) && is_path_deployed_by_pressable( $path, $rules ) ) {
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
