<?php

namespace WPCOMSpecialProjects\CLI\Command;

use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
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
	 * The name of the theme to use for the no-code repository.
	 *
	 * @var string|null
	 */
	private ?string $no_code_theme = null;

	/**
	 * The custom properties to set for the repository.
	 *
	 * @var array|null
	 */
	private ?array $custom_properties = null;

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
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'The name of the template repository to use, if any. One of either `project`, `no-code-project`, `plugin`, or `issues`. Default empty repo.' )
			->addOption( 'no-code-theme', null, InputOption::VALUE_OPTIONAL, 'The name of the no-code theme to use for the repository.' );

		$this->addOption( 'custom-properties', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The custom properties to set for the repository.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->homepage      = $input->getOption( 'homepage' );
		$this->description   = $input->getOption( 'description' );
		$this->no_code_theme = $input->getOption( 'no-code-theme' );

		$this->type = get_enum_input( $input, 'type', array( 'project', 'no-code-project', 'plugin', 'issues' ), fn() => $this->prompt_type_input( $input, $output ) );
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

		if ( 'no-code-project' === $this->type ) {
			$this->setup_no_code_theme( $input, $output );
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

		// Add theme files for no-code-project repositories
		if ( 'no-code-project' === $this->type && ! empty( $this->no_code_theme ) ) {
			$result = $this->add_no_code_theme_files( $output, $repository );
			if ( false === $result ) {
				$output->writeln( '<error>Failed to add theme files.</error>' );
				return Command::FAILURE;
			}
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
		$question = new Question( '<question>Please enter the type of repository to create or press enter for an empty repo:</question> ' );
		// TODO: Show choices in a list.
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array( 'project', 'no-code-project', 'plugin', 'issues' ) );
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
		if ( ! isset( $custom_properties['parent-theme'] ) ) {
			$custom_properties['parent-theme'] = $this->no_code_theme;
		}
		if ( ! isset( $custom_properties['php-globals-long-prefix'] ) ) {
			$custom_properties['php-globals-long-prefix'] = \str_replace( '-', '_', $this->name );
		}
		if ( ! isset( $custom_properties['php-globals-short-prefix'] ) ) {
			$custom_properties['php-globals-short-prefix'] = \str_replace( '-', '_', $this->name );
		}

		return $custom_properties;
	}

	/**
	 * Sets up the no-code theme by cloning/pulling the a8c-themes repo and prompting for theme selection.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @return void
	 */
	private function setup_no_code_theme( InputInterface $input, OutputInterface $output ): void {
		$folders = get_a8c_theme_choices( $output );
		if ( empty( $folders ) ) {
			$output->writeln( '<error>Failed to fetch a8c themes.</error>' );
			return;
		}

		if ( ! empty( $this->no_code_theme ) ) {
			if ( ! in_array( $this->no_code_theme, $folders, true ) ) {
				$output->writeln( '<error>The selected no-code theme is not available.</error>' );
				$output->writeln( '<error>Please select a different theme or press enter to skip.</error>' );
				$this->no_code_theme = null;
			} else {
				return;
			}
		}

		$question            = new ChoiceQuestion(
			'<question>Please select the no-code theme to use:</question> ',
			$folders,
			0
		);
		$this->no_code_theme = $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Adds the no-code theme files to the repository.
	 *
	 * @param   OutputInterface $output     The output interface.
	 * @param   stdClass        $repository The repository object.
	 *
	 * @return  boolean
	 */
	private function add_no_code_theme_files( OutputInterface $output, stdClass $repository ): bool {
		$output->writeln( "<comment>Adding theme files from {$this->no_code_theme}...</comment>" );

		// Create a temporary directory for the main repository
		$temp_dir = sys_get_temp_dir() . '/' . uniqid( 'github-repo-' );
		mkdir( $temp_dir );
		if ( ! is_dir( $temp_dir ) ) {
			$output->writeln( '<error>Failed to create temporary directory.</error>' );
			return false;
		}

		// Clone the new repository
		$clone_command = sprintf(
			'git clone %s %s',
			$repository->ssh_url,
			$temp_dir
		);
		exec( $clone_command, $exec_output, $return_code );
		if ( 0 !== $return_code ) {
			$output->writeln( '<error>Failed to clone the new repository.</error>' );
			$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
			return false;
		}

		// Check for "Template:" entry in the chosen theme's style.css
		$a8c_themes_dir    = dirname( TEAM51_CLI_ROOT_DIR ) . '/a8c-themes';
		$theme_path        = $a8c_themes_dir . '/' . $this->no_code_theme;
		$parent_theme_slug = get_theme_template_entry( $theme_path );

		if ( $parent_theme_slug ) {
			$output->writeln( "<comment>The theme {$this->no_code_theme} is a child theme. Using {$parent_theme_slug} as the parent theme.</comment>" );

			// Rename the theme folder
			$custom_theme_name = $this->no_code_theme . '-custom';
			$custom_theme_path = $temp_dir . '/themes/' . $custom_theme_name;
			mkdir( $custom_theme_path, 0777, true );

			// Copy theme files to the new custom folder
			$copy_command = sprintf(
				'cp -r %s/* %s',
				$theme_path,
				$custom_theme_path
			);
			exec( $copy_command, $exec_output, $return_code );
			if ( 0 !== $return_code ) {
				$output->writeln( '<error>Failed to copy theme files.</error>' );
				$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
				return false;
			}

			// Modify the theme name in style.css after copying
			$style_css_path = $custom_theme_path . '/style.css';
			$style_contents = file_get_contents( $style_css_path );
			$style_contents = preg_replace( '/Theme Name:\s*(.+)/', 'Theme Name: $1 Custom', $style_contents );
			file_put_contents( $style_css_path, $style_contents );

			// Check if the parent theme exists
			$available_themes = get_a8c_theme_choices( $output );
			if ( in_array( $parent_theme_slug, $available_themes, true ) ) {
				// Copy the parent theme files to the main repository
				$parent_theme_path         = $a8c_themes_dir . '/' . $parent_theme_slug;
				$parent_theme_copy_command = sprintf(
					'cp -r %s %s/themes/',
					$parent_theme_path,
					$temp_dir
				);
				exec( $parent_theme_copy_command, $exec_output, $return_code );
				if ( 0 !== $return_code ) {
					$output->writeln( '<error>Failed to copy parent theme files.</error>' );
					$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
					return false;
				}
			} else {
				$output->writeln( "<error>Parent theme {$parent_theme_slug} not found.</error>" );
			}
		} else {
			$output->writeln( "<comment>The theme {$this->no_code_theme} is not a child theme. Proceeding with default setup.</comment>" );

			// Create themes directory and copy theme files
			$copy_command = sprintf(
				'cd %s && mkdir -p themes/%s && cp -r %s/%s/* themes/%s',
				$temp_dir,
				$this->no_code_theme,
				$a8c_themes_dir,
				$this->no_code_theme,
				$this->no_code_theme
			);
			exec( $copy_command, $exec_output, $return_code );
			if ( 0 !== $return_code ) {
				$output->writeln( '<error>Failed to copy theme files.</error>' );
				$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
				return false;
			}
		}

		// Commit and push the theme files
		$git_commands = array(
			sprintf( 'cd %s', $temp_dir ),
			'git add .',
			'git commit -m "Add theme files"',
			'git push origin trunk',
		);

		exec( implode( ' && ', $git_commands ), $exec_output, $return_code );
		if ( 0 !== $return_code ) {
			$output->writeln( '<error>Failed to push theme files.</error>' );
			$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
			return false;
		}

		// Clean up main repository temporary directory
		exec( sprintf( 'rm -rf %s', $temp_dir ), $exec_output, $return_code );
		if ( 0 !== $return_code ) {
			$output->writeln( '<error>Failed to clean up temporary directory.</error>' );
			$output->writeln( '<error>Command output: ' . implode( "\n", $exec_output ) . '</error>' );
			return false;
		}

		$output->writeln( '<fg=green>Theme files added and pushed successfully.</>' );
		return true;
	}

	// endregion
}
