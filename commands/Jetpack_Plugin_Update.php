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
	 * Optional guard: only update sites whose installed version is below this value.
	 *
	 * @var string|null
	 */
	private ?string $min_version = null;

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
		$this->setDescription( 'Force-updates a given plugin on all connected sites where it is installed.' )
			->setHelp( 'Use this command to push a new plugin release to every site that has the plugin installed. Each site is updated via the WPCOM plugin update endpoint, which refreshes its update cache and installs from the plugin\'s own update source (wp.org, or a custom Update URI such as GitHub). Only sites with an active Jetpack connection to WPCOM are processed. The update only fires where a newer version is available, so publish the new release before running this.' );

		$this->addArgument( 'plugin', InputArgument::REQUIRED, 'The plugin to update. The term is matched exactly against the folder name, the main file name, and the textdomain.' );

		$this->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Limit the update to a single site (numeric WPCOM ID or a domain). Useful to canary before running against the fleet.' )
			->addOption( 'min-version', null, InputOption::VALUE_REQUIRED, 'Only update sites whose installed version is below this value.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'List the sites that would be updated without updating them.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt before updating.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If the `--site` value matches no connected Jetpack site.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->plugin = get_string_input( $input, 'plugin', fn() => $this->prompt_plugin_input( $input, $output ) );
		$input->setArgument( 'plugin', $this->plugin );

		$this->min_version = maybe_get_string_input( $input, 'min-version' );
		$this->dry_run     = get_bool_input( $input, 'dry-run' );
		$this->yes         = get_bool_input( $input, 'yes' );

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		$site_option = $input->getOption( 'site' );
		if ( ! empty( $site_option ) ) {
			$this->sites = \array_filter(
				$this->sites,
				static fn( $site ) => (string) $site->userblog_id === (string) $site_option
					|| false !== \stripos( (string) ( $site->siteurl ?? '' ), (string) $site_option )
			);
			if ( empty( $this->sites ) ) {
				throw new \InvalidArgumentException( "No connected Jetpack site matched `$site_option`." );
			}
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

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$installed = (string) $plugin_data->Version;
				if ( ! empty( $this->min_version ) && ! \version_compare( $installed, $this->min_version, '<' ) ) {
					break; // Already at or above the guard version; skip this site.
				}

				$this->targets[ $site_id ] = array(
					'name'      => \preg_replace( '/\.php$/', '', $plugin_file ),
					'installed' => $installed,
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
			$output->writeln( "<comment>No connected sites have the plugin `$this->plugin` installed" . ( empty( $this->min_version ) ? '' : " below version $this->min_version" ) . '.</comment>' );
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
			'failed'  => 0,
		);
		foreach ( $this->targets as $site_id => $target ) {
			$was    = $target['installed'];
			$now    = $was;
			$result = 'failed';

			if ( isset( $errors[ $site_id ] ) ) {
				$now = encode_json_content( $errors[ $site_id ]->errors ?? $errors[ $site_id ] );
			} elseif ( isset( $results[ $site_id ] ) ) {
				$response = $results[ $site_id ];
				$now      = (string) ( $response->version ?? $was );
				$log      = \implode( ' ', (array) ( $response->log ?? array() ) );
				// The update endpoint always logs either "No update needed" or the upgrade steps.
				$result = false !== \stripos( $log, 'no update needed' ) ? 'current' : 'updated';
			}

			++$counts[ $result ];
			$rows[] = array( $site_id, $target['siteurl'], $was, $now, $result );
		}

		output_table(
			$output,
			$rows,
			array( 'Site ID', 'Site URL', 'Was', 'Now', 'Result' ),
			"Update results for `$this->plugin`"
		);

		$output->writeln( "<info>Updated: {$counts['updated']} | Already current: {$counts['current']} | Failed: {$counts['failed']}</info>" );

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

	// endregion
}
