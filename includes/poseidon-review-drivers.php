<?php
/**
 * The model providers that `poseidon:pr-review` can run on, and the setup instructions it prints when one is not ready.
 *
 * @package WPCOMSpecialProjects\CLI
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * A setup or run failure, with the steps a person or their AI agent can follow to fix it.
 */
final class Review_Problem {
	/**
	 * Creates the problem.
	 *
	 * @param string                                     $title        What went wrong, in one line.
	 * @param string                                     $reason       Why it matters.
	 * @param array<int, array{0: string, 1?: string[]}> $steps        The label and optional commands of each step.
	 * @param string                                     $agent_prompt Plain text that a person can paste into an AI agent.
	 * @param string[]                                   $details      Extra lines, such as the available models or an error message.
	 * @param boolean                                    $show_retry Whether the steps end with the command that runs the review again.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $reason,
		public readonly array $steps,
		public readonly string $agent_prompt,
		public readonly array $details = array(),
		public readonly bool $show_retry = true,
	) {}

	/**
	 * Prints the problem.
	 *
	 * @param OutputInterface $output        The output to write to.
	 * @param string          $retry_command The command that runs the review again.
	 *
	 * @return void
	 */
	public function render( OutputInterface $output, string $retry_command ): void {
		$output->writeln( '' );
		$output->writeln( '  <fg=red;options=bold>✖ ' . OutputFormatter::escape( $this->title ) . '</>' );
		$output->writeln( '  ' . OutputFormatter::escape( $this->reason ) );

		foreach ( $this->details as $detail ) {
			$output->writeln( '    <fg=gray>' . OutputFormatter::escape( $detail ) . '</>' );
		}

		$output->writeln( '' );
		$output->writeln( '  <fg=cyan;options=bold>Fix it yourself</>' );

		$steps = $this->steps;
		if ( $this->show_retry ) {
			$steps[] = array( 'Run the review again:', array( $retry_command ) );
		}
		foreach ( $steps as $index => $step ) {
			$output->writeln( '  <options=bold>' . ( $index + 1 ) . '.</> ' . OutputFormatter::escape( $step[0] ) );
			foreach ( $step[1] ?? array() as $command ) {
				$output->writeln( '     <fg=yellow>$ ' . OutputFormatter::escape( $command ) . '</>' );
			}
		}

		$output->writeln( '' );
		$output->writeln( '  <fg=magenta;options=bold>Or paste this into your AI agent</>' );
		$output->writeln( $this->agent_prompt, OutputInterface::OUTPUT_RAW );
		$output->writeln( '' );
	}
}

/**
 * A way to run the review agent against one model.
 */
interface Review_Driver {
	/**
	 * The model name to show the user.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Where the PR code goes during the review.
	 *
	 * @return string
	 */
	public function destination(): string;

	/**
	 * The key under which the user's paid-plan confirmation is saved, or null if the provider needs no confirmation.
	 *
	 * @return string|null
	 */
	public function consent_key(): ?string;

	/**
	 * The account that the provider's CLI reports, if it reports one.
	 *
	 * @return string|null
	 */
	public function account_status(): ?string;

	/**
	 * Checks that this computer can run the provider.
	 *
	 * @return Review_Problem|null
	 */
	public function preflight(): ?Review_Problem;

	/**
	 * The review timeout in seconds, unless the user sets one.
	 *
	 * @return integer
	 */
	public function default_timeout(): int;

	/**
	 * The prompt text that describes the agent's tools.
	 *
	 * @param boolean $has_checkout Whether the working directory contains the repository.
	 *
	 * @return string
	 */
	public function agent_rules( bool $has_checkout ): string;

	/**
	 * Creates the process that runs the agent.
	 *
	 * @param string  $instructions The system prompt.
	 * @param string  $task         The user prompt.
	 * @param string  $scratch_dir  A private directory outside the working directory.
	 * @param string  $work_dir     The working directory.
	 * @param integer $max_turns    The maximum number of agent turns, where the provider supports a limit.
	 * @param boolean $has_checkout Whether the working directory contains the repository.
	 *
	 * @return Process
	 */
	public function create_process( string $instructions, string $task, string $scratch_dir, string $work_dir, int $max_turns, bool $has_checkout ): Process;

