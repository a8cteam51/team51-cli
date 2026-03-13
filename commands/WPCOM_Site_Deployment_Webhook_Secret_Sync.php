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
 * Syncs a WordPress.com deployment webhook secret to OpsOasis.
 */
#[AsCommand( name: 'wpcom:site:deployment:webhook:sync-secret' )]
final class WPCOM_Site_Deployment_Webhook_Secret_Sync extends Command {
	use AutocompleteTrait;

	/**
	 * WPCOM site definition.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * Code deployment ID.
	 *
	 * @var string|null
	 */
	private ?string $deployment_id = null;

	/**
	 * Webhook ID.
	 *
	 * @var string|null
	 */
	private ?string $webhook_id = null;

	/**
	 * Webhook secret.
	 *
	 * @var string|null
	 */
	private ?string $secret = null;

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Syncs an existing WPCOM deployment webhook secret to OpsOasis.' )
			->setHelp( 'Use this command to persist a webhook secret in OpsOasis when automatic sync fails or is skipped.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site.' )
			->addArgument( 'deployment_id', InputArgument::REQUIRED, 'The code deployment ID.' )
			->addArgument( 'webhook_id', InputArgument::REQUIRED, 'The webhook ID.' )
			->addArgument( 'secret', InputArgument::REQUIRED, 'The one-time webhook secret returned by WPCOM on create.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->deployment_id = get_string_input( $input, 'deployment_id' );
		$input->setArgument( 'deployment_id', $this->deployment_id );

		$this->webhook_id = get_string_input( $input, 'webhook_id' );
		$input->setArgument( 'webhook_id', $this->webhook_id );

		$this->secret = get_string_input( $input, 'secret' );
		$input->setArgument( 'secret', $this->secret );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$webhook = get_wpcom_site_code_deployment_webhook( (string) $this->site->ID, $this->deployment_id, $this->webhook_id );
		if ( \is_null( $webhook ) ) {
			$output->writeln( '<error>Failed to fetch the specified deployment webhook from WPCOM. Aborting secret sync.</error>' );
			return Command::FAILURE;
		}

		$canonical_url    = $webhook->url ?? null;
		$canonical_events = normalize_wpcom_site_code_deployment_webhook_events( $webhook->events ?? null );
		if ( \is_null( $canonical_url ) || '' === trim( $canonical_url ) || empty( $canonical_events ) ) {
			$output->writeln( '<error>Webhook metadata from WPCOM is incomplete. Aborting secret sync.</error>' );
			return Command::FAILURE;
		}

		$sync_succeeds = true === sync_wpcom_site_code_deployment_webhook_secret(
			(string) $this->site->ID,
			$this->deployment_id,
			$this->webhook_id,
			$canonical_url,
			$canonical_events,
			$this->secret
		);

		if ( ! $sync_succeeds ) {
			$output->writeln( '<error>Failed to sync webhook secret to OpsOasis.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( "<comment>Using canonical WPCOM webhook URL: $canonical_url</comment>", OutputInterface::VERBOSITY_VERBOSE );
		$output->writeln( '<comment>Using canonical WPCOM webhook events: ' . implode( ',', $canonical_events ) . '</comment>', OutputInterface::VERBOSITY_VERBOSE );
		$output->writeln( '<fg=green;options=bold>Webhook secret synced to OpsOasis successfully.</>' );
		return Command::SUCCESS;
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or WPCOM site ID:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

		return $this->ask_question( $input, $output, $question );
	}

	/**
	 * Asks a Symfony console question with the question helper.
	 *
	 * @param   InputInterface  $input    The input object.
	 * @param   OutputInterface $output   The output object.
	 * @param   Question        $question The question to ask.
	 *
	 * @throws  \RuntimeException If the Symfony question helper is unavailable.
	 *
	 * @return  mixed
	 */
	private function ask_question( InputInterface $input, OutputInterface $output, Question $question ): mixed {
		$question_helper = $this->getHelper( 'question' );
		if ( ! $question_helper instanceof \Symfony\Component\Console\Helper\QuestionHelper ) {
			throw new \RuntimeException( 'Question helper is unavailable.' );
		}

		return $question_helper->ask( $input, $output, $question );
	}
}
