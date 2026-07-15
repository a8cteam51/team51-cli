<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Cycles through sites listed in a `get_header()` fatal audit CSV and, for each candidate theme,
 * re-derives its eligibility live over SSH (active / parent / symlink) and deletes it when safe.
 *
 * The CSV only supplies candidate (site, theme) pairs. The live re-check on the box is authoritative:
 * a theme is only deleted if, at run time, it is installed, NOT the active theme, and NOT the parent
 * (template) of any installed theme. Symlinked platform themes are removed via the Atomic escape hatch
 * (`wp atomic theme use-unmanaged --remove-existing`) before `wp theme delete`.
 *
 * Every candidate is logged. Every live deletion requires per-theme approval unless --dry-run is used.
 *
 * Examples:
 *   team51 site:cleanup-fatal-themes --sites="T51 get_header theme-audit (combined).csv" --dry-run
 *   team51 site:cleanup-fatal-themes --sites=audit.csv
 *   team51 site:cleanup-fatal-themes --sites=audit.csv --only=Pressable --limit=10
 *
 * Input CSV must contain (at least) the columns: platform, url (or site), theme.
 * The audit export header is: platform,site,url,error_message,theme,theme_location,active_theme,parent_of_active,verdict,error_count
 */
#[AsCommand( name: 'site:cleanup-fatal-themes' )]
final class Site_Theme_Cleanup extends Command {

	// region FIELDS AND CONSTANTS

	/**
	 * Theme "names" in the audit CSV that are not real, deletable themes.
	 */
	private const NON_THEME_VALUES = array( '(non-theme)', 'index.php', '' );

	/**
	 * WordPress default themes up to and including Twenty Twenty-One (WP never shipped a "twentyeighteen").
	 * Targeted by --auto-old-defaults for hands-off deletion. Newer defaults (twentytwentytwo+) are excluded.
	 */
	private const OLD_DEFAULT_THEMES = array(
		'twentyten',
		'twentyeleven',
		'twentytwelve',
		'twentythirteen',
		'twentyfourteen',
		'twentyfifteen',
		'twentysixteen',
		'twentyseventeen',
		'twentynineteen',
		'twentytwenty',
		'twentytwentyone',
	);

	/**
	 * Platforms we know how to reach over SSH, mapped to the connection helper class.
	 */
	private const ROUTABLE_PLATFORMS = array(
		'pressable' => \Pressable_Connection_Helper::class,
		'woa'       => \WPCOM_Connection_Helper::class,
	);

	/**
	 * Path to the input (audit) CSV file.
	 *
	 * @var string|null
	 */
	private ?string $sites_csv_path = null;

	/**
	 * Path to the output results CSV file.
	 *
	 * @var string|null
	 */
	private ?string $output_csv_path = null;

	/**
	 * Path to the plain-text log file.
	 *
	 * @var string|null
	 */
	private ?string $log_path = null;

	/**
	 * Whether to run without making any changes (no deletions, no prompts).
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Optional platform filter (e.g. `Pressable` or `WoA`). Null means all routable platforms.
	 *
	 * @var string|null
	 */
	private ?string $only_platform = null;

	/**
	 * Optional cap on the number of candidate (site, theme) pairs to process. Zero means no cap.
	 *
	 * @var int
	 */
	private int $limit = 0;

	/**
	 * Whether to evaluate every installed theme on each connected site, not just the CSV-flagged ones.
	 *
	 * @var bool
	 */
	private bool $all_themes = false;

	/**
	 * Whether to auto-delete (no per-theme prompt) the known old default themes when eligible.
	 *
	 * @var bool
	 */
	private bool $auto_old_defaults = false;

	/**
	 * Open handle to the log file.
	 *
	 * @var resource|null
	 */
	private $log_handle = null;

	/**
	 * Open handle to the results CSV file.
	 *
	 * @var resource|null
	 */
	private $out_handle = null;

	/**
	 * Diagnostic detail from the most recent failed SSH/WP-CLI introspection.
	 *
	 * @var string
	 */
	private string $last_ssh_diag = '';

	/**
	 * Set when the user chooses to quit at a per-theme prompt; stops all further processing.
	 *
	 * @var bool
	 */
	private bool $aborted = false;

	/**
	 * Whether to skip sites already recorded as processed in the log (resume support).
	 *
	 * @var bool
	 */
	private bool $resume = true;

