<?php

declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Creates a deployment webhook for a WordPress.com site deployment.
 */
#[AsCommand( name: 'wpcom:site:deployment:webhook:create' )]
final class WPCOM_Site_Deployment_Webhook_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * WPCOM site definition to create the webhook for.
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
	 * Webhook destination URL.
	 *
	 * @var string|null
	 */
	private ?string $url = null;

	/**
	 * Comma-separated event list.
	 *
	 * @var string|null
	 */
	private ?string $events = null;

	/**
	 * Whether to sync webhook secret to OpsOasis.
	 *
	 * @var bool
	 */
	private bool $sync_secret = true;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a deployment webhook for a WordPress.com code deployment.' )
			->setHelp( 'Use this command to create a WPCOM code deployment webhook and persist its one-time secret to OpsOasis.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site.' )
			->addArgument( 'deployment_id', InputArgument::OPTIONAL, 'The code deployment ID. If omitted, the command auto-selects from deployments for the site.' );

		$this->addOption( 'url', null, InputOption::VALUE_REQUIRED, 'Webhook destination URL.', get_wpcom_site_code_deployment_webhook_default_url() )
			->addOption( 'events', null, InputOption::VALUE_REQUIRED, 'Comma-separated webhook events to subscribe to.', get_wpcom_site_code_deployment_webhook_default_events() )
			->addOption( 'sync-secret', null, InputOption::VALUE_NEGATABLE, 'Persist the one-time webhook secret in OpsOasis immediately.', true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->deployment_id = maybe_get_string_input( $input, 'deployment_id' );
		if ( is_null( $this->deployment_id ) ) {
			$this->deployment_id = $this->resolve_deployment_id( $input, $output );
		}
		$input->setArgument( 'deployment_id', $this->deployment_id );

		$this->url = get_string_input( $input, 'url', fn() => $this->prompt_url_input( $input, $output ) );
		$input->setOption( 'url', $this->url );

		$this->events = get_string_input( $input, 'events', fn() => $this->prompt_events_input( $input, $output ) );
		$input->setOption( 'events', $this->events );

		$this->sync_secret = get_bool_input( $input, 'sync-secret' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating deployment webhook for site `{$this->site->name}` (ID {$this->site->ID}) and deployment {$this->deployment_id}.</>" );

		$webhook_response = create_wpcom_site_code_deployment_webhook(
			(string) $this->site->ID,
			$this->deployment_id,
			$this->url,
			$this->events
		);
		if ( \is_null( $webhook_response ) ) {
			$output->writeln( '<error>Failed to create deployment webhook.</error>' );
			return Command::FAILURE;
		}

		$webhook = get_wpcom_site_code_deployment_webhook_from_response( $webhook_response );
		$secret  = get_wpcom_site_code_deployment_webhook_secret_from_response( $webhook_response );
		if ( \is_null( $secret ) || '' === trim( $secret ) ) {
			$output->writeln( '<error>Webhook was created but no secret was returned by WPCOM. The secret can only be read on creation.</error>' );
			return Command::FAILURE;
		}

		$webhook_url    = $webhook->url ?? $this->url;
		$webhook_events = normalize_wpcom_site_code_deployment_webhook_events( $webhook->events ?? $this->events );

		$secret_sync_status = 'skipped';
		if ( $this->sync_secret ) {
			$secret_sync_succeeded = true === sync_wpcom_site_code_deployment_webhook_secret(
				(string) $this->site->ID,
				$this->deployment_id,
				(string) $webhook->id,
				$webhook_url,
				$webhook_events,
				$secret
			);

			if ( ! $secret_sync_succeeded ) {
				$output->writeln( '<error>Webhook was created, but syncing the webhook secret to OpsOasis failed.</error>' );
				$this->output_manual_sync_payload( $output, (string) $webhook->id, $webhook_url, $webhook_events, $secret );
				return Command::FAILURE;
			}

			$secret_sync_status = 'yes';
		} else {
			$output->writeln( '<comment>Secret sync skipped. Store this secret in OpsOasis immediately.</comment>' );
			$this->output_manual_sync_payload( $output, (string) $webhook->id, $webhook_url, $webhook_events, $secret );
		}

		output_table(
			$output,
			array(
				array(
					(string) $this->site->ID,
					$this->deployment_id,
					(string) $webhook->id,
					is_array( $webhook->events ?? null ) ? implode( ',', $webhook->events ) : ( $webhook->events ?? $this->events ),
					$secret_sync_status,
				),
			),
			array( 'Site ID', 'Deployment ID', 'Webhook ID', 'Events', 'Secret sync succeeded' ),
			'Created WPCOM deployment webhook'
		);

		$output->writeln( '<fg=green;options=bold>Deployment webhook created successfully.</>' );
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
	 * Prompts for the deployment ID.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_deployment_id_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the code deployment ID:</question> ' );
		return $this->ask_question( $input, $output, $question );
	}

	/**
	 * Resolves the deployment ID for the selected site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @throws  \InvalidArgumentException If no code deployments are found or they cannot be retrieved.
	 *
	 * @return  string
	 */
	private function resolve_deployment_id( InputInterface $input, OutputInterface $output ): string {
		$deployments = get_wpcom_site_code_deployments( (string) $this->site->ID );
		if ( \is_null( $deployments ) ) {
			throw new \InvalidArgumentException( 'Failed to retrieve code deployments for this site.' );
		}

		if ( empty( $deployments ) ) {
			throw new \InvalidArgumentException( 'No code deployments found for this site.' );
		}

		if ( 1 === count( $deployments ) ) {
			$deployment = reset( $deployments );
			$output->writeln( "<comment>Using site deployment {$deployment->id} ({$deployment->repository_name}).</comment>" );
			return (string) $deployment->id;
		}

		$choices      = array();
		$choice_to_id = array();
		foreach ( $deployments as $deployment ) {
			$choice                  = sprintf(
				'%s (ID %s%s)',
				$deployment->repository_name ?? 'deployment',
				$deployment->id,
				isset( $deployment->branch_name ) ? ", branch {$deployment->branch_name}" : ''
			);
			$choices[]               = $choice;
			$choice_to_id[ $choice ] = (string) $deployment->id;
		}

		$question = new ChoiceQuestion( '<question>Select the code deployment to use:</question> ', $choices, 0 );
		$question->setErrorMessage( 'Deployment %s is invalid.' );
		$selected_choice = $this->ask_question( $input, $output, $question );

		return $choice_to_id[ $selected_choice ];
	}

	/**
	 * Prompts for the webhook destination URL.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_url_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question(
			'<question>Enter the webhook destination URL:</question> ',
			get_wpcom_site_code_deployment_webhook_default_url()
		);
		return $this->ask_question( $input, $output, $question );
	}

	/**
	 * Prompts for the events to subscribe to.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_events_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question(
			'<question>Enter comma-separated webhook events:</question> ',
			get_wpcom_site_code_deployment_webhook_default_events()
		);
		return $this->ask_question( $input, $output, $question );
	}

	/**
	 * Outputs a structured payload that can be used for immediate manual secret sync.
	 *
	 * @param   OutputInterface $output     The output object.
	 * @param   string          $webhook_id The webhook ID.
	 * @param   string          $url        The webhook URL.
	 * @param   array           $events     The webhook events.
	 * @param   string          $secret     The webhook secret.
	 *
	 * @return  void
	 */
	private function output_manual_sync_payload( OutputInterface $output, string $webhook_id, string $url, array $events, string $secret ): void {
		$output->writeln(
			encode_json_content(
				array(
					'site_id'       => (int) $this->site->ID,
					'deployment_id' => $this->deployment_id,
					'webhook_id'    => $webhook_id,
					'url'           => $url,
					'events'        => $events,
					'secret'        => $secret,
				)
			)
		);
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

	// endregion
}
