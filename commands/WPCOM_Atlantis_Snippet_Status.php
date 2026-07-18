<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Reports which Atlantis snippets are deployed across connected Jetpack sites.
 *
 * Use it to confirm a deploy or removal landed everywhere, to spot drift
 * (stragglers on an old version, or sites that missed a removal), and to surface
 * quarantined or repeatedly-failing snippets.
 */
#[AsCommand( name: 'wpcom:atlantis-snippet-status' )]
final class WPCOM_Atlantis_Snippet_Status extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	/**
	 * The per-site snippet payloads keyed by site ID.
	 *
	 * @var array|null
	 */
	private ?array $statuses = null;

	/**
	 * Per-site errors from the batch call.
	 *
	 * @var array|null
	 */
	private ?array $errors = null;

	/**
	 * Restrict the report to a single snippet id, if set.
	 *
	 * @var string|null
	 */
	private ?string $only_snippet = null;

	/**
	 * Whether to only show rows that need attention (not active, or failing).
	 *
	 * @var bool|null
	 */
	private ?bool $issues_only = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Reports deployed Atlantis snippets across connected Jetpack sites.' )
			->setHelp( 'Calls the OpsOasis batch endpoint for the Atlantis snippet inventory and prints a per-site, per-snippet report. Use --snippet to focus one id, or --issues-only to show just quarantined/failing snippets.' );

		$this->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single site (numeric WPCOM ID or URL).' )
			->addOption( 'snippet', null, InputOption::VALUE_REQUIRED, 'Restrict the report to a single snippet id.' )
			->addOption( 'issues-only', null, InputOption::VALUE_NONE, 'Only show snippets that are not active or have recorded failures.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException If `--site` matches no connected site.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->only_snippet = maybe_get_string_input( $input, 'snippet' );
		$this->issues_only  = get_bool_input( $input, 'issues-only' );

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

		$this->statuses = get_wpcom_sites_atlantis_snippet_status_batch( \array_column( $this->sites, 'userblog_id' ), $this->errors );
		maybe_output_wpcom_failed_sites_table( $output, $this->errors ?? array(), $this->sites, 'Sites that could NOT be queried for snippet status' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$rows = array();

		foreach ( $this->sites as $site_id => $site ) {
			if ( ! isset( $this->statuses[ $site_id ] ) ) {
				continue; // Reported in the failed-sites table during initialize().
			}

			$url      = $site->siteurl ?? '';
			$snippets = $this->statuses[ $site_id ]->snippets ?? array();

			if ( empty( $snippets ) ) {
				if ( ! $this->issues_only && null === $this->only_snippet ) {
					$rows[] = array( $site_id, $url, '(none)', '', '', '' );
				}
				continue;
			}

			foreach ( $snippets as $snippet ) {
				if ( null !== $this->only_snippet && $snippet->snippet_id !== $this->only_snippet ) {
					continue;
				}

				$needs_attention = 'active' !== ( $snippet->status ?? '' ) || 0 < (int) ( $snippet->fail_count ?? 0 );
				if ( $this->issues_only && ! $needs_attention ) {
					continue;
				}

				$rows[] = array(
					$site_id,
					$url,
					$snippet->snippet_id ?? '',
					(string) ( $snippet->version ?? '' ),
					$snippet->status ?? '',
					(string) ( $snippet->fail_count ?? 0 ),
				);
			}
		}

		if ( empty( $rows ) ) {
			$output->writeln( '<comment>No matching snippets found.</comment>' );
			return Command::SUCCESS;
		}

		output_table(
			$output,
			$rows,
			array( 'Site ID', 'Site URL', 'Snippet', 'Version', 'Status', 'Fails' ),
			'Deployed Atlantis snippets'
		);

		return Command::SUCCESS;
	}

	// endregion
}
