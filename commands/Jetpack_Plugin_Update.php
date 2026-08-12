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
	 * With --force, whether to also overwrite sites ahead of the package version (deliberate downgrade).
	 *
	 * @var bool|null
	 */
	private ?bool $downgrade = null;

	/**
	 * Optional environment filter applied to the chosen sites: `staging` or `production` (by URL substring).
	 *
	 * @var string|null
	 */
	private ?string $environment = null;

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
			->addOption( 'environment', null, InputOption::VALUE_REQUIRED, 'Restrict the chosen sites by environment: `staging` acts only on sites whose URL contains "staging"; `production` skips every site whose URL contains "staging". Applied after --sites.' )
			->addOption( 'release', null, InputOption::VALUE_REQUIRED, 'The plugin version you are publishing. When set, each site is reported as updated / current / ahead / behind relative to it, so sites running a newer (e.g. test) build, or ones the release has not reached, are surfaced.' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Force-install the plugin from --package, overwriting it in place. Installs only on sites whose version is below the package version; sites already at that version are skipped (see --reinstall) and sites ahead of it are never touched (see --downgrade). Bypasses update detection entirely — use this when a just-published release has not propagated to sites yet.' )
			->addOption( 'package', null, InputOption::VALUE_REQUIRED, 'The plugin zip URL to install. Required with --force (e.g. a GitHub release asset URL).' )
			->addOption( 'reinstall', null, InputOption::VALUE_NONE, 'With --force, also overwrite sites already at the package version (a same-version reinstall). Sites ahead of the package version are still never touched.' )
			->addOption( 'downgrade', null, InputOption::VALUE_NONE, 'With --force, ALSO overwrite sites running a version NEWER than the package — i.e. roll them back to the package version. This deliberately bypasses the no-downgrade guard; use it to recover sites stuck on a broken newer build. Destructive: newer builds are replaced with the (older) package.' )
			->addOption( 'refresh', null, InputOption::VALUE_NONE, 'Before updating, force the targeted sites to re-check for updates (via the Atlantis lever) so a just-published wp.org or WooCommerce.com release is detected even if their update cache has not refreshed yet. Runs synchronously against the sites you chose with --sites. Applies to the default update path; has no effect with --force, which bypasses update detection.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'List the sites that would be updated without updating them.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt before updating.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--sites` is missing/unmatched, no sites survive the
	 *                                   `--environment` filter, an unknown `--environment` value is given, or
	 *                                   an option is misused (`--force` without `--package`;
	 *                                   `--package`/`--reinstall`/`--downgrade` without `--force`; `--refresh`
	 *                                   with `--force`).
	 * @throws \RuntimeException         If the connected fleet, the sites' installed plugins, or the
	 *                                   `--package` zip cannot be fetched.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		$this->release     = maybe_get_string_input( $input, 'release' );
		$this->dry_run     = get_bool_input( $input, 'dry-run' );
		$this->yes         = get_bool_input( $input, 'yes' );
		$this->force       = get_bool_input( $input, 'force' );
		$this->reinstall   = get_bool_input( $input, 'reinstall' );
		$this->downgrade   = get_bool_input( $input, 'downgrade' );
		$this->package     = maybe_get_string_input( $input, 'package' );
		$this->refresh     = get_bool_input( $input, 'refresh' );
		$this->environment = maybe_get_string_input( $input, 'environment' );

		// Reject misused option combinations instead of silently ignoring them.
		validate_wpcom_plugin_update_options( (bool) $this->force, $this->package, (bool) $this->reinstall, (bool) $this->downgrade, (bool) $this->refresh, $this->environment );

		$sites_spec = get_string_input( $input, 'sites', fn() => $this->prompt_sites_input( $input, $output ) );

		// Resolve, filter, inventory, and plan via the shared planner (single source of the no-downgrade guard).
		$plan = build_wpcom_plugin_update_plan( $this->plugin, $sites_spec, $this->environment, (bool) $this->force, $this->package, $this->release, (bool) $this->reinstall, (bool) $this->downgrade );

		$this->sites          = $plan['sites'];
		$this->targets        = $plan['targets'];
		$this->target_version = $plan['target_version'];
		$this->unqueryable    = $plan['unqueryable'];

		// Surface how the fleet was narrowed, mirroring what the planner did.
		$output->writeln( '<comment>Successfully fetched ' . $plan['fetched_count'] . ' connected Jetpack site(s).</comment>' );
		if ( 'all' !== \strtolower( \trim( $sites_spec ) ) ) {
			if ( ! empty( $plan['unmatched'] ) ) {
				$output->writeln( '<comment>⚠ Not found in the connected fleet, skipped:</comment>' );
				foreach ( $plan['unmatched'] as $identifier ) {
					$output->writeln( "  - $identifier" );
				}
			}
			$output->writeln( '<comment>Matched ' . $plan['matched_count'] . ' of the requested site(s).</comment>' );
		}
		if ( ! \is_null( $this->environment ) ) {
			$output->writeln( '<comment>Environment filter `' . $this->environment . '`: kept ' . $plan['env_after'] . ' of ' . $plan['env_before'] . ' site(s).</comment>' );
		}
		maybe_output_wpcom_failed_sites_table( $output, $this->unqueryable, $this->sites, 'Sites that could NOT be queried for plugins' );
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
			$to_downgrade = \count(
				\array_filter(
					$this->targets,
					fn( $target ) => 'install' === ( $target['plan'] ?? 'install' )
						&& \version_compare( normalize_version_string( $target['installed'] ), normalize_version_string( (string) $this->target_version ), '>' )
				)
			);
			if ( $to_downgrade > 0 ) {
				$output->writeln( "<fg=red;options=bold>⚠ DOWNGRADE: $to_downgrade site(s) are NEWER than $this->target_version and will be ROLLED BACK to it — newer builds get overwritten with the older package.</>" );
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

		$progress = fn( string $message ) => $output->writeln( $message );

		// Refresh the update-check first (detection path only) so a just-published release is detected,
		// naming any sites the refresh could not reach or re-check so they are not assumed done.
		if ( $this->refresh ) {
			$refresh = run_wpcom_plugin_update_refresh( \array_keys( $this->targets ), $progress );
			maybe_output_wpcom_failed_sites_table( $output, $refresh['unreachable'], $this->sites, 'Sites that could NOT be reached to refresh' );
			maybe_output_wpcom_failed_sites_table( $output, $refresh['unrefreshed'], $this->sites, 'Sites in a failed refresh batch (not re-checked)' );
		}

		$output->writeln(
			$this->force
				? "<fg=magenta;options=bold>Force-installing `$this->plugin` ($this->target_version) across " . \count( $install_ids ) . ' site(s).</>'
				: "<fg=magenta;options=bold>Updating `$this->plugin` across " . \count( $install_ids ) . ' site(s).</>'
		);

		$execution = execute_wpcom_plugin_update_plan( array( 'targets' => $this->targets ), (bool) $this->force, $this->package, $progress );
		$results   = $execution['results'];
		$errors    = $execution['errors'];

		// Build the results table.
		$rows   = array();
		$counts = array(
			'updated'     => 0,
			'reinstalled' => 0,
			'downgraded'  => 0,
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
					&& 0 === \version_compare( normalize_version_string( $was ), normalize_version_string( (string) $this->target_version ) ) ) {
					$result = 'reinstalled';
				}
				// A forced --downgrade succeeded: the site was ahead of the package and was rolled back.
				// classify() reads the backwards version move as `current`, so label it `downgraded` instead.
				if ( $this->force && $this->downgrade
					&& \version_compare( normalize_version_string( $was ), normalize_version_string( (string) $this->target_version ), '>' ) ) {
					$result = 'downgraded';
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
			if ( $counts['downgraded'] > 0 ) {
				$summary .= " | Downgraded: {$counts['downgraded']}";
			}
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
			$against_release = \version_compare( normalize_version_string( $now ), normalize_version_string( $this->release ) );
			if ( $against_release > 0 ) {
				return 'ahead';
			}
			if ( $against_release < 0 ) {
				return 'behind';
			}
		}

		return \version_compare( normalize_version_string( $was ), normalize_version_string( $now ), '<' ) ? 'updated' : 'current';
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
			'downgraded'  => '<fg=yellow;options=bold>↓ downgraded</>',
			'ahead'       => '<fg=yellow;options=bold>⚠ ahead</>',
			'behind'      => '<fg=red;options=bold>✗ behind</>',
			'failed'      => '<fg=red>failed</>',
			default       => $result,
		};
	}


	// endregion
}
