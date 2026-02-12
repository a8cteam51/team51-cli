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
 * High-risk operations (site creation, user deletion, WP-CLI execution,
 * deployments) are intentionally excluded. See the README for details.
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
}
