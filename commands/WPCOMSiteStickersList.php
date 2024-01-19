<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Lists the WPCOM stickers associated with a given site.
 */
#[AsCommand( name: 'wpcom:list-site-stickers' )]
final class WPCOMSiteStickersList extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to fetch the stickers for.
	 *
	 * @var \stdClass|null
	 */
	protected ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists the WPCOM stickers associated with a given site.' )
			->setHelp( 'Use this command to show a list of WPCOM stickers associated with a given site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to fetch the stickers for.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Listing stickers for {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$stickers = get_wpcom_site_stickers( $this->site->ID );
		if ( is_null( $stickers ) ) {
			$output->writeln( '<fg=red;options=bold>Could not fetch the stickers for the site.</>' );
			return 1;
		}

		if ( empty( $stickers ) ) {
			$output->writeln( '<fg=yellow;options=bold>There are no stickers associated with this site.</>' );
		} else {
			output_table( $output, array_map( static fn( $sticker ) => array( $sticker ), $stickers ), array( 'Sticker' ) );
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to fetch the stickers for:</question> ' );
		$question->setAutocompleterValues(
			array_map(
				static fn( string $url ) => parse_url( $url, PHP_URL_HOST ),
				array_column( get_wpcom_sites( array( 'fields' => 'ID,name,URL' ) ) ?? array(), 'URL' )
			)
		);

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
