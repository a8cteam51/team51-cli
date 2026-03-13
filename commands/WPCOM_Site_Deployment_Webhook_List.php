<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Lists deployment webhooks for a WordPress.com site deployment.
 */
#[AsCommand( name: 'wpcom:site:deployment:webhook:list' )]
final class WPCOM_Site_Deployment_Webhook_List extends Command {
	use AutocompleteTrait;

	/**
	 * WPCOM site definition to list webhooks for.
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
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists deployment webhooks for a WordPress.com code deployment.' )
			->setHelp( 'Use this command to list webhooks configured for a WPCOM code deployment.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'Domain or WPCOM ID of the site.' )
			->addArgument( 'deployment_id', InputArgument::OPTIONAL, 'The code deployment ID. If omitted, the command auto-selects from deployments for the site.' );
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
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$webhooks = get_wpcom_site_code_deployment_webhooks( (string) $this->site->ID, $this->deployment_id );
		if ( \is_null( $webhooks ) ) {
			$output->writeln( '<error>Failed to list deployment webhooks.</error>' );
			return Command::FAILURE;
		}

		if ( empty( $webhooks ) ) {
			$output->writeln( '<comment>No deployment webhooks are configured for this deployment.</comment>' );
			return Command::SUCCESS;
		}

		output_table(
			$output,
			array_map(
				static fn( \stdClass $webhook ) => array(
					$webhook->id ?? '',
					$webhook->url ?? '',
					is_array( $webhook->events ?? null ) ? implode( ',', $webhook->events ) : ( $webhook->events ?? '' ),
					$webhook->active ?? '',
				),
				$webhooks
			),
			array( 'Webhook ID', 'URL', 'Events', 'Active' ),
			'WPCOM deployment webhooks'
		);

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

		$question = new ChoiceQuestion( '<question>Select the code deployment to inspect:</question> ', $choices, 0 );
		$question->setErrorMessage( 'Deployment %s is invalid.' );
		$selected_choice = $this->ask_question( $input, $output, $question );

		return $choice_to_id[ $selected_choice ];
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
