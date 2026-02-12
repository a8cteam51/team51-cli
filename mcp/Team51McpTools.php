<?php

namespace WPCOMSpecialProjects\CLI\Mcp;

use PhpMcp\Server\Attributes\McpTool;

/**
 * MCP Tool definitions for the Team51 CLI.
 *
 * These tools expose read-only (and select write) operations from the Team51 CLI
 * as MCP tools, allowing AI assistants to interact with WPCOM, Pressable,
 * GitHub, Jetpack, and DeployHQ services.
 *
 * IMPORTANT: When adding new tools, keep in mind that STDOUT is reserved for
 * JSON-RPC communication. Use STDERR for any debug output.
 */
final class Team51McpTools {

	// region WPCOM TOOLS

	/**
	 * List all sites connected to the team's WordPress.com account.
	 * Returns site ID, name, URL, and connection type for each site.
	 *
	 * @param string $type Filter by site type: 'all' (default), 'jetpack', or 'agency'.
	 */
	#[McpTool( name: 'wpcom_list_sites' )]
	public function wpcom_list_sites( string $type = 'all' ): array {
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
		$plugins = get_wpcom_site_plugins( $site_id_or_domain );
		if ( null === $plugins ) {
			return array( 'error' => "Failed to fetch plugins for site: $site_id_or_domain" );
		}

		return array(
			'count'   => count( $plugins ),
			'plugins' => array_map(
				static function ( string $plugin_file, $plugin_data ) {
					return array(
						'file'        => $plugin_file,
						'name'        => $plugin_data->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'slug'        => dirname( $plugin_file ),
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
		$users = get_wpcom_site_users( $site_id_or_url );
		if ( null === $users ) {
			return array( 'error' => "Failed to fetch users for site: $site_id_or_url" );
		}

		return array(
			'count' => count( $users ),
			'users' => array_map(
				static fn( $user ) => (array) $user,
				$users
			),
		);
	}

	// endregion

	// region PRESSABLE TOOLS

	/**
	 * List all Pressable hosting sites.
	 */
	#[McpTool( name: 'pressable_list_sites' )]
	public function pressable_list_sites(): array {
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

	// endregion

	// region GITHUB TOOLS

	/**
	 * List all GitHub repositories in the organization.
	 */
	#[McpTool( name: 'github_list_repositories' )]
	public function github_list_repositories(): array {
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

	// endregion

	// region JETPACK TOOLS

	/**
	 * List all available Jetpack modules with their descriptions and status info.
	 */
	#[McpTool( name: 'jetpack_list_modules' )]
	public function jetpack_list_modules(): array {
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

	// endregion

	// region DEPLOYHQ TOOLS

	/**
	 * List all DeployHQ projects.
	 */
	#[McpTool( name: 'deployhq_list_projects' )]
	public function deployhq_list_projects(): array {
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

	// endregion
}
