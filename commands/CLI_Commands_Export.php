<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\Descriptor;
use Symfony\Component\Console\Descriptor\JsonDescriptor;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Descriptor\TextDescriptor;
use Symfony\Component\Console\Descriptor\XmlDescriptor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Exports all commands to a file in the specified format.
 */
#[AsCommand( name: 'export-commands' )]
final class CLI_Commands_Export extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The descriptor to use for exporting the commands.
	 *
	 * @var Descriptor|null
	 */
	private ?Descriptor $descriptor = null;

	/**
	 * The destination to save the output to.
	 * Null if the output is to be displayed in the terminal.
	 *
	 * @var string|null
	 */
	private ?string $destination = null;

	/**
	 * The object to save the output through.
	 *
	 * @var OutputInterface|null
	 */
	private ?OutputInterface $output = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Exports all commands to a file in the specified format.' )
			->setHelp( 'Use this command to export all commands to a file in the specified format.' );

		$this->addOption( 'format', 'f', InputOption::VALUE_REQUIRED, 'The format to export the commands in. Accepted values are `md`, `txt`, `json`, and `xml`.', 'md' )
			->addOption( 'destination', 'd', InputOption::VALUE_REQUIRED, 'If provided, the output will be saved inside the specified file instead of the terminal output.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$format           = get_enum_input( $input, 'format', array( 'md', 'txt', 'json', 'xml' ) );
		$this->descriptor = match ( $format ) {
			'md' => new MarkdownDescriptor(),
			'json' => new JsonDescriptor(),
			'xml' => new XmlDescriptor(),
			'txt' => new TextDescriptor(),
		};

		$this->destination = maybe_get_string_input( $input, 'destination', fn() => $this->prompt_destination_input( $input, $output ) );
		if ( ! \is_null( $this->destination ) ) {
			$stream       = get_file_handle( $this->destination, $format );
			$this->output = new StreamOutput( $stream );
		} else {
			$this->output = $output;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$destination_text = \is_null( $this->destination ) ? 'terminal' : $this->destination;
		$output->writeln( "<fg=magenta;options=bold>Exporting the commands description to $destination_text</>" );

		$this->descriptor->describe( $this->output, $this->getApplication() );

		$output->writeln( '<fg=green;options=bold>Commands have been exported successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for the destination to save the output to.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_destination_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ConfirmationQuestion( '<question>Would you like to save the output to a file? [y/N]</question> ', false );
		if ( true === $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$default  = get_user_folder_path( 'Downloads/team51-commands' );
			$question = new Question( "<question>Please enter the path to the file you want to save the output to [$default]:</question> ", $default );
			return $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return null;
	}

	// endregion
}
