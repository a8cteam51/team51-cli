<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Registers the Jetpack Monitor status-down webhook for one or more sites, by asking OpsOasis to
 * point each site's Jetpack Monitor `status_down_webhook_url` at the OpsOasis receiver.
 *
 * This command is deliberately thin. It gathers a list of sites, POSTs them to a single OpsOasis
 * endpoint, and reports the per-site result. All of the real work — minting/reusing the shared
 * secret, building the receiver URL, resolving the monitored URL, and the read-merge-write against
 * the site's Jetpack Monitor settings — happens server-side in OpsOasis. The CLI never talks to
 * WordPress.com directly, never sees the secret or the receiver URL, and does not verify delivery.
 *
 * Because OpsOasis reuses a stable secret and merges rather than overwrites, registration is
 * idempotent and safe to re-run; re-running after a partial failure is the intended recovery.
 *
 * With `--deregister` the command issues a DELETE to the same endpoint, removing the webhook instead
 * of setting it (the read-merge-write mirror; no secret involved). It is equally idempotent — an
 * already-clean site deregisters as a no-op success — and shares all of the site-selection, dry-run,
 * and output plumbing.
 *
 * A monitored URL defaults to the site's homepage. Pass a non-homepage URL either with
 * `--monitor-url` (single site only) or as a second `site,monitor_url` column in `--sites-file`.
 *
 * Note: the OpsOasis endpoint returns HTTP 200 even when every site failed, so success is judged from
 * the per-site `results`, not the HTTP status. The service user must hold the
 * `wpcomsp_rest_jetpack_monitor_register_webhook` capability (granted server-side via AAM) or every
 * call 403s.
 *
 * Examples:
 *   # Register two sites (homepage monitored)
 *   team51 jetpack-monitor:register-webhook example.org 123456789
 *
 *   # Register a single site on a non-homepage URL
 *   team51 jetpack-monitor:register-webhook example.org --monitor-url=https://example.org/status
 *
 *   # Bulk register from a file (one identifier per line, or `site,monitor_url` rows)
 *   team51 jetpack-monitor:register-webhook --sites-file=./sites.csv
 *
 *   # Enrol the whole Jetpack fleet, staging only (gated), previewing first
 *   team51 jetpack-monitor:register-webhook --all --staging --dry-run
 *   team51 jetpack-monitor:register-webhook --all --staging
 *
 *   # Remove the webhook from a site
 *   team51 jetpack-monitor:register-webhook example.org --deregister
 */
