<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Creates a new production site on Pressable.
 */
#[AsCommand( name: 'pressable:create-site', aliases: array( 'pressable:create-production-site' ) )]
final class Pressable_Site_Create extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The name of the site to create.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The datacenter to create the site in.
	 *
	 * @var string|null
	 */
	protected ?string $datacenter = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new production site on Pressable.' )
			->setHelp( 'Use this command to create a new production site on Pressable.' );

		$this->addArgument( 'name', InputArgument::REQUIRED, 'The name of the site to create. Probably the same as the project name.' )
			->addOption( 'datacenter', null, InputArgument::OPTIONAL, 'The datacenter to create the site in.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->name = slugify( get_string_input( $input, $output, 'name', fn() => $this->prompt_name_input( $input, $output ) ) );
		$input->setArgument( 'name', $this->name );

		$this->datacenter = get_enum_input( $input, $output, 'datacenter', array_column( get_pressable_datacenters(), 'code' ), fn() => $this->prompt_datacenter_input( $input, $output ), 'DFW' );
		$input->setOption( 'datacenter', $this->datacenter );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to create a new Pressable site named $this->name in the $this->datacenter datacenter? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating new Pressable site named $this->name in the $this->datacenter datacenter.</>" );

		// Create the new site.
		$site = create_pressable_site( $this->name, $this->datacenter );
		if ( \is_null( $site ) ) {
			$output->writeln( '<error>Failed to create the site.</error>' );
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site name.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_name_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the name of the site to create:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a datacenter.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_datacenter_input( InputInterface $input, OutputInterface $output ): ?string {
		foreach ( get_pressable_datacenters() as $datacenter ) {
			if ( 'DFW' === $datacenter->code ) {
				$default = $datacenter->name;
				break;
			}
		}

		$question = new ChoiceQuestion( '<question>Please select the datacenter to create the site in:</question> ', array_column( get_pressable_datacenters(), 'name' ), $default ?? null );
		$question->setValidator( fn( $value ) => $value ? array_column( get_pressable_datacenters(), 'code' )[ array_search( $value, array_column( get_pressable_datacenters(), 'name' ), true ) ] : null );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
