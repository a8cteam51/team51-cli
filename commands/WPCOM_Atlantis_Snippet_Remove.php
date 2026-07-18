<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Removes a deployed Atlantis snippet from all connected Jetpack sites.
 *
 * The removal is signed and version-bumped by OpsOasis so it cannot be replayed
 * to bring the snippet back, and it clears the snippet's materialized file on
 * each site. Run this once the upstream fix has shipped.
 */
#[AsCommand( name: 'wpcom:atlantis-snippet-remove' )]
final class WPCOM_Atlantis_Snippet_Remove extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The snippet id to remove.
	 *
	 * @var string|null
	 */
	private ?string $snippet_id = null;

	/**
	 * The removal version (must exceed the deployed version). Defaults to now.
	 *
	 * @var int|null
	 */
	private ?int $version = null;

	/**
	 * Whether to skip the confirmation prompt.
	 *
	 * @var bool|null
	 */
	private ?bool $yes = null;

	/**
	 * The list of target sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Removes a deployed Atlantis snippet from all connected Jetpack sites.' )
			->setHelp( 'Signs and pushes a removal for the given snippet id to every connected site, clearing its materialized file. The removal is version-bumped so an older deploy cannot replay it back.' );

		$this->addArgument( 'id', InputArgument::REQUIRED, 'The snippet id to remove.' );

		$this->addOption( 'version', null, InputOption::VALUE_REQUIRED, 'Removal version (must exceed the deployed version). Defaults to the current Unix timestamp.' )
			->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Limit the removal to a single site (numeric WPCOM ID or a domain).' )
			->addOption( 'yes', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--site` matches no connected site.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->snippet_id = get_string_input( $input, 'id' );

		$version_option = maybe_get_string_input( $input, 'version' );
		$this->version  = null === $version_option ? time() : (int) $version_option;
		$this->yes      = get_bool_input( $input, 'yes' );

		$this->sites = get_wpcom_jetpack_sites();
		$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );

		$site_option = $input->getOption( 'site' );
		if ( ! empty( $site_option ) ) {
			$this->sites = \array_filter(
				$this->sites,
				static fn( $site ) => (string) $site->userblog_id === (string) $site_option
					|| false !== \stripos( (string) ( $site->siteurl ?? '' ), (string) $site_option )
			);
			if ( empty( $this->sites ) ) {
				throw new \InvalidArgumentException( "No connected Jetpack site matched `$site_option`." );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( empty( $this->sites ) ) {
			$output->writeln( '<comment>No target sites.</comment>' );
			return Command::SUCCESS;
		}

		if ( ! $this->yes ) {
			$question = new ConfirmationQuestion( '<question>Remove snippet `' . $this->snippet_id . '` from ' . \count( $this->sites ) . ' site(s)? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Aborted. No snippet was removed.</comment>' );
				return Command::SUCCESS;
			}
		}

		$errors  = array();
		$results = remove_wpcom_sites_atlantis_snippet_batch(
			\array_column( $this->sites, 'userblog_id' ),
			(string) $this->snippet_id,
			(int) $this->version,
			$errors
		);

		if ( \is_null( $results ) ) {
			$output->writeln( '<error>The removal request failed.</error>' );
			return Command::FAILURE;
		}

		$rows   = array();
		$failed = 0;
		foreach ( $this->sites as $site_id => $site ) {
			$url    = $site->siteurl ?? '';
			$result = 'failed';

			if ( isset( $errors[ $site_id ] ) ) {
				$result = encode_json_content( $errors[ $site_id ]->errors ?? $errors[ $site_id ] );
				++$failed;
			} elseif ( isset( $results[ $site_id ] ) && ! empty( $results[ $site_id ]->removed ) ) {
				$result = 'removed';
			} else {
				++$failed;
			}

			$rows[] = array( $site_id, $url, $result );
		}

		output_table( $output, $rows, array( 'Site ID', 'Site URL', 'Result' ), "Removal results for `$this->snippet_id`" );

		$output->writeln( '<info>Removed: ' . ( \count( $this->sites ) - $failed ) . " | Failed: $failed</info>" );

		return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
	}

	// endregion
}
