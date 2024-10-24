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
 * Updates the value of a GitHub repository secret.
 */
#[AsCommand( name: 'github:update-repository-secret' )]
final class GitHub_Repository_Secret_Update extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple repositories or just a single given one.
	 * Can be one of 'all' or a comma-separated list of repository slugs.
	 *
	 * @var string|null
	 */
	protected ?string $multiple = null;

	/**
	 * The list of GitHub repositories to process.
	 *
	 * @var array|null
	 */
	protected ?array $repositories = null;

	/**
	 * The name of the secret to update.
	 *
	 * @var string|null
	 */
	protected ?string $secret_name = null;

	/**
	 * The new value of the secret.
	 *
	 * @var string|null
	 */
	protected ?string $secret_value = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Updates the value of a GitHub repository secret.' )
			->setHelp( 'This command allows you to update a GitHub repository secret. If the secret does not exist on the repository, it gets skipped.' );

		$this->addArgument( 'secret-name', InputArgument::REQUIRED, 'The name of the secret to update.' )
			->addArgument( 'repository', InputArgument::OPTIONAL, 'The slug of the GitHub repository to operate on.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'repository\' argument is optional or not. Accepts \'all\' or a comma-separated list of repository slugs.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->multiple = $input->getOption( 'multiple' );

		if ( null === $this->multiple ) {
			// If multiple is not set, treat it as a single repository operation
			$repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
			$input->setArgument( 'repository', $repository );
			$this->repositories = array( $repository );
		} elseif ( 'all' === $this->multiple ) {
			$this->repositories = get_github_repositories();
		} else {
			$this->repositories = $this->get_repositories_from_multiple_input( $input );
		}

		$this->secret_name = strtoupper( get_string_input( $input, 'secret-name', fn() => $this->prompt_secret_name_input( $input, $output ) ) );
		$input->setArgument( 'secret-name', $this->secret_name );

		$this->secret_value = 'GH_BOT_TOKEN' === $this->secret_name ? 'WPCOMSP_GITHUB_BOT_API_TOKEN' : $this->secret_name; // Legacy support.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = match ( true ) {
			'all' === $this->multiple => new ConfirmationQuestion( "<question>Are you sure you want to set the $this->secret_name secret on <fg=red;options=bold>ALL</> repositories? [y/N]</question> ", false ),
			null !== $this->multiple => new ConfirmationQuestion( "<question>Are you sure you want to set the $this->secret_name secret on <fg=red;options=bold>" . count( $this->repositories ) . ' selected</> repositories? [y/N]</question> ', false ),
			default => new ConfirmationQuestion( "<question>Are you sure you want to set the $this->secret_name secret on `{$this->repositories[0]->name}`? [y/N]</question> ", false ),
		};

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->repositories as $repository ) {
			$output->writeln( "<fg=magenta;options=bold>Setting the GitHub repository secret $this->secret_name on `$repository->name`.</>" );

			// Check that the secrets exist before proceeding.
			$secrets = get_github_repository_secrets( $repository->name );
			if ( ! \in_array( $this->secret_name, \array_column( $secrets ?? array(), 'name' ), true ) ) {
				$output->writeln( "<comment>Secret $this->secret_name not found on `$repository->name`. Skipping...</comment>" );
				continue;
			}

			$result = set_github_repository_secret( $repository->name, $this->secret_name, $this->secret_value );
			if ( \is_null( $result ) ) {
				$output->writeln( "<error>Failed to update secret $this->secret_name on `$repository->name`.</error>" );
				continue;
			}

			$output->writeln( "<fg=green;options=bold>Successfully updated secret $this->secret_name on `$repository->name`.</>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a repository slug.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the slug of the repository to update the secrets from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a secret name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_secret_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the secret to update:</question> ' );
		if ( 'all' !== $this->multiple && ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repository_secrets( $this->repositories[0]->name ) ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get repositories from the multiple input option.
	 *
	 * @param   InputInterface $input The input object.
	 *
	 * @return  array
	 */
	private function get_repositories_from_multiple_input( InputInterface $input ): array {
		$repo_slugs = array_map( 'trim', explode( ',', $this->multiple ) );
		return array_map( fn( $slug ) => get_github_repository_input( $input, fn() => $slug ), $repo_slugs );
	}

	// endregion
}
