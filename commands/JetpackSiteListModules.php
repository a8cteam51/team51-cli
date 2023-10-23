<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Lists the status of Jetpack modules on a given site.
 */
#[AsCommand( name: 'jetpack:list-site-modules' )]
final class JetpackSiteListModules extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Domain or WPCOM ID of the site to fetch the information for.
	 *
	 * @var string|null
	 */
	protected ?string $site = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists the status of Jetpack modules on a given site.' )
			->setHelp( 'Use this command to show a list of Jetpack modules on a site, and their status. This command requires a Jetpack site connected to the a8cteam51 account.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site to fetch the information for.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		maybe_define_console_verbosity( $output->getVerbosity() );

		$this->site = get_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		if ( \is_null( $this->site ) ) {
			exit( 1 );
		}

		// TODO: Fetch site object to confirm it exists.
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$app_user     = 'YOUR-USERNAME-HERE';
		$app_password = 'YOUR-APP-PASS-HERE';

		$response = get_remote_content(
			'https://opsoasis.wpspecialprojects.com/wp-json/oasis/jetpack-modules-list?site-domain=' . $this->site,
			array(
				'Authorization' => 'Basic ' . base64_encode( $app_user . ':' . $app_password ),
			)
		);
		$output->write( encode_json_content( $response['body'] ) );

		return 0;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		if ( $input->isInteractive() ) {
			// TODO: Add autocompletion for sites.
			$question = new Question( '<question>Enter the domain or WPCOM site ID to fetch the information for:</question> ' );
			$site     = $this->getHelper( 'question' )->ask( $input, $output, $question );
		}

		return $site ?? null;
	}

	// endregion
}
