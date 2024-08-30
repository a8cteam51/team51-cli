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
 * Adds a sticker to a given WPCOM site.
 */
#[AsCommand( name: 'wpcom:add-site-sticker' )]
final class WPCOM_Site_Stickers_Add extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to add the sticker to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The sticker to add.
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
		$this->setDescription( 'Add a given sticker to a WPCOM site.' )
			->setHelp( 'Use this command to associate a new sticker with a WPCOM site.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to add the sticker to.' )
			->addArgument( 'sticker', InputArgument::REQUIRED, 'Sticker to add to the site. Any sticker with the <fg=green;options=bold>team-51-</> prefix and the <fg=green;options=bold>blocked-from-atomic-transfer</> sticker.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->sticker = get_string_input( $input, 'sticker', fn() => $this->prompt_sticker_input( $input, $output ) );
		$input->setArgument( 'sticker', $this->sticker );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the sticker '$this->sticker' to {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL})? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Adding sticker '$this->sticker' to {$this->site->name} (ID {$this->site->ID}, URL {$this->site->URL}).</>" );

		$result = add_wpcom_site_sticker( $this->site->ID, $this->sticker );
		if ( true !== $result ) {
			$output->writeln( '<error>Failed to add sticker.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>Sticker added successfully.</>' );
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
		$question = new Question( '<question>Enter the domain or WPCOM site ID to add the sticker to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

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
		$question = new Question( '<question>Enter the sticker to add:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
