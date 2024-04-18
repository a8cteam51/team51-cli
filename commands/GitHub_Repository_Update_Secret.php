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

/**
 * Create a new GitHub repository, optionally from a template.
 */
#[AsCommand( name: 'github:update-repository-secret' )]
final class GitHub_Repository_Update_Secret extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The slug of the repository.
	 *
	 * @var string|null
	 */
	protected ?string $repo_slug = null;

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Currently, can only be set to `all`, if at all.
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
	 * The secret name to update.
	 *
	 * @var string|null
	 */
	protected ?string $secret_name = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Updates GitHub repository secret on github.com in the organization specified with GITHUB_API_OWNER. and project name.' )
			->setHelp( 'This command allows you to update Github repository secret or create one if it is missing.' );

		$this->addArgument( 'repo-slug', InputArgument::OPTIONAL, 'The slug of the GitHub repository to operate on.' );
		$this->addOption( 'secret-name', null, InputArgument::REQUIRED, 'Secret name in all caps (e.g., GH_BOT_TOKEN)' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'repo-slug\' argument is optional or not. Accepts only \'all\' currently.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {

		$this->multiple = get_enum_input( $input, $output, 'multiple', array( 'all' ) );
		$input->setOption( 'multiple', $this->multiple );

		$this->secret_name = strtoupper( get_string_input( $input, $output, 'secret-name', fn() => $this->prompt_secret_name_input( $input, $output ) ) );
		$input->setOption( 'secret-name', $this->secret_name );

		// If processing a single repository, retrieve it from the input.
		if ( 'all' !== $this->multiple ) {
			$this->repo_slug = get_string_input( $input, $output, 'repo-slug', fn() => $this->prompt_slug_input( $input, $output ) );
			$repo_slug = $this->repo_slug;
			$input->setArgument( 'repo-slug', $repo_slug );

			if ( empty( $repo_slug ) ) {
				$output->writeln( '<error>Repository slug is required.</error>' );
				exit( 1 );
			}

			if ( \is_null( get_github_repository( $repo_slug ) ) ) {
				$output->writeln( '<error>Repository not found.</error>' );
				exit( 1 );
			}

			// Set the repo slug as the only repository to process.
			$this->repositories = array( $repo_slug );
		} else {
			$repositories_list = get_github_repositories(
				array(
					'per_page' => 100,
				)
			)->records;

			if ( \is_null( $repositories_list ) ) {
				$output->writeln( '<error>Failed to retrieve repositories.</error>' );
				exit( 1 );
			}

			$repository_names = array_map( function( $repository ) {
				return $repository->name;
			}, $repositories_list );

			$this->repositories = $repository_names;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( $this->multiple ) {
			case 'all':
				$question = new ConfirmationQuestion( "<question>Are you sure you want to update the $this->secret_name secret on <fg=red;options=bold>ALL</> repositories? [y/N]</question> " );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to update the $this->secret_name secret on {$this->repositories[0]}? [y/N]</question> " );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( print_r($this->repositories) );
		foreach ( $this->repositories as $index=>$repository ) {
			$secrets = get_github_repository_secrets( $repository );

			$output->writeln( "<comment>Processing repository $repository ($index/" . count( $this->repositories ) . ")</comment>", OutputInterface::VERBOSITY_VERBOSE );
			// Check if $secrets is an array before proceeding
			if ( ! \is_array( $secrets ) ) {
				$output->writeln( "<error>Error: Unable to retrieve secrets for $repository. Skipping...</error>", OutputInterface::VERBOSITY_VERBOSE );
				continue;
			}

			if ( ! \in_array( $this->secret_name, \array_column( $secrets, 'name' ), true ) ) {
				$output->writeln( "<comment>Secret $this->secret_name not found on $repository. Skipping...</comment>", OutputInterface::VERBOSITY_VERBOSE );
				continue;
			}

			$result = set_github_repository_secret( $repository, $this->secret_name );
			if ( $result ) {
				$output->writeln( "<fg=green;options=bold>Successfully updated secret $this->secret_name on $repository.</>" );
			} else {
				$output->writeln( "<error>Failed to update secret $this->secret_name on $repository.</error>" );
			}
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
	private function prompt_slug_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the slug of the repository to update the secrets from:</question> ' );
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
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Generate base64 encoded sealed box of passed secret.
	 *
	 * @throws \SodiumException
	 */
	private function seal_secret( string $secret_string, string $public_key ): string {
		return \base64_encode( \sodium_crypto_box_seal( $secret_string, $public_key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	// endregion
}