	/**
	 * Set of already-processed site keys (`platform_key|domain`) loaded from the log.
	 *
	 * @var array<string, bool>
	 */
	private array $processed_sites = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Deletes ineligible-for-keeping themes flagged in a get_header() fatal audit CSV, re-verifying eligibility live over SSH.' )
			->setHelp( 'Reads a CSV of sites/themes that throw get_header() fatals, and for each theme re-derives eligibility on the box (skips active themes and parents of active/installed themes), then deletes it when safe. Symlinked platform themes are removed via `wp atomic theme use-unmanaged`. Use --dry-run first.' );

		$this->addOption( 'sites', null, InputOption::VALUE_REQUIRED, 'Path to the input audit CSV (must have platform, url/site, theme columns).' )
			->addOption( 'output', null, InputOption::VALUE_REQUIRED, 'Path to the results CSV. Defaults to the input filename with a _cleanup-results suffix.' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the stable log file used both for the audit trail and to remember processed sites. Defaults to Downloads/theme-cleanup.log.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without changing anything or prompting.' )
			->addOption( 'all-themes', null, InputOption::VALUE_NONE, 'Evaluate EVERY installed theme on each connected site (not just the CSV-flagged ones), applying the same active/parent guards. Catches inactive orphan themes the fatal log never captured.' )
			->addOption( 'auto-old-defaults', null, InputOption::VALUE_NONE, 'Hands-off mode: on each site, auto-delete the known WordPress default themes Twenty Twenty-One and older (twentyten…twentytwentyone) when eligible (not active, not a parent). No per-theme prompt. Overrides the CSV theme selection.' )
			->addOption( 'no-resume', null, InputOption::VALUE_NONE, 'Do not skip sites already recorded as processed in the log; re-process everything.' )
			->addOption( 'only', null, InputOption::VALUE_REQUIRED, 'Only process a single platform (e.g. `Pressable` or `WoA`).' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many candidate (site, theme) pairs (useful for testing).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->sites_csv_path = $input->getOption( 'sites' );
		if ( empty( $this->sites_csv_path ) ) {
			$output->writeln( '<error>The --sites option is required.</error>' );
			exit( 1 );
		}
		if ( ! \is_file( $this->sites_csv_path ) ) {
			$output->writeln( "<error>CSV file not found: {$this->sites_csv_path}</error>" );
			exit( 1 );
		}

		$this->dry_run           = get_bool_input( $input, 'dry-run' );
		$this->all_themes        = get_bool_input( $input, 'all-themes' );
		$this->auto_old_defaults = get_bool_input( $input, 'auto-old-defaults' );
		$this->resume            = ! get_bool_input( $input, 'no-resume' );
		$this->only_platform     = maybe_get_string_input( $input, 'only' );
		$this->limit             = (int) ( $input->getOption( 'limit' ) ?? 0 );

		$timestamp = \gmdate( 'Y-m-d-H-i-s' );

		$this->output_csv_path = $input->getOption( 'output' );
		if ( empty( $this->output_csv_path ) ) {
			$pathinfo              = \pathinfo( $this->sites_csv_path );
			$this->output_csv_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . "_cleanup-results-$timestamp.csv";
		}

		// Stable (non-timestamped) log so it can double as the processed-sites ledger across runs.
		$this->log_path = $input->getOption( 'log' );
		if ( empty( $this->log_path ) ) {
			$this->log_path = get_user_folder_path( 'Downloads/theme-cleanup.log' );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$candidates = $this->read_candidates( $output );
		if ( empty( $candidates ) ) {
			$output->writeln( '<error>No routable candidate (site, theme) pairs found in the CSV.</error>' );
			return Command::FAILURE;
		}

		// Group candidate themes by site so we connect + introspect once per site.
		$sites = $this->group_by_site( $candidates );

		// Resume support: skip sites already recorded as processed in the log.
		$skipped_done = 0;
		if ( $this->resume ) {
			$this->processed_sites = $this->load_processed_sites();
			if ( ! empty( $this->processed_sites ) ) {
				$before       = \count( $sites );
				$sites        = \array_filter( $sites, fn( string $key ) => ! isset( $this->processed_sites[ $key ] ), ARRAY_FILTER_USE_KEY );
				$skipped_done = $before - \count( $sites );
			}
		}

		if ( empty( $sites ) ) {
			$output->writeln( '<fg=green;options=bold>Nothing to do — all ' . \count( $candidates ) . ' candidate site(s) are already recorded as processed in the log.</>' );
			$output->writeln( "<comment>Use --no-resume to re-process them, or delete {$this->log_path} to start over.</comment>" );
			return Command::SUCCESS;
		}

		$total_pairs  = \array_sum( \array_map( static fn( array $site ) => \count( $site['themes'] ), $sites ) );
		$mode         = $this->dry_run ? '<fg=cyan;options=bold>DRY RUN</>' : '<fg=red;options=bold>LIVE</>';
		$skipped_note = $skipped_done > 0 ? " (skipping $skipped_done already-processed)" : '';
		$output->writeln( "<fg=magenta;options=bold>Theme cleanup — $mode</> — " . \count( $sites ) . " site(s)$skipped_note, $total_pairs candidate theme(s)." );

		if ( ! $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This will delete themes over SSH (each one still needs your approval). Continue? [y/N]</question> ', false );
			if ( true !== $this->question_helper()->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted by user.</comment>' );
				return Command::SUCCESS;
			}
		}

		if ( ! $this->open_outputs( $output ) ) {
			return Command::FAILURE;
		}

		$stats = array(
			'deleted'  => 0,
			'skipped'  => 0,
			'declined' => 0,
			'failed'   => 0,
			'sites_ok' => 0,
			'sites_ko' => 0,
		);

		try {
			$this->log( '=== Theme cleanup started ' . \gmdate( 'c' ) . ' (' . ( $this->dry_run ? 'DRY RUN' : 'LIVE' ) . ') ===' );

			$site_index = 0;
			foreach ( $sites as $site ) {
				++$site_index;
				$this->process_site( $input, $output, $site, $site_index, \count( $sites ), $stats );
				if ( $this->aborted ) {
					$output->writeln( '<comment>Run stopped at your request.</comment>' );
					break;
				}
			}
		} finally {
			$this->close_outputs();
		}

		$output->writeln( '' );
		$output->writeln( '<fg=magenta;options=bold>Summary</>' );
		$output->writeln( "  Deleted:  {$stats['deleted']}" );
		$output->writeln( "  Skipped (ineligible): {$stats['skipped']}" );
		$output->writeln( "  Declined (by you):    {$stats['declined']}" );
		$output->writeln( "  Failed:   {$stats['failed']}" );
		$output->writeln( "  Sites reached: {$stats['sites_ok']} | unreachable: {$stats['sites_ko']}" );
		if ( $this->dry_run ) {
			$output->writeln( '  <comment>Dry run — no sites recorded as processed.</comment>' );
		}
		$output->writeln( "  Log (also the processed-sites ledger): {$this->log_path}" );
		$output->writeln( "  Results: {$this->output_csv_path}" );

		return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Reads the audit CSV and returns the list of routable candidate rows.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array<int, array{platform:string, platform_key:string, domain:string, url:string, theme:string, location:string}>
	 */
	private function read_candidates( OutputInterface $output ): array {
		$handle = \fopen( $this->sites_csv_path, 'r' );
		if ( false === $handle ) {
			$output->writeln( "<error>Cannot read CSV file: {$this->sites_csv_path}</error>" );
			return array();
		}

		$headers = \fgetcsv( $handle, 0, ',', '"', '' );
		if ( false === $headers ) {
			\fclose( $handle );
			return array();
		}
		$headers = \array_map( static fn( $h ) => \strtolower( \trim( (string) $h ) ), $headers );

		$candidates = array();
		$seen       = array();
		while ( ( $row = \fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			if ( empty( \array_filter( $row ) ) ) {
				continue;
			}
			$row  = \array_pad( \array_slice( $row, 0, \count( $headers ) ), \count( $headers ), '' );
			$data = \array_combine( $headers, $row );

			$platform = \trim( (string) ( $data['platform'] ?? '' ) );
			$theme    = \trim( (string) ( $data['theme'] ?? '' ) );
			$url      = \trim( (string) ( $data['url'] ?? ( $data['site'] ?? '' ) ) );

			$platform_key = \strtolower( $platform );
			if ( ! isset( self::ROUTABLE_PLATFORMS[ $platform_key ] ) ) {
				continue; // Simple / Unknown / blank — cannot reach over SSH.
			}
			if ( null !== $this->only_platform && \strtolower( $this->only_platform ) !== $platform_key ) {
				continue;
			}
			if ( \in_array( $theme, self::NON_THEME_VALUES, true ) || \ctype_digit( $theme ) ) {
				continue; // Not a real, deletable theme slug.
			}

			$domain = $this->extract_domain_from_url( $url );
			if ( '' === $domain ) {
				continue; // Unresolvable site.
			}

			$dedupe_key = $platform_key . '|' . $domain . '|' . $theme;
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}
			$seen[ $dedupe_key ] = true;

			$candidates[] = array(
				'platform'     => $platform,
				'platform_key' => $platform_key,
				'domain'       => $domain,
				'url'          => $url,
				'theme'        => $theme,
				'location'     => \trim( (string) ( $data['theme_location'] ?? '' ) ),
			);

			if ( $this->limit > 0 && \count( $candidates ) >= $this->limit ) {
				break;
			}
		}

		\fclose( $handle );
		return $candidates;
	}

	/**
	 * Groups candidate rows by site (platform + domain).
	 *
	 * @param   array $candidates The flat candidate list.
	 *
	 * @return  array<string, array{platform:string, platform_key:string, domain:string, url:string, themes:array}>
	 */
	private function group_by_site( array $candidates ): array {
		$sites = array();
		foreach ( $candidates as $candidate ) {
			$key = $candidate['platform_key'] . '|' . $candidate['domain'];
			if ( ! isset( $sites[ $key ] ) ) {
				$sites[ $key ] = array(
					'platform'     => $candidate['platform'],
					'platform_key' => $candidate['platform_key'],
					'domain'       => $candidate['domain'],
					'url'          => $candidate['url'],
					'themes'       => array(),
				);
			}
			$sites[ $key ]['themes'][] = array(
				'theme'    => $candidate['theme'],
				'location' => $candidate['location'],
			);
		}
		return $sites;
	}

	/**
	 * Processes a single site: resolve, connect, introspect, then evaluate/delete each candidate theme.
	 *
	 * @param   InputInterface  $input       The input object.
	 * @param   OutputInterface $output      The output object.
	 * @param   array           $site        The grouped site definition.
	 * @param   int             $index       The 1-based site index.
	 * @param   int             $site_count  The total number of sites.
	 * @param   array           $stats       Running totals, mutated by reference.
	 *
	 * @return  void
	 */
	private function process_site( InputInterface $input, OutputInterface $output, array $site, int $index, int $site_count, array &$stats ): void {
		$label = "[$index/$site_count] {$site['platform']} {$site['domain']}";
		$output->writeln( "\n<fg=blue;options=bold>$label</> — " . \count( $site['themes'] ) . ' candidate theme(s)' );

		$resolved = $this->resolve_site( $site['platform_key'], $site['domain'] );
		if ( null === $resolved ) {
			$output->writeln( '  <error>Could not resolve site via API — skipping.</error>' );
			++$stats['sites_ko'];
			foreach ( $site['themes'] as $theme ) {
				$this->record( $site, $theme, 'n/a', '', 'skip', 'site-unresolved', 'Site not found via API' );
				++$stats['skipped'];
			}
			return;
		}

		$identifier   = (string) ( 'pressable' === $site['platform_key'] ? $resolved->id : ( $resolved->ID ?? $resolved->id ) );
		$helper_class = self::ROUTABLE_PLATFORMS[ $site['platform_key'] ];

		$ssh = $helper_class::get_ssh_connection( $identifier );
		if ( null === $ssh ) {
			$output->writeln( '  <error>SSH connection failed — skipping.</error>' );
			++$stats['sites_ko'];
			foreach ( $site['themes'] as $theme ) {
				$this->record( $site, $theme, 'n/a', '', 'skip', 'ssh-failed', 'SSH connection failed' );
				++$stats['skipped'];
			}
			return;
		}

		++$stats['sites_ok'];

		try {
			$ssh->setTimeout( 120 );

			$introspection = $this->introspect_themes( $ssh );
			if ( null === $introspection ) {
				$diag = '' !== $this->last_ssh_diag ? $this->last_ssh_diag : 'no output';
				$output->writeln( "  <error>Could not read installed themes over SSH — skipping.</error> <comment>($diag)</comment>" );
				foreach ( $site['themes'] as $theme ) {
					$this->record( $site, $theme, 'n/a', '', 'skip', 'introspect-failed', "wp theme introspection failed: $diag" );
					++$stats['skipped'];
				}
				return;
			}

			$themes_to_process = $this->candidate_themes( $site, $introspection );
			if ( $this->auto_old_defaults ) {
				$output->writeln( '  <comment>--auto-old-defaults: targeting ' . \count( $themes_to_process ) . ' installed old default theme(s) for hands-off deletion.</comment>' );
			} elseif ( $this->all_themes && \count( $themes_to_process ) > \count( $site['themes'] ) ) {
				$extra = \count( $themes_to_process ) - \count( $site['themes'] );
				$output->writeln( "  <comment>--all-themes: evaluating $extra additional installed theme(s) beyond the CSV.</comment>" );
			}

			foreach ( $themes_to_process as $theme ) {
				$this->process_theme( $input, $output, $ssh, $site, $theme, $introspection, $stats );
				if ( $this->aborted ) {
					break;
				}
			}

			// Record the site as processed only when we fully evaluated it (not aborted mid-way) and this
			// was a real run. Dry runs never mark sites done, and connect/introspect failures return early
			// above, so those sites remain eligible for retry on the next run.
			if ( ! $this->aborted && ! $this->dry_run ) {
				$this->mark_site_processed( $site );
			}
		} finally {
			$ssh->disconnect();
		}
	}

	/**
	 * Builds the list of theme rows to evaluate for a site.
	 *
	 * In --auto-old-defaults mode this is exclusively the installed old default themes (marked `auto`).
	 * Otherwise it is the CSV-flagged themes, plus (when --all-themes is set) every other installed
	 * theme, deduplicated by slug. Rows carry an `auto` flag that tells process_theme() whether the
	 * deletion is hands-off (no per-theme prompt).
	 *
	 * @param   array $site          The grouped site definition.
	 * @param   array $introspection The live theme state.
	 *
	 * @return  array<int, array{theme:string, location:string, auto:bool}>
	 */
	private function candidate_themes( array $site, array $introspection ): array {
		$installed = $introspection['themes'];

		// Auto mode: only the known old default themes that are actually installed, deleted without prompts.
		if ( $this->auto_old_defaults ) {
			$rows = array();
			foreach ( self::OLD_DEFAULT_THEMES as $slug ) {
				if ( isset( $installed[ $slug ] ) ) {
					$rows[] = array(
						'theme'    => $slug,
						'location' => 'default',
						'auto'     => true,
					);
				}
			}
			return $rows;
		}

		$rows = array();
		$seen = array();

		foreach ( $site['themes'] as $theme ) {
			$rows[]                  = array(
				'theme'    => $theme['theme'],
				'location' => $theme['location'] ?? '',
				'auto'     => false,
			);
			$seen[ $theme['theme'] ] = true;
		}

		if ( $this->all_themes ) {
			foreach ( \array_keys( $installed ) as $slug ) {
				$slug = (string) $slug;
				if ( isset( $seen[ $slug ] ) ) {
					continue;
				}
				$seen[ $slug ] = true;
				$rows[]        = array(
					'theme'    => $slug,
					'location' => '',
					'auto'     => false,
				);
			}
		}

		return $rows;
	}

	/**
	 * Evaluates one candidate theme against the live site state and, if eligible and approved, deletes it.
	 *
	 * @param   InputInterface       $input          The input object.
	 * @param   OutputInterface      $output         The output object.
	 * @param   \phpseclib3\Net\SSH2 $ssh       The open SSH connection.
	 * @param   array                $site           The grouped site definition.
	 * @param   array                $theme          The candidate theme row.
	 * @param   array                $introspection  The live theme state.
	 * @param   array                $stats          Running totals, mutated by reference.
	 *
	 * @return  void
	 */
	private function process_theme( InputInterface $input, OutputInterface $output, $ssh, array $site, array $theme, array $introspection, array &$stats ): void {
		$slug   = $theme['theme'];
		$themes = $introspection['themes'];
		$active = $introspection['active'];

		// Not installed / already gone.
		if ( ! isset( $themes[ $slug ] ) ) {
			$output->writeln( "  <comment>· $slug — not installed (already gone).</comment>" );
			$this->record( $site, $theme, 'n/a', '', 'skip', 'not-installed', 'Theme not present in wp_get_themes()' );
			++$stats['skipped'];
			return;
		}

		$info    = $themes[ $slug ];
		$symlink = ! empty( $info['symlink'] );

		// Active theme guard.
		if ( $slug === $active ) {
			$output->writeln( "  <comment>· $slug — KEEP (active theme).</comment>" );
			$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'active', 'skip', 'active', 'Active theme' );
			++$stats['skipped'];
			return;
		}

		// Parent guard: is this theme the template (parent) of any installed theme?
		$child = $this->find_child_using( $slug, $themes );
		if ( null !== $child ) {
			$output->writeln( "  <comment>· $slug — KEEP (parent of installed theme `$child`).</comment>" );
			$this->record( $site, $theme, $symlink ? 'symlink' : 'file', "parent-of:$child", 'skip', 'parent', "Parent of installed theme $child" );
			++$stats['skipped'];
			return;
		}

		// Eligible.
		$auto   = ! empty( $theme['auto'] );
		$method = ( $symlink ? 'atomic-unmanage+delete' : 'delete' ) . ( $auto ? ' (auto)' : '' );

		if ( $this->dry_run ) {
			$verb  = $auto ? 'WOULD AUTO-DELETE' : 'WOULD DELETE';
			$descr = $auto ? 'old default theme' : ( $symlink ? 'symlinked platform theme' : 'site-owned' );
			$output->writeln( "  <fg=cyan>· $slug — $verb</> ($descr, method: $method)." );
			$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'eligible', $auto ? 'would-auto-delete' : 'would-delete', $method, 'Dry run — no change made' );
			return;
		}

		if ( $auto ) {
			// Hands-off deletion of a known old default theme — no per-theme prompt.
			$output->writeln( "  <fg=cyan>· $slug — auto-deleting old default theme…</>" );
		} else {
			// Per-theme approval — every deletion is individually gated. `q` aborts the whole run.
			$kind     = $symlink ? 'symlinked platform theme' : 'site-owned theme';
			$question = new Question( "  <question>Delete $kind `$slug` on {$site['domain']}? [y/N/q]</question> ", 'n' );
			$answer   = \strtolower( \trim( (string) $this->question_helper()->ask( $input, $output, $question ) ) );

			if ( 'q' === $answer || 'quit' === $answer ) {
				$output->writeln( '    <comment>Quit — stopping the run. No further themes will be touched.</comment>' );
				$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'eligible', 'aborted', $method, 'User quit at this theme' );
				$this->aborted = true;
				return;
			}

			if ( 'y' !== $answer && 'yes' !== $answer ) {
				$output->writeln( '    <comment>Declined.</comment>' );
				$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'eligible', 'declined', $method, 'Declined by user' );
				++$stats['declined'];
				return;
			}
		}

