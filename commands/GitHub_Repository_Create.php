<?php

namespace WPCOMSpecialProjects\CLI\Command;

use stdClass;
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
	 * The name of the theme to use for the no-code repository.
	 *
	 * @var string|null
	 */
	private ?string $no_code_theme = null;

	/**
	 * The list of available themes.
	 *
	 * @var array|null
	 */
	private ?array $themes = null;

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
		'project'         => 'Full Project Repo',
		'no-code-project' => 'No-Code Project Repo',
		'plugin'          => 'Plugin Specific Repo',
		'issues'          => 'Issues Only Repo',
		'empty'           => 'Empty Repo',
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
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'The name of the template repository to use, if any. One of either `project`, `no-code-project`, `plugin`, `issues`, or `empty`. Default empty repo.' )
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

		$this->type = get_enum_input( $input, 'type', array_keys( self::REPO_TYPES ), fn() => $this->prompt_type_input( $input, $output ) );
		$input->setOption( 'type', $this->type );
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

		if ( 'no-code-project' === $this->type && empty( $this->no_code_theme ) ) {
			$this->setup_no_code_theme( $input, $output );

			if ( ! empty( $this->no_code_theme ) ) {
				$question = new ConfirmationQuestion( "<question>Are you sure you want to use the theme $this->no_code_theme as the parent theme for the $type repository $this->name? [y/N]</question> ", false );
				if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
					$output->writeln( '<comment>Command aborted by user.</comment>' );
					exit( 2 );
				}
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$type = $this->type ?? 'empty';
		$output->writeln( "<fg=magenta;options=bold>Creating the $type repository $this->name.</>" );

		$this->custom_properties = $this->process_custom_properties( $input );
		$input->setOption( 'custom-properties', $this->custom_properties );

		// Create the repository.
		$repository = create_github_repository( $this->name, 'empty' === $this->type ? null : $this->type, $this->homepage, $this->description, $this->custom_properties );
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

		// Check if the selected no code theme is child theme.
		if ( 'no-code-project' === $this->type && ! empty( $this->no_code_theme ) ) {
			if ( null === $this->themes ) {
				$this->themes = get_wporg_theme_choices();
			}

			if ( isset( $this->themes[ $this->no_code_theme ] ) ) {
				$theme = $this->themes[ $this->no_code_theme ];

				if ( isset( $theme->parent ) && ! empty( $theme->parent ) ) {
					$output->writeln( "<comment>The selected no-code theme $this->no_code_theme is a child theme.</comment>" );
					$output->writeln( '<fg=magenta;options=bold>Replacing the default theme with the child theme.</>' );
					$result = $this->add_no_code_theme_files( $output, $repository );
					if ( false === $result ) {
						$output->writeln( '<error>Failed to add theme files.</error>' );
						return Command::FAILURE;
					}
				}
			}
		}

		if ( in_array( $this->type, array( 'no-code-project', 'project', 'plugin' ), true ) ) {
			if ( ! $this->fill_scaffold_placeholders_locally( $output, $repository ) ) {
				$output->writeln( '<error>Failed to fill scaffold placeholders in the new repository.</error>' );
				return Command::FAILURE;
			}
		}

		if ( in_array( $this->type, array( 'no-code-project', 'project' ), true ) ) {
			if ( ! $this->sync_agent_context_files( $output, $repository ) ) {
				$output->writeln( '<error>Failed to sync agent context files to the new repository.</error>' );
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
	 * Sets up the no-code theme by cloning/pulling the themes repo and prompting for theme selection.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @return void
	 */
	private function setup_no_code_theme( InputInterface $input, OutputInterface $output ): void {
		$output->writeln( '<fg=magenta;options=bold>Fetching WordPress.org themes...</>' );

		$this->themes = get_wporg_theme_choices();

		// Inject the "wpcom-theme" option
		$this->themes[] = (object) array(
			'slug' => 'wpcom-theme',
			'name' => 'WPCOM theme, other theme',
		);

		if ( empty( $this->themes ) ) {
			$output->writeln( '<error>Failed to fetch .org themes.</error>' );
			return;
		}

		if ( ! empty( $this->no_code_theme ) ) {
			if ( ! in_array( $this->no_code_theme, $this->themes, true ) ) {
				$output->writeln( '<error>The selected no-code theme is not available.</error>' );
				$output->writeln( '<error>Please select a different theme or press enter to skip.</error>' );
				$this->no_code_theme = null;
			} else {
				return;
			}
		}

		$this->no_code_theme = $this->prompt_no_code_theme_input( $input, $output, $this->themes );

		if ( 'wpcom-theme' === $this->no_code_theme ) {
			$question            = new Question( '<question>Please enter the slug of the WPCOM theme to use:</question> ' );
			$theme_slug          = $this->getHelper( 'question' )->ask( $input, $output, $question );
			$this->no_code_theme = $theme_slug;
		}
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

		// Delete the scaffold folder if it exists
		$scaffold_path = $temp_dir . '/themes/a8csp-no-code-project-scaffold';
		if ( is_dir( $scaffold_path ) ) {
			$output->writeln( '<fg=magenta;options=bold>Removing scaffold theme folder...</>' );
			exec( sprintf( 'rm -rf %s', $scaffold_path ), $exec_output, $return_code );
			if ( 0 !== $return_code ) {
				$output->writeln( '<error>Failed to remove scaffold theme folder.</error>' );
				return false;
			}
		}

		// Create themes directory if it doesn't exist
		$themes_dir = $temp_dir . '/themes';
		if ( ! is_dir( $themes_dir ) ) {
			mkdir( $themes_dir, 0777, true );
		}

		// Download and extract theme
		$download_url = 'https://downloads.wordpress.org/theme/' . $this->no_code_theme . '.zip';
		$zip_path     = $temp_dir . '/' . $this->no_code_theme . '.zip';

		$output->writeln( "<fg=magenta;options=bold>Downloading theme files from {$download_url}...</>" );

		if ( false === file_put_contents( $zip_path, file_get_contents( $download_url ) ) ) {
			$output->writeln( '<error>Failed to download theme files.</error>' );
			return false;
		}

		// Extract the theme
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$output->writeln( '<error>Failed to open theme zip file.</error>' );
			return false;
		}

		// Create a custom theme name TBD if needed
		$custom_theme_name = $this->no_code_theme;
		$custom_theme_path = $themes_dir . '/' . $custom_theme_name;

		// Extract to a temporary location first
		$extract_path = $temp_dir . '/theme-extract';
		mkdir( $extract_path );
		$zip->extractTo( $extract_path );
		$zip->close();

		// Move the extracted theme to the correct location with the custom name
		rename( $extract_path . '/' . $this->no_code_theme, $custom_theme_path );
		rmdir( $extract_path );
		unlink( $zip_path );

		// Modify the theme name in style.css
		$style_css_path = $custom_theme_path . '/style.css';
		if ( file_exists( $style_css_path ) ) {
			$style_contents = file_get_contents( $style_css_path );
			$style_contents = preg_replace( '/Theme Name:\s*(.+)/', 'Theme Name: $1 Custom', $style_contents );
			file_put_contents( $style_css_path, $style_contents );
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

		// Clean up temporary directory
		exec( sprintf( 'rm -rf %s', $temp_dir ), $exec_output, $return_code );
		if ( 0 !== $return_code ) {
			$output->writeln( '<error>Failed to clean up temporary directory.</error>' );
			return false;
		}

		$output->writeln( '<fg=green>Theme files added and pushed successfully.</>' );
		return true;
	}

	/**
	 * Fills scaffold placeholders in the newly created repository locally.
	 *
	 * @param   OutputInterface $output     The output interface.
	 * @param   stdClass        $repository The repository object.
	 *
	 * @return  boolean
	 */
	private function fill_scaffold_placeholders_locally( OutputInterface $output, stdClass $repository ): bool {
		$output->writeln( '<fg=magenta;options=bold>Filling scaffold placeholders locally...</>' );

		$temp_root       = sys_get_temp_dir() . '/' . uniqid( 'github-repo-scaffold-fill-' );
		$destination_dir = $temp_root . '/destination';
		$type            = $this->type ?? 'empty';

		if ( ! mkdir( $temp_root ) && ! is_dir( $temp_root ) ) {
			$output->writeln( '<error>Failed to create temporary directory for scaffold replacement.</error>' );
			return false;
		}

		try {
			$destination_clone_process = run_system_command(
				array( 'git', 'clone', $repository->ssh_url, $destination_dir ),
				'.',
				false
			);

			if ( ! $destination_clone_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to clone the destination repository.</error>' );
				return false;
			}

			if ( ! $this->rename_scaffold_paths( $destination_dir, $type ) ) {
				$output->writeln( '<error>Failed to rename scaffold files and directories.</error>' );
				return false;
			}

			if ( ! $this->replace_placeholders_in_repository_tree( $destination_dir, $type ) ) {
				$output->writeln( '<error>Failed to replace scaffold placeholders in repository files.</error>' );
				return false;
			}

			if ( ! $this->remove_scaffold_workflow_files( $destination_dir ) ) {
				$output->writeln( '<error>Failed to remove scaffold workflow files.</error>' );
				return false;
			}

			$git_add_process = run_system_command(
				array( 'git', 'add', '--all' ),
				$destination_dir,
				false
			);

			if ( ! $git_add_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to stage scaffold placeholder changes.</error>' );
				return false;
			}

			$git_status_process = run_system_command(
				array( 'git', 'status', '--porcelain' ),
				$destination_dir,
				false
			);

			if ( ! $git_status_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to determine whether scaffold placeholder changes exist.</error>' );
				return false;
			}

			if ( '' === trim( $git_status_process->getOutput() ) ) {
				$output->writeln( '<comment>No local scaffold placeholder changes were needed.</comment>' );
				return true;
			}

			$git_commit_process = run_system_command(
				array( 'git', 'commit', '-m', 'chore -- fill in scaffolding placeholders locally' ),
				$destination_dir,
				false
			);

			if ( ! $git_commit_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to commit scaffold placeholder changes.</error>' );
				return false;
			}

			$git_push_process = run_system_command(
				array( 'git', 'push', 'origin', 'trunk' ),
				$destination_dir,
				false
			);

			if ( ! $git_push_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to push scaffold placeholder changes to `trunk`.</error>' );
				return false;
			}
		} finally {
			$this->remove_path( $temp_root );
		}

		$output->writeln( '<fg=green;options=bold>Scaffold placeholders filled successfully.</>' );
		return true;
	}

	/**
	 * Renames scaffold files and directories based on repository type.
	 *
	 * @param   string $repository_dir The cloned repository directory.
	 * @param   string $type           The repository type.
	 *
	 * @return  boolean
	 */
	private function rename_scaffold_paths( string $repository_dir, string $type ): bool {
		$rename_map = match ( $type ) {
			'project' => array(
				'README.scaffold.md' => 'README.md',
				'themes/a8csp-project-scaffold' => 'themes/' . $this->name,
				'mu-plugins/a8csp-project-scaffold-features' => 'mu-plugins/' . $this->name . '-features',
				'mu-plugins/' . $this->name . '-features/a8csp-project-scaffold-features.php' => 'mu-plugins/' . $this->name . '-features/' . $this->name . '-features.php',
			),
			'plugin' => array(
				'README.scaffold.md' => 'README.md',
				'team51-plugin-scaffold.php' => $this->name . '.php',
			),
			'no-code-project' => array(
				'README.scaffold.md' => 'README.md',
				'themes/a8csp-no-code-project-scaffold' => 'themes/' . $this->name,
			),
			default => array(),
		};

		foreach ( $rename_map as $source => $destination ) {
			$source_path = $repository_dir . '/' . $source;
			if ( ! file_exists( $source_path ) && ! is_link( $source_path ) ) {
				continue;
			}

			$move_process = run_system_command(
				array( 'git', 'mv', '--force', $source, $destination ),
				$repository_dir,
				false
			);

			if ( ! $move_process->isSuccessful() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replaces scaffold placeholders in repository files.
	 *
	 * @param   string $repository_dir The cloned repository directory.
	 * @param   string $type           The repository type.
	 * @param   string $relative_dir   The current relative directory.
	 *
	 * @return  boolean
	 */
	private function replace_placeholders_in_repository_tree( string $repository_dir, string $type, string $relative_dir = '' ): bool {
		$directory = '' === $relative_dir ? $repository_dir : $repository_dir . '/' . $relative_dir;
		$items     = scandir( $directory );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || '.git' === $item || '.github' === $item ) {
				continue;
			}

			$relative_path = '' === $relative_dir ? $item : $relative_dir . '/' . $item;
			$path          = $repository_dir . '/' . $relative_path;

			if ( is_dir( $path ) ) {
				if ( ! $this->replace_placeholders_in_repository_tree( $repository_dir, $type, $relative_path ) ) {
					return false;
				}
				continue;
			}

			if ( $this->is_probably_binary_file( $path ) ) {
				continue;
			}

			if ( ! $this->replace_placeholders_in_file( $path, $relative_path, $type ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replaces scaffold placeholders in a file.
	 *
	 * @param   string $path          The absolute file path.
	 * @param   string $relative_path The path relative to the repository root.
	 * @param   string $type          The repository type.
	 *
	 * @return  boolean
	 */
	private function replace_placeholders_in_file( string $path, string $relative_path, string $type ): bool {
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return false;
		}

		$replacements = 'README.md' === $relative_path
			? $this->build_scaffold_placeholder_replacements( $type, true )
			: $this->build_scaffold_placeholder_replacements( $type, false );

		$updated_contents = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$contents
		);

		if ( $updated_contents === $contents ) {
			return true;
		}

		return false !== file_put_contents( $path, $updated_contents );
	}

	/**
	 * Returns the scaffold placeholder replacements for a repository type.
	 *
	 * @param   string  $type      The repository type.
	 * @param   boolean $is_readme Whether the file being replaced is the root README.
	 *
	 * @return  array<string, string>
	 */
	private function build_scaffold_placeholder_replacements( string $type, bool $is_readme ): array {
		$title                    = $this->custom_properties['human-title'] ?? $this->name ?? '';
		$homepage                 = $this->homepage ?? 'https://wpspecialprojects.com';
		$description              = $this->description ?? '';
		$long_prefix              = $this->custom_properties['php-globals-long-prefix'] ?? str_replace( '-', '_', $this->name ?? '' );
		$short_prefix             = $this->custom_properties['php-globals-short-prefix'] ?? str_replace( '-', '_', $this->name ?? '' );
		$short_prefix_upper       = strtoupper( $short_prefix );
		$parent_theme             = $this->custom_properties['parent-theme'] ?? $this->no_code_theme ?? '';
		$plugin_namespace         = 'A8C\\SpecialProjects\\' . str_replace( 'A8CSP', '', str_replace( ' ', '', $title ) );
		$plugin_namespace_escaped = str_replace( '\\', '\\\\', $plugin_namespace ) . '\\\\';

		return match ( $type ) {
			'project' => $is_readme
				? array(
					'EXAMPLE_REPO_NAME' => $title,
					'EXAMPLE_REPO_PROD_URL' => $homepage,
				)
				: array(
					'A8CSP Project Scaffold' => $title,
					'The scaffold for new projects used by the Automattic Special Projects team.' => $description,
					'https://a8csp-project-scaffold-production.mystagingwebsite.com' => $homepage,
					'a8csp-project-scaffold' => $this->name ?? '',
					'a8csp_project_scaffold' => $long_prefix,
					'a8csp' => $short_prefix,
					'A8CSP' => $short_prefix_upper,
				),
			'plugin' => $is_readme
				? array(
					'EXAMPLE_REPO_NAME' => $title,
					'EXAMPLE_REPO_DESCRIPTION' => $description,
				)
				: array(
					'A8CSP Plugin Scaffold' => $title,
					'A scaffold for A8C Special Projects plugins.' => $description,
					'team51-plugin-scaffold' => $this->name ?? '',
					'a8csp-scaffold' => $this->name ?? '',
					'A8C\SpecialProjects\Scaffold' => $plugin_namespace,
					'A8C\\\\SpecialProjects\\\\Scaffold\\\\' => $plugin_namespace_escaped,
					'a8csp_scaffold' => $short_prefix,
					'A8CSP_SCAFFOLD' => $short_prefix_upper,
				),
			'no-code-project' => $is_readme
				? array(
					'EXAMPLE_REPO_NAME' => $title,
					'EXAMPLE_REPO_PROD_URL' => $homepage,
				)
				: array(
					'A8CSP No Code Project Scaffold' => $title,
					'The no code scaffold for new projects used by the Automattic Special Projects team.' => $description,
					'https://a8csp-no-code-project-scaffold-production.mystagingwebsite.com' => $homepage,
					'a8csp-no-code-project-scaffold' => $this->name ?? '',
					'a8csp_no_code_project_scaffold' => $long_prefix,
					'a8csp-no-code-project-parent-theme' => $parent_theme,
					'a8csp' => $short_prefix,
					'A8CSP' => $short_prefix_upper,
				),
			default => array(),
		};
	}

	/**
	 * Removes the no-longer-needed scaffold workflow files.
	 *
	 * @param   string $repository_dir The cloned repository directory.
	 *
	 * @return  boolean
	 */
	private function remove_scaffold_workflow_files( string $repository_dir ): bool {
		return $this->remove_path( $repository_dir . '/.github/workflows/fill-in-scaffold.yml' )
			&& $this->remove_path( $repository_dir . '/.github/workflows/fill-in-scaffold.mjs' );
	}

	/**
	 * Syncs shared AI agent context files into the newly created repository.
	 *
	 * @param   OutputInterface $output     The output interface.
	 * @param   stdClass        $repository The repository object.
	 *
	 * @return  boolean
	 */
	private function sync_agent_context_files( OutputInterface $output, stdClass $repository ): bool {
		$output->writeln( '<fg=magenta;options=bold>Syncing agent context files into the new repository...</>' );

		$temp_root       = sys_get_temp_dir() . '/' . uniqid( 'github-repo-context-sync-' );
		$destination_dir = $temp_root . '/destination';
		$source_dir      = $temp_root . '/agent-context';

		if ( ! mkdir( $temp_root ) && ! is_dir( $temp_root ) ) {
			$output->writeln( '<error>Failed to create temporary directory for agent context sync.</error>' );
			return false;
		}

		try {
			$destination_clone_process = run_system_command(
				array( 'git', 'clone', $repository->ssh_url, $destination_dir ),
				'.',
				false
			);

			if ( ! $destination_clone_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to clone the destination repository.</error>' );
				return false;
			}

			$context_clone_process = run_system_command(
				array( 'git', 'clone', '--recurse-submodules', 'git@github.com:a8cteam51/a8csp-agent-context.git', $source_dir ),
				'.',
				false
			);

			if ( ! $context_clone_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to clone `a8csp-agent-context` with submodules.</error>' );
				return false;
			}

			$this->remove_path( $destination_dir . '/.agents' );
			$this->remove_path( $destination_dir . '/AGENTS.md' );
			$this->remove_path( $destination_dir . '/CLAUDE.md' );

			if ( ! $this->copy_path( $source_dir . '/.agents', $destination_dir . '/.agents' ) ) {
				$output->writeln( '<error>Failed to copy `.agents` into destination repository.</error>' );
				return false;
			}

			if ( ! $this->copy_path( $source_dir . '/AGENTS.md', $destination_dir . '/AGENTS.md' ) ) {
				$output->writeln( '<error>Failed to copy `AGENTS.md` into destination repository.</error>' );
				return false;
			}

			if ( ! $this->copy_path( $source_dir . '/CLAUDE.md', $destination_dir . '/CLAUDE.md' ) ) {
				$output->writeln( '<error>Failed to copy `CLAUDE.md` into destination repository.</error>' );
				return false;
			}

			// Vendored mode requires normal files, not a submodule gitlink/metadata.
			$this->remove_path( $destination_dir . '/.agents/skills/wordpress/.git' );
			$this->remove_path( $destination_dir . '/.gitmodules' );

			$git_add_process = run_system_command(
				array( 'git', 'add', '--all' ),
				$destination_dir,
				false
			);

			if ( ! $git_add_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to stage synced agent context files.</error>' );
				return false;
			}

			$git_status_process = run_system_command(
				array( 'git', 'status', '--porcelain' ),
				$destination_dir,
				false
			);

			if ( ! $git_status_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to determine whether synced files changed.</error>' );
				return false;
			}

			if ( '' === trim( $git_status_process->getOutput() ) ) {
				$output->writeln( '<comment>Agent context files are already up to date. Skipping sync commit.</comment>' );
				return true;
			}

			$git_commit_process = run_system_command(
				array( 'git', 'commit', '-m', 'Sync agent context files from a8csp-agent-context' ),
				$destination_dir,
				false
			);

			if ( ! $git_commit_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to commit synced agent context files.</error>' );
				return false;
			}

			$git_push_process = run_system_command(
				array( 'git', 'push', 'origin', 'trunk' ),
				$destination_dir,
				false
			);

			if ( ! $git_push_process->isSuccessful() ) {
				$output->writeln( '<error>Failed to push synced agent context files to `trunk`.</error>' );
				return false;
			}
		} finally {
			$this->remove_path( $temp_root );
		}

		$output->writeln( '<fg=green;options=bold>Agent context files synced successfully.</>' );
		return true;
	}

	/**
	 * Removes a file or directory path recursively when it exists.
	 *
	 * @param   string $path The absolute path to remove.
	 *
	 * @return  boolean
	 */
	private function remove_path( string $path ): bool {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}

		$items = scandir( $path );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( ! $this->remove_path( $path . '/' . $item ) ) {
				return false;
			}
		}

		return rmdir( $path );
	}

	/**
	 * Copies a file or directory recursively.
	 *
	 * @param   string $source      The source path.
	 * @param   string $destination The destination path.
	 *
	 * @return  boolean
	 */
	private function copy_path( string $source, string $destination ): bool {
		if ( is_file( $source ) ) {
			$destination_dir = dirname( $destination );
			if ( ! is_dir( $destination_dir ) && ! mkdir( $destination_dir, 0777, true ) && ! is_dir( $destination_dir ) ) {
				return false;
			}

			return copy( $source, $destination );
		}

		if ( ! is_dir( $source ) ) {
			return false;
		}

		if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) && ! is_dir( $destination ) ) {
			return false;
		}

		$items = scandir( $source );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source_path      = $source . '/' . $item;
			$destination_path = $destination . '/' . $item;
			if ( ! $this->copy_path( $source_path, $destination_path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prompts the user for a no-code theme.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 * @param   array           $themes The list of available themes.
	 *
	 * @return  string|null
	 */
	private function prompt_no_code_theme_input( InputInterface $input, OutputInterface $output, array $themes ): ?string {
		return prompt_wporg_theme_input( $input, $output, $this->getHelper( 'question' ), $themes );
	}

	/**
	 * Determines whether a file is likely binary and should be skipped for string replacement.
	 *
	 * @param   string $path The file path to inspect.
	 *
	 * @return  boolean
	 */
	private function is_probably_binary_file( string $path ): bool {
		$binary_extensions = array(
			'png',
			'jpg',
			'jpeg',
			'gif',
			'webp',
			'ico',
			'zip',
			'gz',
			'tgz',
			'phar',
			'woff',
			'woff2',
			'eot',
			'ttf',
			'otf',
			'mp3',
			'mp4',
			'pdf',
			'mo',
			'map',
		);
		$extension         = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, $binary_extensions, true ) ) {
			return true;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return true;
		}

		$sample = fread( $handle, 1024 );
		fclose( $handle );

		return false !== $sample && str_contains( $sample, "\0" );
	}

	// endregion
}