#[AsCommand( name: 'jetpack-monitor:register-webhook' )]
final class Jetpack_Monitor_Register_Webhook extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The OpsOasis registration endpoint, relative to `/wp-json/wpcomsp/`.
	 *
	 * @var string
	 */
	private const ENDPOINT = 'jetpack-monitor/v1/webhook-registration';

	/**
	 * How many sites to send per request when chunking a large fleet. The endpoint accepts the whole
	 * list at once, but a big fleet is split to be kind to the wpcom calls OpsOasis makes per site.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * Exit code for a transport/auth/usage failure (a non-2xx response with no per-site results). This
	 * is distinct from a clean run in which some individual sites reported `error`.
	 *
	 * @var int
	 */
	private const EXIT_TRANSPORT = 2;

	/**
	 * The sites to register, each `array{ site: string, monitor_url: string|null, label: string }`.
	 * `site` is the identifier sent to OpsOasis (blog ID or domain); `monitor_url` is null for the
	 * homepage default; `label` is a human-readable identifier for output.
	 *
	 * @var array<int, array{site: string, monitor_url: string|null, label: string}>
	 */
	private array $entries = array();

	/**
	 * Whether to enrol the whole Jetpack fleet.
	 *
	 * @var bool
	 */
	private bool $all = false;

	/**
	 * The `--production` / `--staging` URL-substring filter for `--all`, or null for no filter.
	 *
	 * @var string|null
	 */
	private ?string $environment = null;

	/**
	 * Whether to remove the webhook (DELETE) instead of registering it (POST).
	 *
	 * @var bool
	 */
	private bool $deregister = false;

	/**
	 * Whether to skip the `--all` confirmation (for unattended runs).
	 *
	 * @var bool
	 */
	private bool $yes = false;

	/**
	 * Whether to resolve and print the sites that would be sent without calling anything.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Whether to emit machine-readable JSON instead of a human table.
	 *
	 * @var bool
	 */
	private bool $json = false;

	/**
	 * A human-readable description of the current run scope, for prompts and output.
	 *
	 * @var string
	 */
	private string $scope_label = '';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Registers (or, with --deregister, removes) the Jetpack Monitor status-down webhook for one or more sites via OpsOasis.' )
			->setHelp( 'Tells OpsOasis to point each site\'s Jetpack Monitor `status_down_webhook_url` at the OpsOasis receiver (or, with --deregister, to remove it). The CLI only gathers the site list and reports the per-site result; the secret and receiver URL live server-side. Both directions are idempotent and safe to re-run. Requires the `wpcomsp_rest_jetpack_monitor_register_webhook` capability on the service user.' );

		$this->addArgument( 'site', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'One or more WordPress.com blog IDs and/or domains to register (homepage monitored).' )
			->addOption( 'sites-file', null, InputOption::VALUE_REQUIRED, 'Path to a file of sites: one identifier per line, comma-separated tokens, or `site,monitor_url` rows for per-site monitored URLs.' )
			->addOption( 'monitor-url', null, InputOption::VALUE_REQUIRED, 'Override the monitored URL. Only valid for a single site; for bulk, use the `site,monitor_url` form in --sites-file.' )
			->addOption( 'deregister', null, InputOption::VALUE_NONE, 'Remove the webhook instead of registering it (issues a DELETE to the same endpoint). Idempotent: an already-clean site succeeds as a no-op.' )
			->addOption( 'all', null, InputOption::VALUE_NONE, 'Enrol the whole Jetpack fleet (gated by a confirmation). Cannot be combined with explicit sites or --sites-file.' )
			->addOption( 'production', null, InputOption::VALUE_NONE, 'With --all, restrict to production sites (URL does not contain `staging`).' )
			->addOption( 'staging', null, InputOption::VALUE_NONE, 'With --all, restrict to staging sites (URL contains `staging`).' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the --all confirmation (for unattended runs).' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Resolve and print the sites that would be sent, without calling anything.' )
			->addOption( 'json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON (the merged per-site results) instead of a table.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run    = (bool) $input->getOption( 'dry-run' );
		$this->json       = (bool) $input->getOption( 'json' );
		$this->all        = (bool) $input->getOption( 'all' );
		$this->yes        = (bool) $input->getOption( 'yes' );
		$this->deregister = (bool) $input->getOption( 'deregister' );

		$production = (bool) $input->getOption( 'production' );
		$staging    = (bool) $input->getOption( 'staging' );
		if ( $production && $staging ) {
			$output->writeln( '<error>--production and --staging are mutually exclusive.</error>' );
			exit( 1 );
		}
		if ( ( $production || $staging ) && ! $this->all ) {
			$output->writeln( '<error>--production and --staging only apply together with --all.</error>' );
			exit( 1 );
		}
		$this->environment = $staging ? 'staging' : ( $production ? 'production' : null );

		$site_args  = (array) $input->getArgument( 'site' );
		$sites_file = $input->getOption( 'sites-file' );
		$sites_file = ( \is_string( $sites_file ) && '' !== $sites_file ) ? $sites_file : null;

		$monitor_url = $input->getOption( 'monitor-url' );
		$monitor_url = ( \is_string( $monitor_url ) && '' !== $monitor_url ) ? \trim( $monitor_url ) : null;

		if ( $this->all && ( ! empty( $site_args ) || null !== $sites_file ) ) {
			$output->writeln( '<error>--all cannot be combined with explicit sites or --sites-file.</error>' );
			exit( 1 );
		}
		if ( $this->all && null !== $monitor_url ) {
			$output->writeln( '<error>--monitor-url cannot be combined with --all (it applies to a single site only).</error>' );
			exit( 1 );
		}

		try {
			$this->entries = $this->all
				? $this->resolve_fleet_entries()
				: $this->resolve_explicit_entries( $site_args, $sites_file );
		} catch ( \RuntimeException $e ) {
			$output->writeln( '<error>' . $e->getMessage() . '</error>' );
			exit( 1 );
		}

		if ( null !== $monitor_url ) {
			if ( ! \preg_match( '#^https?://#i', $monitor_url ) ) {
				$output->writeln( '<error>--monitor-url must be an http(s) URL.</error>' );
				exit( 1 );
			}
			if ( 1 !== \count( $this->entries ) ) {
				$output->writeln( '<error>--monitor-url is only valid with exactly one site (got ' . \count( $this->entries ) . '). For bulk per-site URLs, use the `site,monitor_url` form in --sites-file.</error>' );
				exit( 1 );
			}
			$this->entries[0]['monitor_url'] = $monitor_url;
		}

		if ( empty( $this->entries ) ) {
			$output->writeln( '<error>No sites given. Provide one or more site identifiers, --sites-file, or --all.</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->dry_run ) {
			return $this->render_dry_run( $output );
		}

		if ( $this->all && ! $this->confirm_all( $input, $output ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			return Command::FAILURE;
		}

		$attempted        = \count( $this->entries );
		$merged_results   = array();
		$transport_failed = false;

		$chunks = \array_chunk( $this->entries, self::CHUNK_SIZE );
		foreach ( $chunks as $index => $chunk ) {
			$response = API_Helper::make_opsoasis_request(
				self::ENDPOINT,
				$this->deregister ? 'DELETE' : 'POST',
				array( 'sites' => \array_map( array( $this, 'to_payload_item' ), $chunk ) )
			);

			// A non-2xx response (usage/auth/server error) surfaces as null; the API helper has already
			// printed the error body. There are no per-site results for this chunk.
			if ( null === $response || true === $response ) {
				$transport_failed = true;
				continue;
			}

			$results = ( \is_object( $response ) && isset( $response->results ) && \is_array( $response->results ) ) ? $response->results : array();
			foreach ( $results as $result ) {
				$merged_results[] = $result;
			}

			// Be kind to the wpcom API OpsOasis calls per site: a small pause between chunks only.
			if ( $index < \count( $chunks ) - 1 ) {
				\usleep( 300000 );
			}
		}

		return $this->render_results( $merged_results, $attempted, $transport_failed, $output );
	}

	// endregion

	// region SITE RESOLUTION

	/**
	 * Builds the fleet entry list for --all, applying the environment filter.
	 *
	 * @return  array<int, array{site: string, monitor_url: string|null, label: string}>
	 *
	 * @throws  \RuntimeException If the fleet cannot be fetched or the filter leaves no sites.
	 */
	private function resolve_fleet_entries(): array {
		$sites = get_wpcom_jetpack_sites();
		if ( \is_null( $sites ) ) {
			throw new \RuntimeException( 'Could not fetch the Jetpack fleet from WPCOM.' );
		}

		$this->scope_label = match ( $this->environment ) {
			'staging'    => 'Jetpack staging sites',
			'production' => 'Jetpack production sites',
			default      => 'the whole Jetpack fleet',
		};

		$entries = array();
		foreach ( $sites as $site ) {
			$url = (string) ( $site->siteurl ?? '' );
			// Filter by URL substring, not API status: on Pressable more than half of staging URLs are
			// flagged "production", so the URL is the reliable signal.
			$is_staging = ( '' !== $url && false !== \stripos( $url, 'staging' ) );
			if ( 'staging' === $this->environment && ! $is_staging ) {
				continue;
			}
			if ( 'production' === $this->environment && $is_staging ) {
				continue;
			}

			$entries[] = array(
				'site'        => (string) $site->userblog_id,
				'monitor_url' => null,
				'label'       => '' !== $url ? $url : (string) $site->userblog_id,
			);
		}

		if ( empty( $entries ) ) {
			throw new \RuntimeException( 'No sites matched the ' . ( $this->environment ?? 'fleet' ) . ' filter.' );
		}

		return $entries;
	}

	/**
	 * Builds the entry list from positional arguments and/or a sites file, de-duplicated.
	 *
	 * @param   string[]    $site_args  The positional site identifiers.
	 * @param   string|null $sites_file The --sites-file path, if any.
	 *
	 * @return  array<int, array{site: string, monitor_url: string|null, label: string}>
	 *
	 * @throws  \RuntimeException If the sites file cannot be read.
	 */
	private function resolve_explicit_entries( array $site_args, ?string $sites_file ): array {
		$this->scope_label = 'the requested sites';

		$raw = array();
		foreach ( $site_args as $arg ) {
			$arg = \trim( (string) $arg );
			if ( '' !== $arg ) {
				$raw[] = array(
					'site'        => $arg,
					'monitor_url' => null,
				);
			}
		}
		if ( null !== $sites_file ) {
			foreach ( $this->parse_sites_file( $sites_file ) as $entry ) {
				$raw[] = $entry;
			}
		}

		// De-duplicate by identifier, keeping the first occurrence but preferring any that carries an
		// explicit monitored URL (a re-run is safe, so this only tidies the request).
		$by_key = array();
		foreach ( $raw as $entry ) {
			$key = \strtolower( $entry['site'] );
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = $entry;
			} elseif ( null === $by_key[ $key ]['monitor_url'] && null !== $entry['monitor_url'] ) {
				$by_key[ $key ]['monitor_url'] = $entry['monitor_url'];
			}
		}

		$entries = array();
		foreach ( $by_key as $entry ) {
			$entries[] = array(
				'site'        => $entry['site'],
				'monitor_url' => $entry['monitor_url'],
				'label'       => $entry['site'],
			);
		}

		return $entries;
	}

	/**
	 * Parses a sites file into `site` / `monitor_url` entries.
	 *
	 * Each non-blank line is split on commas. A line of exactly two tokens whose second is an http(s)
	 * URL is read as a single `site,monitor_url` pair; otherwise every token on the line is a separate
	 * plain site identifier (so a comma-separated list on one line still works). Blank tokens are
	 * dropped.
	 *
	 * @param   string $path The file path.
	 *
	 * @return  array<int, array{site: string, monitor_url: string|null}>
	 *
	 * @throws  \RuntimeException If the file cannot be opened.
	 */
	private function parse_sites_file( string $path ): array {
		if ( ! \is_file( $path ) ) {
			throw new \RuntimeException( "The sites file `$path` was not found." );
		}
		$handle = \fopen( $path, 'r' );
		if ( false === $handle ) {
			throw new \RuntimeException( "Could not open the sites file `$path`." );
		}

		$entries = array();
		while ( true ) {
			$line = \fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$tokens = \array_values(
				\array_filter(
					\array_map( '\trim', \explode( ',', $line ) ),
					static fn( string $token ): bool => '' !== $token
				)
			);
			if ( empty( $tokens ) ) {
				continue;
			}

			if ( 2 === \count( $tokens ) && \preg_match( '#^https?://#i', $tokens[1] ) ) {
				$entries[] = array(
					'site'        => $tokens[0],
					'monitor_url' => $tokens[1],
				);
				continue;
			}

			foreach ( $tokens as $token ) {
				$entries[] = array(
					'site'        => $token,
					'monitor_url' => null,
				);
			}
		}

		\fclose( $handle );
		return $entries;
	}

	/**
	 * Converts an internal entry into a payload item: a bare string for the homepage default, or an
	 * object with an explicit monitored URL.
	 *
	 * @param   array{site: string, monitor_url: string|null, label: string} $entry The entry.
	 *
	 * @return  string|array{site: string, monitor_url: string}
	 */
	private function to_payload_item( array $entry ): string|array {
		if ( null === $entry['monitor_url'] ) {
			return $entry['site'];
		}
		return array(
			'site'        => $entry['site'],
			'monitor_url' => $entry['monitor_url'],
		);
	}

	// endregion

	// region OUTPUT / PROMPTS

	/**
	 * The per-site status word the endpoint reports on success, mirrored so the CLI's counts and
	 * summary match the direction of the run.
	 *
	 * @return  string `deregistered` when removing, otherwise `registered`.
	 */
	private function success_status(): string {
		return $this->deregister ? 'deregistered' : 'registered';
	}

	/**
	 * Renders the dry-run preview of the sites that would be sent.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  int
	 */
	private function render_dry_run( OutputInterface $output ): int {
		if ( $this->json ) {
			$output->writeln(
				(string) \json_encode(
					array(
						'dry_run' => true,
						'scope'   => $this->scope_label,
						'sites'   => \array_map( array( $this, 'to_payload_item' ), $this->entries ),
					),
					JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
				)
			);
			return Command::SUCCESS;
		}

		$verb = $this->deregister ? 'deregister' : 'register';
		$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: nothing will be sent ---</>' );
		$output->writeln( "Would $verb <info>" . \count( $this->entries ) . "</info> site(s) [{$this->scope_label}]:" );

		$table = new Table( $output );
		$table->setHeaders( array( 'Site', 'Monitored URL' ) );
		foreach ( $this->entries as $entry ) {
			$table->addRow( array( $entry['label'], $entry['monitor_url'] ?? '<comment>(homepage)</comment>' ) );
		}
		$table->render();

		return Command::SUCCESS;
	}

	/**
	 * Renders the merged per-site results and computes the exit code.
	 *
	 * @param   array<int, mixed> $results          The merged per-site result objects.
	 * @param   int               $attempted        The number of sites sent.
	 * @param   bool              $transport_failed Whether any chunk failed at the transport level.
	 * @param   OutputInterface   $output           The output object.
	 *
	 * @return  int
	 */
	private function render_results( array $results, int $attempted, bool $transport_failed, OutputInterface $output ): int {
		$success_status = $this->success_status();

		$succeeded = 0;
		foreach ( $results as $result ) {
			$status = (string) ( $result->status ?? '' );
			if ( $success_status === $status ) {
				++$succeeded;
			}
		}
		$reported = \count( $results );

		if ( $this->json ) {
			$output->writeln(
				(string) \json_encode(
					array(
						$success_status   => $succeeded,
						'reported'        => $reported,
						'attempted'       => $attempted,
						'transport_error' => $transport_failed,
						'results'         => $results,
					),
					JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
				)
			);
		} else {
			$table = new Table( $output );
			$table->setHeaders( array( 'Site', 'Monitored URL', 'Status', 'Error' ) );
			foreach ( $results as $result ) {
				$status = (string) ( $result->status ?? 'unknown' );
				$style  = $success_status === $status ? 'info' : 'error';
				$table->addRow(
					array(
						(string) ( $result->site ?? '' ),
						(string) ( $result->monitor_url ?? '' ),
						"<$style>$status</$style>",
						\Symfony\Component\Console\Formatter\OutputFormatter::escape( (string) ( $result->error ?? '' ) ),
					)
				);
			}
			$table->render();

			$output->writeln( \ucfirst( $success_status ) . " <info>$succeeded</info> of <info>$reported</info> site(s)." );
			if ( $reported < $attempted ) {
				$dropped = $attempted - $reported;
				$output->writeln( "<comment>$dropped of $attempted requested site(s) were dropped as unresolvable/blank by the server and are not in the results.</comment>" );
			}
			if ( $transport_failed ) {
				$output->writeln( "<error>One or more requests failed before returning per-site results (see the error above). Those sites were not $success_status; re-run to retry.</error>" );
			}
		}

		if ( $transport_failed ) {
			return self::EXIT_TRANSPORT;
		}
		if ( 0 === $reported || $succeeded < $reported ) {
			return Command::FAILURE;
		}
		return Command::SUCCESS;
	}

	/**
	 * The gating confirmation for --all (whole-fleet enrolment).
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool True to proceed.
	 */
	private function confirm_all( InputInterface $input, OutputInterface $output ): bool {
		if ( $this->yes ) {
			return true;
		}

		$verb  = $this->deregister ? 'remove the Jetpack Monitor webhook from' : 'register the Jetpack Monitor webhook for';
		$count = \count( $this->entries );
		$output->writeln( "<comment>You are about to $verb $count site(s) [{$this->scope_label}].</comment>" );
		$output->writeln( '<comment>This is idempotent and safe to re-run, but it is a large action.</comment>' );

		$prompt   = $this->deregister ? 'Deregister' : 'Register';
		$question = new ConfirmationQuestion( "<question>$prompt $count site(s)? [y/N]</question> ", false );
		return true === $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
