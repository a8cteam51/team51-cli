<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Creates a new Pressable site collaborator on one or more sites.
 */
#[AsCommand( name: 'pressable:create-site-collaborator' )]
final class Pressable_Site_Collaborator_Create extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definitions to create the collaborator on.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The email address of the collaborator to create.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Creates a new Pressable site collaborator on one or more sites.' )
			->setHelp( "Use this command to create a new Pressable site collaborator.\n\nTo add them to a site and its related sites (the sites cloned from the same root), pass `--multiple=related`. To add them to a list of sites, pass `--multiple=<site>,<site>` and leave out the site argument: `team51 pressable:create-site-collaborator --multiple=example.com,example.net someone@example.com`." );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric Pressable ID of the site to create the collaborator on.' )
			->addArgument( 'email', InputArgument::OPTIONAL, 'The email address of the collaborator to create.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Process multiple sites. Use `related` for the given site and its related sites, or a comma-separated list of site IDs or domains.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--multiple=all` is given, or none of the listed sites exist.
	 * @throws \RuntimeException If the sites related to the given site cannot be fetched.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$multiple = $input->getOption( 'multiple' );
		if ( 'all' === $multiple ) {
			throw new \InvalidArgumentException( 'Adding a collaborator to every site is not supported. Use `related` or a comma-separated list of sites.' );
		}

		// A list of sites takes the place of the site argument, so the email lands in it instead.
		$is_site_list = ! \is_null( $multiple ) && 'related' !== $multiple;
		if ( $is_site_list && empty( $input->getArgument( 'email' ) ) && filter_var( $input->getArgument( 'site' ), FILTER_VALIDATE_EMAIL ) ) {
			$input->setArgument( 'email', $input->getArgument( 'site' ) );
			$input->setArgument( 'site', null );
		}

		if ( $is_site_list ) {
			$this->sites = $this->get_sites_from_multiple_input( $multiple );
			if ( empty( $this->sites ) ) {
				throw new \InvalidArgumentException( 'None of the given sites could be found.' );
			}
		} else {
			$site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );

			$this->sites = array( $site );
			if ( 'related' === $multiple ) {
				$related_sites = get_pressable_related_sites( $site->id );
				if ( \is_null( $related_sites ) ) {
					throw new \RuntimeException( 'Could not fetch the sites related to ' . $site->url . '.' );
				}

				$this->sites = \array_merge( ...$related_sites );
			}
		}

		$this->email = get_email_input( $input, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		if ( 1 === \count( $this->sites ) ) {
			$site     = \reset( $this->sites );
			$question = new ConfirmationQuestion( "<question>Are you sure you want to add the collaborator $this->email to {$site->displayName} (ID {$site->id}, URL {$site->url})? [y/N]</question> ", false );
		} else {
			output_table(
				$output,
				\array_map( static fn( \stdClass $site ) => array( $site->id, $site->url ), $this->sites ),
				array( 'Site ID', 'Site URL' ),
				'Pressable sites to add the collaborator to'
			);
			$question = new ConfirmationQuestion( "<question>Are you sure you want to add the collaborator $this->email to all the sites above? [y/N]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Creating collaborator $this->email on " . \count( $this->sites ) . ' Pressable site(s).</>' );

		// One site failing does not stop the others, but it does fail the command.
		$failed = 0;
		foreach ( $this->sites as $site ) {
			$collaborator = create_pressable_site_collaborator( $site->id, $this->email );
			if ( \is_null( $collaborator ) ) {
				$output->writeln( "<error>Failed to create collaborator on {$site->displayName} (ID {$site->id}, URL {$site->url}).</error>" );
				++$failed;
				continue;
			}

			$output->writeln( "<fg=green;options=bold>Collaborator created on {$site->displayName} (ID {$site->id}, URL {$site->url}).</>" );
		}

		return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
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
		$question = new Question( '<question>Enter the domain or Pressable site ID to create the collaborator on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for an email address.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_email_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email address of the collaborator to create [' . OPSOASIS_WP_USERNAME . ']:</question> ', OPSOASIS_WP_USERNAME );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : throw new \RuntimeException( 'Invalid email address.' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get sites from the multiple input option.
	 *
	 * @param   string $multiple The multiple input option.
	 *
	 * @return  \stdClass[]
	 */
	private function get_sites_from_multiple_input( string $multiple ): array {
		$site_identifiers = array_map( 'trim', explode( ',', $multiple ) );
		return array_values( array_filter( array_map( static fn( $identifier ) => get_pressable_site( $identifier ), $site_identifiers ) ) );
	}

	// endregion
}
