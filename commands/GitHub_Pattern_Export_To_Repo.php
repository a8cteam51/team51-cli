<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Exports a block pattern from a site to the block pattern library.
 */
#[AsCommand( name: 'github:pattern-export-to-repo' )]
final class GitHub_Pattern_Export_To_Repo extends Command {
	use AutocompleteTrait;


	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The name of the pattern to export.
	 *
	 * @var string|null
	 */
	protected ?string $pattern_name = null;

	/**
	 * The slug of the category under which the pattern will be exported.
	 *
	 * @var string|null
	 */
	protected ?string $category_slug = null;

	/**
	 * {@inheritDoc}
	 */
	protected function configure() {
		$this
			->setDescription( 'Exports a block pattern from a site to a GitHub.' )
			->setHelp( 'This command exports a specified block pattern into a category within a GitHub repository.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the Pressable site to run the command on.' )
			->addArgument( 'pattern-name', InputArgument::REQUIRED, 'The unique identifier of the block pattern to export (e.g., "namespace/pattern-name").' )
			->addArgument( 'category-slug', InputArgument::REQUIRED, 'The slug of the category under which the pattern should be exported. It should be lowercase with hyphens instead of spaces (e.g., "featured-patterns").' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {

		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( $output->isVerbose() ) {
			$output->writeln( "<info>Site {$this->pressable_site->id}: {$this->pressable_site->url}</info>" );
		}
		if ( \is_null( $this->pressable_site ) ) {
			exit( 1 ); // Exit if the site does not exist.
		}

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		$this->pattern_name = $input->getArgument( 'pattern-name' );
		// Check if the pattern name was already provided as an argument. If not, prompt the user for it.
		if ( ! $this->pattern_name ) {
			$this->pattern_name = $this->prompt_pattern_name_input( $input, $output );
			$input->setArgument( 'pattern-name', $this->pattern_name );
		}
		if ( $output->isVerbose() ) {
			$output->writeln( "<info>Pattern name: {$this->pattern_name}</info>" );
		}

		// Check if the category slug was already provided as an argument. If not, prompt the user for it.
		$this->category_slug = $input->getArgument( 'category-slug' );
		if ( ! $this->category_slug ) {
			$this->category_slug = $this->prompt_category_slug_input( $input, $output );
			$input->setArgument( 'category-slug', $this->category_slug );
		}
		if ( $output->isVerbose() ) {
			$output->writeln( "<info>Category slug: {$this->category_slug}</info>" );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {

		// Upload script.
		$sftp   = \Pressable_Connection_Helper::get_sftp_connection( $this->pressable_site->id );
		$result = $sftp->put( '/htdocs/pattern-extract.php', file_get_contents( __DIR__ . '/../scaffold/pattern-extract.php' ) );
		if ( ! $result ) {
			$output->writeln( "<error>Failed to copy pattern-extract.php to {$this->pressable_site->id}.</error>" );
		}

		$ssh_connection = \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh_connection ) ) {
			$output->writeln( "<error>Failed to connect via SSH for {$this->pressable_site->url}. Aborting!</error>" );
			return Command::FAILURE;
		}

		// Run script.
		$result = $ssh_connection->exec( sprintf( "wp eval-file /htdocs/pattern-extract.php '%s'", $this->pattern_name ) );
		if ( $output->isDebug() ) {
			$output->writeln( "<info>Pattern extraction result: {$result}</info>" );
		}
		// Delete script.
		// $ssh_connection->exec( 'rm /htdocs/pattern-extract.php' );

		if ( ! empty( $result ) ) {

			// Temporary directory to clone the repository
			$temp_dir = sys_get_temp_dir() . '/team51-patterns';
			$repo_url = 'git@github.com:a8cteam51/team51-patterns.git';

			// Clone the repository
			\run_system_command( array( 'git', 'clone', $repo_url, $temp_dir ), sys_get_temp_dir() );

			// The 'patterns' folder at the root of the repo.
			$patterns_dir = $temp_dir . '/patterns';

			// Additional setup for category directory and metadata.json handling.
			$category_dir  = $patterns_dir . '/' . $this->category_slug;
			$metadata_path = $category_dir . '/metadata.json';

			// Ensure the category directory exists.
			\run_system_command( array( 'mkdir', '-p', $category_dir ), $temp_dir );

			// Check if metadata.json exists before creating or overwriting
			if ( ! file_exists( $metadata_path ) ) {
				$metadata = array( 'title' => $this->category_slug );
				file_put_contents( $metadata_path, json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				// Add metadata.json to the repository
				\run_system_command( array( 'git', 'add', $metadata_path ), $temp_dir );
			}

			// Path to the JSON file for the pattern.
			$pattern_file_base = basename( $this->slugify( $this->pattern_name ) );
			$pattern_file_name = $pattern_file_base . '.json';
			$json_file_path    = $category_dir . '/' . $pattern_file_name;

			// Check for existing files with the same name and append a number if necessary.
			$count = 1;
			while ( file_exists( $json_file_path ) ) {
				++$count;
				$pattern_file_name = $pattern_file_base . '-' . $count . '.json';
				$json_file_path    = $category_dir . '/' . $pattern_file_name;
			}

			// Save the pattern result to the file. Re-enconded to save as pretty JSON.
			$result = json_decode( $result, true );
			$result = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			file_put_contents( $json_file_path, $result );

			// Add, commit, and push the change.
			$branch_name = 'add/pattern/' . $pattern_file_base . '-' . time();
			\run_system_command( array( 'git', 'branch', '-m', $branch_name ), $temp_dir );
			\run_system_command( array( 'git', 'add', $json_file_path ), $temp_dir );
			\run_system_command( array( 'git', 'commit', '-m', 'New pattern: ' . $pattern_file_base ), $temp_dir );
			\run_system_command( array( 'git', 'push', 'origin', $branch_name ), $temp_dir );

			// Clean up by removing the cloned repository directory, if desired
			\run_system_command( array( 'rm', '-rf', $temp_dir ), sys_get_temp_dir() );
		} else {
			$output->writeln( '<error>Pattern not found. Aborting!</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<comment>Done!</comment>' );
		return Command::SUCCESS;
	}

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		// Ask for the pattern name, providing an example as a hint.
		$question = new Question( '<question>Enter the site ID or URL to extract the pattern from:</question> ' );
		$question->setAutocompleterValues( \array_map( static fn( object $site ) => $site->url, \get_pressable_sites() ?? array() ) );

		// Retrieve the user's input.
		$site = $this->getHelper( 'question' )->ask( $input, $output, $question );

		return $site ?? null;
	}

	/**
	 * Prompts the user for a pattern name in interactive mode.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return string|null
	 */
	private function prompt_pattern_name_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {

			// Ask for the pattern name, providing an example as a hint.
			$question_text = '<question>Enter the pattern name (e.g., "twentytwentyfour/banner-hero"):</question> ';
			$question      = new Question( $question_text );

			// Retrieve the user's input.
			$pattern_name = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $pattern_name ?? null;
	}

	/**
	 * Prompts the user for a category slug in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_category_slug_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {

			// Provide guidance on the expected format for the category slug.
			$question = new Question( '<question>Enter the category slug (lowercase, hyphens for spaces, e.g., "hero"):</question> ' );

			// Ask the question and retrieve the user's input.
			$category_slug = $this->getHelper( 'question' )->ask( $input, $output, $question );

			// Ensure the input matches the expected format.
			$category_slug = $this->slugify( $category_slug );
		}

		return $category_slug ?? null;
	}

	/**
	 * Convert a text string to something ready to be used as a unique, machine-friendly identifier.s
	 *
	 * @param string $_text The input text to be slugified.
	 *
	 * @return string The slugified version of the input text.
	 */
	protected function slugify( $_text ) {
		$_slug = strtolower( $_text ); // convert to lowercase
		$_slug = preg_replace( '/\s+/', '-', $_slug ); // convert all contiguous whitespace to a single hyphen
		$_slug = preg_replace( '/[^a-z0-9\-]/', '', $_slug ); // Lowercase alphanumeric characters and dashes are allowed.
		$_slug = preg_replace( '/-+/', '-', $_slug ); // convert multiple contiguous hyphens to a single hyphen

		return $_slug;
	}
}
