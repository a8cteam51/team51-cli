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
use Symfony\Component\Console\Question\ChoiceQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Create a new GitHub repository, optionally from a template.
 */
#[AsCommand( name: 'github:create-repository' )]
final class GitHub_Repository_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The name of the repository to create.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * A URL with more information about the repository.
	 *
	 * @var string|null
	 */
	private ?string $homepage = null;

	/**
	 * A short, human-friendly description for this project.
	 *
	 * @var string|null
	 */
	private ?string $description = null;

	/**
	 * The type of repository to create aka the name of the template repository to use.
	 *
	 * @var string|null
	 */
	private ?string $type = null;

	/**
	 * The custom properties to set for the repository.
	 *
	 * @var array|null
	 */
	private ?array $custom_properties = null;

	/**
	 * Repo types that can be created.
	 *
	 * @var array
	 */
	private const REPO_TYPES = array(
		'project' => 'Full Project Repo',
		'plugin'  => 'Plugin Specific Repo',
		'issues'  => 'Issues Only Repo',
		'empty'   => 'Empty Repo',
	);

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new GitHub repository on github.com in the organization specified by the GITHUB_API_OWNER constant.' )
			->setHelp( 'This command allows you to create a new Github repository.' );

		$this->addArgument( 'name', InputArgument::REQUIRED, 'The name of the repository to create.' )
			->addOption( 'homepage', null, InputOption::VALUE_REQUIRED, 'A URL with more information about the repository.' )
			->addOption( 'description', null, InputOption::VALUE_REQUIRED, 'A short, human-friendly description for this project.' )
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'The name of the template repository to use, if any. One of either `project`, `plugin`, `issues`, or `empty`. Default empty repo.' );

		$this->addOption( 'custom-properties', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The custom properties to set for the repository.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->homepage    = $input->getOption( 'homepage' );
		$this->description = $input->getOption( 'description' );

		$this->type = get_enum_input( $input, 'type', array_keys( self::REPO_TYPES ), fn() => $this->prompt_type_input( $input, $output ) );
		$input->setOption( 'type', $this->type );

		$this->custom_properties = $this->process_custom_properties( $input );
		$input->setOption( 'custom-properties', $this->custom_properties );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$type     = $this->type ?? 'empty';
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create the $type repository $this->name? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$type = $this->type ?? 'empty';
		$output->writeln( "<fg=magenta;options=bold>Creating the $type repository $this->name.</>" );

		// Create the repository.
		$repository = create_github_repository( $this->name, $this->type, $this->homepage, $this->description, $this->custom_properties );
		if ( \is_null( $repository ) ) {
			$output->writeln( '<error>Failed to create the repository.</error>' );
			return Command::FAILURE;
		}

		// Set a topic on the repository for easier finding.
		if ( ! \is_null( $this->type ) ) {
			set_github_repository_topics( $repository->name, array( "team51-$this->type" ) );
		} else {
			set_github_repository_topics( $repository->name, array( 'team51-empty' ) );
		}

		$output->writeln( "<fg=green;options=bold>Repository $this->name created successfully.</>" );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a repository name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the repository to create:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a repository type.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_type_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Please select the type of repo:</question> ', self::REPO_TYPES, 'empty' );

		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_keys( self::REPO_TYPES ) );
		}
		
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Gets the custom properties from the input.
	 *
	 * @param   InputInterface $input The input object.
	 *
	 * @return  array
	 */
	private function process_custom_properties( InputInterface $input ): array {
		$custom_properties = array();

		foreach ( $input->getOption( 'custom-properties' ) as $property ) {
			$property_parts = explode( '=', $property, 2 );
			if ( 2 !== count( $property_parts ) ) {
				continue;
			}

			$custom_properties[ $property_parts[0] ] = $property_parts[1];
		}

		if ( ! isset( $custom_properties['human-title'] ) ) {
			$custom_properties['human-title'] = $this->name;
		}
		if ( ! isset( $custom_properties['php-globals-long-prefix'] ) ) {
			$custom_properties['php-globals-long-prefix'] = \str_replace( '-', '_', $this->name );
		}
		if ( ! isset( $custom_properties['php-globals-short-prefix'] ) ) {
			$custom_properties['php-globals-short-prefix'] = \str_replace( '-', '_', $this->name );
		}

		return $custom_properties;
	}

	// endregion
}
