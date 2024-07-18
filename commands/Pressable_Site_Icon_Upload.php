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
 * Uploads the site icon as apple-touch-icon.png to the Pressable sites.
 */
#[AsCommand( name: 'pressable:upload-site-icon' )]
final class Pressable_Site_Icon_Upload extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to upload the site icon to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Uploads the site icon as apple-touch-icon.png to the Pressable sites.' )
			->setHelp( 'If a site is displaying a white square icon when bookmarking it in iOS, this command may help fix it.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to upload the icon to.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=green;options=bold>Uploading apple-touch-icon.png to {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}).</>" );

		$sftp = \Pressable_Connection_Helper::get_sftp_connection( $this->site->id );
		if ( \is_null( $sftp ) ) {
			$output->writeln( '<error>Could not connect to the SFTP server.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>SFTP connections established.</>', OutputInterface::VERBOSITY_VERBOSE );

		// First, check if the site already has an `apple-touch-icon.png` at its root.
		if ( $sftp->file_exists( 'apple-touch-icon.png' ) ) {
			$output->writeln( '<comment>`apple-touch-icon.png` already exists. Aborting.</comment>' );
			return Command::SUCCESS;
		}

		$output->writeln( '<comment>Getting site icon URL...</comment>' );
		run_pressable_site_wp_cli_command( $this->site->id, "--skip-themes --skip-plugins eval 'echo get_site_icon_url(180);'", true );

		$site_icon_url = $GLOBALS['wp_cli_output'];
		if ( ! filter_var( $site_icon_url, FILTER_VALIDATE_URL ) ) {
			$output->writeln( '<error>Site has no icon set. Aborting.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<fg=green;options=bold>Downloading site icon from $site_icon_url...</>" );

		$site_icon = \file_get_contents( $site_icon_url );
		if ( false === $site_icon ) {
			$output->writeln( '<error>Could not download the site icon. Aborting.</error>' );
			return Command::FAILURE;
		}

		$image = $this->process_image( $site_icon, $output );
		if ( false === $image ) {
			$output->writeln( '<error>Could not process the site icon. Aborting.</error>' );
			return Command::FAILURE;
		}

		// Upload the image to the site's root.
		$output->writeln( '<comment>Uploading site icon through SFTP...</comment>' );

		$result = $sftp->put( 'apple-touch-icon.png', $image );
		if ( ! $result ) {
			$output->writeln( '<error>Could not upload the site icon. Aborting.</error>' );
			return Command::FAILURE;
		}

		$sftp->disconnect();

		$output->writeln( '<fg=green;options=bold>Site icon uploaded successfully.</>' );
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to upload the icon to:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Processes the image data so that images are converted to PNGs if needed.
	 *
	 * @param   string          $data   The image data.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|false
	 */
	private function process_image( string $data, OutputInterface $output ): string|false {
		$image_info = getimagesizefromstring( $data );
		if ( $image_info && IMAGETYPE_PNG === $image_info[2] ) {
			$output->writeln( '<comment>Image is already PNG. Skipping conversion.</comment>', OutputInterface::VERBOSITY_VERBOSE );
			return $data;
		}

		ob_start();
		imagepng( imagecreatefromstring( $data ) );

		return ob_get_clean();
	}

	// endregion
}
