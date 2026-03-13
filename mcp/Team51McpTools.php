<?php

namespace WPCOMSpecialProjects\CLI\Mcp;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Schema\ToolAnnotations;

/**
 * MCP Tool definitions for the Team51 CLI.
 *
 * These tools expose read-only and low/medium-risk write operations from the
 * Team51 CLI as MCP tools, allowing AI assistants to interact with WPCOM,
 * Pressable, GitHub, Jetpack, and DeployHQ services.
 *
 * High-risk operations are exposed only when explicitly needed and are marked
 * with ToolAnnotations so MCP clients can prompt/guard appropriately. This
 * includes WP-CLI execution tools, which are allowlisted and audit-logged.
 *
 * IMPORTANT: When adding new tools, keep in mind that STDOUT is reserved for
 * JSON-RPC communication. Use STDERR for any debug output.
 */
final class Team51McpTools {

	// region IDENTITY

	/**
	 * Whether the 1Password identity has been loaded.
	 *
	 * @var bool
	 */
	private static bool $identity_loaded = false;

	/**
	 * Ensures the Team51 identity (1Password credentials) is loaded.
	 *
	 * This is called lazily on the first tool invocation rather than at server
	 * startup, so that Cursor does not prompt for 1Password unlock just by
	 * opening a project.
	 *
	 * @return array|null Returns an error array if identity loading fails, null on success.
	 */
	private static function ensure_identity(): ?array {
		if ( self::$identity_loaded ) {
			return null;
		}

		try {
			require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';
			self::$identity_loaded = true;
			fwrite( STDERR, "[MCP] Identity loaded successfully.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return null;
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Failed to load Team51 identity (1Password): ' . $e->getMessage() );
		}
	}

	/**
	 * Runs a Team51 CLI command and returns a structured result.
	 *
	 * @param string $command_name The command name, e.g. wpcom:create-site.
	 * @param array  $args         Positional/flag arguments as a flat list.
	 *
	 * @return array
	 */
	private static function run_cli_command( string $command_name, array $args = array() ): array {
		$process = run_system_command(
			array_merge(
				array(
					PHP_BINARY,
					TEAM51_CLI_FILE,
					$command_name,
					'--no-interaction',
					'--no-ansi',
				),
				$args
			),
			TEAM51_CLI_ROOT_DIR,
			false
		);

		return array(
			'ok'           => 0 === $process->getExitCode(),
			'exit_code'    => $process->getExitCode(),
			'output'       => trim( $process->getOutput() ),
			'error_output' => trim( $process->getErrorOutput() ),
		);
	}

	/**
	 * Restrictive allowlist for WP-CLI commands exposed through MCP.
	 *
	 * @param string $wp_cli_command Raw WP-CLI command (without leading `wp`).
	 *
	 * @return bool
	 */
	private static function is_allowed_wp_cli_command( string $wp_cli_command ): bool {
		$command = trim( preg_replace( '/^wp\s+/', '', trim( $wp_cli_command ) ) ?? '' );
		if ( '' === $command ) {
			return false;
		}

		// Block known WP-CLI global flags, but allow command-specific flags.
		$tokens = preg_split( '/\s+/', $command ) ?: array();
		$blocked_global_flags = array(
			'--path',
			'--url',
			'--ssh',
			'--http',
			'--user',
			'--require',
			'--exec',
			'--context',
			'--prompt',
			'--quiet',
			'--debug',
			'--allow-root',
			'--color',
			'--no-color',
		);

		foreach ( $tokens as $token ) {
			$token = trim( $token );
			if ( ! str_starts_with( $token, '--' ) ) {
				continue;
			}

			$token_name = explode( '=', $token )[0];
			if ( in_array( strtolower( $token_name ), $blocked_global_flags, true ) ) {
				return false;
			}
		}

		$allowed_prefixes = array(
			'option get ',
			'plugin list',
			'theme list',
			'core version',
			'site health',
			'user list',
			'post list',
			'term list',
			'comment list',
			'transient get ',
		);

		foreach ( $allowed_prefixes as $prefix ) {
			if ( str_starts_with( $command, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Emits a structured audit entry for high-risk WP-CLI execution.
	 *
	 * @param string $provider       Either wpcom or pressable.
	 * @param string $site_id_or_url Site identifier passed by caller.
	 * @param string $wp_cli_command WP-CLI command.
	 *
	 * @return void
	 */
	private static function audit_wp_cli_command( string $provider, string $site_id_or_url, string $wp_cli_command ): void {
		$actor = defined( 'OPSOASIS_WP_USERNAME' ) ? OPSOASIS_WP_USERNAME : 'unknown';

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			'[MCP WP-CLI AUDIT] ' . ( encode_json_content(
				array(
					'provider'  => $provider,
					'site'      => $site_id_or_url,
					'command'   => trim( $wp_cli_command ),
					'actor'     => $actor,
					'timestamp' => gmdate( DATE_ATOM ),
				)
			) ?? '' )
		);
	}

	/**
	 * Filters sites by deny-list.
	 *
	 * @param array $sites The source sites array.
	 * @param array $deny  Domain fragments to exclude.
	 *
	 * @return array
	 */
	private static function filter_sites_by_deny_list( array $sites, array $deny ): array {
		return array_filter(
			$sites,
			static function ( $site ) use ( $deny ) {
				$site_url  = $site->URL ?? $site->siteurl ?? '';
				$host      = parse_url( $site_url, PHP_URL_HOST );
				if ( ! is_string( $host ) || '' === $host ) {
					// Some site URLs are schemeless (e.g. example.com); add a default
					// scheme so parse_url can reliably extract the host.
					$host = parse_url( 'https://' . ltrim( (string) $site_url, '/' ), PHP_URL_HOST );
				}
				$host      = is_string( $host ) ? strtolower( $host ) : '';
				if ( '' === $host ) {
					return true;
				}

				foreach ( $deny as $item ) {
					$item = strtolower( $item );
					if ( $host === $item || str_ends_with( $host, '.' . $item ) ) {
						return false;
					}
				}
				return true;
			}
		);
	}

	/**
	 * Re-indexes site objects by `userblog_id`.
	 *
	 * @param array $sites List of site objects.
	 *
	 * @return array
	 */
	private static function index_sites_by_userblog_id( array $sites ): array {
		$indexed = array();
		foreach ( $sites as $site ) {
			if ( isset( $site->userblog_id ) ) {
				$indexed[ $site->userblog_id ] = $site;
			}
		}

		return $indexed;
	}

	// endregion

	// region WPCOM TOOLS

	/**
	 * List all sites connected to the team's WordPress.com account.
	 * Returns site ID, name, URL, and connection type for each site.
	 *
	 * @param string $type Filter by site type: 'all' (default), 'jetpack', or 'agency'.
	 */
	#[McpTool( name: 'wpcom_list_sites' )]
	public function wpcom_list_sites( string $type = 'all' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$params = array();
		if ( 'all' !== $type ) {
			$params['type'] = $type;
		}

		$sites = get_wpcom_sites( $params );
		if ( null === $sites ) {
			return array( 'error' => 'Failed to fetch WPCOM sites.' );
		}

		return array(
			'count' => count( $sites ),
			'sites' => array_map(
				static function ( $site ) {
					return array(
						'id'              => $site->ID ?? $site->userblog_id ?? $site->id ?? null,
						'name'            => $site->name ?? null,
						'url'             => $site->URL ?? $site->siteurl ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'is_private'      => $site->is_private ?? null,
						'is_coming_soon'  => $site->is_coming_soon ?? null,
						'is_wpcom_atomic' => $site->is_wpcom_atomic ?? null,
						'jetpack'         => $site->jetpack ?? null,
					);
				},
				array_values( $sites )
			),
		);
	}

	/**
	 * Get details about a specific WordPress.com site by domain or site ID.
	 *
	 * @param string $site_id_or_url The domain name or WPCOM site ID.
	 */
	#[McpTool( name: 'wpcom_get_site' )]
	public function wpcom_get_site( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$site = get_wpcom_site( $site_id_or_url );
		if ( null === $site ) {
			return array( 'error' => "Failed to fetch WPCOM site: $site_id_or_url" );
		}

		return (array) $site;
	}

	/**
	 * List plugins installed on a specific WordPress.com or Jetpack-connected site.
	 *
	 * @param string $site_id_or_domain The domain name or WPCOM site ID.
	 */
	#[McpTool( name: 'wpcom_list_site_plugins' )]
	public function wpcom_list_site_plugins( string $site_id_or_domain ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$plugins = get_wpcom_site_plugins( $site_id_or_domain );
		if ( null === $plugins ) {
			return array( 'error' => "Failed to fetch plugins for site: $site_id_or_domain" );
		}

		return array(
			'count'   => count( $plugins ),
			'plugins' => array_map(
				static function ( string $plugin_file, $plugin_data ) {
					$plugin_dir = dirname( $plugin_file );
					$slug       = ( '.' === $plugin_dir || '' === $plugin_dir )
						? basename( $plugin_file, '.php' )
						: $plugin_dir;

					return array(
						'file'        => $plugin_file,
						'name'        => $plugin_data->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'slug'        => $slug,
						'version'     => $plugin_data->Version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'active'      => $plugin_data->active,
						'text_domain' => $plugin_data->TextDomain ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					);
				},
				array_keys( $plugins ),
				array_values( $plugins )
			),
		);
	}

	/**
	 * List stickers (tags/labels) associated with a WordPress.com site.
	 *
	 * @param string $site_id_or_domain The domain name or WPCOM site ID.
	 */
	#[McpTool( name: 'wpcom_list_site_stickers' )]
	public function wpcom_list_site_stickers( string $site_id_or_domain ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$stickers = get_wpcom_site_stickers( $site_id_or_domain );
		if ( null === $stickers ) {
			return array( 'error' => "Failed to fetch stickers for site: $site_id_or_domain" );
		}

		return array(
			'count'    => count( $stickers ),
			'stickers' => $stickers,
		);
	}

	/**
	 * List all WordPress.com sites that have a specific sticker applied.
	 *
	 * @param string $sticker The sticker name to search for.
	 */
	#[McpTool( name: 'wpcom_list_sites_with_sticker' )]
	public function wpcom_list_sites_with_sticker( string $sticker ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$sites = get_wpcom_sites_with_sticker( $sticker );
		if ( null === $sites ) {
			return array( 'error' => "Failed to fetch sites with sticker: $sticker" );
		}

		return array(
			'count' => count( $sites ),
			'sites' => array_map(
				static function ( $site ) {
					return array(
						'id'   => $site->userblog_id ?? $site->ID ?? null,
						'name' => $site->blogname ?? $site->name ?? null,
						'url'  => $site->siteurl ?? $site->URL ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					);
				},
				array_values( $sites )
			),
		);
	}

	/**
	 * Get site statistics summary for a WordPress.com site.
	 *
	 * @param string $site_id_or_url The domain name or WPCOM site ID.
	 */
	#[McpTool( name: 'wpcom_get_site_stats' )]
	public function wpcom_get_site_stats( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$stats = get_wpcom_site_stats( $site_id_or_url );
		if ( null === $stats ) {
			return array( 'error' => "Failed to fetch stats for site: $site_id_or_url" );
		}

		return (array) $stats;
	}

	/**
	 * List WordPress users on a WPCOM or Jetpack-connected site.
	 *
	 * @param string $site_id_or_url The domain name or WPCOM site ID.
	 */
	#[McpTool( name: 'wpcom_list_site_users' )]
	public function wpcom_list_site_users( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$users = get_wpcom_site_users( $site_id_or_url );
		if ( null === $users ) {
			return array( 'error' => "Failed to fetch users for site: $site_id_or_url" );
		}
		if ( ! is_array( $users ) ) {
			return array(
				'error'   => 'Unexpected response from get_wpcom_site_users',
				'details' => $users,
			);
		}

		return array(
			'count' => count( $users ),
			'users' => array_map(
				static fn( $user ) => (array) $user,
				$users
			),
		);
	}

	/**
	 * Add a sticker (tag/label) to a WordPress.com site.
	 *
	 * @param string $site_id_or_domain The domain name or WPCOM site ID.
	 * @param string $sticker           The sticker name to add.
	 */
	#[McpTool(
		name: 'wpcom_add_sticker',
		annotations: new ToolAnnotations(
			title: 'Add WPCOM Site Sticker',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function wpcom_add_sticker( string $site_id_or_domain, string $sticker ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = add_wpcom_site_sticker( $site_id_or_domain, $sticker );
		if ( null === $result ) {
			return array( 'error' => "Failed to add sticker '$sticker' to site: $site_id_or_domain" );
		}

		return array(
			'success' => true,
			'site'    => $site_id_or_domain,
			'sticker' => $sticker,
			'action'  => 'added',
		);
	}

	/**
	 * Remove a sticker (tag/label) from a WordPress.com site.
	 *
	 * @param string $site_id_or_domain The domain name or WPCOM site ID.
	 * @param string $sticker           The sticker name to remove.
	 */
	#[McpTool(
		name: 'wpcom_remove_sticker',
		annotations: new ToolAnnotations(
			title: 'Remove WPCOM Site Sticker',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function wpcom_remove_sticker( string $site_id_or_domain, string $sticker ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = remove_wpcom_site_sticker( $site_id_or_domain, $sticker );
		if ( null === $result ) {
			return array( 'error' => "Failed to remove sticker '$sticker' from site: $site_id_or_domain" );
		}

		return array(
			'success' => true,
			'site'    => $site_id_or_domain,
			'sticker' => $sticker,
			'action'  => 'removed',
		);
	}

	/**
	 * Update settings for a WordPress.com site.
	 *
	 * @param string $site_id_or_url The domain name or WPCOM site ID.
	 * @param string $settings_json  JSON object of settings to update (e.g. {"blogname": "New Name"}).
	 */
	#[McpTool(
		name: 'wpcom_update_site',
		annotations: new ToolAnnotations(
			title: 'Update WPCOM Site Settings',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function wpcom_update_site( string $site_id_or_url, string $settings_json ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$settings = json_decode( $settings_json, true );
		if ( null === $settings || ! is_array( $settings ) ) {
			return array( 'error' => 'Invalid settings_json. Must be a valid JSON object.' );
		}

		$result = update_wpcom_site( $site_id_or_url, $settings );
		if ( null === $result ) {
			return array( 'error' => "Failed to update site settings for: $site_id_or_url" );
		}

		return array(
			'success'          => true,
			'site'             => $site_id_or_url,
			'updated_settings' => $settings,
		);
	}

	/**
	 * Rotate the SFTP user password for a WordPress.com site.
	 * Returns the new credentials.
	 *
	 * @param string $site_id_or_url The domain name or WPCOM site ID.
	 * @param string $username       The SFTP username to rotate the password for.
	 */
	#[McpTool(
		name: 'wpcom_rotate_sftp_password',
		annotations: new ToolAnnotations(
			title: 'Rotate WPCOM SFTP Password',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function wpcom_rotate_sftp_password( string $site_id_or_url, string $username ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = rotate_wpcom_site_sftp_user_password( $site_id_or_url, $username );
		if ( null === $result ) {
			return array( 'error' => "Failed to rotate SFTP password for user '$username' on site: $site_id_or_url" );
		}

		return (array) $result;
	}

	// endregion

	// region PRESSABLE TOOLS

	/**
	 * List all Pressable hosting sites.
	 */
	#[McpTool( name: 'pressable_list_sites' )]
	public function pressable_list_sites(): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$sites = get_pressable_sites();
		if ( null === $sites ) {
			return array( 'error' => 'Failed to fetch Pressable sites.' );
		}

		return array(
			'count' => count( $sites ),
			'sites' => array_map(
				static function ( $site ) {
					return array(
						'id'          => $site->id,
						'name'        => $site->name,
						'url'         => $site->url,
						'state'       => $site->state ?? null,
						'datacenter'  => $site->datacenterCode ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					);
				},
				$sites
			),
		);
	}

	/**
	 * Get details about a specific Pressable site by ID or URL.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 */
	#[McpTool( name: 'pressable_get_site' )]
	public function pressable_get_site( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$site = get_pressable_site( $site_id_or_url );
		if ( null === $site ) {
			return array( 'error' => "Failed to fetch Pressable site: $site_id_or_url" );
		}

		return (array) $site;
	}

	/**
	 * Get PHP error logs for a Pressable site.
	 *
	 * @param string $site_id      The Pressable site ID.
	 * @param int    $max_entries  Maximum number of log entries to return (default: 200).
	 */
	#[McpTool( name: 'pressable_get_php_errors' )]
	public function pressable_get_php_errors( string $site_id, int $max_entries = 200 ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		if ( $max_entries < 1 ) {
			return array( 'error' => 'max_entries must be a positive integer' );
		}

		$errors = get_pressable_site_php_logs( $site_id, null, $max_entries );
		if ( null === $errors ) {
			return array( 'error' => "Failed to fetch PHP errors for Pressable site: $site_id" );
		}

		return array(
			'count'  => count( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * List collaborators on a Pressable account.
	 */
	#[McpTool( name: 'pressable_list_collaborators' )]
	public function pressable_list_collaborators(): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$collaborators = get_pressable_collaborators();
		if ( null === $collaborators ) {
			return array( 'error' => 'Failed to fetch Pressable collaborators.' );
		}

		return array(
			'count'         => count( $collaborators ),
			'collaborators' => array_map(
				static fn( $c ) => (array) $c,
				$collaborators
			),
		);
	}

	/**
	 * List SFTP users for a Pressable site.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 */
	#[McpTool( name: 'pressable_list_sftp_users' )]
	public function pressable_list_sftp_users( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$users = get_pressable_site_sftp_users( $site_id_or_url );
		if ( null === $users ) {
			return array( 'error' => "Failed to fetch SFTP users for site: $site_id_or_url" );
		}

		return array(
			'count' => count( $users ),
			'users' => array_map(
				static fn( $u ) => (array) $u,
				$users
			),
		);
	}

	/**
	 * List domains configured for a Pressable site.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 */
	#[McpTool( name: 'pressable_list_site_domains' )]
	public function pressable_list_site_domains( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$domains = get_pressable_site_domains( $site_id_or_url );
		if ( null === $domains ) {
			return array( 'error' => "Failed to fetch domains for site: $site_id_or_url" );
		}

		return array(
			'count'   => count( $domains ),
			'domains' => array_map(
				static fn( $d ) => (array) $d,
				$domains
			),
		);
	}

	/**
	 * Create a note on a Pressable site. Useful for documenting changes or issues.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 * @param string $subject        The note subject/title.
	 * @param string $content        The note content/body.
	 */
	#[McpTool(
		name: 'pressable_create_site_note',
		annotations: new ToolAnnotations(
			title: 'Create Pressable Site Note',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function pressable_create_site_note( string $site_id_or_url, string $subject, string $content ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = create_pressable_site_note( $site_id_or_url, $subject, $content );
		if ( null === $result ) {
			return array( 'error' => "Failed to create note on Pressable site: $site_id_or_url" );
		}

		return (array) $result;
	}

	/**
	 * Add a collaborator to a Pressable site by email address.
	 *
	 * @param string $site_id_or_url    The Pressable site ID or URL.
	 * @param string $collaborator_email The email address of the collaborator to add.
	 */
	#[McpTool(
		name: 'pressable_add_collaborator',
		annotations: new ToolAnnotations(
			title: 'Add Pressable Site Collaborator',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function pressable_add_collaborator( string $site_id_or_url, string $collaborator_email ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = create_pressable_site_collaborator( $site_id_or_url, $collaborator_email );
		if ( null === $result ) {
			return array( 'error' => "Failed to add collaborator '$collaborator_email' to site: $site_id_or_url" );
		}

		return (array) $result;
	}

	/**
	 * Remove a collaborator from a Pressable site.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 * @param string $collaborator   The collaborator ID or email to remove.
	 * @param bool   $delete_wp_user Whether to also delete the collaborator's WordPress user account.
	 */
	#[McpTool(
		name: 'pressable_remove_collaborator',
		annotations: new ToolAnnotations(
			title: 'Remove Pressable Site Collaborator',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function pressable_remove_collaborator( string $site_id_or_url, string $collaborator, bool $delete_wp_user = false ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = delete_pressable_site_collaborator( $site_id_or_url, $collaborator, $delete_wp_user );
		if ( null === $result ) {
			return array( 'error' => "Failed to remove collaborator '$collaborator' from site: $site_id_or_url" );
		}

		return array(
			'success'        => true,
			'site'           => $site_id_or_url,
			'collaborator'   => $collaborator,
			'wp_user_deleted' => $delete_wp_user,
		);
	}

	/**
	 * Add a domain to a Pressable site.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 * @param string $domain         The domain name to add (e.g., 'example.com').
	 */
	#[McpTool(
		name: 'pressable_add_domain',
		annotations: new ToolAnnotations(
			title: 'Add Domain to Pressable Site',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function pressable_add_domain( string $site_id_or_url, string $domain ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = add_pressable_site_domain( $site_id_or_url, $domain );
		if ( null === $result ) {
			return array( 'error' => "Failed to add domain '$domain' to site: $site_id_or_url" );
		}

		return array(
			'success' => true,
			'site'    => $site_id_or_url,
			'domain'  => $domain,
			'domains' => $result,
		);
	}

	/**
	 * Rotate the SFTP user password for a Pressable site.
	 * Returns the new credentials.
	 *
	 * @param string $site_id_or_url The Pressable site ID or URL.
	 * @param string $username       The SFTP username to rotate the password for.
	 */
	#[McpTool(
		name: 'pressable_rotate_sftp_password',
		annotations: new ToolAnnotations(
			title: 'Rotate Pressable SFTP Password',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function pressable_rotate_sftp_password( string $site_id_or_url, string $username ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = rotate_pressable_site_sftp_user_password( $site_id_or_url, $username );
		if ( null === $result ) {
			return array( 'error' => "Failed to rotate SFTP password for user '$username' on site: $site_id_or_url" );
		}

		return (array) $result;
	}

	// endregion

	// region GITHUB TOOLS

	/**
	 * List all GitHub repositories in the organization.
	 */
	#[McpTool( name: 'github_list_repositories' )]
	public function github_list_repositories(): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$repos = get_github_repositories();
		if ( null === $repos ) {
			return array( 'error' => 'Failed to fetch GitHub repositories.' );
		}

		return array(
			'count'        => count( $repos ),
			'repositories' => array_map(
				static function ( $repo ) {
					return array(
						'name'        => $repo->name ?? null,
						'full_name'   => $repo->full_name ?? null,
						'description' => $repo->description ?? null,
						'url'         => $repo->html_url ?? null,
						'private'     => $repo->private ?? null,
						'archived'    => $repo->archived ?? null,
					);
				},
				$repos
			),
		);
	}

	/**
	 * Get details about a specific GitHub repository.
	 *
	 * @param string $repository The repository name (e.g., 'my-repo' without the org prefix).
	 */
	#[McpTool( name: 'github_get_repository' )]
	public function github_get_repository( string $repository ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$repo = get_github_repository( $repository );
		if ( null === $repo ) {
			return array( 'error' => "Failed to fetch GitHub repository: $repository" );
		}

		return (array) $repo;
	}

	/**
	 * List branches of a GitHub repository.
	 *
	 * @param string $repository The repository name.
	 */
	#[McpTool( name: 'github_list_branches' )]
	public function github_list_branches( string $repository ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$branches = get_github_repository_branches( $repository );
		if ( null === $branches ) {
			return array( 'error' => "Failed to fetch branches for repository: $repository" );
		}

		return array(
			'count'    => count( $branches ),
			'branches' => array_map(
				static fn( $b ) => (array) $b,
				$branches
			),
		);
	}

	/**
	 * List secrets configured for a GitHub repository.
	 *
	 * @param string $repository The repository name.
	 */
	#[McpTool( name: 'github_list_secrets' )]
	public function github_list_secrets( string $repository ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$secrets = get_github_repository_secrets( $repository );
		if ( null === $secrets ) {
			return array( 'error' => "Failed to fetch secrets for repository: $repository" );
		}

		return array(
			'count'   => count( $secrets ),
			'secrets' => array_map(
				static fn( $s ) => (array) $s,
				$secrets
			),
		);
	}

	/**
	 * List recent workflow runs for a GitHub repository.
	 *
	 * @param string $repository The repository name.
	 */
	#[McpTool( name: 'github_list_workflow_runs' )]
	public function github_list_workflow_runs( string $repository ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$runs = get_github_repository_workflow_runs( $repository );
		if ( null === $runs ) {
			return array( 'error' => "Failed to fetch workflow runs for repository: $repository" );
		}

		return array(
			'count' => count( $runs ),
			'runs'  => array_map(
				static fn( $r ) => (array) $r,
				$runs
			),
		);
	}

	/**
	 * Set topics/tags for a GitHub repository. Replaces all existing topics.
	 *
	 * @param string $repository  The repository name.
	 * @param string $topics_json JSON array of topic strings (e.g., ["wordpress", "plugin"]).
	 */
	#[McpTool(
		name: 'github_set_topics',
		annotations: new ToolAnnotations(
			title: 'Set GitHub Repository Topics',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function github_set_topics( string $repository, string $topics_json ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$topics = json_decode( $topics_json, true );
		if ( null === $topics || ! is_array( $topics ) ) {
			return array( 'error' => 'Invalid topics_json. Must be a JSON array of strings.' );
		}

		$result = set_github_repository_topics( $repository, $topics );
		if ( null === $result ) {
			return array( 'error' => "Failed to set topics for repository: $repository" );
		}

		return array(
			'success'    => true,
			'repository' => $repository,
			'topics'     => $result,
		);
	}

	/**
	 * Create a new branch in a GitHub repository.
	 *
	 * @param string $repository The repository name.
	 * @param string $name       The name for the new branch.
	 * @param string $source     The source branch to create from (e.g., 'trunk').
	 */
	#[McpTool(
		name: 'github_create_branch',
		annotations: new ToolAnnotations(
			title: 'Create GitHub Branch',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function github_create_branch( string $repository, string $name, string $source = 'trunk' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = create_github_repository_branch( $repository, $name, $source );
		if ( null === $result ) {
			return array( 'error' => "Failed to create branch '$name' in repository: $repository" );
		}

		return (array) $result;
	}

	/**
	 * Create or update a secret in a GitHub repository.
	 *
	 * @param string $repository   The repository name.
	 * @param string $secret_name  The name of the secret.
	 * @param string $secret_value The value to set for the secret.
	 */
	#[McpTool(
		name: 'github_set_secret',
		annotations: new ToolAnnotations(
			title: 'Set GitHub Repository Secret',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function github_set_secret( string $repository, string $secret_name, string $secret_value ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = set_github_repository_secret( $repository, $secret_name, $secret_value );
		if ( null === $result ) {
			return array( 'error' => "Failed to set secret '$secret_name' in repository: $repository" );
		}

		return array(
			'success'    => true,
			'repository' => $repository,
			'secret'     => $secret_name,
			'action'     => 'set',
		);
	}

	/**
	 * Create a new issue in a GitHub repository.
	 *
	 * @param string $repository The repository name.
	 * @param string $title      The issue title.
	 * @param string $body       The issue body/description (supports Markdown).
	 * @param string $labels_json JSON array of label strings (e.g., ["bug", "urgent"]). Optional, defaults to [].
	 */
	#[McpTool(
		name: 'github_create_issue',
		annotations: new ToolAnnotations(
			title: 'Create GitHub Issue',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function github_create_issue( string $repository, string $title, string $body, string $labels_json = '[]' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$labels = json_decode( $labels_json, true );
		if ( null === $labels || ! is_array( $labels ) ) {
			$labels = array();
		}

		$result = create_github_issue( $repository, $title, $body, $labels );
		if ( null === $result ) {
			return array( 'error' => "Failed to create issue in repository: $repository" );
		}

		return (array) $result;
	}

	// endregion

	// region JETPACK TOOLS

	/**
	 * List all available Jetpack modules with their descriptions and status info.
	 */
	#[McpTool( name: 'jetpack_list_modules' )]
	public function jetpack_list_modules(): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$modules = get_jetpack_modules();
		if ( null === $modules ) {
			return array( 'error' => 'Failed to fetch Jetpack modules.' );
		}

		return array(
			'count'   => count( $modules ),
			'modules' => array_map(
				static fn( $m ) => (array) $m,
				$modules
			),
		);
	}

	/**
	 * List Jetpack modules and their status for a specific site.
	 *
	 * @param string $site_id_or_url The WPCOM site ID or domain.
	 */
	#[McpTool( name: 'jetpack_list_site_modules' )]
	public function jetpack_list_site_modules( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$modules = get_jetpack_site_modules( $site_id_or_url );
		if ( null === $modules ) {
			return array( 'error' => "Failed to fetch Jetpack modules for site: $site_id_or_url" );
		}

		return array(
			'count'   => count( $modules ),
			'modules' => array_map(
				static fn( $m ) => (array) $m,
				$modules
			),
		);
	}

	/**
	 * Update Jetpack module settings for a site. Use this to enable or disable
	 * specific Jetpack modules.
	 *
	 * @param string $site_id_or_url The WPCOM site ID or domain.
	 * @param string $settings_json  JSON object of module settings (e.g., {"module-name": true} to enable, false to disable).
	 */
	#[McpTool(
		name: 'jetpack_update_module_settings',
		annotations: new ToolAnnotations(
			title: 'Update Jetpack Module Settings',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function jetpack_update_module_settings( string $site_id_or_url, string $settings_json ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$settings = json_decode( $settings_json, true );
		if ( null === $settings || ! is_array( $settings ) ) {
			return array( 'error' => 'Invalid settings_json. Must be a valid JSON object (e.g., {"module-name": true}).' );
		}

		$result = update_jetpack_site_modules_settings( $site_id_or_url, $settings );
		if ( null === $result ) {
			return array( 'error' => "Failed to update Jetpack module settings for site: $site_id_or_url" );
		}

		return array(
			'success'          => $result,
			'site'             => $site_id_or_url,
			'updated_settings' => $settings,
		);
	}

	// endregion

	// region DEPLOYHQ TOOLS

	/**
	 * List all DeployHQ projects.
	 */
	#[McpTool( name: 'deployhq_list_projects' )]
	public function deployhq_list_projects(): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$projects = get_deployhq_projects();
		if ( null === $projects ) {
			return array( 'error' => 'Failed to fetch DeployHQ projects.' );
		}

		return array(
			'count'    => count( $projects ),
			'projects' => array_map(
				static fn( $p ) => (array) $p,
				$projects
			),
		);
	}

	/**
	 * Get details about a specific DeployHQ project.
	 *
	 * @param string $project The DeployHQ project permalink/slug.
	 */
	#[McpTool( name: 'deployhq_get_project' )]
	public function deployhq_get_project( string $project ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$proj = get_deployhq_project( $project );
		if ( null === $proj ) {
			return array( 'error' => "Failed to fetch DeployHQ project: $project" );
		}

		return (array) $proj;
	}

	/**
	 * List servers configured for a DeployHQ project.
	 *
	 * @param string $project The DeployHQ project permalink/slug.
	 */
	#[McpTool( name: 'deployhq_list_project_servers' )]
	public function deployhq_list_project_servers( string $project ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$servers = get_deployhq_project_servers( $project );
		if ( null === $servers ) {
			return array( 'error' => "Failed to fetch servers for project: $project" );
		}

		return array(
			'count'   => count( $servers ),
			'servers' => array_map(
				static fn( $s ) => (array) $s,
				$servers
			),
		);
	}

	/**
	 * Rotate the SSH private key for a DeployHQ project.
	 *
	 * @param string $project The DeployHQ project permalink/slug.
	 */
	#[McpTool(
		name: 'deployhq_rotate_private_key',
		annotations: new ToolAnnotations(
			title: 'Rotate DeployHQ Project Private Key',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function deployhq_rotate_private_key( string $project ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = rotate_deployhq_project_private_key( $project );
		if ( null === $result ) {
			return array( 'error' => "Failed to rotate private key for DeployHQ project: $project" );
		}

		return (array) $result;
	}

	/**
	 * Update the connected GitHub repository for a DeployHQ project.
	 *
	 * @param string $project    The DeployHQ project permalink/slug.
	 * @param string $repository The SSH URL of the GitHub repository to connect.
	 */
	#[McpTool(
		name: 'deployhq_connect_repository',
		annotations: new ToolAnnotations(
			title: 'Connect Repository to DeployHQ Project',
			readOnlyHint: false,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: true,
		)
	)]
	public function deployhq_connect_repository( string $project, string $repository ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$result = update_deployhq_project_repository( $project, $repository );
		if ( null === $result ) {
			return array( 'error' => "Failed to connect repository to DeployHQ project: $project" );
		}

		return (array) $result;
	}

	// endregion

	// region ADDITIONAL LEGACY COMMAND TOOLS

	#[McpTool(
		name: 'wpcom_create_site',
		annotations: new ToolAnnotations(
			title: 'Create WPCOM Site',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function wpcom_create_site( string $name ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$site = create_wpcom_site( $name );
		return $site ? (array) $site : array( 'error' => 'Failed to create WPCOM site.' );
	}

	#[McpTool(
		name: 'wpcom_clone_site',
		annotations: new ToolAnnotations(
			title: 'Clone WPCOM Site',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function wpcom_clone_site( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$staging = create_wpcom_staging_site( $site_id_or_url );
		return $staging ? (array) $staging : array( 'error' => 'Failed to create WPCOM staging site.' );
	}

	#[McpTool(
		name: 'wpcom_rotate_wp_user_password',
		annotations: new ToolAnnotations(
			title: 'Rotate WPCOM WP User Password',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function wpcom_rotate_wp_user_password( string $site_id_or_url, string $user = 'concierge@wordpress.com' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$credentials = rotate_wpcom_site_wp_user_password( $site_id_or_url, $user );
		return $credentials ? (array) $credentials : array( 'error' => 'Failed to rotate WPCOM WP user password.' );
	}

	#[McpTool(
		name: 'wpcom_run_wp_cli_command',
		annotations: new ToolAnnotations(
			title: 'Run WPCOM WP-CLI Command (High Risk)',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function wpcom_run_wp_cli_command( string $site_id_or_url, string $wp_cli_command ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		if ( ! self::is_allowed_wp_cli_command( $wp_cli_command ) ) {
			return array(
				'error'          => 'Command is not allowed by MCP WP-CLI allowlist.',
				'allowed_prefix' => array( 'option get', 'plugin list', 'theme list', 'core version', 'site health', 'user list', 'post list', 'term list', 'comment list', 'transient get' ),
			);
		}

		self::audit_wp_cli_command( 'wpcom', $site_id_or_url, $wp_cli_command );

		$exit_code = run_wpcom_site_wp_cli_command( $site_id_or_url, $wp_cli_command, true );
		return array(
			'exit_code' => $exit_code,
			'output'    => $GLOBALS['wp_cli_output'] ?? '',
		);
	}

	#[McpTool( name: 'wpcom_connect_site_repository' )]
	public function wpcom_connect_site_repository( string $site_id_or_url, string $repository, string $branch = 'trunk', string $target_dir = '/wp-content/', bool $deploy = false ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$gh_repository = get_github_repository( $repository );
		if ( null === $gh_repository ) {
			return array( 'error' => "GitHub repository not found: $repository" );
		}

		$deployment = create_wpcom_site_code_deployment( $site_id_or_url, $gh_repository->id, $branch, $target_dir );
		if ( null === $deployment ) {
			return array( 'error' => 'Failed to connect WPCOM site repository.' );
		}

		$result = array( 'deployment' => (array) $deployment );
		if ( $deploy ) {
			$run = create_wpcom_site_code_deployment_run( $site_id_or_url, $deployment->id );
			$result['deployment_run'] = $run ? (array) $run : array( 'error' => 'Failed to trigger deployment run.' );
		}

		return $result;
	}

	#[McpTool( name: 'wpcom_list_sites_stats_summary' )]
	public function wpcom_list_sites_stats_summary( int $num = 1, string $period = 'day', ?string $date = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$date  = $date ?: gmdate( 'Y-m-d' );
		$jetpack_sites = get_wpcom_jetpack_sites();
		if ( null === $jetpack_sites ) {
			return array( 'error' => 'Failed to fetch Jetpack sites from WPCOM.' );
		}

		$sites = self::index_sites_by_userblog_id(
			self::filter_sites_by_deny_list(
				$jetpack_sites,
				array( 'mystagingwebsite.com', 'go-vip.co', 'wpcomstaging.com', 'wpengine.com', 'jurassic.ninja', 'woocommerce.com', 'atomicsites.blog', 'ninomihovilic.com', 'team51.blog' )
			)
		);

		$stats = get_wpcom_site_stats_batch(
			array_column( $sites, 'userblog_id' ),
			array_combine(
				array_column( $sites, 'userblog_id' ),
				array_fill(
					0,
					count( $sites ),
					array(
						'num'    => $num,
						'period' => $period,
						'date'   => $date,
					)
				)
			),
			'summary',
			$errors
		);
		$stats = array_filter( $stats ?? array(), static fn( $s ) => 0 < ( $s->views ?? 0 ) );

		return array(
			'count'  => count( $stats ),
			'sites'  => array_map( static fn( $s, $id ) => array(
				'site_id'   => $id,
				'site_url'  => $sites[ $id ]->siteurl ?? null,
				'views'     => $s->views ?? 0,
				'visitors'  => $s->visitors ?? 0,
				'comments'  => $s->comments ?? 0,
				'followers' => $s->followers ?? 0,
			), $stats, array_keys( $stats ) ),
			'errors' => array_map( static fn( $e ) => (array) $e, $errors ?? array() ),
		);
	}

	#[McpTool( name: 'wpcom_list_sites_stats_orders' )]
	public function wpcom_list_sites_stats_orders( string $unit = 'day', ?string $date = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$date  = $date ?: gmdate( match ( $unit ) {
			'week' => 'Y-\WW',
			'month' => 'Y-m',
			'year' => 'Y',
			default => 'Y-m-d',
		} );
		$jetpack_sites = get_wpcom_jetpack_sites();
		if ( null === $jetpack_sites ) {
			return array( 'error' => 'Failed to fetch Jetpack sites from WPCOM.' );
		}

		$sites = self::index_sites_by_userblog_id(
			self::filter_sites_by_deny_list(
				$jetpack_sites,
				array( 'mystagingwebsite.com', 'go-vip.co', 'wpcomstaging.com', 'wpengine.com', 'jurassic.ninja', 'woocommerce.com', 'atomicsites.blog', 'ninomihovilic.com', 'team51.blog' )
			)
		);

		$plugins = get_wpcom_site_plugins_batch( array_column( $sites, 'userblog_id' ), $plugin_errors ) ?? array();
		$sites   = array_filter(
			$sites,
			static fn( $site ) => isset( $plugins[ $site->userblog_id ] ) && array_reduce(
				$plugins[ $site->userblog_id ],
				static fn( $carry, $plugin ) => $carry || ( 'woocommerce' === ( $plugin->TextDomain ?? '' ) && true === ( $plugin->active ?? false ) ),
				false
			)
		);
		$sites = self::index_sites_by_userblog_id( $sites );

		$stats = get_wpcom_site_stats_batch(
			array_column( $sites, 'userblog_id' ),
			array_combine(
				array_column( $sites, 'userblog_id' ),
				array_fill( 0, count( $sites ), array( 'unit' => $unit, 'date' => $date, 'quantity' => 1 ) )
			),
			'orders',
			$errors
		);
		$stats = array_filter( $stats ?? array(), static fn( $s ) => 0 < ( $s->total_gross_sales ?? 0 ) && 0 < ( $s->total_orders ?? 0 ) );

		return array(
			'count'  => count( $stats ),
			'sites'  => array_map( static fn( $s, $id ) => array(
				'site_id'           => $id,
				'site_url'          => $sites[ $id ]->siteurl ?? null,
				'total_gross_sales' => $s->total_gross_sales ?? 0,
				'total_net_sales'   => $s->total_net_sales ?? 0,
				'total_orders'      => $s->total_orders ?? 0,
				'total_products'    => $s->total_products ?? 0,
			), $stats, array_keys( $stats ) ),
			'errors' => array_map( static fn( $e ) => (array) $e, array_merge( $errors ?? array(), $plugin_errors ?? array() ) ),
		);
	}

	#[McpTool(
		name: 'pressable_create_site',
		annotations: new ToolAnnotations(
			title: 'Create Pressable Site',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function pressable_create_site( string $name, string $datacenter = 'DFW' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}
		$site = create_pressable_site( $name, $datacenter );
		return $site ? (array) $site : array( 'error' => 'Failed to create Pressable site.' );
	}

	#[McpTool(
		name: 'pressable_clone_site',
		annotations: new ToolAnnotations(
			title: 'Clone Pressable Site',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function pressable_clone_site( string $site_id_or_url, string $name, ?string $datacenter = null, bool $staging = true ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}
		$site = create_pressable_site_clone( $site_id_or_url, $name, $datacenter, $staging );
		return $site ? (array) $site : array( 'error' => 'Failed to clone Pressable site.' );
	}

	#[McpTool( name: 'pressable_rotate_wp_user_password' )]
	public function pressable_rotate_wp_user_password( string $site_id_or_url, string $user = 'concierge@wordpress.com' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}
		$credentials = rotate_pressable_site_wp_user_password( $site_id_or_url, $user );
		return $credentials ? (array) $credentials : array( 'error' => 'Failed to rotate Pressable WP user password.' );
	}

	#[McpTool(
		name: 'pressable_run_wp_cli_command',
		annotations: new ToolAnnotations(
			title: 'Run Pressable WP-CLI Command (High Risk)',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function pressable_run_wp_cli_command( string $site_id_or_url, string $wp_cli_command ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		if ( ! self::is_allowed_wp_cli_command( $wp_cli_command ) ) {
			return array(
				'error'          => 'Command is not allowed by MCP WP-CLI allowlist.',
				'allowed_prefix' => array( 'option get', 'plugin list', 'theme list', 'core version', 'site health', 'user list', 'post list', 'term list', 'comment list', 'transient get' ),
			);
		}

		self::audit_wp_cli_command( 'pressable', $site_id_or_url, $wp_cli_command );

		$exit_code = run_pressable_site_wp_cli_command( $site_id_or_url, $wp_cli_command, true );
		return array(
			'exit_code' => $exit_code,
			'output'    => $GLOBALS['wp_cli_output'] ?? '',
		);
	}

	#[McpTool( name: 'pressable_open_site_shell' )]
	public function pressable_open_site_shell( string $site_id_or_url, string $shell_type = 'ssh' ): array {
		return array(
			'error'      => 'Unsupported operation in MCP context: interactive shell sessions are not supported over JSON-RPC.',
			'site'       => $site_id_or_url,
			'shell_type' => $shell_type,
		);
	}

	#[McpTool( name: 'pressable_upload_site_icon' )]
	public function pressable_upload_site_icon( string $site_id_or_url ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		return self::run_cli_command(
			'pressable:upload-site-icon',
			array( $site_id_or_url )
		);
	}

	#[McpTool(
		name: 'github_create_repository',
		annotations: new ToolAnnotations(
			title: 'Create GitHub Repository',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function github_create_repository( string $name, ?string $type = null, ?string $homepage = null, ?string $description = null, string $custom_properties_json = '{}' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$custom_properties = decode_json_content( $custom_properties_json, true );
		if ( ! is_array( $custom_properties ) ) {
			return array( 'error' => 'Invalid custom_properties_json. Expected a JSON object.' );
		}

		$repository = create_github_repository( $name, $type, $homepage, $description, $custom_properties );
		if ( null === $repository ) {
			return array( 'error' => 'Failed to create GitHub repository.' );
		}

		$topics        = array( 'team51-' . ( $type ?: 'empty' ) );
		$topics_result = null;
		try {
			$topics_result = set_github_repository_topics( $repository->name, $topics );
		} catch ( \Throwable $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[MCP] Failed to set GitHub repository topics: ' . ( encode_json_content(
					array(
						'repository' => $repository->name,
						'topics'     => $topics,
						'error'      => $e->getMessage(),
					)
				) ?? '' )
			);
		}

		if ( null === $topics_result ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[MCP] GitHub topics not applied: ' . ( encode_json_content(
					array(
						'repository' => $repository->name,
						'topics'     => $topics,
						'result'     => $topics_result,
					)
				) ?? '' )
			);
		}

		return (array) $repository;
	}

	#[McpTool( name: 'github_export_pattern_to_repo' )]
	public function github_export_pattern_to_repo( string $site_id_or_url, string $pattern_name, string $category_slug, bool $preserve_images = false ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$args = array( $site_id_or_url, $pattern_name, $category_slug );
		if ( $preserve_images ) {
			$args[] = '--preserve-images';
		}
		return self::run_cli_command( 'github:export-pattern-to-repo', $args );
	}

	#[McpTool( name: 'github_add_checklist' )]
	public function github_add_checklist( string $checklist, string $repository, string $host = 'pressable', bool $skip_issue = false, string $conditional_tags_json = '{}' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$tags = decode_json_content( $conditional_tags_json, true );
		if ( ! is_array( $tags ) ) {
			return array( 'error' => 'Invalid conditional_tags_json. Expected a JSON object.' );
		}

		$args = array( $checklist, $repository, $host );
		if ( $skip_issue ) {
			$args[] = '--skip-issue';
		}
		foreach ( $tags as $tag => $enabled ) {
			if ( true === $enabled ) {
				$args[] = '--' . $tag;
			}
		}

		return self::run_cli_command( 'github:add-checklist', $args );
	}

	#[McpTool(
		name: 'deployhq_create_project',
		annotations: new ToolAnnotations(
			title: 'Create DeployHQ Project',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function deployhq_create_project( string $name, int $zone_id = 6, string $template_id = 'pressable-included-integration', ?string $repository = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$project = create_deployhq_project( $name, $zone_id, array( 'template_id' => $template_id ) );
		if ( null === $project ) {
			return array( 'error' => 'Failed to create DeployHQ project.' );
		}

		if ( $repository ) {
			$connected = update_deployhq_project_repository( $project->permalink, "git@github.com:a8cteam51/$repository.git" );
			return array(
				'project'             => (array) $project,
				'repository_connect'  => $connected ? (array) $connected : array( 'error' => 'Failed to connect repository.' ),
			);
		}

		return (array) $project;
	}

	#[McpTool(
		name: 'deployhq_create_project_server',
		annotations: new ToolAnnotations(
			title: 'Create DeployHQ Project Server',
			readOnlyHint: false,
			destructiveHint: true,
			idempotentHint: false,
			openWorldHint: true,
		)
	)]
	public function deployhq_create_project_server( string $project, string $site_id_or_url, string $name, string $branch = 'trunk', string $branch_source = 'trunk' ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$gh_repository = get_github_repository_from_deployhq_project( $project );
		$site          = get_pressable_site( $site_id_or_url );
		$sftp_owner    = $site ? get_pressable_site_sftp_owner( $site->id ) : null;

		if ( ! $gh_repository || ! $site || ! $sftp_owner ) {
			return array( 'error' => 'Failed to resolve DeployHQ project repository or Pressable site owner.' );
		}

		$branches = get_github_repository_branches( $gh_repository->name ) ?? array();
		if ( ! in_array( $branch, array_column( $branches, 'name' ), true ) ) {
			$created = create_github_repository_branch( $gh_repository->name, $branch, $branch_source );
			if ( null === $created ) {
				return array( 'error' => "Failed to create GitHub branch: $branch" );
			}
		}

		$server = create_deployhq_project_server(
			$project,
			$name,
			array(
				'protocol_type'      => 'ssh',
				'server_path'        => 'wp-content',
				'email_notify_on'    => 'never',
				'root_path'          => '',
				'auto_deploy'        => true,
				'notification_email' => '',
				'branch'             => $branch,
				'environment'        => 'trunk' === $branch ? 'production' : 'development',
				'hostname'           => \Pressable_Connection_Helper::SSH_HOST,
				'username'           => $sftp_owner->username,
				'port'               => 22,
				'use_ssh_keys'       => true,
			)
		);

		return $server ? (array) $server : array( 'error' => 'Failed to create DeployHQ project server.' );
	}

	#[McpTool( name: 'jetpack_connection_triage' )]
	public function jetpack_connection_triage( ?string $csv_path = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$args = array();
		if ( $csv_path ) {
			$args[] = $csv_path;
		}
		return self::run_cli_command( 'jetpack:connection-triage', $args );
	}

	#[McpTool( name: 'jetpack_export_site_plugins' )]
	public function jetpack_export_site_plugins( ?string $site_id_or_url = null, ?string $multiple = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$sites = array();
		if ( $site_id_or_url ) {
			$site = get_wpcom_site( $site_id_or_url );
			if ( $site ) {
				$sites = array(
					$site->ID => (object) array(
						'userblog_id' => $site->ID,
						'siteurl'     => $site->URL,
					),
				);
			}
		} elseif ( 'all' === $multiple || null === $multiple ) {
			$jetpack_sites = get_wpcom_jetpack_sites();
			if ( null === $jetpack_sites ) {
				return array( 'error' => 'Failed to fetch Jetpack sites from WPCOM.' );
			}

			$all_sites = self::filter_sites_by_deny_list(
				$jetpack_sites,
				array( 'mystagingwebsite.com', 'go-vip.co', 'wpcomstaging.com', 'wpengine.com', 'jurassic.ninja', 'atomicsites.blog', 'woocommerce.com', 'woo.com' )
			);
			$sites     = array();
			foreach ( $all_sites as $site ) {
				$sites[ $site->userblog_id ] = (object) array(
					'userblog_id' => $site->userblog_id,
					'siteurl'     => $site->siteurl,
				);
			}
		} else {
			foreach ( array_map( 'trim', explode( ',', $multiple ) ) as $identifier ) {
				$site = get_wpcom_site( $identifier );
				if ( $site ) {
					$sites[ $site->ID ] = (object) array(
						'userblog_id' => $site->ID,
						'siteurl'     => $site->URL,
					);
				}
			}
		}

		$plugins = get_wpcom_site_plugins_batch( array_column( $sites, 'userblog_id' ), $errors ) ?? array();
		$rows    = array();
		foreach ( $plugins as $site_id => $site_plugins ) {
			foreach ( $site_plugins as $plugin_file => $plugin_data ) {
				$rows[] = array(
					'site_id'   => $sites[ $site_id ]->userblog_id,
					'site_url'  => $sites[ $site_id ]->siteurl,
					'name'      => $plugin_data->Name,
					'slug'      => dirname( $plugin_file ),
					'version'   => $plugin_data->Version,
					'status'    => ( $plugin_data->active ?? false ) ? 'Active' : 'Inactive',
				);
			}
		}

		return array(
			'count'  => count( $rows ),
			'sites'  => count( $sites ),
			'rows'   => $rows,
			'errors' => array_map( static fn( $e ) => (array) $e, $errors ?? array() ),
		);
	}

	#[McpTool( name: 'cli_export_commands' )]
	public function cli_export_commands( string $format = 'md', ?string $destination = null ): array {
		$identity_error = self::ensure_identity();
		if ( $identity_error ) {
			return $identity_error;
		}

		$args = array( '--format', $format );
		if ( $destination ) {
			$args[] = '--destination';
			$args[] = $destination;
		}

		return self::run_cli_command( 'export-commands', $args );
	}

	// endregion
}
