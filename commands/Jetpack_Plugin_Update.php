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
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The sites that have the plugin installed and will be updated, keyed by site ID.
	 * Each entry: array{ name: string, installed: string, siteurl: string }.
	 *
	 * @var array|null
	 */
	private ?array $targets = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Force-updates a given plugin on connected sites where it is installed.' )
			->setHelp( 'Use this command to push a new plugin release to sites that have the plugin installed. Pass --sites to choose the targets: `all` for the whole connected fleet, a comma-separated list of site URLs and/or WPCOM IDs, or the path to a CSV whose first column holds the site URLs. Each site is updated via the WPCOM plugin update endpoint, which refreshes its update cache and installs from the plugin\'s own update source (wp.org, or a custom Update URI such as GitHub). Only sites with an active Jetpack connection to WPCOM are processed. The update only fires where a newer version is available, so publish the new release before running this.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin to update. The term is matched exactly against the folder name, the main file name, and the textdomain.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Which sites to update: `all` for the whole connected fleet, a comma-separated list of site URLs and/or numeric WPCOM IDs, or a path to a CSV file whose first column holds the site URLs.' )
			->addOption( 'release', null, InputOption::VALUE_REQUIRED, 'The plugin version you are publishing. When set, each site is reported as updated / current / ahead / behind relative to it, so sites running a newer (e.g. test) build, or ones the release has not reached, are surfaced.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'List the sites that would be updated without updating them.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt before updating.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--sites` is missing or matches no connected Jetpack site.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		$this->release = maybe_get_string_input( $input, 'release' );
		$this->dry_run = get_bool_input( $input, 'dry-run' );
		$this->yes     = get_bool_input( $input, 'yes' );

		$sites_spec = get_string_input( $input, 'sites', fn() => $this->prompt_sites_input( $input, $output ) );

		$all_sites = get_wpcom_jetpack_sites();
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
		maybe_output_wpcom_failed_sites_table( $output, $errors ?? array(), $this->sites, 'Sites that could NOT be queried for plugins' );

		$this->targets = array();
		foreach ( $plugins as $site_id => $site_plugins ) {
			foreach ( $site_plugins as $plugin_file => $plugin_data ) {
				if ( ! $this->is_exact_match( $plugin_data, \dirname( $plugin_file ), \basename( $plugin_file, '.php' ) ) ) {
					continue;
				}

				$this->targets[ $site_id ] = array(
					'name'      => \preg_replace( '/\.php$/', '', $plugin_file ),
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'installed' => (string) $plugin_data->Version,
					'siteurl'   => (string) ( $this->sites[ $site_id ]->siteurl ?? '' ),
				);
				break; // One match per site is enough.
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( empty( $this->targets ) ) {
			$output->writeln( "<comment>No connected sites have the plugin `$this->plugin` installed.</comment>" );
			return Command::SUCCESS;
		}

		// Show what will be updated.
		output_table(
			$output,
			\array_map(
				static fn( $site_id, $target ) => array( $site_id, $target['siteurl'], $target['installed'] ),
				\array_keys( $this->targets ),
				$this->targets
			),
			array( 'Site ID', 'Site URL', 'Installed Version' ),
			"Sites with `$this->plugin` installed"
		);

		if ( $this->dry_run ) {
			$output->writeln( '<comment>Dry run: no sites were updated.</comment>' );
			return Command::SUCCESS;
		}

		if ( ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>Update `' . $this->plugin . '` on ' . \count( $this->targets ) . ' site(s)? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. No sites were updated.</comment>' );
				return Command::SUCCESS;
			}
		}

		$output->writeln( "<fg=magenta;options=bold>Updating `$this->plugin` across " . \count( $this->targets ) . ' site(s).</>' );

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

		// Build the results table.
		$rows   = array();
		$counts = array(
			'updated' => 0,
			'current' => 0,
			'ahead'   => 0,
			'behind'  => 0,
			'failed'  => 0,
		);
		foreach ( $this->targets as $site_id => $target ) {
			$was    = $target['installed'];
			$now    = $was;
			$result = 'failed';

			if ( isset( $errors[ $site_id ] ) ) {
				$now = encode_json_content( $errors[ $site_id ]->errors ?? $errors[ $site_id ] );
			} elseif ( isset( $results[ $site_id ] ) ) {
				$now    = (string) ( $results[ $site_id ]->version ?? $was );
				$result = $this->classify( $was, $now );
			}

			++$counts[ $result ];
			$rows[] = array( $site_id, $target['siteurl'], $was, $now, $this->format_result( $result ) );
		}

		output_table(
			$output,
			$rows,
			array( 'Site ID', 'Site URL', 'Was', 'Now', 'Result' ),
			"Update results for `$this->plugin`"
		);

		$summary = "Updated: {$counts['updated']} | Current: {$counts['current']}";
		if ( ! empty( $this->release ) ) {
			$summary .= " | Ahead: {$counts['ahead']} | Behind: {$counts['behind']}";
		}
		$summary .= " | Failed: {$counts['failed']}";
		$output->writeln( "<info>$summary</info>" );

		if ( $counts['ahead'] > 0 ) {
			$output->writeln( "<fg=yellow;options=bold>⚠ {$counts['ahead']} site(s) are AHEAD of `$this->release` (running a newer/test build) and were left unchanged.</>" );
		}
		if ( $counts['behind'] > 0 ) {
			$output->writeln( "<fg=red;options=bold>✗ {$counts['behind']} site(s) are BEHIND `$this->release` — the release did not reach them (its update source may not have it yet).</>" );
		}
		if ( empty( $this->release ) && $counts['current'] > 0 ) {
			$output->writeln( '<comment>Tip: some sites reported no change — pass --release <version> to distinguish "already current" from "ahead of the release".</comment>' );
		}

		// Only real per-site errors fail the command; `behind` (the release hasn't propagated to the
		// plugin's own update source yet) and `ahead` are surfaced as warnings but are not failures.
		return 0 === $counts['failed'] ? Command::SUCCESS : Command::FAILURE;
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
			'updated' => '<fg=green>updated</>',
			'ahead'   => '<fg=yellow;options=bold>⚠ ahead</>',
			'behind'  => '<fg=red;options=bold>✗ behind</>',
			'failed'  => '<fg=red>failed</>',
			default   => $result,
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
