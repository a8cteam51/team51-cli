<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Removes a sticker from a given WPCOM site.
 */
#[AsCommand( name: 'wpcom:remove-site-sticker' )]
final class WPCOMSiteStickersRemove extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to remove the sticker from.
	 *
	 * @var \stdClass|null
	 */
	protected ?\stdClass $site = null;

	/**
	 * The sticker to remove.
	 *
	 * @var string|null
	 */
	protected ?string $sticker = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Removes a given sticker from a WPCOM site.' )
			->setHelp( 'Use this command to disassociate a sticker from a WPCOM site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to remove the sticker from.' )
			->addArgument( 'sticker', InputArgument::REQUIRED, 'Sticker to remove from the site.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->sticker = get_string_input( $input, $output, 'sticker', fn() => $this->prompt_sticker_input( $input, $output ) );
		$input->setArgument( 'sticker', $this->sticker );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Removing sticker '$this->sticker' from {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$result = remove_wpcom_site_sticker( $this->site->ID, $this->sticker );
		if ( true === $result ) {
			$output->writeln( '<fg=green;options=bold>Sticker removed successfully.</>' );
		} else {
			$output->writeln( '<fg=red;options=bold>Failed to remove sticker.</>' );
		}

		return 0;
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to remove the sticker from:</question> ' );
		$question->setAutocompleterValues(
			array_map(
				static fn( string $url ) => parse_url( $url, PHP_URL_HOST ),
				array_column( get_wpcom_sites( array( 'fields' => 'ID,name,URL' ) ) ?? array(), 'URL' )
			)
		);

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a sticker.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_sticker_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the sticker to remove:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