		[ $ok, $detail ] = $this->delete_theme( $ssh, $slug, $symlink );
		if ( $ok ) {
			$output->writeln( "    <fg=green;options=bold>Deleted `$slug`.</>" );
			$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'eligible', 'deleted', $method, $detail );
			++$stats['deleted'];
		} else {
			$output->writeln( "    <error>Failed to delete `$slug`: $detail</error>" );
			$this->record( $site, $theme, $symlink ? 'symlink' : 'file', 'eligible', 'failed', $method, $detail );
			++$stats['failed'];
		}
	}

	/**
	 * Deletes a theme over SSH, using the Atomic escape hatch first for symlinked themes, then verifies.
	 *
	 * @param   \phpseclib3\Net\SSH2 $ssh     The open SSH connection.
	 * @param   string               $slug    The theme slug.
	 * @param   bool                 $symlink Whether the theme directory is a symlink.
	 *
	 * @return  array{0:bool, 1:string} Success flag and a detail message (combined command output).
	 */
	private function delete_theme( $ssh, string $slug, bool $symlink ): array {
		$arg = \escapeshellarg( $slug );
		$log = array();

		if ( $symlink ) {
			[ $out, $code ] = $this->run_wp( $ssh, "atomic theme use-unmanaged $arg --remove-existing" );
			$log[]          = "use-unmanaged(exit=$code): " . $this->one_line( $out );
			// Some flows remove the symlink outright; a follow-up delete is still attempted below and is
			// tolerant of an already-removed theme (verified at the end).
		}

		[ $out, $code ] = $this->run_wp( $ssh, "theme delete $arg" );
		$log[]          = "delete(exit=$code): " . $this->one_line( $out );

		// Verify: `wp theme is-installed` exits 0 when still installed, non-zero when gone.
		[ , $installed_code ] = $this->run_wp( $ssh, "theme is-installed $arg" );
		$gone                 = 0 !== $installed_code;

		return array( $gone, \implode( ' | ', $log ) );
	}

	/**
	 * Reads the live theme state on the site via a single base64-wrapped `wp eval` call.
	 *
	 * @param   \phpseclib3\Net\SSH2 $ssh The open SSH connection.
	 *
	 * @return  array{active:string, active_parent:string, themes:array<string, array>}|null
	 */
	private function introspect_themes( $ssh ): ?array {
		$php = <<<'PHP'
error_reporting(0);
$active = (string) get_option('stylesheet');
$active_parent = (string) get_option('template');
$themes = array();
foreach (wp_get_themes() as $slug => $theme) {
	$dir = $theme->get_stylesheet_directory();
	$themes[(string) $slug] = array(
		'name'     => (string) $theme->get('Name'),
		'template' => (string) $theme->get_template(),
		'symlink'  => is_link($dir) ? 1 : 0,
		'exists'   => is_dir($dir) ? 1 : 0,
	);
}
echo json_encode(array('active' => $active, 'active_parent' => $active_parent, 'themes' => $themes));
PHP;
		$b64 = \base64_encode( $php );

		// Single-quoted outer shell wrapping (proven pattern; base64 is quote-free so this is bulletproof).
		// Inner double quotes delimit the PHP string passed to base64_decode(). The trailing semicolon is
		// REQUIRED: `wp eval` runs eval($arg), and eval() throws "unexpected end of file" on a bare expression.
		[ $out, $code ] = $this->run_wp( $ssh, "eval 'eval(base64_decode(\"$b64\"));'" );

		$json  = \trim( $out );
		$start = \strpos( $json, '{' );
		if ( false === $start ) {
			$this->last_ssh_diag = "exit=$code: " . ( '' === $json ? 'no output' : $this->one_line( \substr( $json, 0, 300 ) ) );
			return null;
		}
		$decoded = \json_decode( \substr( $json, $start ), true );
		if ( ! \is_array( $decoded ) || ! isset( $decoded['themes'] ) || ! \is_array( $decoded['themes'] ) ) {
			$this->last_ssh_diag = "exit=$code: unparseable JSON: " . $this->one_line( \substr( $json, 0, 300 ) );
			return null;
		}

		$this->last_ssh_diag = '';

		return array(
			'active'        => (string) ( $decoded['active'] ?? '' ),
			'active_parent' => (string) ( $decoded['active_parent'] ?? '' ),
			'themes'        => $decoded['themes'],
		);
	}

	/**
	 * Returns the slug of an installed theme whose parent (template) is the given slug, or null.
	 * A theme is never considered its own child.
	 *
	 * @param   string $slug   The potential parent slug.
	 * @param   array  $themes The live themes map.
	 *
	 * @return  string|null
	 */
	private function find_child_using( string $slug, array $themes ): ?string {
		foreach ( $themes as $child_slug => $info ) {
			if ( (string) $child_slug === $slug ) {
				continue;
			}
			if ( isset( $info['template'] ) && (string) $info['template'] === $slug ) {
				return (string) $child_slug;
			}
		}
		return null;
	}

	/**
	 * Runs a WP-CLI subcommand over SSH and returns the combined output and exit status.
	 *
	 * Global flags (`--skip-themes --skip-plugins`) are placed BEFORE the subcommand — this WP-CLI
	 * build rejects them when they trail the subcommand's positional arguments. They keep a broken
	 * active theme/plugin from fataling the bootstrap during theme operations.
	 *
	 * @param   \phpseclib3\Net\SSH2 $ssh     The open SSH connection.
	 * @param   string               $command The WP-CLI subcommand minus the leading `wp` and global flags.
	 *
	 * @return  array{0:string, 1:int}
	 */
	private function run_wp( $ssh, string $command ): array {
		$output = $ssh->exec( "wp --skip-themes --skip-plugins $command 2>&1" );
		return array( (string) $output, (int) $ssh->getExitStatus() );
	}

	/**
	 * Returns the Symfony question helper with a concrete type (avoids ambiguous getHelper() return).
	 *
	 * @return  QuestionHelper
	 */
	private function question_helper(): QuestionHelper {
		$helper = $this->getHelper( 'question' );
		\assert( $helper instanceof QuestionHelper );
		return $helper;
	}

	/**
	 * Resolves a site object from a domain and platform key.
	 *
	 * @param   string $platform_key The lowercased platform key.
	 * @param   string $domain       The site domain.
	 *
	 * @return  \stdClass|null
	 */
	private function resolve_site( string $platform_key, string $domain ): ?\stdClass {
		return 'pressable' === $platform_key ? get_pressable_site( $domain ) : get_wpcom_site( $domain );
	}

	/**
	 * Extracts a bare domain from a URL.
	 *
	 * @param   string $url The URL.
	 *
	 * @return  string
	 */
	private function extract_domain_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$domain = \preg_replace( '#^https?://#i', '', $url );
		$domain = \preg_replace( '~[/?#].*$~', '', (string) $domain );
		return \rtrim( (string) $domain, '/' );
	}

	/**
	 * Collapses multi-line command output into a single trimmed line for logging.
	 *
	 * @param   string $text The raw output.
	 *
	 * @return  string
	 */
	private function one_line( string $text ): string {
		return \trim( \preg_replace( '/\s+/', ' ', $text ) ?? '' );
	}

	/**
	 * Opens the log and results-CSV handles and writes the results header.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool
	 */
	private function open_outputs( OutputInterface $output ): bool {
		$this->log_handle = \fopen( $this->log_path, 'a' );
		if ( false === $this->log_handle ) {
			$output->writeln( "<error>Cannot write to log file: {$this->log_path}</error>" );
			return false;
		}

		$this->out_handle = \fopen( $this->output_csv_path, 'w' );
		if ( false === $this->out_handle ) {
			$output->writeln( "<error>Cannot write to results file: {$this->output_csv_path}</error>" );
			\fclose( $this->log_handle );
			$this->log_handle = null;
			return false;
		}

		\fputcsv( $this->out_handle, array( 'Timestamp', 'Platform', 'Domain', 'Theme', 'Location', 'Live State', 'Action', 'Method', 'Detail' ), ',', '"', '' );
		return true;
	}

	/**
	 * Closes any open output handles.
	 *
	 * @return  void
	 */
	private function close_outputs(): void {
		if ( \is_resource( $this->out_handle ) ) {
			\fclose( $this->out_handle );
			$this->out_handle = null;
		}
		if ( \is_resource( $this->log_handle ) ) {
			$this->log( '=== Theme cleanup finished ' . \gmdate( 'c' ) . ' ===' );
			\fclose( $this->log_handle );
			$this->log_handle = null;
		}
	}

	/**
	 * Records an action to both the results CSV and the log file.
	 *
	 * @param   array  $site      The grouped site definition.
	 * @param   array  $theme     The candidate theme row.
	 * @param   string $location  The (live) theme location: symlink / file / n/a.
	 * @param   string $state     The live eligibility state.
	 * @param   string $action    The action taken (deleted / skip / declined / failed / would-delete).
	 * @param   string $method    The method used.
	 * @param   string $detail    A free-text detail message.
	 *
	 * @return  void
	 */
	private function record( array $site, array $theme, string $location, string $state, string $action, string $method, string $detail ): void {
		$now = \gmdate( 'c' );
		if ( \is_resource( $this->out_handle ) ) {
			\fputcsv(
				$this->out_handle,
				array( $now, $site['platform'], $site['domain'], $theme['theme'], $location, $state, $action, $method, $detail ),
				',',
				'"',
				''
			);
		}
		$this->log( \sprintf( '%s [%s] %s theme=%s state=%s action=%s method=%s :: %s', $now, $site['platform'], $site['domain'], $theme['theme'], $state, $action, $method, $detail ) );
	}

	/**
	 * Appends a line to the log file (if open).
	 *
	 * @param   string $message The message to log.
	 *
	 * @return  void
	 */
	private function log( string $message ): void {
		if ( \is_resource( $this->log_handle ) ) {
			\fwrite( $this->log_handle, $message . "\n" );
		}
	}

	/**
	 * Reads the log file and returns the set of already-processed site keys (`platform_key|domain`).
	 * Parses only the machine-readable `PROCESSED\t<platform_key>\t<domain>\t...` marker lines.
	 *
	 * @return  array<string, bool>
	 */
	private function load_processed_sites(): array {
		if ( ! \is_file( $this->log_path ) ) {
			return array();
		}

		$contents = \file_get_contents( $this->log_path );
		if ( false === $contents || '' === $contents ) {
			return array();
		}

		$processed = array();
		foreach ( \explode( "\n", $contents ) as $line ) {
			if ( ! \str_starts_with( $line, "PROCESSED\t" ) ) {
				continue;
			}
			$parts = \explode( "\t", $line );
			if ( isset( $parts[1], $parts[2] ) && '' !== $parts[1] && '' !== $parts[2] ) {
				$processed[ $parts[1] . '|' . $parts[2] ] = true;
			}
		}

		return $processed;
	}

	/**
	 * Appends a machine-readable PROCESSED marker for a site to the log so future runs can skip it.
	 *
	 * @param   array $site The grouped site definition.
	 *
	 * @return  void
	 */
	private function mark_site_processed( array $site ): void {
		$key                           = $site['platform_key'] . '|' . $site['domain'];
		$this->processed_sites[ $key ] = true;
		$this->log( \sprintf( "PROCESSED\t%s\t%s\t%s", $site['platform_key'], $site['domain'], \gmdate( 'c' ) ) );
	}

	// endregion
}
