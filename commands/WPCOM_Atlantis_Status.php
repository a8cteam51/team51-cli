<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;
use WPCOMSpecialProjects\CLI\Helper\ExportTrait;

/**
 * Reports the status of the Atlantis plugin and its modules across
 * Jetpack-connected sites.
 *
 * By default, reports every site and every module. Use `--site` to inspect a
 * single site, or `--module` to narrow the report to one module column.
 * Sites without Atlantis return `not installed`.
 *
 * Atlantis is public, so it only behaves as the team's tool on a site marked
 * as managed and told where the fleet's autoupdate settings are. The `Managed`
 * column and an Autoupdates cell reading `on (no settings address)` are how a
 * site missing either shows up; both count as issues, and
 * `atlantis:mark-managed` is what fixes them.
 */
#[AsCommand( name: 'wpcom:atlantis-status' )]
final class WPCOM_Atlantis_Status extends Command {
	use AutocompleteTrait;
	use ExportTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Columns that precede the module columns in the report.
	 *
	 * @var string[]
	 */
	private const SITE_COLUMNS = array( 'Site ID', 'Site URL', 'Atlantis Version', 'Managed', 'Custom Msgs' );

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The per-site status payloads keyed by site ID. Sites that failed to
	 * respond are absent from this map and present in $errors instead.
	 *
	 * @var array|null
	 */
	private ?array $statuses = null;

	/**
	 * Per-site errors from the batch call.
	 *
	 * @var array|null
	 */
	private ?array $errors = null;

	/**
	 * Module keys known to be reported by the Atlantis status endpoint.
	 *
	 * Ordered to control the column order in the report.
	 *
	 * @var string[]
	 */
	private const KNOWN_MODULE_KEYS = array( 'messages', 'colophon', 'tracking', 'autoupdates', 'bot-protection' );

	/**
	 * Human-readable column labels for module keys whose `ucfirst` form is
	 * unfriendly. Keys absent here fall back to `ucfirst( $key )`.
	 *
	 * @var array<string, string>
	 */
	private const MODULE_LABELS = array(
		'bot-protection' => 'Bot Protection',
	);

	/**
	 * The module key whose status is reported as an enforcement `state`
	 * (inherit|off) rather than a generic enabled on/off flag.
	 *
	 * @var string
	 */
	private const BOT_PROTECTION_KEY = 'bot-protection';

	/**
	 * Module keys included in the current report.
	 *
	 * Either every key in KNOWN_MODULE_KEYS or a single key when --module is set.
	 *
	 * @var string[]
	 */
	private array $module_keys = self::KNOWN_MODULE_KEYS;

	/**
	 * The single site to report on, when --site is set.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $single_site = null;

	/**
	 * Columns of the report.
	 *
	 * Populated in initialize() once the module set is known.
	 *
	 * @var string[]
	 */
	private array $columns = array();

	/**
	 * When true, only sites with Atlantis missing or any module not "on" are rendered.
	 *
	 * @var bool
	 */
	private bool $issues_only = false;

	/**
	 * When true, only sites with at least one stored custom message are rendered.
	 *
	 * @var bool
	 */
	private bool $with_messages_only = false;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Reports Atlantis plugin and module status across Jetpack-connected sites.' )
			->setHelp( 'Calls the OpsOasis batch endpoint for `a8csp-atlantis/v1/status` and prints a per-site report. By default every connected site is queried; pass `--site` to inspect a single site. By default every Atlantis module is shown as its own column; pass `--module` to narrow to one. Sites without Atlantis installed are reported as `not installed`.' );

