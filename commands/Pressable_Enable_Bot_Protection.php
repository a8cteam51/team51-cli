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
 * Enables WP Cloud Bot Protection on a Pressable site by defining the
 * WPC_BOT_PROTECTION_ENABLED constant in wp-config over SSH.
 *
 * Enablement of WP Cloud Bot Protection comes from WP Cloud's own tiers; the
 * operator-settable one is the WPC_BOT_PROTECTION_ENABLED constant. WoA/Atomic
 * (wpcom) sites already have a tier armed at the platform level, so only
 * Pressable sites need the constant set explicitly. (The Atlantis Bot Protection
 * module can only inherit or force *off* — it has no enable path — so enabling
 * is done here, via the constant.)
 *
 * The existing fleet was swept once when this was written; the standing use is
 * arming a newly launched site, so the command takes one site at a time. There
 * is deliberately no fleet mode: nothing here should be able to rewrite
 * wp-config across every Pressable site in one invocation, not least because
 * each connection rotates the concierge SFTP password for the site it touches.
 *
 * Every run appends to a JSONL ledger — the audit trail for a security control,
 * so it is append-only and never rewritten or compacted in place.
 *
 * Examples:
 *   # Arm a newly launched site (dry run first, then for real)
 *   team51 pressable:enable-bot-protection example.mystagingwebsite.com --dry-run
 *   team51 pressable:enable-bot-protection example.mystagingwebsite.com
 *
 *   # Unattended (skips the confirmation the conventional way)
 *   team51 pressable:enable-bot-protection example.com --no-interaction
 */
