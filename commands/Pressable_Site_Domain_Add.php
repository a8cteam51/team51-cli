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

/**
 * Creates a new Pressable site collaborator.
 */
#[AsCommand( name: 'pressable:add-site-domain' )]
final class Pressable_Site_Domain_Add extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Pressable site definition to add the domain to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The domain to add to the site.
	 *
	 * @var string|null
	 */
	private ?string $domain = null;

	/**
	 * Whether to set the domain as primary.
	 *
	 * @var bool
	 */
	private ?bool $primary = false;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Adds a given domain to a given Pressable site and optionally sets it as primary.' )
			->setHelp( 'This command allows you to add a new domain to a Pressable site. If the given domain is to also be set as primary, then any 1Password entries using the old URL will be updated as well.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the site to add the domain to.' )
			->addArgument( 'domain', InputArgument::REQUIRED, 'The domain to add to the site.' )
			->addOption( 'primary', null, InputOption::VALUE_NONE, 'Set the given domain as the primary one.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->domain = get_domain_input( $input, $output, fn() => $this->prompt_domain_input( $input, $output ) );
		$input->setArgument( 'domain', $this->domain );

		$this->primary = filter_var(
			get_enum_input( $input, $output, 'primary', array( true, false ), fn() => $this->prompt_primary_input( $input, $output ), false ),
			FILTER_VALIDATE_BOOLEAN
		);
		$input->setOption( 'primary', $this->primary );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		// Force the domain to be primary if the site has no primary domain.
		if ( ! $this->primary && $this->maybe_force_primary_option( $this->site->id, $this->primary ) ) {
			$this->primary = true;
			$output->writeln( '<comment>There is no primary domain for the site. Setting the domain as primary.</comment>' );
		}

		$primary  = $this->primary ? ' and set it as primary' : '';
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the domain $this->domain to {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url})$primary? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		// If the site is a staging one, confirm that it's ok to convert it to a live one.
		if ( $this->site->staging ) {
			$question = new ConfirmationQuestion( "<question>{$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}) is a staging site. Adding a domain will first convert it to a live site. Continue? [y/N]</question> ", false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		
		$output->writeln( "<fg=magenta;options=bold>Adding the domain $this->domain on {$this->site->displayName} (ID {$this->site->id}, URL {$this->site->url}).</>" );

		// First convert the site to a live site, if needed.
		if ( $this->site->staging ) {
			$output->writeln( '<comment>Converting to live site.</comment>', OutputInterface::VERBOSITY_VERBOSE );

			$result = convert_pressable_site( $this->site->id );
			if ( \is_null( $result ) ) {
				$output->writeln( '<error>Failed to convert staging site to live site.</error>' );
				return 1;
			}

			$output->writeln( '<fg=green;options=bold>Converted staging site to live site.</>' );
		} else {
			$output->writeln( '<comment>Given site already supports domains. No conversion from staging site to live site required.</comment>', OutputInterface::VERBOSITY_VERY_VERBOSE );
		}
		
		// Add the new domain to the site.
		$output->writeln( '<comment>Adding domain to site.</comment>', OutputInterface::VERBOSITY_VERBOSE );

		$site_domains = add_pressable_site_domain( $this->site->id, $this->domain );
		if ( \is_null( $site_domains ) ) {
			$output->writeln( '<error>Failed to add the domain.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>Domain added to site.</>' );

		// If the new domain is the primary domain, set it as such and update 1Password URLs.
		if ( $this->primary ) {
			$new_domain = $this->find_domain_object( $site_domains );
			if ( \is_null( $new_domain ) ) {
				$output->writeln( '<error>Failed to find the newly added domain in the list of site domains.</error>' );
				return 1;
			}

			// Set the new domain as the primary domain.
			if ( $new_domain->primary ) {
				$output->writeln( '<fg=green;options=bold>New domain already set as primary.</>' );
			} else {
				$output->writeln( '<comment>Setting domain as primary.</comment>', OutputInterface::VERBOSITY_VERBOSE );

				$new_domain = set_pressable_site_primary_domain( $this->site->id, $new_domain->id );
				if ( \is_null( $new_domain ) ) {
					$output->writeln( '<error>Failed to set primary domain.</error>' );
					return 1;
				}

				$output->writeln( '<fg=green;options=bold>Domain set as primary.</>' );
			}

			// Perform a few URL-change-related tasks.
			if ( ! is_case_insensitive_match( $this->site->url, $new_domain->domainName ) ) { // This should always be true, but just in case.
				// Run a search-replace on the database for completion/correction.
				$output->writeln( '<comment>Running search-replace via WP-CLI.</comment>', OutputInterface::VERBOSITY_VERBOSE );

				/* @noinspection PhpUnhandledExceptionInspection */
				$search_replace_result = run_pressable_site_wp_cli_command( $this->site->id, "search-replace {$this->site->url} $new_domain->domainName" );
				if ( 0 !== $search_replace_result ) {
					$output->writeln( '<error>Failed to run search-replace via WP-CLI. Please run it manually!</error>' );
				} else {
					$output->writeln( '<fg=green;options=bold>Search-replace via WP-CLI completed.</>' );

					/* @noinspection PhpUnhandledExceptionInspection */
					$cache_flush_result = run_pressable_site_wp_cli_command( $this->site->id, 'cache flush' );
					if ( 0 !== $cache_flush_result ) {
						$output->writeln( '<error>Failed to flush object cache via WP-CLI. Please flush it manually!</error>' );
					} else {
						$output->writeln( '<fg=green;options=bold>Object cache flushed via WP-CLI.</>' );
					}
				}

				// Update 1Password URLs.
				$output->writeln( '<comment>Updating site URL in 1Password.</comment>', OutputInterface::VERBOSITY_VERBOSE );

				$op_login_entries = search_1password_items(
					fn( object $op_item ) => is_1password_item_url_match( $op_item, $this->site->url ),
					array(
						'categories' => 'login',
						'tags'       => 'team51-cli',
					)
				);
				$output->writeln( \sprintf( '<comment>Found %d login entries in 1Password that require a URL update.</comment>', \count( $op_login_entries ) ), OutputInterface::VERBOSITY_DEBUG );

				$this->site = get_pressable_site( $this->site->id ); // Refresh the site data. The displayName field is likely to have changed.
				foreach ( $op_login_entries as $op_login_entry ) {
					$output->writeln( "<info>Updating 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id).</info>", OutputInterface::VERBOSITY_VERY_VERBOSE );

					$result = update_1password_item(
						$op_login_entry->id,
						array(
							'title' => $this->site->displayName,
							'url'   => "https://$new_domain->domainName/wp-admin",
						)
					);
					if ( \is_null( $result ) ) {
						$output->writeln( "<error>Failed to update 1Password login entry <fg=cyan;options=bold>$op_login_entry->title</> (ID $op_login_entry->id). Please update manually!</error>" );
					}
				}

				$output->writeln( '<fg=green;options=bold>Relevant 1Password login entries have been updated.</>' );
			}
		}

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
		$question = new Question( '<question>Enter the domain or Pressable site ID to add the domain to:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a domain.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_domain_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain to add to the site:</question> ' );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_DOMAIN ) ? $value : throw new \RuntimeException( 'Invalid domain.' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a primary domain.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  bool
	 */
	private function prompt_primary_input( InputInterface $input, OutputInterface $output ): bool {
		$question = new ConfirmationQuestion( '<question>Set the domain as primary? [y/N]</question> ', false );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Forces the primary option to be true if the site has no primary domain.
	 *
	 * @param   string  $site_id  The ID of the site.
	 * @param   bool    $primary  The primary option.
	 *
	 * @return  bool
	 */
	public function maybe_force_primary_option( string $site_id, bool $primary ): bool {
		$force = false;
		if ( ! $primary && is_null( get_pressable_site_primary_domain( $site_id ) ) ) {
			$force = true;
		}
		return $force;
	}

	/**
	 * Returns the Pressable domain object corresponding to the newly added domain.
	 *
	 * @param   array   $site_domains   The list of domains that the site currently has.
	 *
	 * @return  object|null
	 */
	private function find_domain_object( array $site_domains ): ?object {
		foreach ( $site_domains as $site_domain ) {
			if ( ! is_case_insensitive_match( $this->domain, $site_domain->domainName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			return $site_domain;
		}

		return null;
	}

	// endregion
}
