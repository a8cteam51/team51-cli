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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Runs the Poseidon AI code review against a pull request using the locally authenticated `gh` and `claude` CLIs.
 *
 * Unlike the Poseidon GitHub Action (which only runs inside a8cteam51 repos that have the workflow synced), this
 * command relies on the user's own `gh` credentials, so it works on any PR the user can access — including repos in
 * other organisations such as `Automattic`. It deliberately bypasses OpsOasis.
 */
#[AsCommand( name: 'poseidon:pr-review' )]
final class Poseidon_PR_Review extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The owner (organisation or user) of the target repository.
	 *
	 * @var string|null
	 */
	private ?string $owner = null;

	/**
	 * The name of the target repository.
	 *
	 * @var string|null
	 */
	private ?string $repo = null;

	/**
	 * The pull request number.
	 *
	 * @var string|null
	 */
	private ?string $pr_number = null;

	/**
	 * The pull request title, resolved during the access pre-flight.
	 *
	 * @var string|null
	 */
	private ?string $pr_title = null;

	/**
	 * Whether the review should be posted to the PR. When false (default) the review is printed to the terminal only.
	 *
	 * @var boolean
	 */
	private bool $post_to_github = false;

	/**
	 * Whether to skip cloning the repository and have the agent read the PR through the GitHub API instead.
	 *
	 * @var boolean
	 */
	private bool $no_clone = false;

	/**
	 * The Claude model to use.
	 *
	 * @var string|null
	 */
	private ?string $model = null;

	/**
	 * The maximum number of agent turns.
	 *
	 * @var integer
	 */
	private int $max_turns = 45;

	/**
	 * The GitHub username of the currently authenticated `gh` CLI user.
	 *
	 * @var string|null
	 */
	private ?string $gh_username = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Runs the Poseidon AI code review against a pull request using your local `gh` and `claude` CLIs.' )
			->setHelp( "This command fetches the Poseidon review prompt from `a8cteam51/poseidon-actions` (trunk), clones the PR locally, and runs the local `claude` CLI to review it.\n\nIt uses your own `gh` and `claude` authentication (not OpsOasis), so it works on any PR you have GitHub access to — including repos outside a8cteam51 such as `Automattic`.\n\nBy default the review is printed to the terminal and nothing is posted. Pass `--post-to-github` to post the review to the PR as your `gh` user." );

		$this->addArgument( 'pr', InputArgument::OPTIONAL, 'Pull request URL (https://github.com/OWNER/REPO/pull/N) or OWNER/REPO#N. Prompted for if omitted.' );

		$this->addOption( 'post-to-github', null, InputOption::VALUE_NONE, 'Post the review to the PR as your `gh` user. Off by default (prints to the terminal only).' );
		$this->addOption( 'no-clone', null, InputOption::VALUE_NONE, 'Skip cloning the repository; the agent reads the PR through the GitHub API. Faster, but loses AGENTS.md/.agents grounding and surrounding-code context.' );
		$this->addOption( 'model', null, InputOption::VALUE_REQUIRED, 'The Claude model to use.', 'claude-opus-4-8' );
		$this->addOption( 'max-turns', null, InputOption::VALUE_REQUIRED, 'The maximum number of agent turns.', '45' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If the PR reference cannot be parsed.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$reference = get_string_input( $input, 'pr', fn() => $this->prompt_pr_input( $input, $output ) );
		$parsed    = $this->parse_pr_reference( $reference );
		if ( null === $parsed ) {
			throw new \InvalidArgumentException( "Could not parse a pull request reference from `$reference`. Expected a PR URL or OWNER/REPO#N." );
		}
		list( $this->owner, $this->repo, $this->pr_number ) = $parsed;

		$this->post_to_github = (bool) $input->getOption( 'post-to-github' );
		$this->no_clone       = (bool) $input->getOption( 'no-clone' );
		$this->model          = get_string_input( $input, 'model' );
		$this->max_turns      = (int) get_string_input( $input, 'max-turns' );

		if ( ! $this->command_exists( 'claude' ) ) {
			$output->writeln( '<error>The `claude` CLI is not installed or not on PATH. Install Claude Code and try again.</error>' );
			exit( Command::FAILURE );
		}

		$this->gh_username = $this->resolve_gh_username( $output );
		if ( null === $this->gh_username ) {
			exit( Command::FAILURE );
		}

		$this->pr_title = $this->resolve_pr_title( $output );
		if ( null === $this->pr_title ) {
			exit( Command::FAILURE );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( ! $this->post_to_github ) {
			return;
		}

		$question = new ConfirmationQuestion(
			"<question>Run Poseidon Review on `{$this->owner}/{$this->repo}#{$this->pr_number}` and POST the comment as `{$this->gh_username}`? [y/N]</question> ",
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
		$output->writeln( "<fg=magenta;options=bold>Poseidon Review — {$this->owner}/{$this->repo}#{$this->pr_number}: {$this->pr_title}</>" );

		$prompt = $this->fetch_review_prompt( $output );
		if ( null === $prompt ) {
			return Command::FAILURE;
		}
		$prompt .= "\n\n" . $this->target_context();
		if ( $this->no_clone ) {
			$prompt .= "\n\n" . $this->no_clone_instructions();
		}
		if ( ! $this->post_to_github ) {
			$prompt .= "\n\n" . $this->terminal_only_override();
		}

		$filesystem  = new Filesystem();
		$suffix      = bin2hex( random_bytes( 4 ) );
		$prompt_file = sys_get_temp_dir() . "/team51-poseidon-prompt-$suffix.md";
		$work_dir    = sys_get_temp_dir() . "/team51-poseidon-review-{$this->pr_number}-$suffix";

		try {
			$filesystem->dumpFile( $prompt_file, $prompt );

			if ( $this->no_clone ) {
				$filesystem->mkdir( $work_dir );
				$output->writeln( '<comment>Skipping clone (--no-clone); the agent will read the PR via the GitHub API.</comment>' );
			} else {
				$output->writeln( "<comment>Cloning into a temporary directory:</comment> $work_dir" );
				$clone = new Process( array( 'gh', 'repo', 'clone', "{$this->owner}/{$this->repo}", $work_dir, '--', '--depth=50' ) );
				$clone->setTimeout( 300 );
				$clone->run();
				if ( ! $clone->isSuccessful() ) {
					$output->writeln( '<error>Failed to clone the repository.</error>' );
					$this->maybe_print_stderr( $output, $clone );
					return Command::FAILURE;
				}

				$checkout = new Process( array( 'gh', 'pr', 'checkout', $this->pr_number, '--detach' ), $work_dir );
				$checkout->setTimeout( 300 );
				$checkout->run();
				if ( ! $checkout->isSuccessful() ) {
					$output->writeln( '<error>Failed to check out the PR branch.</error>' );
					$this->maybe_print_stderr( $output, $checkout );
					return Command::FAILURE;
				}
			}

			$mode = $this->post_to_github ? 'the review will be posted to GitHub' : 'the review will be printed below — nothing will be posted';
			$output->writeln( "<comment>Running Claude ({$this->model}); $mode.</comment>\n" );

			$claude = new Process(
				array(
					'claude',
					'-p',
					"Review pull request {$this->owner}/{$this->repo}#{$this->pr_number} following the system prompt rules exactly.",
					'--append-system-prompt-file',
					$prompt_file,
					'--model',
					$this->model,
					'--max-turns',
					(string) $this->max_turns,
					'--permission-mode',
					'bypassPermissions',
				),
				$work_dir
			);
			$claude->setTimeout( 1200 );
			$claude->run(
				static function ( string $type, string $buffer ) use ( $output ): void {
					$output->write( $buffer );
				}
			);

			if ( ! $claude->isSuccessful() ) {
				$output->writeln( "\n<error>Claude exited with a non-zero status.</error>" );
				return Command::FAILURE;
			}

			$output->writeln( "\n<fg=green;options=bold>Poseidon Review complete.</>" );
			if ( $this->post_to_github ) {
				$output->writeln( "<comment>Review posted to https://github.com/{$this->owner}/{$this->repo}/pull/{$this->pr_number}</comment>" );
			}

			return Command::SUCCESS;
		} finally {
			$filesystem->remove( array( $prompt_file, $work_dir ) );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a pull request reference.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_pr_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the pull request URL or OWNER/REPO#N:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Parses a PR reference (full URL or OWNER/REPO#N) into its parts.
	 *
	 * @param   string $reference The raw reference.
	 *
	 * @return  array{0: string, 1: string, 2: string}|null
	 */
	private function parse_pr_reference( string $reference ): ?array {
		$reference = trim( $reference );

		if ( preg_match( '#github\.com/([^/]+)/([^/]+)/pull/(\d+)#', $reference, $matches ) ) {
			return array( $matches[1], $matches[2], $matches[3] );
		}

		if ( preg_match( '~^([^/\s]+)/([^/\s#]+)#(\d+)$~', $reference, $matches ) ) {
			return array( $matches[1], $matches[2], $matches[3] );
		}

		return null;
	}

	/**
	 * Checks whether a command is available on PATH.
	 *
	 * @param   string $command The command to check.
	 *
	 * @return  boolean
	 */
	private function command_exists( string $command ): bool {
		$process = new Process( array( 'which', $command ) );
		$process->run();
		return $process->isSuccessful();
	}

	/**
	 * Resolves the currently authenticated GitHub username via the `gh` CLI.
	 * Returns null and prints a diagnostic on failure (gh missing or not logged in).
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function resolve_gh_username( OutputInterface $output ): ?string {
		if ( ! $this->command_exists( 'gh' ) ) {
			$output->writeln( '<error>The `gh` CLI is not installed or not on PATH. Install it from https://cli.github.com and run `gh auth login`.</error>' );
			return null;
		}

		$process = new Process( array( 'gh', 'api', 'user', '--jq', '.login' ) );
		$process->run();
		if ( ! $process->isSuccessful() ) {
			$output->writeln( '<error>`gh` is installed but not authenticated. Run `gh auth login` and try again.</error>' );
			$this->maybe_print_stderr( $output, $process );
			return null;
		}

		$username = trim( $process->getOutput() );
		if ( '' === $username ) {
			$output->writeln( '<error>`gh api user` returned an empty username. Run `gh auth login` and try again.</error>' );
			return null;
		}

		return $username;
	}

	/**
	 * Resolves the PR title and confirms the authenticated user can access the PR.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function resolve_pr_title( OutputInterface $output ): ?string {
		$process = new Process( array( 'gh', 'pr', 'view', $this->pr_number, '--repo', "{$this->owner}/{$this->repo}", '--json', 'title', '--jq', '.title' ) );
		$process->run();
		if ( ! $process->isSuccessful() ) {
			$output->writeln( "<error>Could not access `{$this->owner}/{$this->repo}#{$this->pr_number}`. Your `gh` user may lack access, or the PR does not exist.</error>" );
			$this->maybe_print_stderr( $output, $process );
			return null;
		}

		return trim( $process->getOutput() );
	}

	/**
	 * Fetches the Poseidon review system prompt from `a8cteam51/poseidon-actions` (trunk) and extracts it from the
	 * inlined `--append-system-prompt` block of `pr-review/action.yml`.
	 *
	 * The prompt is fetched at runtime rather than vendored so it tracks upstream. Once poseidon-actions exposes the
	 * prompt as a standalone file, this should fetch that file directly and drop the string extraction.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function fetch_review_prompt( OutputInterface $output ): ?string {
		$process = new Process( array( 'gh', 'api', 'repos/a8cteam51/poseidon-actions/contents/pr-review/action.yml?ref=trunk', '--jq', '.content' ) );
		$process->run();
		if ( ! $process->isSuccessful() ) {
			$output->writeln( '<error>Failed to fetch the Poseidon review prompt from `a8cteam51/poseidon-actions`. Check your `gh` access to the repository.</error>' );
			$this->maybe_print_stderr( $output, $process );
			return null;
		}

		$yaml = base64_decode( trim( $process->getOutput() ), true );
		if ( false === $yaml || '' === $yaml ) {
			$output->writeln( '<error>Could not decode `pr-review/action.yml`.</error>' );
			return null;
		}

		if ( ! preg_match( '/^(\h*)--append-system-prompt\s+"/m', $yaml, $matches, PREG_OFFSET_CAPTURE ) ) {
			$output->writeln( '<error>Could not locate the system prompt in `pr-review/action.yml` (upstream format may have changed).</error>' );
			return null;
		}

		$indent = $matches[1][0];
		$start  = $matches[0][1] + strlen( $matches[0][0] );
		$end    = strpos( $yaml, '"', $start );
		if ( false === $end ) {
			$output->writeln( '<error>Malformed system prompt block in `pr-review/action.yml`.</error>' );
			return null;
		}

		$prompt = substr( $yaml, $start, $end - $start );
		$prompt = preg_replace( '/^' . preg_quote( $indent, '/' ) . '/m', '', $prompt );

		if ( false === strpos( $prompt, 'poseidon-review:v2' ) ) {
			$output->writeln( '<error>The extracted prompt failed its sanity check (missing the `poseidon-review:v2` marker).</error>' );
			return null;
		}

		return $prompt;
	}

	/**
	 * The target context appended to the system prompt so the agent knows exactly which PR to review.
	 *
	 * @return  string
	 */
	private function target_context(): string {
		return "## Target\nReview pull request `{$this->owner}/{$this->repo}#{$this->pr_number}`.";
	}

	/**
	 * Instructions appended when running with --no-clone, telling the agent to read everything through the GitHub API.
	 *
	 * @return  string
	 */
	private function no_clone_instructions(): string {
		return "## NO LOCAL CHECKOUT\n"
			. "There is no local clone of the repository in this working directory. Read everything through the GitHub CLI for `{$this->owner}/{$this->repo}`:\n"
			. "- The diff: `gh pr diff {$this->pr_number} --repo {$this->owner}/{$this->repo}` (or `gh api repos/{$this->owner}/{$this->repo}/pulls/{$this->pr_number}/files`).\n"
			. "- File contents, `AGENTS.md`, and `.agents/`: `gh api repos/{$this->owner}/{$this->repo}/contents/<path>?ref=<head-sha>` then base64-decode.\n"
			. "- Prior reviews: `gh api repos/{$this->owner}/{$this->repo}/pulls/{$this->pr_number}/reviews`.\n"
			. 'Do not attempt to read project files from disk.';
	}

	/**
	 * The terminal-only override appended to the system prompt when not posting to GitHub. Produces clean,
	 * terminal-readable output with no HTML (the marker and collapsible blocks are only meaningful on GitHub).
	 *
	 * @return  string
	 */
	private function terminal_only_override(): string {
		return <<<'PROMPT'
## OUTPUT MODE OVERRIDE — highest priority, overrides any instruction above
This review runs in a local terminal, not on GitHub. Do NOT post anything: do not run `gh pr review`, `gh pr comment`, `gh api` with a write method, or any other `gh`/`git` mutation. Ignore every instruction above that tells you to post a review comment. Read-only commands (`gh pr diff`, `gh pr view`, `gh api` GET requests, git reads) are allowed.

Print the review to stdout as clean, terminal-readable text:
- Do NOT emit any HTML — no `<!-- ... -->` markers, no `<details>`/`<summary>` tags.
- Do NOT hide anything in a collapsed section; show Nitpicks and the review-info footer as normal visible sections.
- Plain Markdown headings and bullet lists are fine. Start with a `## 🔱 Poseidon Review` heading. Keep it concise.
- Produce the review only once — do not print a draft followed by a final version.
PROMPT;
	}

	/**
	 * Prints a process's trimmed stderr as a comment, if any.
	 *
	 * @param   OutputInterface $output  The output object.
	 * @param   Process         $process The finished process.
	 *
	 * @return  void
	 */
	private function maybe_print_stderr( OutputInterface $output, Process $process ): void {
		$stderr = trim( $process->getErrorOutput() );
		if ( '' !== $stderr ) {
			$output->writeln( "<comment>$stderr</comment>" );
		}
	}

	// endregion
}
