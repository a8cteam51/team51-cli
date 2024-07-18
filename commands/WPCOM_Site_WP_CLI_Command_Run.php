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
 * Runs a given WP-CLI command on a given WordPress.com site.
 */
#[AsCommand( name: 'wpcom:run-site-wp-cli-command' )]
final class WPCOM_Site_WP_CLI_Command_Run extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WordPress.com site definition to run the WP CLI command on.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The WP-CLI command to run.
	 *
	 * @var string|null
	 */
	private ?string $wp_command = null;

	/**
	 * Whether to skip outputting the response to the console.
	 *
	 * @var bool|null
	 */
	private ?bool $skip_output = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Runs a given WP-CLI command on a given WordPress.com site.' )
			->setHelp( 'This command allows you to run an arbitrary WP-CLI command on a WordPress.com site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric WordPress.com ID of the site to open the shell to.' )
			->addArgument( 'wp-cli-command', InputArgument::REQUIRED, 'The WP-CLI command to run.' )
			->addOption( 'skip-output', null, InputOption::VALUE_NONE, 'Skip outputting the response to the console.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->wp_command = get_string_input( $input, $output, 'wp-cli-command', fn() => $this->prompt_command_input( $input, $output ) );
		$this->wp_command = \trim( \preg_replace( '/^wp/', '', \trim( $this->wp_command ) ) );
		if ( false === \str_contains( $this->wp_command, 'eval' ) ) {
			$this->wp_command = \escapeshellcmd( $this->wp_command );
		}
		$input->setArgument( 'wp-cli-command', $this->wp_command );

		$this->skip_output = get_bool_input( $input, $output, 'skip-output' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL})? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Running the command `wp $this->wp_command` on {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$ssh = \WPCOM_Connection_Helper::get_ssh_connection( $this->site->ID );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>SSH connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

		try {
			$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
			$ssh->exec(
				"wp $this->wp_command",
				function ( string $str ): void {
					$GLOBALS['wp_cli_output'] = $str;
					if ( ! $this->skip_output ) {
						echo "$str\n";
					}
				}
			);

			return Command::SUCCESS;
		} catch ( \RuntimeException $exception ) {
			$output->writeln( "<error>Something went wrong. Please double-check if things worked out. This is what we know: {$exception->getMessage()}</error>" );
			return Command::FAILURE;
		} finally {
			$ssh->disconnect();
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or WordPress.com site ID to run the WP-CLI command on:</question> ' );
		$question->setAutocompleterValues( \array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a WP-CLI command.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_command_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the WP-CLI command to run:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
