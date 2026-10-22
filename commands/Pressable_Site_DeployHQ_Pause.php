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
 * Stops DeployHQ deploying a Pressable site on every push, or starts it again.
 */
#[AsCommand( name: 'pressable:pause-site-deployhq' )]
final class Pressable_Site_DeployHQ_Pause extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The Pressable site whose DeployHQ servers to pause.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $site = null;

	/**
	 * The GitHub repository the site deploys from.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * Whether to turn automatic deployments back on instead of off.
	 *
	 * @var bool|null
	 */
	private ?bool $resume = null;

	/**
	 * The identifiers of the servers to act on, or none for every server that deploys to the site.
	 *
	 * @var string[]
	 */
	private array $server_ids = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Stops DeployHQ deploying a Pressable site on every push, or starts it again.' )
			->setHelp( 'Use this command before connecting a site to Pressable\'s GitHub integration. It finds every DeployHQ server that deploys to the site, which is the one OpsOasis maps it to and any on a project for the repository that deploys as the site\'s SFTP owner, and turns off their automatic deployments, leaving their other settings as they are. DeployHQ\'s API cannot disable a server, and `pressable:connect-site-repository` accepts a server that no longer deploys on push. The command prints how to undo what it changed. With --resume, it turns automatic deployments back on for the servers given with --server.' );

		$this->addArgument( 'site', InputArgument::REQUIRED, 'The domain or numeric Pressable ID of the site.' )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the GitHub repository the site deploys from.' );

		$this->addOption( 'resume', null, InputOption::VALUE_NONE, 'Turn automatic deployments back on instead of off.' )
			->addOption( 'server', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The identifier of a DeployHQ server to act on. Repeatable, and required with --resume.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$input->setArgument( 'site', $this->site );

		$this->gh_repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setArgument( 'repository', $this->gh_repository );

		$this->resume     = get_bool_input( $input, 'resume' );
		$this->server_ids = \array_values( \array_unique( \array_map( 'strval', (array) $input->getOption( 'server' ) ) ) );

		// Without the list, resuming would also start servers that never deployed on push.
		if ( $this->resume && ! $this->server_ids ) {
			$output->writeln( '<error>--resume needs the servers to resume, given with --server. Aborting!</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$servers  = $this->server_ids ? \count( $this->server_ids ) . ' DeployHQ server(s)' : 'every DeployHQ server';
		$question = new ConfirmationQuestion( '<question>Turn ' . ( $this->resume ? 'on' : 'off' ) . " automatic deployments for $servers deploying {$this->gh_repository->full_name} to {$this->site->displayName} (ID {$this->site->id})? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$servers = list_deployhq_servers_for_pressable_site( $this->site, $this->gh_repository );
		if ( \is_null( $servers ) ) {
			$output->writeln( '<error>Could not read the site\'s DeployHQ servers from OpsOasis, DeployHQ or the site\'s SFTP owner.</error>' );
			return Command::FAILURE;
		}

		if ( $this->server_ids ) {
			$unknown = \array_diff( $this->server_ids, \array_keys( $servers ) );
			if ( $unknown ) {
				$output->writeln( '<error>No DeployHQ server deploying to the site has the identifier ' . \implode( ', ', $unknown ) . '.</error>' );
				return Command::FAILURE;
			}
			$servers = \array_intersect_key( $servers, \array_flip( $this->server_ids ) );
		}

		if ( ! $servers ) {
			$output->writeln( '<fg=green;options=bold>No DeployHQ server deploys to the site.</>' );
			return Command::SUCCESS;
		}

		$target  = (bool) $this->resume;
		$rows    = array();
		$changed = array();
		$grouped = array();
		$failed  = false;
		foreach ( $servers as $id => $entry ) {
			$server = $entry['server'];
			$before = (bool) ( $server->auto_deploy ?? true );
			$after  = $before;
			if ( ! empty( $server->server_group_identifier ) ) {
				$grouped[] = $server->name ?? $id;
			} elseif ( $before !== $target ) {
				$updated = set_deployhq_project_server_auto_deploy( $entry['project'], $server, $target );
				$after   = \is_null( $updated ) ? $before : (bool) ( $updated->auto_deploy ?? $before );
				if ( $after === $target ) {
					$changed[] = $id;
				} else {
					$failed = true;
				}
			}

			$rows[] = array( $entry['project'], $server->name ?? '?', $id, $server->branch ?? '?', (bool) ( $server->enabled ?? true ) ? 'yes' : 'no', $before ? 'on' : 'off', $after ? 'on' : 'off' );
		}

		output_table( $output, $rows, array( 'Project', 'Server', 'Identifier', 'Branch', 'Enabled', 'Auto deploy before', 'Auto deploy now' ), 'DeployHQ servers deploying to the site' );

		if ( $failed ) {
			$output->writeln( '<error>DeployHQ did not take the change for every server, as the table shows.</error>' );
		}
		if ( $grouped ) {
			$output->writeln( '<error>These servers belong to a DeployHQ server group, whose own settings may still deploy on push, so they were left as they are: ' . \implode( ', ', $grouped ) . '. Disable them in DeployHQ instead.</error>' );
		}
		if ( $changed && ! $this->resume ) {
			$servers_option = \implode( ' ', \array_map( static fn( string $id ): string => "--server=$id", $changed ) );
			$output->writeln( "<comment>To undo: team51 pressable:pause-site-deployhq {$this->site->id} {$this->gh_repository->name} --resume $servers_option</comment>" );
		}

		return $failed || $grouped ? Command::FAILURE : Command::SUCCESS;
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

	/**
	 * Prompts the user for a GitHub repository slug.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the slug of the GitHub repository the site deploys from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( get_github_repository_autocomplete_values() );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