#[AsCommand( name: 'pressable:enable-bot-protection' )]
final class Pressable_Enable_Bot_Protection extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The wp-config constant that arms WP Cloud's bot-protection tier.
	 *
	 * @var string
	 */
	private const CONSTANT_NAME = 'WPC_BOT_PROTECTION_ENABLED';

	/**
	 * Default JSONL ledger filename, written in the CLI's own directory.
	 *
	 * @var string
	 */
	private const DEFAULT_LEDGER = 'bot-protection-ledger.jsonl';

	/**
	 * Per-site SSH command timeout, in seconds.
	 *
	 * @var int
	 */
	private const SSH_TIMEOUT = 30;

	/**
	 * The site to process.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Whether to minimize output. Affects verbosity only — it never skips a
	 * confirmation, which is what `--no-interaction` is for.
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Whether to run without writing any changes.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Absolute path to the JSONL ledger (append-only audit log).
	 *
	 * @var string
	 */
	private string $ledger_path = '';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Enables WP Cloud Bot Protection on a Pressable site by setting the WPC_BOT_PROTECTION_ENABLED constant in wp-config.' )
			->setHelp( 'Sets `WPC_BOT_PROTECTION_ENABLED = true` in wp-config on a Pressable site over SSH. WoA/Atomic sites already have this armed at the platform level, so only Pressable sites need it. Intended for newly launched sites — one site per run. The command is idempotent: a site that already has the constant set truthy is left alone. A site where the constant is present but *not* truthy is left unchanged and reported as a failure, so a deliberate manual disable is never clobbered but never passes silently either. Every run is appended to a JSONL ledger.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The Pressable site (URL, domain, or ID) to arm. Prompted for when omitted.' )
			->addOption( 'log', null, InputOption::VALUE_REQUIRED, 'Path to the JSONL ledger (append-only audit log). Defaults to ' . self::DEFAULT_LEDGER . ' in the CLI directory.' )
			->addOption( 'no-output', null, InputOption::VALUE_NONE, 'Minimize output. Does not skip the confirmation — use --no-interaction for that.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything (no SSH writes, no ledger entry).' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->dry_run = (bool) $input->getOption( 'dry-run' );
		$this->quiet   = (bool) $input->getOption( 'no-output' );

		// Resolve and pre-flight the ledger before anything is touched: the
		// ledger is the audit trail, so discovering it is unwritable after the
		// site has already been modified would lose the record of that write.
		$this->ledger_path = $this->resolve_ledger_path( $input->getOption( 'log' ) );
		if ( ! $this->dry_run ) {
			$this->assert_ledger_writable( $output );
		}

		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( $this->dry_run ) {
			return;
		}

		$question = new ConfirmationQuestion(
			'<question>Set ' . self::CONSTANT_NAME . ' = true on ' . $this->site_label( $this->site ) . ' [pressable]? [y/N]</question> ',
			false
		);

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( ! $this->quiet ) {
			if ( $this->dry_run ) {
				$output->writeln( '<fg=yellow;options=bold>--- DRY RUN: no changes will be written ---</>' );
			}
			$output->writeln( '<info>' . $this->site_label( $this->site ) . '</info>' );
		}

		try {
			$result = $this->process_site( $this->site );
		} catch ( \Throwable $e ) {
			$result = array(
				'status' => 'failed',
				'note'   => 'Error: ' . $e->getMessage(),
			);
		}

		$this->append_ledger( $this->site, $result );
		$this->report_result( $result['status'], $result['note'], $output );

		// `exists-disabled` is not an error in the site, but it does mean bot
		// protection is off and a human has to look: a zero exit would let a
		// security control read as handled when it is not.
		return \in_array( $result['status'], array( 'failed', 'exists-disabled' ), true )
			? Command::FAILURE
			: Command::SUCCESS;
	}

	// endregion

	// region PROCESSING

	/**
	 * Applies the constant to the site over a single SSH connection.
	 *
	 * @param   \stdClass $site The Pressable site object.
	 *
	 * @return  array{ status: string, note: string } Result status and descriptive note.
	 */
	private function process_site( \stdClass $site ): array {
		$ssh = \Pressable_Connection_Helper::get_ssh_connection( (string) $site->id );
		if ( null === $ssh ) {
			return array(
				'status' => 'failed',
				'note'   => 'SSH connection failed (site unreachable, no connection, or authentication failure).',
			);
		}

		$ssh->setTimeout( self::SSH_TIMEOUT );

		try {
			// Idempotency: is the constant already defined? `wp config get`
			// exits 0 and prints the value when present, non-zero when absent.
			$current = $ssh->exec( 'wp config get ' . self::CONSTANT_NAME . ' 2>/dev/null' );
			$exists  = 0 === $ssh->getExitStatus();

			if ( $exists ) {
				$value  = trim( (string) $current );
				$truthy = in_array( strtolower( $value ), array( 'true', '1' ), true );

				if ( $truthy ) {
					return array(
						'status' => 'exists',
						'note'   => "Already set (value: {$value}).",
					);
				}

				// Present but not truthy: never overwrite — this could be a
				// deliberate manual disable. Report it instead. The value is
				// quoted because the common case is an empty string, which is
				// indistinguishable from a missing value unquoted.
				return array(
					'status' => 'exists-disabled',
					'note'   => 'Already defined but NOT truthy (value: "' . $value . '") — left unchanged. Bot protection is OFF on this site; review it by hand.',
				);
			}

			if ( $this->dry_run ) {
				return array(
					'status' => 'would-set',
					'note'   => 'Would set ' . self::CONSTANT_NAME . ' = true.',
				);
			}

			$set_output = $ssh->exec( 'wp config set ' . self::CONSTANT_NAME . ' true --raw' );
			$exit_code  = $ssh->getExitStatus();

			if ( 0 !== $exit_code ) {
				$detail = trim( (string) $set_output );
				$detail = '' !== $detail ? ": {$detail}" : '.';
				return array(
					'status' => 'failed',
					'note'   => "wp config set failed (exit {$exit_code}){$detail}",
				);
			}

			return array(
				'status' => 'success',
				'note'   => 'Set ' . self::CONSTANT_NAME . ' = true.',
			);
		} finally {
			$ssh->disconnect();
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the ledger path to an absolute one.
	 *
	 * A bare filename would resolve against the process CWD, and `team51` is
	 * installed on the PATH and run from arbitrary directories — so the default
	 * is pinned to the CLI's own directory, where the ledger from the original
	 * fleet sweep lives and where .gitignore expects it.
	 *
	 * @param   mixed $log_option The raw `--log` option value.
	 *
	 * @return  string
	 */
	private function resolve_ledger_path( mixed $log_option ): string {
		if ( ! is_string( $log_option ) || '' === $log_option ) {
			return TEAM51_CLI_ROOT_DIR . '/' . self::DEFAULT_LEDGER;
		}

		// An explicit relative --log stays relative to the CWD, which is what an
		// operator typing a path means by it.
		return str_starts_with( $log_option, '/' ) ? $log_option : getcwd() . '/' . $log_option;
	}

	/**
	 * Verifies the ledger can be appended to, before any site is touched.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function assert_ledger_writable( OutputInterface $output ): void {
		if ( is_file( $this->ledger_path ) ) {
			if ( ! is_writable( $this->ledger_path ) ) {
				$output->writeln( "<error>The ledger at {$this->ledger_path} is not writable.</error>" );
				exit( 1 );
			}

			return;
		}

		$directory = \dirname( $this->ledger_path );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			$output->writeln( "<error>The ledger directory {$directory} does not exist or is not writable.</error>" );
			exit( 1 );
		}
	}

	/**
	 * Appends the result to the ledger. No-op during a dry run.
	 *
	 * The ledger is the audit trail for a security control, so it is only ever
	 * appended to: a run that reads the whole file and writes it back can lose
	 * rows it never wrote — to a partial read, or to a second run that flushed
	 * in between. Appending under an exclusive lock has neither failure mode,
	 * and a repeated site simply gains a second row, which is history rather
	 * than a duplicate.
	 *
	 * @param   \stdClass                             $site   The processed site.
	 * @param   array{ status: string, note: string } $result The processing result.
	 *
	 * @throws  \RuntimeException If the entry cannot be encoded or appended (must fail loudly).
	 *
	 * @return  void
	 */
	private function append_ledger( \stdClass $site, array $result ): void {
		if ( $this->dry_run ) {
			return;
		}

		$entry = array(
			'id'        => $site->id,
			'url'       => $site->url ?? null,
			'name'      => $site->displayName ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'staging'   => (bool) ( $site->staging ?? false ),
			'status'    => $result['status'],
			'note'      => $result['note'],
			'timestamp' => gmdate( 'c' ),
		);

		// Substitute rather than fail on malformed UTF-8 from the API: a slightly
		// degraded row still records that the site was written to, which is the
		// point of the ledger.
		$encoded = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $encoded ) {
			throw new \RuntimeException( 'Failed to encode the ledger entry for ' . $this->site_label( $site ) . '.' );
		}

		if ( false === file_put_contents( $this->ledger_path, $encoded . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( "Failed to append to the ledger at {$this->ledger_path}." );
		}
	}

	/**
	 * Prints the result line (skipped when --no-output).
	 *
	 * @param   string          $status The result status.
	 * @param   string          $note   The descriptive note.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  void
	 */
	private function report_result( string $status, string $note, OutputInterface $output ): void {
		if ( $this->quiet ) {
			return;
		}

		$style = match ( $status ) {
			'success', 'would-set' => 'info',
			'exists'               => 'comment',
			default                => 'error',
		};

		$output->writeln( "  <{$style}>{$note}</{$style}>" );

		if ( ! $this->dry_run ) {
			$output->writeln( "  Ledger: {$this->ledger_path}" );
		}
	}

	/**
	 * Builds a human-readable label for a site.
	 *
	 * @param   \stdClass $site The site object.
	 *
	 * @return  string
	 */
	private function site_label( \stdClass $site ): string {
		return (string) ( $site->url ?? $site->displayName ?? $site->id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Prompts for a site when the argument is omitted in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or Pressable site ID to enable bot protection on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
