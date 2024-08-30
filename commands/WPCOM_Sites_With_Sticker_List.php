<?php declare( strict_types=1 );

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Lists the WPCOM blog IDs that have a specific sticker.
 */
#[AsCommand( name: 'wpcom:list-sites-with-sticker' )]
final class WPCOM_Sites_With_Sticker_List extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Sticker to search sites with.
	 *
	 * @var string|null
	 */
	private ?string $sticker = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists the Blog IDs of sites with a specific sticker.' )
			->setHelp( 'Use this command to show a list of WPCOM Sites with a specific sticker.' );

		$this->addArgument( 'sticker', InputArgument::REQUIRED, 'Sticker to fetch the sites with.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->sticker = get_team51_sticker_input( $input, $output, 'sticker', fn () => $this->prompt_sticker_input( $input, $output ) );
		$input->setArgument( 'sticker', $this->sticker );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing sites with {$this->sticker}.</>" );

		$sites = get_wpcom_sites_with_sticker( $this->sticker );
		if ( is_null( $sites ) ) {
			$output->writeln( '<error>Could not fetch the sites.</error>' );
			return Command::FAILURE;
		}

		if ( empty( $sites ) ) {
			$output->writeln( '<fg=yellow;options=bold>There are no sites with the chosen sticker.</>' );
		} else {
			output_table(
				$output,
				array_map( static fn( $site ) => array( $site ), $sites ),
				array( 'Blog ID' ),
			);

			$output->writeln( sprintf( '<fg=magenta;options=bold>Found <fg=yellow>%d</> sites with <fg=yellow>%s</>.</>', count( $sites ), $this->sticker ) );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a sticker.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_sticker_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the sticker you are searching the sites for:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
