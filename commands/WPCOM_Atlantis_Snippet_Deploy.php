<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Deploys a signed Atlantis remediation snippet to all connected Jetpack sites.
 *
 * The snippet is authored as a normal, lintable PHP file, carried verbatim to
 * OpsOasis (which signs it with the Ed25519 private key it holds), and fanned
 * out to each site's Atlantis snippet endpoint. Sites verify the signature
 * against the public key baked into Atlantis before running it — so a random
 * public install of the plugin is never a target, and the code is never served
 * to a site OpsOasis does not already manage.
 */
#[AsCommand( name: 'wpcom:atlantis-snippet-deploy' )]
final class WPCOM_Atlantis_Snippet_Deploy extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The path to the snippet PHP file.
	 *
	 * @var string|null
	 */
	private ?string $file = null;

	/**
	 * The stable snippet id (lowercase slug).
	 *
	 * @var string|null
	 */
	private ?string $snippet_id = null;

	/**
	 * The monotonic version for this deploy. Defaults to the current Unix time.
	 *
	 * @var int|null
	 */
	private ?int $version = null;

	/**
	 * Optional ISO-8601 expiry.
	 *
	 * @var string|null
	 */
	private ?string $expires = null;

	/**
	 * Optional operator notes / tracking reference.
	 *
	 * @var string|null
	 */
	private ?string $notes = null;

	/**
	 * Whether to only list the sites that would be targeted, without deploying.
	 *
	 * @var bool|null
	 */
	private ?bool $dry_run = null;

	/**
	 * Whether to skip the confirmation prompt before deploying.
	 *
	 * @var bool|null
	 */
	private ?bool $yes = null;

	/**
	 * The raw snippet code bytes.
	 *
	 * @var string|null
	 */
	private ?string $code = null;

	/**
	 * The list of target sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Deploys a signed Atlantis remediation snippet to all connected Jetpack sites.' )
			->setHelp( 'Use this command to push a temporary PHP remediation (e.g. a hotfix filter) to every site running Atlantis, without cutting a plugin release. The snippet is signed by OpsOasis and verified on each site before it runs. Author the snippet as a self-contained, idempotent PHP file (opening with <?php), canary it with --site first, and remove it with wpcom:atlantis-snippet-remove once the real fix ships.' );

		$this->addArgument( 'snippet-file', InputArgument::REQUIRED, 'Path to the PHP file to deploy. Must be a complete, valid PHP file opening with <?php.' );

		$this->addOption( 'id', null, InputOption::VALUE_REQUIRED, 'Stable snippet id (lowercase slug). Re-deploying the same id updates it. Defaults to the file name.' )
			->addOption( 'version', null, InputOption::VALUE_REQUIRED, 'Monotonic integer version for this snippet id. Defaults to the current Unix timestamp.' )
			->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Limit the deploy to a single site (numeric WPCOM ID or a domain). Use this to canary before the fleet.' )
			->addOption( 'expires', null, InputOption::VALUE_REQUIRED, 'Optional ISO-8601 expiry after which the snippet becomes inert (e.g. 2026-08-01T00:00:00Z).' )
			->addOption( 'notes', null, InputOption::VALUE_REQUIRED, 'Optional notes / tracking reference stored with the snippet.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'List the sites that would be targeted without deploying.' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt before deploying.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If the file is unreadable, fails a syntax check, the id is invalid, or `--site` matches no connected site.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->file = get_string_input( $input, 'snippet-file' );
		if ( ! is_readable( $this->file ) ) {
			throw new \InvalidArgumentException( "Snippet file `$this->file` does not exist or is not readable." );
		}

		$code = file_get_contents( $this->file );
		if ( false === $code || '' === trim( $code ) ) {
			throw new \InvalidArgumentException( "Snippet file `$this->file` is empty." );
		}
		$this->code = $code;

		$this->assert_valid_php( $this->file, $output );

		$this->snippet_id = $this->normalize_id( maybe_get_string_input( $input, 'id' ) ?? pathinfo( $this->file, PATHINFO_FILENAME ) );
		if ( '' === $this->snippet_id ) {
			throw new \InvalidArgumentException( 'Could not derive a valid snippet id; pass one with --id (lowercase letters, numbers, hyphens, underscores).' );
		}

		$version_option = maybe_get_string_input( $input, 'version' );
		$this->version  = null === $version_option ? time() : (int) $version_option;
		if ( $this->version < 1 ) {
			throw new \InvalidArgumentException( 'The --version must be a positive integer.' );
		}

		$this->expires = maybe_get_string_input( $input, 'expires' ) ?? '';
		$this->notes   = maybe_get_string_input( $input, 'notes' ) ?? '';
		$this->dry_run = get_bool_input( $input, 'dry-run' );
		$this->yes     = get_bool_input( $input, 'yes' );

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
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( empty( $this->sites ) ) {
			$output->writeln( '<comment>No target sites.</comment>' );
			return Command::SUCCESS;
		}

		$sha256 = \hash( 'sha256', (string) $this->code );

		output_table(
			$output,
			array(
				array( 'ID', $this->snippet_id ),
				array( 'Version', (string) $this->version ),
				array( 'File', (string) $this->file ),
				array( 'Bytes', (string) \strlen( (string) $this->code ) ),
				array( 'SHA-256', $sha256 ),
				array( 'Expires', '' === $this->expires ? '(never)' : $this->expires ),
				array( 'Target sites', (string) \count( $this->sites ) ),
			),
			array( 'Field', 'Value' ),
			'Snippet to deploy'
		);

		if ( $this->dry_run ) {
			output_table(
				$output,
				\array_map( static fn( $site ) => array( $site->userblog_id, $site->siteurl ?? '' ), $this->sites ),
				array( 'Site ID', 'Site URL' ),
				'Sites that would receive the snippet'
			);
			$output->writeln( '<comment>Dry run: no snippet was deployed.</comment>' );
			return Command::SUCCESS;
		}

		if ( ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>Deploy snippet `' . $this->snippet_id . '` to ' . \count( $this->sites ) . ' site(s)? This runs arbitrary PHP on each. [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. No snippet was deployed.</comment>' );
				return Command::SUCCESS;
			}
		}

		$output->writeln( "<fg=magenta;options=bold>Deploying snippet `$this->snippet_id` (v$this->version) across " . \count( $this->sites ) . ' site(s).</>' );

		$errors  = array();
		$results = deploy_wpcom_sites_atlantis_snippet_batch(
			\array_column( $this->sites, 'userblog_id' ),
			(string) $this->snippet_id,
			(int) $this->version,
			\base64_encode( (string) $this->code ),
			(string) $this->expires,
			(string) $this->notes,
			$errors
		);

		if ( \is_null( $results ) ) {
			$output->writeln( '<error>The deploy request failed.</error>' );
			return Command::FAILURE;
		}

		$rows    = array();
		$failed  = 0;
		$applied = 0;
		foreach ( $this->sites as $site_id => $site ) {
			$url    = $site->siteurl ?? '';
			$result = 'failed';
			$note   = '';

			if ( isset( $errors[ $site_id ] ) ) {
				$note = encode_json_content( $errors[ $site_id ]->errors ?? $errors[ $site_id ] );
				++$failed;
			} elseif ( isset( $results[ $site_id ] ) ) {
				$response = $results[ $site_id ];
				$result   = ! empty( $response->stored ) ? 'stored' : 'unknown';
				if ( ! empty( $response->applied ) ) {
					$note = 'materialized';
					++$applied;
				} else {
					$note = 'stored (not materialized on this host)';
				}
			} else {
				++$failed;
			}

			$rows[] = array( $site_id, $url, $result, $note );
		}

		output_table( $output, $rows, array( 'Site ID', 'Site URL', 'Result', 'Detail' ), "Deploy results for `$this->snippet_id`" );

		$output->writeln( '<info>Stored: ' . ( \count( $this->sites ) - $failed ) . " | Materialized: $applied | Failed: $failed</info>" );

		return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
	}

	// endregion

	// region HELPERS

	/**
	 * Normalizes a candidate id into Atlantis's accepted slug shape
	 * (^[a-z0-9][a-z0-9_-]{0,190}$).
	 *
	 * @param   string $candidate The candidate id.
	 *
	 * @return  string
	 */
	private function normalize_id( string $candidate ): string {
		$slug = \strtolower( $candidate );
		$slug = (string) \preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = \trim( $slug, '-_' );
		return \substr( $slug, 0, 191 );
	}

	/**
	 * Aborts if the snippet file does not pass a PHP lint (`php -l`) check, so a
	 * syntactically broken snippet never reaches the fleet.
	 *
	 * @param   string          $file   The file to lint.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  void
	 *
	 * @throws \InvalidArgumentException If the file fails the syntax check.
	 */
	private function assert_valid_php( string $file, OutputInterface $output ): void {
		$php = \defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ? PHP_BINARY : 'php';
		$cmd = \escapeshellarg( $php ) . ' -l ' . \escapeshellarg( $file ) . ' 2>&1';

		$lint = \shell_exec( $cmd );
		if ( \is_string( $lint ) && false === \stripos( $lint, 'No syntax errors detected' ) ) {
			$output->writeln( '<error>' . \trim( $lint ) . '</error>' );
			throw new \InvalidArgumentException( "Snippet file `$file` failed the PHP syntax check. Fix it before deploying." );
		}
	}

	// endregion
}
