<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Force-updates a given plugin across all connected Jetpack sites where it is installed.
 */
#[AsCommand( name: 'jetpack:plugin-update' )]
final class Jetpack_Plugin_Update extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Number of sites per batch when force-installing (each install is a real download + overwrite).
	 */
	private const FORCE_BATCH_SIZE = 30;

	/**
	 * Number of sites per batch when refreshing the update-check. Matches the server-side `maxItems`
	 * on `sites/batch/atlantis-force-check`: that route fans out one concurrent tunnelled POST per
	 * site with no chunking, so the client keeps each request self-limiting.
	 */
	private const REFRESH_BATCH_SIZE = 30;

	/**
	 * The plugin slug to update (matched against folder name, main file name, and textdomain).
	 *
	 * @var string|null
	 */
	private ?string $plugin = null;

	/**
	 * Optional release version being published; sites are classified against it (updated/current/ahead/behind).
	 *
	 * @var string|null
	 */
	private ?string $release = null;

	/**
	 * Whether to only list the sites that would be updated, without updating them.
	 *
	 * @var bool|null
	 */
	private ?bool $dry_run = null;

	/**
	 * Whether to skip the confirmation prompt before updating.
	 *
	 * @var bool|null
	 */
	private ?bool $yes = null;

	/**
	 * Whether to force-install the package on every targeted site, bypassing update detection.
	 *
	 * @var bool|null
	 */
	private ?bool $force = null;

	/**
	 * The plugin zip URL to force-install (required with --force).
	 *
	 * @var string|null
	 */
	private ?string $package = null;

	/**
	 * With --force, whether to also overwrite sites already at the package version (same-version reinstall).
	 *
	 * @var bool|null
	 */
	private ?bool $reinstall = null;

	/**
	 * The plugin version contained in the package (read from the zip, or --release as a fallback).
	 *
	 * @var string|null
	 */
	private ?string $target_version = null;

	/**
	 * Whether to make the targeted sites re-check for updates before updating (detection path only).
	 *
	 * @var bool|null
	 */
	private ?bool $refresh = null;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The sites that have the plugin installed and will be updated, keyed by site ID.
	 * Each entry: array{ name: string, folder: string, installed: string, siteurl: string, plan?: string }.
	 * The `plan` key (`install`/`current`/`ahead`) is added by plan_force_install() in --force mode.
	 *
	 * @var array|null
	 */
	private ?array $targets = null;

	/**
	 * Sites that could not be queried for their installed plugins, keyed by WPCOM ID. Surfaced in the
	 * summary and the exit code so a fleet-wide push cannot silently skip unreachable sites.
	 *
	 * @var array
	 */
	private array $unqueryable = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Force-updates a given plugin on connected sites where it is installed.' )
			->setHelp( 'Use this command to push a new plugin release to sites that have the plugin installed. Pass --sites to choose the targets: `all` for the whole connected fleet, a comma-separated list of site URLs and/or WPCOM IDs, or the path to a CSV whose first column holds the site URLs. By default each site is updated via the WPCOM plugin update endpoint, which relies on the site having already detected a newer version from the plugin\'s own update source (wp.org, or a custom Update URI such as GitHub) — so a freshly published release may not reach every site immediately. Pass --force with --package <zip-url> to instead overwrite the plugin in place from a specific zip on every targeted site, bypassing update detection entirely (the deterministic way to push a just-published release fleet-wide). If a release has been published to wp.org but sites have not detected it yet, pass --refresh to make the targeted sites (those chosen with --sites) re-check for updates first (via the Atlantis lever), then update — no force-install needed. Only sites with an active Jetpack connection to WPCOM are processed.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin to update. The term is matched exactly against the folder name, the main file name, and the textdomain.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Which sites to update: `all` for the whole connected fleet, a comma-separated list of site URLs and/or numeric WPCOM IDs, or a path to a CSV file whose first column holds the site URLs.' )
			->addOption( 'release', null, InputOption::VALUE_REQUIRED, 'The plugin version you are publishing. When set, each site is reported as updated / current / ahead / behind relative to it, so sites running a newer (e.g. test) build, or ones the release has not reached, are surfaced.' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Force-install the plugin from --package, overwriting it in place. Installs only on sites whose version is below the package version; sites already at that version are skipped and sites ahead of it are never touched (no downgrades). Bypasses update detection entirely — use this when a just-published release has not propagated to sites yet.' )
			->addOption( 'package', null, InputOption::VALUE_REQUIRED, 'The plugin zip URL to install. Required with --force (e.g. a GitHub release asset URL).' )
			->addOption( 'reinstall', null, InputOption::VALUE_NONE, 'With --force, also overwrite sites already at the package version (a same-version reinstall). Sites ahead of the package version are still never touched.' )
			->addOption( 'refresh', null, InputOption::VALUE_NONE, 'Before updating, force the targeted sites to re-check for updates (via the Atlantis lever) so a just-published wp.org or WooCommerce.com release is detected even if their update cache has not refreshed yet. Runs synchronously against the sites you chose with --sites. Applies to the default update path; has no effect with --force, which bypasses update detection.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'List the sites that would be updated without updating them.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt before updating.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--sites` is missing/unmatched, or an option is misused
	 *                                   (`--force` without `--package`; `--package`/`--reinstall` without
	 *                                   `--force`; `--refresh` with `--force`).
	 * @throws \RuntimeException         If the connected fleet, the sites' installed plugins, or the
	 *                                   `--package` zip cannot be fetched.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		$this->release   = maybe_get_string_input( $input, 'release' );
		$this->dry_run   = get_bool_input( $input, 'dry-run' );
		$this->yes       = get_bool_input( $input, 'yes' );
		$this->force     = get_bool_input( $input, 'force' );
		$this->reinstall = get_bool_input( $input, 'reinstall' );
		$this->package   = maybe_get_string_input( $input, 'package' );
		$this->refresh   = get_bool_input( $input, 'refresh' );

		// Reject misused option combinations instead of silently ignoring them.
		if ( $this->force && empty( $this->package ) ) {
			throw new \InvalidArgumentException( 'The --force option requires --package <zip-url> (e.g. a GitHub release asset URL).' );
		}
		if ( ! $this->force && ! empty( $this->package ) ) {
			throw new \InvalidArgumentException( 'The --package option only applies to --force.' );
		}
		if ( ! $this->force && $this->reinstall ) {
			throw new \InvalidArgumentException( 'The --reinstall option only applies to --force.' );
		}
		if ( $this->refresh && $this->force ) {
			throw new \InvalidArgumentException( 'The --refresh option applies to the detection-based update path and has no effect with --force (which bypasses update detection). Use one or the other.' );
		}

		$sites_spec = get_string_input( $input, 'sites', fn() => $this->prompt_sites_input( $input, $output ) );

		$all_sites = get_wpcom_jetpack_sites();
		if ( \is_null( $all_sites ) ) {
			throw new \RuntimeException( 'Could not fetch the connected Jetpack sites from WPCOM.' );
		}
		$output->writeln( '<comment>Successfully fetched ' . \count( $all_sites ) . ' connected Jetpack site(s).</comment>' );

		if ( 'all' === \strtolower( \trim( $sites_spec ) ) ) {
			$this->sites = $all_sites;
		} else {
			[ $matched, $unmatched ] = $this->resolve_sites( $this->parse_requested_identifiers( $sites_spec ), $all_sites );

			if ( ! empty( $unmatched ) ) {
				$output->writeln( '<comment>⚠ Not found in the connected fleet, skipped:</comment>' );
				foreach ( $unmatched as $identifier ) {
					$output->writeln( "  - $identifier" );
				}
			}
			if ( empty( $matched ) ) {
				throw new \InvalidArgumentException( 'None of the requested sites were found in the connected Jetpack fleet.' );
			}

			$this->sites = $matched;
			$output->writeln( '<comment>Matched ' . \count( $matched ) . ' of the requested site(s).</comment>' );
		}

		// Fetch the plugins installed on the target sites and compile the list of sites to update.
		$plugins = get_wpcom_site_plugins_batch( \array_column( $this->sites, 'userblog_id' ), $errors );
		if ( \is_null( $plugins ) ) {
			throw new \RuntimeException( 'Could not fetch the installed plugins for the requested sites from WPCOM.' );
		}
		$this->unqueryable = \is_array( $errors ) ? $errors : array();
		maybe_output_wpcom_failed_sites_table( $output, $this->unqueryable, $this->sites, 'Sites that could NOT be queried for plugins' );

		$this->targets = array();
		foreach ( $plugins as $site_id => $site_plugins ) {
			foreach ( $site_plugins as $plugin_file => $plugin_data ) {
				if ( ! $this->is_exact_match( $plugin_data, \dirname( $plugin_file ), \basename( $plugin_file, '.php' ) ) ) {
					continue;
				}

				$this->targets[ $site_id ] = array(
					'name'      => \preg_replace( '/\.php$/', '', $plugin_file ),
					'folder'    => \dirname( $plugin_file ),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'installed' => (string) $plugin_data->Version,
					'siteurl'   => (string) ( $this->sites[ $site_id ]->siteurl ?? '' ),
				);
				break; // One match per site is enough.
			}
		}

		if ( $this->force && ! empty( $this->targets ) ) {
			$this->plan_force_install();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( empty( $this->targets ) ) {
			if ( ! empty( $this->unqueryable ) ) {
				$output->writeln( '<error>' . \count( $this->unqueryable ) . ' site(s) could not be queried for plugins (see the table above), so no sites could be assessed.</error>' );
				return Command::FAILURE;
			}
			$output->writeln( "<comment>No connected sites have the plugin `$this->plugin` installed.</comment>" );
			return Command::SUCCESS;
		}

		// In force mode, targets carry a plan: only `install` sites are written; `current`/`ahead` are skipped.
		$install_ids = array();
		foreach ( $this->targets as $site_id => $target ) {
			if ( 'install' === ( $target['plan'] ?? 'install' ) ) {
				$install_ids[] = $site_id;
			}
		}

		if ( $this->force ) {
			$skipped_ahead   = \count( \array_filter( $this->targets, static fn( $target ) => 'ahead' === ( $target['plan'] ?? '' ) ) );
			$skipped_current = \count( \array_filter( $this->targets, static fn( $target ) => 'current' === ( $target['plan'] ?? '' ) ) );
			if ( $skipped_ahead > 0 ) {
				$output->writeln( "<comment>Skipping $skipped_ahead site(s) ahead of $this->target_version — never downgraded.</comment>" );
			}
			if ( $skipped_current > 0 ) {
				$output->writeln( "<comment>Skipping $skipped_current site(s) already at $this->target_version — pass --reinstall to overwrite them too.</comment>" );
			}
			if ( empty( $install_ids ) ) {
				if ( ! empty( $this->unqueryable ) ) {
					$this->warn_unqueryable( $output );
					return Command::FAILURE;
				}
				$output->writeln( "<info>Nothing to install: every assessed site is already at or ahead of $this->target_version.</info>" );
				return Command::SUCCESS;
			}
		}

		// Show what will be changed. The matched plugin identifier is shown so a heterogeneous match
		// (different folders/textdomains across sites) is visible before a fleet-wide overwrite.
		output_table(
			$output,
			\array_map(
				fn( $site_id ) => array( $site_id, $this->targets[ $site_id ]['siteurl'], $this->targets[ $site_id ]['name'], $this->targets[ $site_id ]['installed'] ),
				$install_ids
			),
			array( 'Site ID', 'Site URL', 'Plugin', 'Installed Version' ),
			'Sites to ' . ( $this->force ? 'force-install' : 'update' ) . " `$this->plugin`"
		);

		if ( $this->dry_run ) {
			$this->warn_unqueryable( $output );
			$output->writeln( '<comment>Dry run: no sites were changed.</comment>' );
			return Command::SUCCESS;
		}

		if ( ! $this->yes ) {
			$action   = $this->force
				? 'Force-install `' . $this->plugin . '` from ' . $this->package . ' on '
				: ( $this->refresh ? 'Refresh update-checks, then update `' : 'Update `' ) . $this->plugin . '` on ';
			$question = new ConfirmationQuestion( '<question>' . $action . \count( $install_ids ) . ' site(s)? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. No sites were changed.</comment>' );
				return Command::SUCCESS;
			}
		}

		if ( $this->refresh ) {
			$this->run_refresh( $output );
		}

		if ( $this->force ) {
			$output->writeln( "<fg=magenta;options=bold>Force-installing `$this->plugin` ($this->target_version) across " . \count( $install_ids ) . ' site(s).</>' );
			[ $results, $errors ] = $this->run_force_install( $output, $install_ids );
		} else {
			$output->writeln( "<fg=magenta;options=bold>Updating `$this->plugin` across " . \count( $install_ids ) . ' site(s).</>' );
			[ $results, $errors ] = $this->run_update( $output );
		}

		// Build the results table.
		$rows   = array();
		$counts = array(
			'updated'     => 0,
			'reinstalled' => 0,
			'current'     => 0,
			'ahead'       => 0,
			'behind'      => 0,
			'failed'      => 0,
		);
		foreach ( $this->targets as $site_id => $target ) {
			$was  = $target['installed'];
			$now  = $was;
			$plan = $target['plan'] ?? 'install';

			if ( 'install' !== $plan ) {
				$result = $plan; // Skipped in force mode: `current` or `ahead`, left unchanged.
			} elseif ( isset( $errors[ $site_id ] ) ) {
				$now    = encode_json_content( $errors[ $site_id ]->errors ?? $errors[ $site_id ] );
				$result = 'failed';
			} elseif ( isset( $results[ $site_id ] ) ) {
				$now    = (string) ( $results[ $site_id ]->version ?? $was );
				$result = $this->classify( $was, $now );
				// A forced same-version reinstall succeeded but the version didn't move; report it as
				// `reinstalled` rather than `current`, which would read as "nothing happened". Gate on the
				// installed version actually equalling the target so a below-target site whose response
				// carried no version is not mislabelled.
				if ( $this->force && $this->reinstall && 'current' === $result
					&& 0 === \version_compare( $this->normalize_version( $was ), $this->normalize_version( (string) $this->target_version ) ) ) {
					$result = 'reinstalled';
				}
			} else {
				$result = 'failed';
			}

			++$counts[ $result ];
			$rows[] = array( $site_id, $target['siteurl'], $was, $now, $this->format_result( $result ) );
		}

		output_table(
			$output,
			$rows,
			array( 'Site ID', 'Site URL', 'Was', 'Now', 'Result' ),
			( $this->force ? 'Force-install' : 'Update' ) . " results for `$this->plugin`"
		);

		$summary = "Updated: {$counts['updated']} | Current: {$counts['current']}";
		if ( $this->force ) {
			$summary .= " | Reinstalled: {$counts['reinstalled']}";
		}
		if ( $this->force || ! empty( $this->release ) ) {
			$summary .= " | Ahead: {$counts['ahead']} | Behind: {$counts['behind']}";
		}
		$summary .= " | Failed: {$counts['failed']}";
		$output->writeln( "<info>$summary</info>" );

		$reference = ! empty( $this->target_version ) ? $this->target_version : $this->release;
		if ( $counts['ahead'] > 0 ) {
			$output->writeln( "<fg=yellow;options=bold>⚠ {$counts['ahead']} site(s) are AHEAD of `$reference` (running a newer/test build) and were left unchanged.</>" );
		}
		if ( $counts['behind'] > 0 ) {
			$output->writeln( "<fg=red;options=bold>✗ {$counts['behind']} site(s) are BEHIND `$this->release` — the release did not reach them (its update source may not have it yet).</>" );
		}
		if ( ! $this->force && empty( $this->release ) && $counts['current'] > 0 ) {
			$output->writeln( '<comment>Tip: some sites reported no change — pass --release <version> to distinguish "already current" from "ahead of the release".</comment>' );
		}
		$this->warn_unqueryable( $output );

		// Real per-site errors and sites we could not even assess fail the command; `behind` (the release
		// hasn't propagated to the plugin's own update source yet) and `ahead` are warnings, not failures.
		return ( 0 === $counts['failed'] && empty( $this->unqueryable ) ) ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * Warns about sites that could not be queried for their installed plugins, when any.
	 *
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  void
	 */
	private function warn_unqueryable( OutputInterface $output ): void {
		if ( ! empty( $this->unqueryable ) ) {
			$output->writeln( '<fg=red;options=bold>✗ ' . \count( $this->unqueryable ) . ' site(s) could not be queried for plugins and were not assessed (see the table above).</>' );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Updates the plugin on every target site via the WPCOM update endpoint (version-gated).
	 *
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  array A two-element list: per-site results and per-site errors, both keyed by site ID.
	 */
	private function run_update( OutputInterface $output ): array {
		// Group by plugin identifier (near-always a single group) and update each group in one batch call.
		$groups = array();
		foreach ( $this->targets as $site_id => $target ) {
			$groups[ $target['name'] ][] = $site_id;
		}

		$results = array();
		$errors  = array();
		foreach ( $groups as $plugin_name => $site_ids ) {
			$group_results = update_wpcom_site_plugins_batch( $site_ids, $plugin_name, $group_errors );
			if ( \is_null( $group_results ) ) {
				$output->writeln( "<error>The update request failed for plugin `$plugin_name`.</error>" );
				continue;
			}
			$results += $group_results;
			$errors  += $group_errors ?? array();
		}

		return array( $results, $errors );
	}

	/**
	 * Forces a fresh update-check on the targeted sites before updating.
	 *
	 * Calls the Atlantis lever (via OpsOasis, over the authenticated Jetpack REST tunnel) on the sites
	 * that have the plugin, so each clears its update cache and re-detects a just-published release
	 * synchronously — before we call the version-gated update endpoint. Best-effort: sites that can't be
	 * reached (no Atlantis, or connection down) are reported, and the update still proceeds.
	 *
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  void
	 */
	private function run_refresh( OutputInterface $output ): void {
		$site_ids = \array_keys( $this->targets );
		$chunks   = \array_chunk( $site_ids, self::REFRESH_BATCH_SIZE );
		$total    = \count( $chunks );
		$output->writeln( '<fg=magenta;options=bold>Refreshing the update-check on ' . \count( $site_ids ) . ' site(s)…</>' );

		$results     = array();
		$errors      = array();
		$unrefreshed = array();
		$any_batch   = false;
		foreach ( $chunks as $index => $chunk ) {
			if ( $total > 1 ) {
				$output->writeln( '<comment>Batch ' . ( $index + 1 ) . "/$total: refreshing " . \count( $chunk ) . ' site(s)…</comment>' );
			}
			$chunk_results = force_check_wpcom_site_plugins_batch( $chunk, $chunk_errors );
			if ( \is_null( $chunk_results ) ) {
				$output->writeln( '<comment>⚠ The refresh request failed for this batch.</comment>' );
				// Record the whole chunk so its sites are not silently dropped from the coverage summary.
				$unrefreshed += \array_fill_keys( $chunk, 'The batch refresh request failed (e.g. timeout).' );
				continue;
			}
			$any_batch = true;
			$results  += $chunk_results;
			$errors   += $chunk_errors ?? array();
		}

		if ( ! $any_batch ) {
			$output->writeln( '<comment>⚠ No refresh batch succeeded; proceeding anyway (sites that already detected the release will still update).</comment>' );
		}

		// State coverage explicitly: a failed batch must not read as full coverage and leave its sites
		// reported "current" at exit 0.
		$notes = array();
		if ( \count( $errors ) > 0 ) {
			$notes[] = \count( $errors ) . ' could not be reached (no Atlantis or connection down)';
		}
		if ( \count( $unrefreshed ) > 0 ) {
			$notes[] = \count( $unrefreshed ) . ' were in a failed batch and not re-checked';
		}
		$suffix = empty( $notes ) ? '' : '; ' . \implode( '; ', $notes ) . ' and may not detect the release';
		$output->writeln( '<comment>Refreshed ' . \count( $results ) . ' of ' . \count( $site_ids ) . ' site(s)' . $suffix . '.</comment>' );

		// Name the sites that were skipped, so they can be re-run or investigated rather than assumed done.
		maybe_output_wpcom_failed_sites_table( $output, $errors, $this->sites, 'Sites that could NOT be reached to refresh' );
		maybe_output_wpcom_failed_sites_table( $output, $unrefreshed, $this->sites, 'Sites in a failed refresh batch (not re-checked)' );
	}

	/**
	 * Force-installs the package on the given sites via the WPCOM replace endpoint, in batches.
	 *
	 * @param   OutputInterface $output      The output interface.
	 * @param   array           $install_ids The site IDs to install on (already filtered by plan).
	 *
	 * @return  array A two-element list: per-site results and per-site errors, both keyed by site ID.
	 */
	private function run_force_install( OutputInterface $output, array $install_ids ): array {
		// Group by plugin folder (the replace slug); near-always a single group.
		$groups = array();
		foreach ( $install_ids as $site_id ) {
			$groups[ $this->targets[ $site_id ]['folder'] ][] = $site_id;
		}

		$results = array();
		$errors  = array();
		foreach ( $groups as $slug => $site_ids ) {
			$chunks = \array_chunk( $site_ids, self::FORCE_BATCH_SIZE );
			$total  = \count( $chunks );
			foreach ( $chunks as $index => $chunk ) {
				$output->writeln( '<comment>Batch ' . ( $index + 1 ) . "/$total: force-installing on " . \count( $chunk ) . ' site(s)…</comment>' );
				$chunk_results = replace_wpcom_site_plugins_batch( $chunk, $slug, $this->package, $chunk_errors );
				if ( \is_null( $chunk_results ) ) {
					$output->writeln( '<error>The force-install request failed for this batch.</error>' );
					continue;
				}
				$results += $chunk_results;
				$errors  += $chunk_errors ?? array();
			}
		}

		return array( $results, $errors );
	}

	/**
	 * Resolves the package version and tags each target with a force-install plan.
	 *
	 * The plan enforces the version rules for --force: sites below the package version are installed,
	 * sites already at it are skipped (unless --reinstall), and sites ahead of it are never touched.
	 *
	 * @return  void
	 *
	 * @throws  \RuntimeException If the package version cannot be determined (no zip version, no --release).
	 */
	private function plan_force_install(): void {
		$this->target_version = $this->resolve_package_version() ?? $this->release;
		if ( empty( $this->target_version ) ) {
			throw new \RuntimeException( 'Could not read the plugin version from the package. Pass --release <version> so already-current and ahead sites can be detected and skipped, or check the --package URL.' );
		}

		foreach ( $this->targets as $site_id => $target ) {
			$comparison = \version_compare( $this->normalize_version( $target['installed'] ), $this->normalize_version( $this->target_version ) );
			if ( $comparison > 0 ) {
				$this->targets[ $site_id ]['plan'] = 'ahead';   // Never overwrite a newer build.
			} elseif ( 0 === $comparison && ! $this->reinstall ) {
				$this->targets[ $site_id ]['plan'] = 'current'; // Already at the target; skip unless --reinstall.
			} else {
				$this->targets[ $site_id ]['plan'] = 'install';
			}
		}
	}

	/**
	 * Reads the plugin version from the package zip's header.
	 *
	 * @return  string|null The version, or null if the (successfully downloaded) package has no readable
	 *                      version header.
	 *
	 * @throws  \RuntimeException If a local temp file cannot be created, or the package URL cannot be
	 *                            downloaded (curl error or non-2xx response) — force-installing an
	 *                            unfetchable URL would fail on every site.
	 */
	private function resolve_package_version(): ?string {
		$tmp = \tempnam( \sys_get_temp_dir(), 't51pkg' );
		if ( false === $tmp ) {
			throw new \RuntimeException( 'Could not create a local temporary file to download the package into.' );
		}

		$handle = \fopen( $tmp, 'wb' );
		if ( false === $handle ) {
			\unlink( $tmp );
			throw new \RuntimeException( 'Could not open a local temporary file to download the package into.' );
		}

		$curl = \curl_init( $this->package );
		\curl_setopt_array(
			$curl,
			array(
				\CURLOPT_FILE           => $handle,
				\CURLOPT_FOLLOWLOCATION => true,
				\CURLOPT_TIMEOUT        => 60,
			)
		);
		$downloaded = \curl_exec( $curl );
		$http_code  = (int) \curl_getinfo( $curl, \CURLINFO_RESPONSE_CODE );
		$curl_error = \curl_error( $curl );
		\curl_close( $curl );
		\fclose( $handle );

		// A bad --package URL is a hard error: don't silently fall back to --release and push a URL that
		// already failed to fetch locally out to the whole fleet.
		if ( false === $downloaded || $http_code < 200 || $http_code >= 300 ) {
			\unlink( $tmp );
			$detail = '' !== $curl_error ? $curl_error : "HTTP $http_code";
			throw new \RuntimeException( "Could not download the package from `$this->package` ($detail)." );
		}

		$version = null;
		if ( \class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $tmp ) ) {
				for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$name = (string) $zip->getNameIndex( $index );
					if ( ! \preg_match( '#^[^/]+/[^/]+\.php$#', $name ) ) {
						continue; // Only top-level PHP files inside the plugin folder.
					}
					$contents = $zip->getFromIndex( $index, 8192 );
					if ( false === $contents || false === \stripos( $contents, 'Plugin Name:' ) ) {
						continue;
					}
					if ( \preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $contents, $matches ) ) {
						$version = \trim( $matches[1] );
						break;
					}
				}
				$zip->close();
			}
		}

		\unlink( $tmp );
		return $version;
	}

	/**
	 * Prompts the user for the plugin term to update.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_plugin_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the slug of the plugin to update:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Checks if the plugin data matches the search term exactly.
	 *
	 * @param   \stdClass $plugin_data   The plugin data.
	 * @param   string    $plugin_folder The plugin folder.
	 * @param   string    $plugin_file   The plugin file.
	 *
	 * @return  boolean
	 */
	private function is_exact_match( \stdClass $plugin_data, string $plugin_folder, string $plugin_file ): bool {
		return $this->plugin === $plugin_data->TextDomain // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			|| $this->plugin === $plugin_folder
			|| $this->plugin === $plugin_file;
	}

	/**
	 * Prompts the user for the sites to update.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_sites_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Which sites? Enter `all`, a comma-separated list of site URLs/IDs, or a path to a CSV:</question> ' );
		return (string) $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Parses the `--sites` value into a list of requested site identifiers.
	 *
	 * The value is either a path to a CSV file (first column holds the site URLs) or a
	 * comma-separated list of URLs and/or numeric WPCOM IDs. Blank and implausible entries
	 * (such as a CSV header row) are dropped.
	 *
	 * @param   string $spec The raw `--sites` value.
	 *
	 * @return  string[]
	 */
	private function parse_requested_identifiers( string $spec ): array {
		$raw = \is_file( $spec ) ? $this->read_csv_first_column( $spec ) : \explode( ',', $spec );

		$identifiers = array();
		foreach ( $raw as $value ) {
			$value = \trim( (string) $value );
			// Keep only plausible identifiers: numeric WPCOM IDs, or values that look like a domain.
			// This also discards a CSV header cell such as "URL" without surfacing it as unmatched.
			if ( '' === $value || ( ! \is_numeric( $value ) && ! \str_contains( $value, '.' ) ) ) {
				continue;
			}
			$identifiers[ $value ] = $value;
		}

		return \array_values( $identifiers );
	}

	/**
	 * Reads the first column of a CSV file.
	 *
	 * @param   string $path The path to the CSV file.
	 *
	 * @return  string[]
	 *
	 * @throws  \RuntimeException If the file cannot be opened.
	 */
	private function read_csv_first_column( string $path ): array {
		$handle = \fopen( $path, 'r' );
		if ( false === $handle ) {
			throw new \RuntimeException( "Could not open the sites file `$path`." );
		}

		$values = array();
		while ( false !== ( $row = \fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( isset( $row[0] ) ) {
				$values[] = $row[0];
			}
		}
		\fclose( $handle );

		return $values;
	}

	/**
	 * Matches the requested identifiers against the connected fleet.
	 *
	 * @param   string[] $identifiers The requested site URLs and/or numeric WPCOM IDs.
	 * @param   array    $all_sites   The connected Jetpack sites, keyed by WPCOM ID.
	 *
	 * @return  array A two-element list: the matched sites keyed by WPCOM ID, and the unmatched identifiers.
	 */
	private function resolve_sites( array $identifiers, array $all_sites ): array {
		$matched   = array();
		$unmatched = array();

		foreach ( $identifiers as $identifier ) {
			$key = $this->match_site( $identifier, $all_sites );
			if ( \is_null( $key ) ) {
				$unmatched[] = $identifier;
				continue;
			}
			$matched[ $key ] = $all_sites[ $key ];
		}

		return array( $matched, $unmatched );
	}

	/**
	 * Finds the fleet key for a single requested identifier, by numeric WPCOM ID or by host.
	 *
	 * @param   string $identifier The requested site URL or numeric WPCOM ID.
	 * @param   array  $all_sites  The connected Jetpack sites, keyed by WPCOM ID.
	 *
	 * @return  int|string|null The matching fleet key, or null when no site matches.
	 */
	private function match_site( string $identifier, array $all_sites ): int|string|null {
		if ( \is_numeric( $identifier ) ) {
			foreach ( $all_sites as $key => $site ) {
				if ( (string) $site->userblog_id === $identifier ) {
					return $key;
				}
			}
		}

		$needle = $this->normalize_host( $identifier );
		if ( '' !== $needle ) {
			foreach ( $all_sites as $key => $site ) {
				if ( $this->normalize_host( (string) ( $site->siteurl ?? '' ) ) === $needle ) {
					return $key;
				}
			}
		}

		return null;
	}

	/**
	 * Normalizes a URL or host to a bare lowercase host for comparison.
	 *
	 * @param   string $value The URL or host to normalize.
	 *
	 * @return  string
	 */
	private function normalize_host( string $value ): string {
		$value = \strtolower( \trim( $value ) );
		$value = \preg_replace( '#^https?://#', '', $value );
		$value = \preg_replace( '#/.*$#', '', $value );

		return $value;
	}

	/**
	 * Classifies a site's outcome from its installed and resulting versions.
	 *
	 * With `--release` set, the site is compared to the release being published, so a newer
	 * (e.g. test) build surfaces as `ahead` and one the release has not reached as `behind`.
	 * Without it, the result is simply whether the version moved forward.
	 *
	 * @param   string $was The version installed before the update.
	 * @param   string $now The version reported after the update.
	 *
	 * @return  string One of `updated`, `current`, `ahead`, `behind`.
	 */
	private function classify( string $was, string $now ): string {
		if ( ! empty( $this->release ) ) {
			$against_release = \version_compare( $this->normalize_version( $now ), $this->normalize_version( $this->release ) );
			if ( $against_release > 0 ) {
				return 'ahead';
			}
			if ( $against_release < 0 ) {
				return 'behind';
			}
		}

		return \version_compare( $this->normalize_version( $was ), $this->normalize_version( $now ), '<' ) ? 'updated' : 'current';
	}

	/**
	 * Formats a result label with colour/emphasis so anomalies stand out in the table.
	 *
	 * @param   string $result The result label.
	 *
	 * @return  string
	 */
	private function format_result( string $result ): string {
		return match ( $result ) {
			'updated'     => '<fg=green>updated</>',
			'reinstalled' => '<fg=green>reinstalled</>',
			'ahead'       => '<fg=yellow;options=bold>⚠ ahead</>',
			'behind'      => '<fg=red;options=bold>✗ behind</>',
			'failed'      => '<fg=red>failed</>',
			default       => $result,
		};
	}

	/**
	 * Normalizes a version string for comparison (drops a leading `v`).
	 *
	 * @param   string $version The version string.
	 *
	 * @return  string
	 */
	private function normalize_version( string $version ): string {
		return \ltrim( \trim( $version ), 'vV' );
	}

	// endregion
}
