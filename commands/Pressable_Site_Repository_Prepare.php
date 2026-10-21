<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Writes the Pressable allowlist block into a site repository's .deployignore, so its first Pressable deploy keeps the server's own files.
 */
#[AsCommand( name: 'pressable:prepare-site-repository' )]
final class Pressable_Site_Repository_Prepare extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The Pressable site the branch deploys to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The GitHub repository the site deploys from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The branch the site deploys.
	 *
	 * @var string|null
	 */
	private ?string $branch = null;

	/**
	 * Whether to only report whether the branch's file is current, without SSH or writes.
	 *
	 * @var bool|null
	 */
	private ?bool $check = null;

	/**
	 * Whether to drop tombstones whose server copies are gone.
	 *
	 * @var bool|null
	 */
	private ?bool $prune = null;

	/**
	 * Whether to commit straight to the branch instead of opening a pull request.
	 *
	 * @var bool|null
	 */
	private ?bool $push = null;

	/**
	 * The local folder the wp-content snapshot is saved in.
	 *
	 * @var string|null
	 */
	private ?string $snapshot_dir = null;

	/**
	 * The temporary clone of the branch.
	 *
	 * @var string|null
	 */
	private ?string $work_dir = null;

	/**
	 * The choices --check found no earlier decision for.
	 *
	 * @var string[]
	 */
	private array $undecided = array();

	/**
	 * The server paths that are symlinks, which Pressable manages.
	 *
	 * @var array<string,bool>
	 */
	private array $server_symlinks = array();

	/**
	 * Third-party markers in a component's code.
	 */
	private const THIRD_PARTY_PATTERN = 'EDD_SL_Plugin_Updater|PucFactory|Puc_v[0-9]|plugin-update-checker|WPMUDEV_Dashboard|Freemius|fs_dynamic_init';

	/**
	 * Components WordPress or Pressable ship, which a repository should never take over.
	 */
	private const STOCK_COMPONENTS = array( 'plugins/hello.php', 'plugins/akismet', 'plugins/jetpack', 'plugins/a8csp-atlantis' );

	/**
	 * The submodules an Atlantis module replaces, by repository name, with the module that must be on first.
	 */
	private const ATLANTIS_REPLACES = array(
		'colophon'                      => 'colophon',
		'team51-tracking'               => 'tracking',
		'wc-usage-tracking-auto-opt-in' => 'tracking',
		'plugin-autoupdate-filter'      => 'autoupdates',
	);

	/**
	 * The submodules to remove from the repository, because Atlantis now does their work on the site.
	 *
	 * @var string[]
	 */
	private array $removed_submodules = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Writes the Pressable allowlist block into a site repository\'s .deployignore.' )
			->setHelp( 'Pressable deletes every file on the site that the repository lacks, unless .deployignore protects it. This command clones the branch, works out which components the repository owns, and writes a block that deploys those and protects everything else on the server. It removes negations outside the block, because Pressable lets them beat every other rule. Before writing, it saves a snapshot of wp-content and shows what the first deploy would delete. Use --check to only report whether the branch\'s file is current.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site the branch deploys to.' )
			->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'The slug of the GitHub repository. Defaults to the one the site deploys from.' )
			->addOption( 'branch', null, InputOption::VALUE_REQUIRED, 'The branch the site deploys. Defaults to the site\'s Pressable or DeployHQ branch.' )
			->addOption( 'check', null, InputOption::VALUE_NONE, 'Only report whether the branch\'s .deployignore is current and passes the checks. Uses git alone.' )
			->addOption( 'prune', null, InputOption::VALUE_NONE, 'Drop tombstones whose server copies are gone.' )
			->addOption( 'push', null, InputOption::VALUE_NONE, 'Commit straight to the branch instead of opening a pull request.' )
			->addOption( 'snapshot-dir', null, InputOption::VALUE_REQUIRED, 'The local folder to save the wp-content snapshot in.', \getcwd() ?: '.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws  \InvalidArgumentException If --check is combined with an option that writes.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->undecided          = array();
		$this->server_symlinks    = array();
		$this->removed_submodules = array();
		$this->work_dir           = null;

		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->check        = get_bool_input( $input, 'check' );
		$this->prune        = get_bool_input( $input, 'prune' );
		$this->push         = get_bool_input( $input, 'push' );
		$this->snapshot_dir = (string) $input->getOption( 'snapshot-dir' );
		if ( $this->check && ( $this->prune || $this->push ) ) {
			throw new \InvalidArgumentException( '--check writes nothing, so it cannot be combined with --prune or --push.' );
		}

		$repository = maybe_get_string_input( $input, 'repository' );
		$branch     = maybe_get_string_input( $input, 'branch' );
		if ( \is_null( $repository ) || \is_null( $branch ) ) {
			[ $found_repository, $found_branch ] = $this->find_deployed_branch( $output );
			$repository                        ??= $found_repository;
			$branch                            ??= $found_branch;
		}
		if ( \is_null( $repository ) || \is_null( $branch ) ) {
			$output->writeln( '<error>Could not tell which repository and branch the site deploys. Pass --repository and --branch. Aborting!</error>' );
			exit( 1 );
		}

		$this->gh_repository = get_github_repository( $repository );
		if ( \is_null( $this->gh_repository ) ) {
			$output->writeln( "<error>Could not find the GitHub repository `$repository`. Aborting!</error>" );
			exit( 1 );
		}
		$this->branch = $branch;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( $this->check ) {
			return;
		}

		$question = new ConfirmationQuestion( "<question>Prepare the branch `$this->branch` of {$this->gh_repository->full_name} for Pressable deploys to {$this->site->displayName} (ID {$this->site->id})? Nothing is committed before you confirm the result. [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Preparing `$this->branch` of {$this->gh_repository->full_name} for {$this->site->displayName} (ID {$this->site->id}).</>" );

		$this->work_dir = \sys_get_temp_dir() . '/team51-prepare-' . \bin2hex( \random_bytes( 6 ) );
		try {
			return $this->prepare( $input, $output );
		} finally {
			$this->run_process( array( 'rm', '-rf', $this->work_dir ), \sys_get_temp_dir() );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Runs the preparation inside the temporary clone.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  int The command's exit code.
	 */
	private function prepare( InputInterface $input, OutputInterface $output ): int {
		$clone = $this->run_process( array( 'git', '-c', 'filter.lfs.smudge=', '-c', 'filter.lfs.process=', '-c', 'filter.lfs.required=false', 'clone', '--quiet', '--filter=blob:none', '--single-branch', '--branch', $this->branch, $this->gh_repository->ssh_url, $this->work_dir ), \sys_get_temp_dir() );
		if ( ! $clone->isSuccessful() ) {
			$output->writeln( "<error>Failed to clone `$this->branch` of {$this->gh_repository->full_name}.</error>" );
			return Command::FAILURE;
		}

		$tree = $this->read_tree();
		$tops = \array_unique( \array_map( static fn( string $path ): string => \explode( '/', $path )[0], \array_merge( $tree['files'], $tree['gitlinks'] ) ) );
		if ( ! \array_intersect( $tops, PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS ) ) {
			$output->writeln( '<error>The branch has no themes, plugins or mu-plugins folder at its root, so it is not deployed into wp-content as a whole. This command only handles that layout.</error>' );
			return Command::FAILURE;
		}

		$current = \str_replace( array( "\r\n", "\r" ), "\n", $this->git( array( 'show', 'HEAD:.deployignore' ), false ) ?? '' );
		$parts   = split_pressable_deployignore( $current );
		if ( $parts['malformed'] ) {
			$output->writeln( '<error>The branch\'s .deployignore has an unterminated or repeated Pressable allowlist block. Fix it by hand first.</error>' );
			return Command::FAILURE;
		}
		$stripped = strip_pressable_deployignore_negations( $parts['kept'] );
		foreach ( $stripped['removed'] as $line ) {
			$output->writeln( "<comment>Removing `$line`: outside the block, Pressable lets a negation beat every other rule.</comment>" );
		}

		$kept       = $stripped['kept'];
		$kept_rules = get_pressable_deployignore_intent_rules( $kept );
		foreach ( \array_diff( \array_map( 'trim', $kept ), \array_map( 'trim', \array_column( $kept_rules, 'text' ) ) ) as $line ) {
			if ( \in_array( \trim( $line, '/' ), PRESSABLE_DEPLOYIGNORE_LEGACY_LINES, true ) ) {
				$output->writeln( "<comment>Keeping the legacy line `$line`: the block overrides it, so the repository's own components still deploy.</comment>", OutputInterface::VERBOSITY_VERBOSE );
			}
		}
		$previous = $this->read_previous_decisions( $parts['block'] ?? array() );

		$listing = $this->check ? null : list_pressable_site_wp_content( (string) $this->site->id );
		$server  = $listing['files'] ?? null;
		foreach ( $listing['symlinks'] ?? array() as $symlink ) {
			$this->server_symlinks[ $symlink ] = true;
		}
		if ( ! $this->check && \is_null( $server ) ) {
			$output->writeln( '<error>Failed to list the site\'s files over SSH, which the tombstones and the deploy preview need.</error>' );
			return Command::FAILURE;
		}

		$this->decide_submodules( $input, $output, $tree['gitlinks'] );
		$tree['gitlinks'] = \array_values( \array_diff( $tree['gitlinks'], $this->removed_submodules ) );

		[ $root_entries, $declined ] = $this->decide_root_entries( $input, $output, $tree, $kept_rules, $previous );
		[ $children, $protected ]    = $this->decide_children( $input, $output, $tree, $kept_rules, $previous );
		$protected                   = \array_merge( $protected, $declined );
		$tombstones                  = \array_values( \array_unique( \array_merge( $this->decide_tombstones( $input, $output, $tree, $server, $previous ), \array_map( static fn( string $path ): string => "$path/", $this->removed_submodules ) ) ) );

		$block    = build_pressable_deployignore_block( $root_entries, $children, $protected, $tombstones );
		$contents = assemble_pressable_deployignore( $kept, $block );

		if ( ! \is_null( $server ) ) {
			$kept     = $this->decide_runtime_exclusions( $input, $output, $contents, $tree['files'], $server, $kept, $tombstones );
			$contents = assemble_pressable_deployignore( $kept, $block );
		}

		$final_rules = parse_pressable_deployignore( $contents );
		foreach ( find_pressable_deployignore_root_only_lines( $kept, $tree['files'] ) as $line => $paths ) {
			$paths = \array_values( \array_filter( $paths, static fn( string $path ): bool => is_path_deployed_by_pressable( $path, $final_rules ) ) );
			if ( $paths ) {
				$output->writeln( '<comment>`' . \trim( $line ) . '` matches only at the root on Pressable, so these deploy: ' . \implode( ', ', \array_slice( $paths, 0, 3 ) ) . ( \count( $paths ) > 3 ? ', ...' : '' ) . ' Add a `**/` form to withhold them.</comment>' );
			}
		}

		$problems = \array_merge( check_pressable_deployignore( $contents, $tree['files'], $tree['gitlinks'] ), $this->undecided );
		foreach ( $problems as $problem ) {
			$output->writeln( "<error>$problem</error>" );
		}

		if ( $this->check ) {
			$current_problems = '' === $current ? array( 'The branch has no .deployignore.' ) : check_pressable_deployignore( $current, $tree['files'], $tree['gitlinks'] );
			foreach ( $current_problems as $problem ) {
				$output->writeln( "<error>Current file: $problem</error>" );
			}
			$current_file = '' === $current ? '' : \rtrim( $current, "\r\n" ) . "\n";
			if ( $contents === $current_file && ! $current_problems && ! $problems ) {
				$output->writeln( "<fg=green;options=bold>The .deployignore on `$this->branch` is current and passes every check.</>" );
				return Command::SUCCESS;
			}
			$output->writeln( $contents === $current_file ? '<error>The file is current but fails the checks above.</error>' : '<error>The file differs from what the branch needs. Run without --check to update it.</error>' );
			$this->show_diff( $output, $current, $contents );
			return Command::FAILURE;
		}

		if ( $problems ) {
			$output->writeln( '<error>The generated file fails the checks above, so nothing was written.</error>' );
			return Command::FAILURE;
		}

		output_pressable_deploy_preview( $output, predict_pressable_deploy( $contents, $tree['files'], $server ) );
		$this->show_diff( $output, $current, $contents );

		if ( \rtrim( $current, "\r\n" ) . "\n" === $contents && ! $this->removed_submodules ) {
			$output->writeln( '<fg=green;options=bold>The branch\'s .deployignore is already current.</>' );
			return Command::SUCCESS;
		}

		$snapshot = save_pressable_site_wp_content_snapshot( (string) $this->site->id, $this->snapshot_dir, $output );
		if ( \is_null( $snapshot ) ) {
			$output->writeln( '<error>Failed to save a snapshot of wp-content, so nothing was written.</error>' );
			return Command::FAILURE;
		}
		$output->writeln( "<info>Saved a snapshot of wp-content, without uploads, caches or backup archives, to $snapshot.</info>" );

		$what     = $this->removed_submodules ? 'this .deployignore and the removal of ' . \implode( ', ', $this->removed_submodules ) : 'this .deployignore';
		$question = new ConfirmationQuestion( '<question>' . ( $this->push ? "Commit $what straight to `$this->branch`?" : "Open a pull request into `$this->branch` with $what?" ) . ' [y/N]</question> ', false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Nothing was written.</comment>' );
			return Command::SUCCESS;
		}

		return $this->commit( $output, $contents );
	}

	/**
	 * Finds the repository and branch the site deploys, from its Pressable git connection or else its DeployHQ server.
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  array{0: string|null, 1: string|null}
	 */
	private function find_deployed_branch( OutputInterface $output ): array {
		$git = get_pressable_site_git_config( $this->site->id );
		if ( ! \is_null( $git ) && ! empty( $git->repository ) ) {
			$destination = \trim( (string) ( $git->destinationPath ?? '' ), '/' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$subdir      = \trim( (string) ( $git->repositorySubdirectory ?? '' ), '/' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'wp-content' !== $destination || '' !== $subdir ) {
				$output->writeln( "<error>The site deploys `$subdir` into `$destination`; this command only handles the repository root deployed into wp-content. Aborting!</error>" );
				exit( 1 );
			}
			$parsed = parse_github_remote_repository_url( (string) $git->repository );
			return array( $parsed?->repo, $git->branch ?? null );
		}

		$deployhq = get_pressable_site_deployhq_config( $this->site->id );
		if ( \is_null( $deployhq ) || empty( $deployhq->project->permalink ) ) {
			return array( null, null );
		}
		return array( get_github_repository_from_deployhq_project( $deployhq->project->permalink )?->name, $deployhq->server->branch ?? null );
	}

	/**
	 * Runs git in the clone.
	 *
	 * @param   string[] $args          The arguments.
	 * @param   bool     $exit_on_error Whether a failure throws.
	 *
	 * @throws  \RuntimeException If git fails and $exit_on_error is set.
	 *
	 * @return  string|null The output, or null when git failed.
	 */
	private function git( array $args, bool $exit_on_error = true ): ?string {
		$process = $this->run_process( \array_merge( array( 'git', '-C', $this->work_dir ), $args ), $this->work_dir );
		if ( ! $process->isSuccessful() && $exit_on_error ) {
			throw new \RuntimeException( 'git ' . \implode( ' ', $args ) . ' failed: ' . \trim( $process->getErrorOutput() ) );
		}

		return $process->isSuccessful() ? $process->getOutput() : null;
	}

	/**
	 * Runs a process without treating a non-zero exit as an error, since grep and diff exit 1 by design.
	 *
	 * @param   string[] $command           The command.
	 * @param   string   $working_directory The working directory.
	 *
	 * @return  Process
	 */
	private function run_process( array $command, string $working_directory ): Process {
		$process = new Process( $command, $working_directory, null, null, 600 );
		$process->run();

		return $process;
	}

	/**
	 * Reads the branch's tree.
	 *
	 * @return  array{files: string[], gitlinks: string[]}
	 */
	private function read_tree(): array {
		$tree = array(
			'files'    => array(),
			'gitlinks' => array(),
		);
		foreach ( \explode( "\0", (string) $this->git( array( 'ls-tree', '-r', '-z', '--full-tree', 'HEAD' ) ) ) as $entry ) {
			if ( ! \preg_match( '/^\d+ (blob|commit) [0-9a-f]+\t(.+)$/s', $entry, $matches ) ) {
				continue;
			}
			$tree[ 'blob' === $matches[1] ? 'files' : 'gitlinks' ][] = $matches[2];
		}

		return $tree;
	}

	/**
	 * Reads what an earlier block decided, so a rerun keeps the operator's choices.
	 *
	 * @param   string[] $block The earlier block's lines, markers excluded.
	 *
	 * @return  array{included: string[], protected: string[], tombstones: string[]}
	 */
	private function read_previous_decisions( array $block ): array {
		$decisions = array(
			'included'   => array(),
			'protected'  => array(),
			'tombstones' => get_pressable_deployignore_tombstones( $block ),
		);
		foreach ( $block as $line ) {
			if ( \str_starts_with( $line, '# Removed from the repository' ) ) {
				break;
			}
			if ( \str_starts_with( $line, '# protected: ' ) ) {
				$decisions['protected'][] = \substr( $line, \strlen( '# protected: ' ) );
			} elseif ( \str_starts_with( $line, '!/' ) ) {
				$decisions['included'][] = \rtrim( unescape_pressable_deployignore_pattern( \substr( $line, 2 ) ), '/' );
			}
		}

		return $decisions;
	}

	/**
	 * Decides which root files and folders other than the content folders deploy.
	 *
	 * @param   InputInterface  $input      The input object.
	 * @param   OutputInterface $output     The output object.
	 * @param   array           $tree       The branch's tree.
	 * @param   array[]         $kept_rules The rules outside the block.
	 * @param   array           $previous   The earlier block's decisions.
	 *
	 * @return  array{0: string[], 1: string[]} The entries to re-include, folders ending with a slash, and the runtime-loaded ones declined.
	 */
	private function decide_root_entries( InputInterface $input, OutputInterface $output, array $tree, array $kept_rules, array $previous ): array {
		$entries = array();
		foreach ( $tree['files'] as $path ) {
			$segments                  = \explode( '/', $path );
			$entries[ $segments[0] ][] = $path;
		}

		$referenced = $this->find_runtime_references();
		$included   = array();
		$declined   = array();
		foreach ( $entries as $name => $paths ) {
			if ( \in_array( $name, PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS, true ) || \str_starts_with( $name, '.' ) ) {
				continue;
			}
			$is_dir = ! ( 1 === \count( $paths ) && $paths[0] === $name );
			if ( \in_array( $name, PRESSABLE_DEPLOYIGNORE_SERVER_OWNED, true ) ) {
				$output->writeln( "<comment>`$name` is tracked, but the server owns it, so it stays protected and never deploys.</comment>" );
				continue;
			}
			if ( ! \array_filter( $paths, static fn( string $path ): bool => is_path_deployed_by_pressable( $path, $kept_rules ) ) ) {
				continue;
			}

			$is_referenced = \in_array( $name, $referenced, true );
			$was_declined  = \in_array( $name, $previous['protected'], true );
			$default       = \in_array( $name, $previous['included'], true ) || ( ! $this->check && $is_referenced && ! $was_declined );
			if ( $this->check ) {
				$deploy = \in_array( $name, $previous['included'], true );
				if ( ! $deploy && $is_referenced && ! $was_declined ) {
					$this->undecided[] = "Runtime PHP loads the root entry `$name`, which does not deploy. Decide on it with a full run.";
				}
			} else {
				$hint     = $is_referenced ? ' Runtime PHP refers to it.' : '';
				$question = new ConfirmationQuestion( '<question>Deploy the root ' . ( $is_dir ? 'folder' : 'file' ) . " `$name` (" . \count( $paths ) . " tracked files)? Not deploying freezes the server's copy.$hint [" . ( $default ? 'Y/n' : 'y/N' ) . ']</question> ', $default );
				$deploy   = true === $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
			if ( $deploy ) {
				$included[] = $name . ( $is_dir ? '/' : '' );
			} elseif ( $is_referenced && ( ! $this->check || $was_declined ) ) {
				$declined[] = $name;
			}
		}

		return array( $included, $declined );
	}

	/**
	 * Decides which submodules to remove, since Pressable never checks them out and Atlantis replaces some.
	 *
	 * @param   InputInterface  $input    The input object.
	 * @param   OutputInterface $output   The output object.
	 * @param   string[]        $gitlinks The submodule paths in the branch.
	 *
	 * @return  void
	 */
	private function decide_submodules( InputInterface $input, OutputInterface $output, array $gitlinks ): void {
		$entries = array();
		foreach ( \explode( "\n", (string) $this->git( array( 'config', '--file', '.gitmodules', '--get-regexp', '^submodule\\..*\\.(path|url)$' ), false ) ) as $line ) {
			if ( \preg_match( '/^submodule\.(.+)\.(path|url) (.+)$/', \trim( $line ), $matches ) ) {
				$entries[ $matches[1] ][ $matches[2] ] = $matches[3];
			}
		}
		$urls = array();
		foreach ( $entries as $entry ) {
			$urls[ \trim( (string) ( $entry['path'] ?? '' ), '/' ) ] = (string) ( $entry['url'] ?? '' );
		}

		$status = null;
		foreach ( $gitlinks as $path ) {
			$name   = \preg_match( '#github\.com[:/]a8cteam51/([^/]+?)(?:\.git)?/?$#i', $urls[ $path ] ?? '', $matches ) ? \strtolower( $matches[1] ) : null;
			$module = \is_null( $name ) ? null : ( self::ATLANTIS_REPLACES[ $name ] ?? null );
			if ( \is_null( $module ) ) {
				$output->writeln( "<comment>`$path` is a submodule. Pressable does not check out submodules, so it stays protected and its server copy never updates.</comment>" );
				continue;
			}
			if ( $this->check ) {
				$output->writeln( "<comment>`$path` is a submodule that Atlantis's $module module replaces. A full run removes it once that module is on for the site.</comment>" );
				continue;
			}

			$status ??= $this->read_atlantis_status();
			$missing = $this->find_missing_atlantis_requirements( $status, $module );
			if ( $missing ) {
				$output->writeln( "<comment>`$path` is a submodule that Atlantis's $module module replaces, but it stays for now: $missing. It stays protected, and its server copy keeps running.</comment>" );
				continue;
			}

			$question = new ConfirmationQuestion( "<question>Atlantis's $module module is on for the site, so it does `$path`'s work. Remove the submodule, and let the first deploy delete the server's copy? Every site deploying `$this->branch` needs that module on too. [Y/n]</question> ", true );
			if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$this->removed_submodules[] = $path;
			}
		}
	}

	/**
	 * Reads the site's Atlantis status through its WordPress.com ID, which the status endpoint needs.
	 *
	 * @return  \stdClass|string The status, or why it could not be read.
	 */
	private function read_atlantis_status(): \stdClass|string {
		$wpcom = get_wpcom_site( (string) \parse_url( 'https://' . \preg_replace( '#^https?://#', '', (string) $this->site->url ), PHP_URL_HOST ) );
		if ( \is_null( $wpcom ) ) {
			$domain = get_pressable_site_primary_domain( (string) $this->site->id )->domainName ?? ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$wpcom  = '' === $domain ? null : get_wpcom_site( $domain );
		}
		if ( empty( $wpcom->ID ) ) {
			return 'the site could not be found on WordPress.com through Jetpack';
		}

		$errors   = array();
		$statuses = get_wpcom_sites_atlantis_status_batch( array( (int) $wpcom->ID ), $errors );
		$status   = \is_array( $statuses ) ? \reset( $statuses ) : false;
		if ( \is_object( $status ) ) {
			return $status;
		}

		return \str_contains( (string) \json_encode( $errors ), 'rest_no_route' ) ? 'Atlantis is not installed on the site' : 'the site\'s Atlantis status could not be read';
	}

	/**
	 * Says what is missing before an Atlantis module can replace a submodule on the site.
	 *
	 * @param   \stdClass|string $status The site's Atlantis status, or why it could not be read.
	 * @param   string           $module The module's key.
	 *
	 * @return  string|null What is missing, or null when nothing is.
	 */
	private function find_missing_atlantis_requirements( \stdClass|string $status, string $module ): ?string {
		if ( \is_string( $status ) ) {
			return $status;
		}
		if ( true !== ( $status->plugin->managed ?? null ) ) {
			return 'the site is not marked as managed in Atlantis (`team51 atlantis:mark-managed`)';
		}
		if ( true !== ( $status->modules->$module->enabled ?? null ) ) {
			return "Atlantis's $module module is not on";
		}
		if ( 'autoupdates' === $module && false === ( $status->modules->$module->settings_url_configured ?? null ) ) {
			return "Atlantis's autoupdates module has no settings address (`team51 atlantis:mark-managed`)";
		}

		return null;
	}

	/**
	 * Finds root entries that the tracked runtime PHP loads.
	 *
	 * @return  string[]
	 */
	private function find_runtime_references(): array {
		$pattern = "(WP_CONTENT_DIR[[:space:]]*\\.[[:space:]]*'/|WPMU_PLUGIN_DIR[[:space:]]*\\.[[:space:]]*'/\\.\\./|dirname\\([[:space:]]*__DIR__[^)]*\\)[[:space:]]*\\.[[:space:]]*'/)[A-Za-z0-9._-]+";
		$found   = $this->git( array( 'grep', '-h', '-o', '-E', $pattern, '--', '*.php' ), false ) ?? '';
		\preg_match_all( '#/([A-Za-z0-9._-]+)$#m', $found, $matches );

		return \array_values( \array_unique( $matches[1] ) );
	}

	/**
	 * Decides which children of the content folders deploy, and which tracked ones stay protected.
	 *
	 * @param   InputInterface  $input      The input object.
	 * @param   OutputInterface $output     The output object.
	 * @param   array           $tree       The branch's tree.
	 * @param   array[]         $kept_rules The rules outside the block.
	 * @param   array           $previous   The earlier block's decisions.
	 *
	 * @return  array{0: array<string,string[]>, 1: string[]} The children to re-include per folder, and the protected ones.
	 */
	private function decide_children( InputInterface $input, OutputInterface $output, array $tree, array $kept_rules, array $previous ): array {
		$children = array();
		foreach ( $tree['files'] as $path ) {
			$segments = \explode( '/', $path );
			if ( \in_array( $segments[0], PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS, true ) && \count( $segments ) > 1 ) {
				$children[ "{$segments[0]}/{$segments[1]}" ][] = $path;
			}
		}
		$included  = array();
		$protected = array();
		foreach ( $children as $child => $paths ) {
			[ $dir, $name ] = \explode( '/', $child, 2 );
			$is_dir         = ! ( 1 === \count( $paths ) && $paths[0] === $child );
			if ( \str_starts_with( $name, '.' ) || 'index.php' === $name || \in_array( $child, $tree['gitlinks'], true ) ) {
				continue;
			}
			if ( ! \array_filter( $paths, static fn( string $path ): bool => is_path_deployed_by_pressable( $path, $kept_rules ) ) ) {
				continue;
			}

			$signal = $this->find_third_party_signal( $child, $is_dir );
			if ( \is_null( $signal ) && ! \in_array( $child, $previous['protected'], true ) ) {
				$included[ $dir ][] = $name . ( $is_dir ? '/' : '' );
				continue;
			}

			$deploy = \in_array( $child, $previous['included'], true );
			if ( $this->check && ! $deploy && ! \in_array( $child, $previous['protected'], true ) ) {
				$this->undecided[] = "`$child` looks like third-party code ($signal) and has no decision yet. Decide on it with a full run.";
			}
			if ( ! $this->check ) {
				$reason   = $signal ?? 'An earlier run kept it protected.';
				$question = new ConfirmationQuestion( "<question>`$child` looks like third-party code ($reason) Deploy it from the repository instead of keeping the server's copy? [" . ( $deploy ? 'Y/n' : 'y/N' ) . ']</question> ', $deploy );
				$deploy   = true === $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
			if ( $deploy ) {
				$included[ $dir ][] = $name . ( $is_dir ? '/' : '' );
			} else {
				$protected[] = $child;
			}
		}

		return array( $included, $protected );
	}

	/**
	 * Looks for signs that a component is third-party code the server should keep managing.
	 *
	 * @param   string $child  The component, like `plugins/x`.
	 * @param   bool   $is_dir Whether it is a folder.
	 *
	 * @return  string|null The sign, or null when there is none.
	 */
	private function find_third_party_signal( string $child, bool $is_dir ): ?string {
		if ( \in_array( $child, self::STOCK_COMPONENTS, true ) || \preg_match( '#^themes/twenty[a-z-]+$#', $child ) ) {
			return 'WordPress or Pressable ships it.';
		}
		if ( ! $is_dir ) {
			return null;
		}
		if ( ! \is_null( $this->git( array( 'grep', '-q', '-i', '-E', '^[[:space:]*#@]*Update URI:[[:space:]]*(https?://|[a-z0-9-]+\\.[a-z])', '--', "$child/*.php", "$child/style.css" ), false ) ) ) {
			return 'its header names an update server.';
		}
		if ( ! \is_null( $this->git( array( 'grep', '-q', '-E', self::THIRD_PARTY_PATTERN, '--', $child ), false ) ) ) {
			return 'it bundles a commercial updater.';
		}

		return null;
	}

	/**
	 * Decides which removed components the deploy deletes from the server.
	 *
	 * @param   InputInterface  $input    The input object.
	 * @param   OutputInterface $output   The output object.
	 * @param   array           $tree     The branch's tree.
	 * @param   string[]|null   $server   The server's files, or null in --check.
	 * @param   array           $previous The earlier block's decisions.
	 *
	 * @return  string[] The tombstones, folders ending with a slash.
	 */
	private function decide_tombstones( InputInterface $input, OutputInterface $output, array $tree, ?array $server, array $previous ): array {
		$present = array();
		foreach ( \array_merge( $tree['files'], $tree['gitlinks'] ) as $path ) {
			$segments = \explode( '/', $path );
			if ( \count( $segments ) > 1 ) {
				$present[ "{$segments[0]}/{$segments[1]}" ] = true;
			}
		}
		$on_server = array();
		foreach ( $server ?? array() as $path ) {
			$segments = \explode( '/', $path );
			if ( \count( $segments ) > 1 ) {
				$on_server[ "{$segments[0]}/{$segments[1]}" ] = \count( $segments ) > 2;
			}
		}

		$tombstones = array();
		foreach ( $previous['tombstones'] as $tombstone ) {
			$child = \rtrim( $tombstone, '/' );
			if ( isset( $present[ $child ] ) || ( $this->prune && ! isset( $on_server[ $child ] ) ) ) {
				continue;
			}
			$tombstones[] = $tombstone;
		}
		if ( $this->check ) {
			return $tombstones;
		}

		$deleted = (string) $this->git( array( 'log', '--diff-filter=D', '--name-only', '--format=', 'HEAD', '--', ...PRESSABLE_DEPLOYIGNORE_CONTENT_DIRS ), false );
		$removed = array();
		foreach ( \array_filter( \explode( "\n", $deleted ) ) as $path ) {
			$segments = \explode( '/', $path );
			if ( \count( $segments ) > 1 && ! \str_starts_with( $segments[1], '.' ) && 'index.php' !== $segments[1] ) {
				$removed[ "{$segments[0]}/{$segments[1]}" ] = true;
			}
		}
		foreach ( \array_keys( $removed ) as $child ) {
			$listed = \in_array( $child, \array_map( static fn( string $t ): string => \rtrim( $t, '/' ), $tombstones ), true );
			if ( isset( $present[ $child ] ) || ! isset( $on_server[ $child ] ) || $listed || isset( $this->server_symlinks[ $child ] ) ) {
				continue;
			}
			$question = new ConfirmationQuestion( "<question>`$child` was removed from the repository but is still on the server. Delete the server copy on the next deploy? Otherwise it stays, protected. [y/N]</question> ", false );
			if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$tombstones[] = $child . ( $on_server[ $child ] ? '/' : '' );
			}
		}

		return $tombstones;
	}

	/**
	 * Lets the operator protect server files under re-included components that the first deploy would delete.
	 *
	 * @param   InputInterface  $input    The input object.
	 * @param   OutputInterface $output   The output object.
	 * @param   string          $contents The generated file.
	 * @param   string[]        $tracked  The tracked files.
	 * @param   string[]        $server   The server's files.
	 * @param   string[]        $kept       The lines outside the block.
	 * @param   string[]        $tombstones The paths the deploy deletes on purpose, folders ending with a slash.
	 *
	 * @return  string[] The lines outside the block, with any runtime exclusions added.
	 */
	private function decide_runtime_exclusions( InputInterface $input, OutputInterface $output, string $contents, array $tracked, array $server, array $kept, array $tombstones ): array {
		$tracked_dirs = array();
		foreach ( $tracked as $path ) {
			$segments = \explode( '/', $path );
			for ( $depth = \count( $segments ) - 1; $depth > 0; $depth-- ) {
				$tracked_dirs[ \implode( '/', \array_slice( $segments, 0, $depth ) ) ] = true;
			}
		}

		$groups = array();
		foreach ( predict_pressable_deploy( $contents, $tracked, $server )['deleted'] as $path ) {
			foreach ( $tombstones as $tombstone ) {
				if ( $path === $tombstone || \str_starts_with( $path, \rtrim( $tombstone, '/' ) . '/' ) ) {
					continue 2;
				}
			}
			$segments = \explode( '/', $path );
			$count    = \count( $segments );
			$key      = $path;
			for ( $depth = 3; $depth < $count; $depth++ ) {
				$dir = \implode( '/', \array_slice( $segments, 0, $depth ) );
				if ( ! isset( $tracked_dirs[ $dir ] ) ) {
					$key = "$dir/";
					break;
				}
			}
			$groups[ $key ][] = $path;
		}

		$exclusions = array();
		foreach ( $groups as $group => $paths ) {
			$question = new ChoiceQuestion(
				"<question>The first deploy deletes `$group` from the server (" . \count( $paths ) . ' files). Are these stale copies the deploy should remove, or files the site writes at runtime?</question>',
				array(
					'stale'   => 'Stale: let the deploy delete them',
					'runtime' => 'Runtime: protect them with an exclusion above the block',
				),
				'runtime'
			);
			if ( 'runtime' === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$exclusions[] = '/' . \implode( '/', \array_map( 'escape_pressable_deployignore_name', \explode( '/', \rtrim( $group, '/' ) ) ) ) . ( \str_ends_with( $group, '/' ) ? '/' : '' );
			}
		}
		if ( ! $exclusions ) {
			return $kept;
		}

		while ( $kept && '' === \trim( \end( $kept ) ) ) {
			\array_pop( $kept );
		}
		return \array_merge( $kept, array( '', '# Written by the site at runtime; the deploy must not delete it.' ), $exclusions );
	}

	/**
	 * Shows the change to the .deployignore file.
	 *
	 * @param   OutputInterface $output   The output object.
	 * @param   string          $current  The branch's file.
	 * @param   string          $contents The generated file.
	 *
	 * @return  void
	 */
	private function show_diff( OutputInterface $output, string $current, string $contents ): void {
		$before = "$this->work_dir/.deployignore.before";
		$after  = "$this->work_dir/.deployignore.after";
		\file_put_contents( $before, $current );
		\file_put_contents( $after, $contents );

		$diff = $this->run_process( array( 'git', 'diff', '--no-index', '--no-color', '.deployignore.before', '.deployignore.after' ), $this->work_dir );
		$output->writeln( $diff->getOutput() );
	}

	/**
	 * Commits the generated file, to a new branch with a pull request unless --push is given.
	 *
	 * @param   OutputInterface $output   The output object.
	 * @param   string          $contents The generated file.
	 *
	 * @return  int The command's exit code.
	 */
	private function commit( OutputInterface $output, string $contents ): int {
		foreach ( array( '.deployignore.before', '.deployignore.after' ) as $scratch ) {
			if ( \is_file( "$this->work_dir/$scratch" ) ) {
				\unlink( "$this->work_dir/$scratch" );
			}
		}
		$target = $this->push ? $this->branch : 'update/pressable-deployignore' . ( \in_array( $this->branch, array( 'trunk', 'main' ), true ) ? '' : "-$this->branch" );
		if ( ! $this->push ) {
			if ( '' !== \trim( (string) $this->git( array( 'ls-remote', '--heads', 'origin', $target ), false ) ) ) {
				$output->writeln( "<error>The branch `$target` already exists on GitHub, most likely from an earlier pull request. Merge or close it, delete the branch, then run this again.</error>" );
				return Command::FAILURE;
			}
			$this->git( array( 'checkout', '--quiet', '-b', $target ) );
		}

		\file_put_contents( "$this->work_dir/.deployignore", $contents );
		try {
			foreach ( $this->removed_submodules as $path ) {
				$this->git( array( 'rm', '--quiet', $path ) );
			}
			if ( $this->removed_submodules && \is_file( "$this->work_dir/.gitmodules" ) && '' === \trim( (string) \file_get_contents( "$this->work_dir/.gitmodules" ) ) ) {
				$this->git( array( 'rm', '--quiet', '--force', '.gitmodules' ) );
			}
			$this->git( array( 'add', '.deployignore' ) );
			$this->git( array( 'commit', '--quiet', '-m', 'Protect the server\'s own files from Pressable deploys', '-m', 'Pressable deletes every file on the site that the repository lacks unless .deployignore protects it. The allowlist block deploys the components this repository owns and keeps everything else on the server.' . $this->describe_removed_submodules() ) );
		} catch ( \RuntimeException $exception ) {
			$output->writeln( "<error>{$exception->getMessage()}</error>" );
			return Command::FAILURE;
		}

		if ( \is_null( $this->git( array( 'push', '--quiet', 'origin', "HEAD:refs/heads/$target" ), false ) ) ) {
			$output->writeln( "<error>Failed to push `$target`.</error>" );
			return Command::FAILURE;
		}
		if ( $this->push ) {
			$output->writeln( "<fg=green;options=bold>Committed the .deployignore to `$this->branch`.</>" );
			return Command::SUCCESS;
		}

		$pr = $this->run_process(
			array( 'gh', 'pr', 'create', '--repo', $this->gh_repository->full_name, '--base', $this->branch, '--head', $target, '--title', 'Protect the server\'s own files from Pressable deploys', '--body', "Generated by `team51 pressable:prepare-site-repository {$this->site->id}`.\n\nPressable deletes every file on the site that the repository lacks unless `.deployignore` protects it. The allowlist block at the end deploys the components this repository owns and keeps everything else on the server. Negations outside the block are removed, because Pressable lets them beat every other rule." . $this->describe_removed_submodules() ),
			$this->work_dir
		);
		if ( ! $pr->isSuccessful() ) {
			$output->writeln( "<error>Pushed `$target`, but failed to open the pull request: " . \trim( $pr->getErrorOutput() ) . '</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>Opened ' . \trim( $pr->getOutput() ) . '</>' );
		return Command::SUCCESS;
	}

	/**
	 * Describes the removed submodules for the commit message and pull request, or nothing when there are none.
	 *
	 * @return  string
	 */
	private function describe_removed_submodules(): string {
		if ( ! $this->removed_submodules ) {
			return '';
		}

		$one = 1 === \count( $this->removed_submodules );
		return "\n\nRemoves the submodule" . ( $one ? ' ' : 's ' ) . \implode( ', ', $this->removed_submodules ) . ', whose work Atlantis now does on the site. Pressable never checks submodules out, and the first deploy deletes ' . ( $one ? 'its server copy.' : 'their server copies.' );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or Pressable site ID the branch deploys to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
