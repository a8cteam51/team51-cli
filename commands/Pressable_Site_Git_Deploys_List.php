<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Lists a Pressable site's GitHub integration settings and its most recent deploys.
 */
#[AsCommand( name: 'pressable:list-site-git-deploys' )]
final class Pressable_Site_Git_Deploys_List extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The Pressable site to list the deploys of.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * How many deploys to list.
	 *
	 * @var int|null
	 */
	private ?int $limit = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Lists a Pressable site\'s GitHub integration settings and its most recent deploys.' )
			->setHelp( 'Use this command to see which repository and branch a Pressable site deploys from, and how its recent deploys went.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'How many deploys to list, at most 50.', '10' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--limit` is not a whole number.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$limit = (string) $input->getOption( 'limit' );
		if ( ! \ctype_digit( $limit ) ) {
			throw new \InvalidArgumentException( "The `limit` option must be a whole number, not `$limit`." );
		}

		$this->limit = \max( 1, \min( 50, (int) $limit ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$git = get_pressable_site_git_config( $this->site->id );
		if ( \is_null( $git ) ) {
			$output->writeln( '<error>Failed to read the site\'s GitHub integration settings.</error>' );
			return Command::FAILURE;
		}

		output_table(
			$output,
			array(
				array(
					$git->repository ?? '',
					$git->branch ?? '',
					$git->destinationPath ?? '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$git->repositorySubdirectory ?? '', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					empty( $git->connected ) ? 'no' : 'yes',
					empty( $git->hasToken ) ? 'no' : 'yes', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				),
			),
			array( 'Repository', 'Branch', 'Destination path', 'Repository subdirectory', 'Connected', 'Has token' ),
			"GitHub integration of {$this->site->displayName} (ID {$this->site->id})"
		);

		$deploys = get_pressable_site_git_deploys( $this->site->id, $this->limit );
		if ( \is_null( $deploys ) ) {
			$output->writeln( '<error>Failed to read the site\'s deploys.</error>' );
			return Command::FAILURE;
		}

		output_table(
			$output,
			\array_map(
				static fn( \stdClass $deploy ): array => array(
					(string) ( $deploy->created ?? '' ),
					(string) ( $deploy->state ?? '' ),
					(string) ( $deploy->branch ?? '' ),
					\substr( (string) ( $deploy->after ?? '' ), 0, 7 ),
					(string) ( $deploy->name ?? '' ),
					(string) ( $deploy->message ?? '' ),
				),
				$deploys
			),
			array( 'Created', 'State', 'Branch', 'Commit', 'Author', 'Message' ),
			'Recent deploys, newest first'
		);

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
		$question = new Question( '<question>Enter the domain or Pressable site ID:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