		$this->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single site, identified by its WPCOM ID or URL. Skips the full fleet fetch.' )
			->addOption( 'module', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single module column. Accepted values: ' . \implode( ', ', self::KNOWN_MODULE_KEYS ) . '.' )
			->addOption( 'prod', null, InputOption::VALUE_NEGATABLE, 'Exclude any site whose URL contains the substring "staging" (case-insensitive). Use --no-prod to suppress the prompt and include staging sites. When neither is set you will be prompted (unless --no-interaction).' )
			->addOption( 'issues-only', null, InputOption::VALUE_NEGATABLE, 'Only show sites where Atlantis is not installed or at least one module is not on. Mandatory modules such as Bot Protection are never counted as issues (their `inherit`/`off` states are deliberate). Use --no-issues-only to suppress the prompt and show all sites. When neither is set you will be prompted (unless --no-interaction).' )
			->addOption( 'with-messages-only', null, InputOption::VALUE_NONE, 'Only show sites that have at least one stored Atlantis custom message.' );

		// With --module, the report only has that module's column.
		$this->configure_export_options( $this->columns_for_modules( self::KNOWN_MODULE_KEYS ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If --module is set to a value outside KNOWN_MODULE_KEYS, or --site cannot be resolved.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$module_option = $input->getOption( 'module' );
		if ( ! \is_null( $module_option ) ) {
			$module_option = \strtolower( (string) $module_option );
			if ( ! \in_array( $module_option, self::KNOWN_MODULE_KEYS, true ) ) {
				throw new \InvalidArgumentException(
					"Unknown module '$module_option'. Accepted values: " . \implode( ', ', self::KNOWN_MODULE_KEYS ) . '.'
				);
			}
			$this->module_keys = array( $module_option );
		}

		$this->columns = $this->columns_for_modules( $this->module_keys );
		$this->initialize_export( $input, $output, 'wpcom-atlantis-status', $this->columns );

		$this->with_messages_only = (bool) $input->getOption( 'with-messages-only' );

		$site_option = $input->getOption( 'site' );
		if ( ! \is_null( $site_option ) ) {
			// Single-site path: --prod and --issues-only are honoured if explicitly passed, but never prompted.
			$this->issues_only = true === $input->getOption( 'issues-only' );
			$this->initialize_single_site( (string) $site_option, $output );
			return;
		}

		$prod_filter       = $this->resolve_boolean_flag( $input, $output, 'prod', 'Would you like to exclude staging sites (any URL containing "staging") from the report?' );
		$this->issues_only = $this->resolve_boolean_flag( $input, $output, 'issues-only', 'Would you like to only show sites where Atlantis is missing or any module is not on?' );

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		if ( $prod_filter ) {
			$before      = \count( $this->sites );
			$this->sites = \array_filter(
				$this->sites,
				static fn( $site ) => false === \stripos( (string) ( $site->siteurl ?? '' ), 'staging' )
			);
			$excluded    = $before - \count( $this->sites );
			$output->writeln( "<comment>Excluding $excluded staging site(s); querying " . \count( $this->sites ) . ' production site(s).</comment>' );
		}

		$this->statuses = get_wpcom_sites_atlantis_status_batch( \array_column( $this->sites, 'userblog_id' ), $this->errors );
		maybe_output_wpcom_failed_sites_table( $output, $this->errors ?? array(), $this->sites, 'Sites that could NOT be queried for Atlantis status' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$scope_label = \is_null( $this->single_site )
			? 'across connected sites'
			: "for site {$this->single_site->siteurl}";
		$output->writeln( "<fg=magenta;options=bold>Reporting Atlantis status $scope_label.</>" );

		$rows                      = array();
		$installed_count           = 0;
		$issue_count               = 0;
		$sites_with_messages_count = 0;
		$module_enabled_counts     = \array_fill_keys(
			\array_filter( $this->module_keys, static fn( string $key ) => self::BOT_PROTECTION_KEY !== $key ),
			0
		);
		$bot_protection_tally      = array(
			'off'          => 0,
			'inherit'      => 0,
			'inapplicable' => 0,
			'absent'       => 0,
		);

		foreach ( $this->sites as $site_id => $site ) {
			$status = $this->statuses[ $site_id ] ?? null;

			$row = array(
				'Site ID'          => $site->userblog_id,
				'Site URL'         => $site->siteurl,
				'Atlantis Version' => 'not installed',
				'Managed'          => '—',
				'Custom Msgs'      => '—',
			);
			foreach ( $this->module_keys as $module_key ) {
				$row[ $this->column_label( $module_key ) ] = '—';
			}

			$has_issue      = true; // Atlantis missing counts as an issue.
			$messages_count = 0;

			if ( \is_object( $status ) ) {
				++$installed_count;
				$has_issue = false;

				$row['Atlantis Version'] = $status->plugin->version ?? 'unknown';

				// Every site in this report is one of ours, so one Atlantis treats as a stranger's is
				// an issue: it reads no central autoupdate settings and sends the team no update
				// emails. A release older than the flag reports nothing and still behaves as managed.
				$managed = $status->plugin->managed ?? null;
				if ( \is_bool( $managed ) ) {
					$row['Managed'] = $managed ? 'yes' : 'no';
					$has_issue      = ! $managed;
				}

				$count_raw = $status->modules->messages->count ?? null;
				if ( \is_int( $count_raw ) || ( \is_string( $count_raw ) && \ctype_digit( $count_raw ) ) ) {
					$messages_count     = (int) $count_raw;
					$row['Custom Msgs'] = $messages_count;
					if ( $messages_count > 0 ) {
						++$sites_with_messages_count;
					}
				}

				foreach ( $this->module_keys as $module_key ) {
					if ( self::BOT_PROTECTION_KEY === $module_key ) {
						// Mandatory module: reported as an enforcement `state`, not an
						// enabled flag, and never counted as an issue.
						$cell                                      = $this->bot_protection_cell( $status );
						$row[ $this->column_label( $module_key ) ] = $cell['value'];
						++$bot_protection_tally[ $cell['bucket'] ];
						continue;
					}

					$enabled = $status->modules->$module_key->enabled ?? null;
					if ( true === $enabled ) {
						$row[ $this->column_label( $module_key ) ] = 'on';
						++$module_enabled_counts[ $module_key ];

						// Running, but never told where the fleet's settings are, so it follows none
						// of them. `atlantis:mark-managed` is what gives a site the address.
						if ( false === ( $status->modules->$module_key->settings_url_configured ?? null ) ) {
							$row[ $this->column_label( $module_key ) ] = 'on (no settings address)';
							$has_issue                                 = true;
						}
					} elseif ( false === $enabled ) {
						$row[ $this->column_label( $module_key ) ] = 'off';
						$has_issue                                 = true;
					} else {
						// Module key absent from response — treat as an issue so it surfaces in --issues-only.
						$has_issue = true;
					}
				}
			}

			if ( $has_issue ) {
				++$issue_count;
			}

			if ( $this->issues_only && ! $has_issue ) {
				continue;
			}

			if ( $this->with_messages_only && $messages_count <= 0 ) {
				continue;
			}

			$rows[] = $row;
		}

		$table_title = 'Atlantis status by site';
		if ( $this->with_messages_only ) {
			$table_title = 'Atlantis sites with custom messages';
		} elseif ( $this->issues_only ) {
			$table_title = 'Atlantis sites with issues';
		}

		output_table(
			$output,
			array_map( 'array_values', $rows ),
			$this->columns,
			$table_title
		);

		$summary_output = array(
			'REPORT SUMMARY'             => '',
			'Total sites queried'        => \count( $this->sites ),
			'Sites with Atlantis'        => $installed_count,
			'Sites with issues'          => $issue_count,
			'Sites with custom messages' => $sites_with_messages_count,
		);
		foreach ( $module_enabled_counts as $module_key => $count ) {
			$summary_output[ $this->column_label( $module_key ) . ' module ON' ] = $count;
		}
		if ( \in_array( self::BOT_PROTECTION_KEY, $this->module_keys, true ) ) {
			$summary_output['Bot Protection forced OFF']               = $bot_protection_tally['off'];
			$summary_output['Bot Protection inherit']                  = $bot_protection_tally['inherit'];
			$summary_output['Bot Protection inert (n/a)']              = $bot_protection_tally['inapplicable'];
			$summary_output['Bot Protection not reported (pre-1.3.0)'] = $bot_protection_tally['absent'];
		}
		$summary_output['Sites that errored'] = \count( $this->errors ?? array() );

		foreach ( $summary_output as $key => $value ) {
			$output->writeln( "<info>$key: $value</info>" );
		}

		$this->maybe_export( $output, $this->columns, $rows, $summary_output );

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the report column label for a module key.
	 *
	 * @param   string $module_key The module key.
	 *
	 * @return  string
	 */
	private function column_label( string $module_key ): string {
		return self::MODULE_LABELS[ $module_key ] ?? \ucfirst( $module_key );
	}

	/**
	 * Returns the report columns for the given modules.
	 *
	 * @param   string[] $module_keys The module keys included in the report.
	 *
	 * @return  string[]
	 */
	private function columns_for_modules( array $module_keys ): array {
		return \array_merge( self::SITE_COLUMNS, \array_map( fn( string $key ) => $this->column_label( $key ), $module_keys ) );
	}

	/**
	 * Computes the Bot Protection column cell for a site's status payload.
	 *
	 * Bot Protection is mandatory, so its shared `enabled` flag is always true and
	 * does not indicate enforcement. The authoritative signal is `state`
	 * (inherit|off); `wp_cloud` and `mu_plugin_present` determine whether the
	 * setting has any effect on this host (the `off` override is a no-op without
	 * both). A site running Atlantis older than 1.3.0 omits the key entirely.
	 *
	 * @param   \stdClass $status The site's Atlantis status payload.
	 *
	 * @return  array{ value: string, bucket: string } Display value and the tally bucket
	 *                                                  (off|inherit|inapplicable|absent).
	 */
	private function bot_protection_cell( \stdClass $status ): array {
		$bot_protection = $status->modules->{self::BOT_PROTECTION_KEY} ?? null;
		if ( ! \is_object( $bot_protection ) || ! \is_string( $bot_protection->state ?? null ) ) {
			// Atlantis predates the Bot Protection module (< 1.3.0), or the
			// payload is malformed — degrade to a dash rather than casting it.
			return array(
				'value'  => '—',
				'bucket' => 'absent',
			);
		}

		$state = (string) $bot_protection->state;

		// `off` and `inherit` are both no-ops without WP Cloud + the mu-plugin, so
		// the applicability qualifier must apply symmetrically to both.
		$has_effect = ! empty( $bot_protection->wp_cloud ) && ! empty( $bot_protection->mu_plugin_present );

		if ( 'off' === $state ) {
			return $has_effect
				? array(
					'value'  => 'forced off',
					'bucket' => 'off',
				)
				: array(
					'value'  => 'forced off (n/a)',
					'bucket' => 'inapplicable',
				);
		}

		// Anything other than `off` defers to WP Cloud's tiers ("inherit"); show the
		// raw state if it is somehow neither, so an unexpected value is not hidden.
		$label = 'inherit' === $state ? 'inherit' : $state;

		if ( ! $has_effect ) {
			return array(
				'value'  => $label . ' (n/a)',
				'bucket' => 'inapplicable',
			);
		}

		return array(
			'value'  => $label,
			'bucket' => 'inherit',
		);
	}

	/**
	 * Resolves a VALUE_NEGATABLE boolean option, prompting the user when the option
	 * is absent and the session is interactive.
	 *
	 * Explicit `--flag` or `--no-flag` always wins. In non-interactive mode an
	 * absent option resolves to `false` (the safe default for these filters).
	 *
	 * @param   InputInterface  $input    The console input.
	 * @param   OutputInterface $output   The console output.
	 * @param   string          $name     The option name.
	 * @param   string          $question The yes/no prompt text.
	 *
	 * @return  bool
	 */
	private function resolve_boolean_flag( InputInterface $input, OutputInterface $output, string $name, string $question ): bool {
		$value = $input->getOption( $name );
		if ( ! \is_null( $value ) ) {
			return (bool) $value;
		}

		if ( ! $input->isInteractive() ) {
			return false;
		}

		$confirm = new ConfirmationQuestion( "<question>$question [y/N]</question> ", false );
		return (bool) $this->getHelper( 'question' )->ask( $input, $output, $confirm );
	}

	/**
	 * Resolves a single site from $site_id_or_url, fetches its Atlantis status, and
	 * populates $this->sites and $this->statuses for the single-site report path.
	 *
	 * @param   string          $site_id_or_url Site URL or WPCOM numeric ID.
	 * @param   OutputInterface $output         Console output (for status messages).
	 *
	 * @throws \InvalidArgumentException If the site cannot be resolved or has no usable ID.
	 *
	 * @return  void
	 */
	private function initialize_single_site( string $site_id_or_url, OutputInterface $output ): void {
		$lookup = $site_id_or_url;
		$host   = \parse_url( $lookup, PHP_URL_HOST );
		if ( ! \is_string( $host ) || '' === $host ) {
			$host = \parse_url( 'https://' . \ltrim( $lookup, '/' ), PHP_URL_HOST );
		}
		if ( \is_string( $host ) && '' !== $host ) {
			$lookup = $host;
		}

		$sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Fetched ' . \count( $sites ) . ' Jetpack site(s) to resolve --site.</comment>' );

		$matched = null;
		if ( \ctype_digit( $lookup ) ) {
			$matched = $sites[ (int) $lookup ] ?? null;
		} else {
			$lookup_lc = \strtolower( $lookup );
			foreach ( $sites as $site ) {
				$candidate_host = \parse_url( (string) ( $site->siteurl ?? '' ), PHP_URL_HOST );
				if ( \is_string( $candidate_host ) && \strtolower( $candidate_host ) === $lookup_lc ) {
					$matched = $site;
					break;
				}
			}
		}

		if ( null === $matched ) {
			throw new \InvalidArgumentException( "Site '$site_id_or_url' is not in the connected Jetpack sites list." );
		}

		$site_id = (int) ( $matched->userblog_id ?? 0 );
		if ( 0 === $site_id ) {
			throw new \InvalidArgumentException( "Resolved site '$site_id_or_url' has no usable ID." );
		}

		$this->single_site = $matched;
		$this->sites       = array( $site_id => $matched );

		$output->writeln( "<comment>Querying single site {$matched->siteurl} (ID $site_id).</comment>" );

		$this->statuses = get_wpcom_sites_atlantis_status_batch( array( $site_id ), $this->errors );
		maybe_output_wpcom_failed_sites_table( $output, $this->errors ?? array(), $this->sites, 'Sites that could NOT be queried for Atlantis status' );
	}

	// endregion
}
