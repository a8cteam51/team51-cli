<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Runs a given WP-CLI command on a given Pressable site.
 */
#[AsCommand( name: 'pressable:run-site-wp-cli-command' )]
final class Pressable_Site_WP_CLI_Command_Run extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to run the WP CLI command on.
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

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Runs a given WP-CLI command on a given Pressable site.' )
			->setHelp( 'This command allows you to run an arbitrary WP-CLI command on a Pressable site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site to open the shell to.' )
			->addArgument( 'wp-cli-command', InputArgument::REQUIRED, 'The WP-CLI command to run.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->wp_command = get_string_input( $input, $output, 'wp-cli-command', fn() => $this->prompt_command_input( $input, $output ) );
		$this->wp_command = \escapeshellcmd( \trim( \preg_replace( '/^wp/', '', \trim( $this->wp_command ) ) ) );
		$input->setArgument( 'wp-cli-command', $this->wp_command );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url})? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Running the command `wp $this->wp_command` on {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}).</>" );

		$ssh = \Pressable_Connection_Helper::get_ssh_connection( $this->site->id );
		if ( \is_null( $ssh ) ) {
			$output->writeln( '<error>Could not connect to the SSH server.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>SSH connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

		$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
		$ssh->exec(
			"wp $this->wp_command",
			static function ( string $str ): void {
				echo $str;
			}
		);

		$ssh->disconnect();
		return Command::SUCCESS;
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to run the WP-CLI command on:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

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
