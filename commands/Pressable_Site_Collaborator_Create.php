<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Creates a new Pressable site collaborator.
 */
#[AsCommand( name: 'pressable:create-site-collaborator' )]
final class Pressable_Site_Collaborator_Create extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to create the collaborator on.
	 *
	 * @var \stdClass|null
	 */
	protected ?\stdClass $site = null;

	/**
	 * The email address of the collaborator to create.
	 *
	 * @var string|null
	 */
	protected ?string $email = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new Pressable site collaborator.' )
			->setHelp( 'Use this command to create a new Pressable site collaborator.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site to create the collaborator on.' )
			->addArgument( 'email', InputArgument::REQUIRED, 'The email address of the collaborator to create.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->email = get_email_input( $input, $output, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the collaborator $this->email to {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url})? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating collaborator $this->email on {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}).</>" );

		$collaborator = create_pressable_site_collaborator( $this->site->id, $this->email );
		if ( \is_null( $collaborator ) ) {
			$output->writeln( '<error>Failed to create collaborator.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>Collaborator created successfully.</>' );
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to create the collaborator on:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for an email address.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_email_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email address of the collaborator to create [' . OPSOASIS_WP_USERNAME . ']:</question> ', OPSOASIS_WP_USERNAME );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : throw new \RuntimeException( 'Invalid email address.' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
