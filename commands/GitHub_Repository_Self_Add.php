<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Adds the currently authenticated `gh` CLI user as a push collaborator on a repository.
 *
 * The username is sourced from `gh api user` so a contractor can only ever invite themselves.
 */
#[AsCommand( name: 'github:self-add-to-repository' )]
final class GitHub_Repository_Self_Add extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The GitHub repository to add the user to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $repository = null;

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
		$this->setDescription( 'Adds your authenticated GitHub user (per `gh` CLI) to a repository as a push collaborator.' )
			->setHelp( "This command resolves the currently authenticated GitHub username from the `gh` CLI and adds it to the given repository with `push` permission.\n\n`gh` must be installed and logged in (`gh auth login`). Adding any other user is intentionally not supported.\n\nRepositories holding privileged credentials (e.g. `poseidon-runner`) are locked and cannot be joined through this command." );

		$this->addArgument( 'repository', InputArgument::OPTIONAL, 'The slug of the GitHub repository to add yourself to.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->gh_username = $this->resolve_gh_username( $output );
		if ( null === $this->gh_username ) {
			exit( Command::FAILURE );
		}

		$this->repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
		if ( is_github_repository_collaborator_locked( $this->repository->name ) ) {
			$output->writeln( "<error>Adding collaborators to `{$this->repository->name}` via the CLI is disabled. Access to this repository is managed manually.</error>" );
			exit( Command::FAILURE );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion(
			"<question>Add `{$this->gh_username}` as a push collaborator on `{$this->repository->name}`? [y/N]</question> ",
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
		$output->writeln( "<fg=magenta;options=bold>Adding `{$this->gh_username}` to `{$this->repository->name}` as a push collaborator.</>" );

		$result = add_github_repository_collaborator( $this->repository->name, $this->gh_username, 'push' );
		if ( \is_null( $result ) ) {
			$output->writeln( "<error>Failed to add `{$this->gh_username}` to `{$this->repository->name}`.</error>" );
			return Command::FAILURE;
		}

		if ( \is_object( $result ) && 'already_collaborator' === ( $result->status ?? null ) ) {
			$output->writeln( "<fg=green;options=bold>`{$this->gh_username}` is already a collaborator on `{$this->repository->name}`.</>" );
			return Command::SUCCESS;
		}

		$invitation_url = $result->html_url ?? null;
		$output->writeln( "<fg=green;options=bold>Invitation sent to `{$this->gh_username}` for `{$this->repository->name}`.</>" );
		if ( null !== $invitation_url ) {
			$output->writeln( "<comment>Accept it here: $invitation_url</comment>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the currently authenticated GitHub username via the `gh` CLI.
	 * Returns null and prints a diagnostic on failure (gh missing or not logged in).
	 *
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function resolve_gh_username( OutputInterface $output ): ?string {
		$which = new Process( array( 'which', 'gh' ) );
		$which->run();
		if ( ! $which->isSuccessful() ) {
			$output->writeln( '<error>The `gh` CLI is not installed or not on PATH. Install it from https://cli.github.com and run `gh auth login`.</error>' );
			return null;
		}

		$process = new Process( array( 'gh', 'api', 'user', '--jq', '.login' ) );
		$process->run();
		if ( ! $process->isSuccessful() ) {
			$output->writeln( '<error>`gh` is installed but not authenticated. Run `gh auth login` and try again.</error>' );
			$stderr = trim( $process->getErrorOutput() );
			if ( '' !== $stderr ) {
				$output->writeln( "<comment>$stderr</comment>" );
			}
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
	 * Prompts the user for a repository slug.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the slug of the repository to add yourself to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				array_filter(
					array_column( get_github_repositories() ?? array(), 'name' ),
					static fn( string $name ) => ! is_github_repository_collaborator_locked( $name )
				)
			);
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