	/**
	 * Reads the agent's final message from a finished process.
	 *
	 * @param Process $process The finished process.
	 *
	 * @return string
	 */
	public function read_result( Process $process ): string;

	/**
	 * Explains a failed run, if the failure is a known one.
	 *
	 * @param Process $process The finished process.
	 *
	 * @return Review_Problem|null
	 */
	public function diagnose( Process $process ): ?Review_Problem;
}

/**
 * Runs the review through the Claude Code CLI, which also serves local models through LM Studio's Anthropic-compatible API.
 */
abstract class Claude_Code_Review_Driver implements Review_Driver {
	/**
	 * The permission rules for the agent. `dontAsk` denies every other tool call, but built-in read-only commands still run, so network commands are denied explicitly.
	 */
	private const PERMISSIONS = array(
		'allow'                               => array( 'Bash(git log *)', 'Bash(git diff *)', 'Bash(git show *)' ),
		'deny'                                => array( 'Bash(gh *)', 'Bash(git fetch *)', 'Bash(git pull *)', 'Bash(git push *)', 'Bash(git ls-remote *)', 'Bash(git remote *)', 'Bash(git submodule *)', 'Bash(git clone *)' ),
		'blockReadsOutsideWorkingDirectories' => true,
	);

	/**
	 * Creates the driver.
	 *
	 * @param string $model The model ID.
	 */
	public function __construct( protected readonly string $model ) {}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function consent_key(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function account_status(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function preflight(): ?Review_Problem {
		$help = Review_Drivers::command_output( array( 'claude', '--help' ) );
		if ( null === $help ) {
			return Review_Drivers::claude_missing_problem();
		}

		if ( ! str_contains( $help, '--restricted' ) || ! str_contains( $help, 'dontAsk' ) || ! str_contains( $help, '--strict-mcp-config' ) ) {
			return new Review_Problem(
				'Your `claude` CLI is too old',
				'This version cannot confine the review agent, so the review will not start.',
				array( array( 'Update Claude Code:', array( 'claude update' ) ) ),
				'Run `claude update`, then confirm that `claude --help` lists `--restricted`. Do not run the review.'
			);
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function agent_rules( bool $has_checkout ): string {
		$rules = 'Inspect files with the Read, Grep, and Glob tools.';
		if ( $has_checkout ) {
			$rules .= ' Bash runs only `git log`, `git diff`, and `git show`.';
		}

		return $rules;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_process( string $instructions, string $task, string $scratch_dir, string $work_dir, int $max_turns, bool $has_checkout ): Process {
		$instructions_file = $scratch_dir . '/instructions.md';
		file_put_contents( $instructions_file, $instructions ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$process = new Process(
			array(
				'claude',
				'-p',
				$task,
				'--append-system-prompt-file',
				$instructions_file,
				'--model',
				$this->model,
				'--max-turns',
				(string) $max_turns,
				'--restricted',
				'--tools',
				$has_checkout ? 'Read,Grep,Glob,Bash' : 'Read,Grep,Glob',
				'--strict-mcp-config',
				'--permission-mode',
				'dontAsk',
				'--settings',
				json_encode( array( 'permissions' => self::PERMISSIONS ), JSON_UNESCAPED_SLASHES ),
				'--output-format',
				'json',
			),
			$work_dir,
			$this->environment() + Review_Drivers::REMOVED_ENV
		);
		$process->setInput( '' );

		return $process;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read_result( Process $process ): string {
		$data = json_decode( $process->getOutput(), true );
		if ( ! is_array( $data ) || ! empty( $data['is_error'] ) || 'success' !== ( $data['subtype'] ?? '' ) ) {
			return '';
		}

		return trim( (string) ( $data['result'] ?? '' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function diagnose( Process $process ): ?Review_Problem {
		$data    = json_decode( $process->getOutput(), true );
		$data    = is_array( $data ) ? $data : array();
		$message = trim( (string) ( $data['result'] ?? '' ) . ' ' . $process->getErrorOutput() );

		if ( 'error_max_turns' === ( $data['subtype'] ?? '' ) ) {
			return new Review_Problem(
				'The agent reached the turn limit',
				'The agent used all of its turns before it finished the review.',
				array( array( 'Add `--max-turns` with a higher number when you run the review again, for example `--max-turns=90`.' ) ),
				'Tell me that the review reached the turn limit. Do not run the review.'
			);
		}

		if ( preg_match( '/issue with the selected model|may not exist|model_not_found/i', $message ) ) {
			return new Review_Problem(
				"Claude Code cannot use the model `{$this->model}`",
				'The model does not exist, or your account has no access to it.',
				array( array( 'Check the model ID and your account access. The default model is `claude-opus-5-5`.' ) ),
				"The `claude` CLI rejected the model `{$this->model}`. Tell me which model IDs my account can use. Do not run the review.",
				array( $message )
			);
		}

		if ( preg_match( '/not logged in|\/login|invalid api key|authentication/i', $message ) ) {
			return new Review_Problem(
				'Claude Code is not signed in',
				'The `claude` CLI could not authenticate this run.',
				array( array( 'Sign in to Claude Code:', array( 'claude auth login' ) ) ),
				'Ask me to run `claude auth login`, because it opens a browser. Then run `claude auth status` and tell me the result. Do not run the review.',
				array( $message )
			);
		}

		return null;
	}

	/**
	 * The environment that the agent needs, added to the inherited environment.
	 *
	 * @return array<string, string|false>
	 */
	abstract protected function environment(): array;
}

/**
 * Runs the review on Anthropic's hosted models.
 */
final class Anthropic_Review_Driver extends Claude_Code_Review_Driver {
	/**
	 * {@inheritDoc}
	 */
	public function destination(): string {
		return 'Anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_timeout(): int {
		return 1200;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function environment(): array {
		return array();
	}
}

/**
 * Runs the review on a model that LM Studio serves from this computer.
 */
final class LM_Studio_Review_Driver extends Claude_Code_Review_Driver {
	/**
	 * The LM Studio server. A remote URL would send PR code off this computer, so the address is fixed.
	 */
	private const BASE_URL = 'http://localhost:1234';

	/**
	 * The smallest context that holds Claude Code's own prompt, the review prompt, and a small PR. LM Studio advises more than 25,000 tokens.
	 */
	private const MIN_CONTEXT = 32768;

	/**
	 * Models whose safety behavior was removed. They read untrusted PR text, so they cannot run reviews.
	 */
	private const BLOCKED_MODELS = '/obliterat|abliterat|uncensored/i';

	/**
	 * The context of the loaded model, if it is loaded.
	 *
	 * @var integer|null
	 */
	private ?int $loaded_context = null;

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return "{$this->model} (LM Studio)";
	}

	/**
	 * {@inheritDoc}
	 */
	public function destination(): string {
		return 'this computer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_timeout(): int {
		return 3600;
	}

	/**
	 * {@inheritDoc}
	 */
	public function preflight(): ?Review_Problem {
		$problem = parent::preflight();
		if ( null !== $problem ) {
			return $problem;
		}

		if ( preg_match( self::BLOCKED_MODELS, $this->model ) ) {
			return new Review_Problem(
				"The model `{$this->model}` cannot run reviews",
				'The safety behavior of this model was removed, and a review reads untrusted PR text.',
				array( array( 'Use a supported local model. Run `lms ls` to see your models.' ) ),
				'Run `lms ls` and tell me which models I have. Do not suggest a model with "obliterated", "abliterated", or "uncensored" in its name. Do not run the review.'
			);
		}

		$models = $this->fetch_models();
		if ( null === $models ) {
			return null === Review_Drivers::command_output( array( 'lms', 'version' ) ) ? $this->lm_studio_missing_problem() : $this->server_down_problem();
		}

		$usable = array();
		$model  = null;
		foreach ( $models as $candidate ) {
			$id = (string) ( $candidate['id'] ?? '' );
			if ( $id === $this->model ) {
				$model = $candidate;
			}
			if ( in_array( 'tool_use', (array) ( $candidate['capabilities'] ?? array() ), true ) && ! preg_match( self::BLOCKED_MODELS, $id ) ) {
				$usable[] = "--model=lmstudio/$id";
			}
		}

		if ( null === $model ) {
			return new Review_Problem(
				"The model `{$this->model}` is not on this computer",
				'LM Studio is running, but it does not list this model. LM Studio can answer an unknown ID with another model, so the review will not start.',
				array( array( 'Download the model. Large models can use many gigabytes:', array( "lms get {$this->model}" ) ) ),
				"Run `lms ls` and check for {$this->model}. If it is missing, ask me before you run `lms get {$this->model}`, because the download can be large. Then confirm that the model is listed. Do not run the review.",
				$usable ? array_merge( array( 'Models here that can run a review:' ), $usable ) : array()
			);
		}

		if ( ! in_array( 'tool_use', (array) ( $model['capabilities'] ?? array() ), true ) ) {
			return new Review_Problem(
				"The model `{$this->model}` cannot use tools",
				'The review agent reads files through tool calls. Without them, the model cannot see the PR.',
				array( array( 'Use a model that LM Studio reports with the `tool_use` capability.' ) ),
				'Run `curl -s http://localhost:1234/api/v0/models` and tell me which models have `tool_use` in `capabilities`. Do not run the review.',
				$usable ? array_merge( array( 'Models here that can run a review:' ), $usable ) : array()
			);
		}

		if ( 'loaded' === ( $model['state'] ?? '' ) && isset( $model['loaded_context_length'] ) ) {
			$this->loaded_context = (int) $model['loaded_context_length'];
			if ( self::MIN_CONTEXT > $this->loaded_context ) {
				return new Review_Problem(
					"The model `{$this->model}` has too little context",
					"LM Studio loaded this model with {$this->loaded_context} tokens. A review needs at least " . self::MIN_CONTEXT . '.',
					array(
						array( 'Unload the model:', array( "lms unload {$this->model}" ) ),
						array( 'Load it with more context:', array( "lms load {$this->model} --context-length " . self::MIN_CONTEXT ) ),
					),
					"Run `lms unload {$this->model}`, then `lms load {$this->model} --context-length " . self::MIN_CONTEXT . '`, then confirm with `lms ps`. Do not run the review.'
				);
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function diagnose( Process $process ): ?Review_Problem {
		$data    = json_decode( $process->getOutput(), true );
		$message = trim( (string) ( is_array( $data ) ? ( $data['result'] ?? '' ) : '' ) . ' ' . $process->getErrorOutput() );

		if ( preg_match( '/no models loaded/i', $message ) ) {
			return new Review_Problem(
				"LM Studio did not load `{$this->model}`",
				'The server is running, but no model is in memory.',
				array( array( 'Load the model:', array( "lms load {$this->model}" ) ) ),
				"Run `lms load {$this->model}`, then confirm with `lms ps`. Do not run the review.",
				array( $message )
			);
		}

		if ( preg_match( '/connection|econnrefused|fetch failed|unable to connect/i', $message ) ) {
			return $this->server_down_problem( array( $message ) );
		}

		return parent::diagnose( $process );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function environment(): array {
		$environment = array(
			'ANTHROPIC_BASE_URL'                       => self::BASE_URL,
			'ANTHROPIC_AUTH_TOKEN'                     => 'lmstudio',
			'ANTHROPIC_API_KEY'                        => false,
			'CLAUDE_CODE_ATTRIBUTION_HEADER'           => '0',
			'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
		);

		if ( null !== $this->loaded_context ) {
			$environment['CLAUDE_CODE_AUTO_COMPACT_WINDOW'] = (string) $this->loaded_context;
		}

		return $environment;
	}

	/**
	 * Lists the models that LM Studio knows about, or returns null if the server is not reachable.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function fetch_models(): ?array {
		$context = stream_context_create(
			array(
				'http' => array(
					'timeout'       => 3,
					'ignore_errors' => true,
				),
			)
		);
		$body    = @file_get_contents( self::BASE_URL . '/api/v0/models', false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data    = false === $body ? null : json_decode( $body, true );

		return is_array( $data['data'] ?? null ) ? $data['data'] : null;
	}

	/**
	 * The instructions for a computer without LM Studio.
	 *
	 * @return Review_Problem
	 */
	private function lm_studio_missing_problem(): Review_Problem {
		return new Review_Problem(
			'LM Studio is not installed',
			'Local reviews run on a model that LM Studio serves from this computer. The PR code stays here.',
			array(
				array( 'Install LM Studio, or download it from https://lmstudio.ai/download:', array( 'brew install --cask lm-studio' ) ),
				array( 'Open LM Studio once, so that it sets up the `lms` command. Then open a new terminal.' ),
				array( 'Download the model. Large models can use many gigabytes:', array( "lms get {$this->model}" ) ),
				array( 'Start the server:', array( 'lms server start --port 1234' ) ),
			),
			"Help me set up LM Studio for local code reviews. Ask me before you install software or download a model. The steps are: install LM Studio with `brew install --cask lm-studio`; ask me to open the app once; download the model with `lms get {$this->model}`; start the server with `lms server start --port 1234`; confirm that `curl -s http://localhost:1234/api/v0/models` lists {$this->model}. Do not run the review."
		);
	}

	/**
	 * The instructions for a stopped server.
	 *
	 * @param string[] $details Extra lines to show.
	 *
	 * @return Review_Problem
	 */
	private function server_down_problem( array $details = array() ): Review_Problem {
		return new Review_Problem(
			'The LM Studio server is not running',
			'The CLI could not reach LM Studio at ' . self::BASE_URL . '.',
			array( array( 'Start the server:', array( 'lms server start --port 1234' ) ) ),
			'Run `lms server start --port 1234`, then confirm that `curl -s http://localhost:1234/api/v0/models` returns a model list. Do not run the review.',
			$details
		);
	}
}

/**
 * Runs the review on OpenAI's hosted models through the Codex CLI.
 */
final class Codex_Review_Driver implements Review_Driver {
	/**
	 * The oldest Codex CLI tested with the read limits in the permission profile.
	 *
	 * @var string
	 */
	private const MIN_VERSION = '0.166.0';

	/**
	 * The file in the scratch directory where Codex writes its final message.
	 *
	 * @var string|null
	 */
	private ?string $result_file = null;

	/**
	 * Creates the driver.
	 *
	 * @param string $model The model ID.
	 */
	public function __construct( private readonly string $model ) {}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function destination(): string {
		return 'OpenAI';
	}

	/**
	 * {@inheritDoc}
	 */
	public function consent_key(): ?string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function account_status(): ?string {
		// `codex login status` prints to stderr.
		$status = trim( (string) Review_Drivers::command_output( array( 'codex', 'login', 'status' ), true ) );
		return '' === $status ? null : $status;
	}

	/**
	 * {@inheritDoc}
	 */
	public function preflight(): ?Review_Problem {
		// A wrapper such as cmux's can put `codex` on PATH with no real binary behind it, so `which` is not enough.
		$version = Review_Drivers::command_output( array( 'codex', '--version' ) );
		if ( null === $version ) {
			return new Review_Problem(
				'The `codex` CLI is not installed',
				'Reviews on OpenAI models run through the Codex CLI, with your own OpenAI account.',
				array(
					array( 'Install the Codex CLI. Use one of these commands:', array( 'brew install --cask codex', 'npm install --global @openai/codex' ) ),
					array( 'Sign in. Use an Enterprise or paid plan:', array( 'codex login' ) ),
					array( 'Check the sign-in:', array( 'codex login status' ) ),
				),
				'Help me install the OpenAI Codex CLI. Ask me before you install software. If Homebrew is available, run `brew install --cask codex`. Otherwise run `npm install --global @openai/codex`. Confirm with `codex --version`. Then ask me to run `codex login`, because it opens a browser and needs an Enterprise or paid plan. Finish with `codex login status`. Do not run the review.'
			);
		}

		$help    = (string) Review_Drivers::command_output( array( 'codex', 'exec', '--help' ) );
		$too_old = ! preg_match( '/\d+\.\d+\.\d+/', $version, $number ) || version_compare( $number[0], self::MIN_VERSION, '<' );
		if ( $too_old || ! str_contains( $help, '--ignore-user-config' ) || ! str_contains( $help, '--ignore-rules' ) || ! str_contains( $help, '--ephemeral' ) ) {
			return new Review_Problem(
				'Your `codex` CLI is too old',
				'This version cannot confine the review agent, so the review will not start.',
				array( array( 'Update the Codex CLI. Use the command that matches your install:', array( 'brew upgrade --cask codex', 'npm install --global @openai/codex@latest' ) ) ),
				'Update the OpenAI Codex CLI with the package manager that installed it. Then confirm that `codex exec --help` lists `--ignore-user-config`. Do not run the review.'
			);
		}

		if ( null === Review_Drivers::command_output( array( 'codex', 'login', 'status' ) ) ) {
			return $this->login_problem();
		}

		// Codex names ChatGPT sign-ins in this rejection, so an API key sign-in skips the check.
		$models = $this->model_catalog();
		if ( array() !== $models && ! isset( $models[ $this->model ] ) && str_contains( (string) $this->account_status(), 'ChatGPT' ) ) {
			return $this->model_problem();
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_timeout(): int {
		return 1200;
	}

	/**
	 * {@inheritDoc}
	 */
	public function agent_rules( bool $has_checkout ): string {
		$rules = 'The Read, Grep, Glob, and Write tools named above do not exist in this run. Inspect files with read-only shell commands such as `cat`, `sed -n`, and `grep`. The sandbox is read-only, has no network, and blocks files outside the working directory. `git` does not work in this run, so use the supplied `diff.patch` and `pr.json`.';
		if ( $has_checkout ) {
			$rules .= ' The working directory contains the PR version of the files.';
		}

		return $rules;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_process( string $instructions, string $task, string $scratch_dir, string $work_dir, int $max_turns, bool $has_checkout ): Process {
		$this->result_file = $scratch_dir . '/codex-result.md';

		$process = new Process(
			array(
				'codex',
				'exec',
				$task,
				'--model',
				$this->model,
				'--ephemeral',
				'--skip-git-repo-check',
				'--ignore-user-config',
				'--ignore-rules',
				'--color',
				'never',
				'--output-last-message',
				$this->result_file,
				'-c',
				'approval_policy="never"',
				'-c',
				'shell_environment_policy.inherit="core"',
				'-c',
				'web_search="disabled"',
				'-c',
				'default_permissions="poseidon"',
				'-c',
				'permissions.poseidon.filesystem=' . $this->filesystem_profile(),
				'-c',
				'developer_instructions=' . json_encode( $instructions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			),
			$work_dir,
			Review_Drivers::REMOVED_ENV
		);
		$process->setInput( '' );

		return $process;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read_result( Process $process ): string {
		if ( null === $this->result_file || ! is_file( $this->result_file ) ) {
			return '';
		}

		return trim( (string) file_get_contents( $this->result_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * {@inheritDoc}
	 */
	public function diagnose( Process $process ): ?Review_Problem {
		$message = trim( $process->getErrorOutput() . ' ' . $process->getOutput() );

		// `codex login status` still reports success when the saved token cannot refresh.
		if ( preg_match( '/access token|sign in again|not logged in|unauthorized|\b401\b/i', $message ) ) {
			return $this->login_problem( array( $message ) );
		}

		if ( preg_match( '/fs sandbox helper|sandbox-exec/i', $message ) ) {
			return new Review_Problem(
				'Codex could not start its sandbox',
				'The CLI stopped the review because the agent cannot run with its file limits. The CLI sent no PR code to the agent.',
				array(
					array( 'Update the Codex CLI. Use the command that matches your install:', array( 'brew upgrade --cask codex', 'npm install --global @openai/codex@latest' ) ),
					array( 'If the error stays, use an Anthropic model with `--model=claude-opus-5-5`.' ),
				),
				'The Poseidon review could not start the Codex sandbox. Show me the error details, and help me update the Codex CLI. Do not change the sandbox settings, and do not run the review.',
				array( mb_substr( $message, -500 ) )
			);
		}

		if ( preg_match( '/model.{0,80}(not supported|not found|does not exist|unsupported|no access|not available)|model_not_found/is', $message ) ) {
			return $this->model_problem( preg_match( '/^ERROR: .*$/m', $message, $error ) ? array( $error[0] ) : array( mb_substr( $message, -500 ) ) );
		}

		return null;
	}

	/**
	 * The files that the agent can read, as a TOML table: the working directory and what its tools need.
	 *
	 * @return string
	 */
	private function filesystem_profile(): string {
		$home    = (string) getenv( 'HOME' );
		$profile = array(
			':minimal'         => 'read',
			':workspace_roots' => array( '.' => 'read' ),
		);

		// The sandbox starts Codex itself, so it must read the install folder. Never allow a folder that contains the home folder.
		foreach ( array_filter( explode( "\n", (string) Review_Drivers::command_output( array( 'which', '-a', 'codex' ) ) ) ) as $path ) {
			foreach ( array( dirname( $path ), dirname( (string) realpath( $path ), 2 ) ) as $folder ) {
				if ( '' !== $home && ! str_starts_with( $home . '/', rtrim( $folder, '/' ) . '/' ) ) {
					$profile[ $folder ] = 'read';
				}
			}
		}

		// Other tools keep files in the shared temporary folders. The working directory stays readable.
		foreach ( array( '/private/tmp', (string) realpath( sys_get_temp_dir() ) ) as $path ) {
			if ( '' !== $path && ! isset( $profile[ $path ] ) ) {
				$profile[ $path ] = 'deny';
			}
		}

		$entries = array();
		foreach ( $profile as $path => $access ) {
			$value     = is_array( $access ) ? '{"."="read"}' : "\"$access\"";
			$entries[] = json_encode( $path, JSON_UNESCAPED_SLASHES ) . "=$value";
		}

		return '{' . implode( ', ', $entries ) . '}';
	}

	/**
	 * The models in this sign-in's catalog, as model ID => whether Codex lists it for users.
	 *
	 * @return array<string, boolean> An empty array if the catalog cannot be read.
	 */
	private function model_catalog(): array {
		$catalog = json_decode( (string) Review_Drivers::command_output( array( 'codex', 'debug', 'models' ) ), true );

		$models = array();
		foreach ( $catalog['models'] ?? array() as $model ) {
			if ( isset( $model['slug'] ) ) {
				$models[ $model['slug'] ] = 'list' === ( $model['visibility'] ?? '' );
			}
		}

		return $models;
	}

	/**
	 * The screen for a model ID that this sign-in cannot use.
	 *
	 * @param string[] $details The error text from Codex.
	 *
	 * @return Review_Problem
	 */
	private function model_problem( array $details = array() ): Review_Problem {
		$listed = array_keys( array_filter( $this->model_catalog() ) );
		$step   = array() === $listed
			? array( 'Find a model ID with `codex debug models`, and use it as `--model=openai/<id>`.' )
			: array(
				'Use a model that your sign-in offers:',
				array_map( static fn( string $id ): string => "team51 poseidon:pr-review <PR> --model=openai/$id", $listed ),
			);

		return new Review_Problem(
			"Codex cannot use the model `{$this->model}`",
			'The model does not exist, or your sign-in has no access to it. OpenAI publishes some models that a ChatGPT sign-in cannot use.',
			array( $step ),
			"The `codex` CLI rejected the model `{$this->model}`. Run `codex debug models`, and tell me the `slug` values where `visibility` is `list`. Do not run the review.",
			$details,
			false
		);
	}

	/**
	 * The instructions for a missing or expired sign-in.
	 *
	 * @param string[] $details Extra lines to show.
	 *
	 * @return Review_Problem
	 */
	private function login_problem( array $details = array() ): Review_Problem {
		return new Review_Problem(
			'Codex is not signed in',
			'The sign-in is missing or expired. `codex login status` can report a sign-in even when the saved token no longer works.',
			array(
				array( 'Remove the old sign-in:', array( 'codex logout' ) ),
				array( 'Sign in again. Use an Enterprise or paid plan:', array( 'codex login' ) ),
				array( 'On a computer with no browser, use this command instead:', array( 'codex login --device-auth' ) ),
			),
			'Ask me to run `codex logout` and then `codex login`, because the sign-in opens a browser and needs an Enterprise or paid plan. Then test it with `codex exec --skip-git-repo-check --sandbox read-only "Reply with OK"`. Do not run the review.',
			$details
		);
	}
}

/**
 * Creates drivers, and holds what the drivers share.
 */
final class Review_Drivers {
	/**
	 * The credentials that the review agent must not inherit. It has no reason to reach GitHub.
	 */
	public const REMOVED_ENV = array(
		'GH_TOKEN'            => false,
		'GITHUB_TOKEN'        => false,
		'GH_ENTERPRISE_TOKEN' => false,
	);

	/**
	 * Creates the driver for a `--model` value.
	 *
	 * @param string $model The value of `--model`.
	 *
	 * @return Review_Driver|Review_Problem
	 */
	public static function create( string $model ): Review_Driver|Review_Problem {
		if ( 1 === preg_match( '/^[a-z0-9][\w.:\/@-]*$/i', $model ) ) {
			if ( str_starts_with( $model, 'claude-' ) ) {
				return new Anthropic_Review_Driver( $model );
			}

			$parts = explode( '/', $model, 2 );
			$id    = $parts[1] ?? '';
			if ( '' !== $id ) {
				switch ( $parts[0] ) {
					case 'anthropic':
						return new Anthropic_Review_Driver( $id );
					case 'openai':
						return new Codex_Review_Driver( $id );
					case 'lmstudio':
						return new LM_Studio_Review_Driver( $id );
				}
			}
		}

		return new Review_Problem(
			"The CLI does not recognize the model `$model`",
			'A model value starts with its provider, so that the CLI knows which tool runs the review.',
			array(
				array(
					'Run the review with one of these forms. The first two send the PR code to the provider. The last one keeps it on this computer:',
					array(
						'team51 poseidon:pr-review <PR> --model=claude-opus-5-5',
						'team51 poseidon:pr-review <PR> --model=openai/gpt-6-luna',
						'team51 poseidon:pr-review <PR> --model=lmstudio/qwen/qwen3.8-27b',
					),
				),
			),
			'The `team51 poseidon:pr-review` command needs `--model` in the form `claude-<id>`, `anthropic/<id>`, `openai/<id>`, or `lmstudio/<id>`. Tell me the corrected value. Do not run the review.',
			array(),
			false
		);
	}

	/**
	 * The instructions for a computer without Claude Code.
	 *
	 * @return Review_Problem
	 */
	public static function claude_missing_problem(): Review_Problem {
		return new Review_Problem(
			'The `claude` CLI is not installed',
			'Reviews on Anthropic and LM Studio models run through Claude Code.',
			array(
				array( 'Install Claude Code. Use one of these commands:', array( 'brew install --cask claude-code', 'npm install --global @anthropic-ai/claude-code' ) ),
				array( 'Sign in. LM Studio models do not need this step:', array( 'claude auth login' ) ),
			),
			'Help me install Claude Code. Ask me before you install software. If Homebrew is available, run `brew install --cask claude-code`. Otherwise run `npm install --global @anthropic-ai/claude-code`. Confirm with `claude --version`. Then ask me to run `claude auth login`, because it opens a browser. Do not run the review.'
		);
	}

	/**
	 * Runs a short command and returns its output, or null if it fails or does not exist.
	 *
	 * @param string[] $command        The command and its arguments.
	 * @param boolean  $include_stderr Whether to include stderr in the output.
	 *
	 * @return string|null
	 */
	public static function command_output( array $command, bool $include_stderr = false ): ?string {
		try {
			$process = new Process( $command );
			$process->setTimeout( 30 );
			$process->run();
			if ( ! $process->isSuccessful() ) {
				return null;
			}

			return $include_stderr ? $process->getOutput() . $process->getErrorOutput() : $process->getOutput();
		} catch ( \Throwable ) {
			return null;
		}
	}
}
