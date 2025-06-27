<?php
/**
 * SSH Worker Command
 *
 * Handles SSH execution for WPCOM and Pressable sites.
 *
 * @package WPCOMSpecialProjects\CLI\Command
 */

declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Command;

use InvalidArgumentException;
use Symfony\Component\Console\{
	Command\Command,
	Input\InputInterface,
	Input\InputOption,
	Output\OutputInterface,
	Attribute\AsCommand
};
use phpseclib3\Net\SSH2;

use Pressable_Connection_Helper;
use WPCOM_Connection_Helper;
use WPCOMSpecialProjects\CLI\Enums\Site_Type;
/**
 * SSH Worker Command
 */
#[AsCommand( name: 'ssh-worker', hidden: true )]
class SSH_Worker extends Command {

	// region FIELDS AND CONSTANTS

	/**
	 * Site UUID
	 *
	 * @var string
	 */
	protected string $site_id;

	/**
	 * Site type i.e. wpcom or pressable
	 *
	 * @var Site_Type
	 */
	protected Site_Type $site_type;

	/**
	 * Site URL
	 *
	 * @var string|null
	 */
	protected ?string $site_url = null;

	/**
	 * Command to execute in shell
	 *
	 * @var string
	 */
	protected string $shell_command;

	/**
	 * Timeout for SSH connection
	 *
	 * @var int
	 */
	protected int $timeout = 60;

	// endregion

	// region INHERITED METHODS

	/**
	 * Configures the Symfony Console command.
	 */
	protected function configure(): void {
		$this
			->setDescription( 'SSH Worker for processing shell commands' )
			// Required.
			->addOption( 'site-id', null, InputOption::VALUE_REQUIRED, 'The site ID to SSH into' )
			->addOption( 'site-type', null, InputOption::VALUE_REQUIRED, 'Site type (wpcom or pressable)' )
			->addOption( 'shell-command', null, InputOption::VALUE_REQUIRED, 'The shell command to execute' )
			// Optional.
			->addOption( 'site-url', null, InputOption::VALUE_OPTIONAL, 'The site url used for Pressable sites' )
			->addOption( 'timeout', null, InputOption::VALUE_OPTIONAL, 'The timeout for the SSH connection', 60 );
	}

	/**
	 * Initializes the command.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @throws InvalidArgumentException If site type is invalid.
	 * @throws InvalidArgumentException If site ID or shell command is missing.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site_id = $input->getOption( 'site-id' );
		$site_type_str = $input->getOption( 'site-type' );

		// Safely convert string to enum
		$this->site_type = Site_Type::tryFrom( $site_type_str )
			?? throw new InvalidArgumentException(
				json_encode(
					array(
						'error'   => 'invalid_site_type',
						'details' => 'Site type must be one of: '
							. implode( ', ', array_column( Site_Type::cases(), 'value' ) ),
					)
				)
			);

		$this->site_url      = $input->getOption( 'site-url' );
		$this->shell_command = $input->getOption( 'shell-command' );
		$this->timeout       = (int) $input->getOption( 'timeout' );

		if ( ! $this->site_id || ! $this->shell_command ) {
			throw new InvalidArgumentException(
				json_encode(
					array(
						'error'   => 'missing_required_param',
						'details' => sprintf(
							'Required: site-id: %s, shell-command: %s',
							$this->site_id,
							$this->shell_command
						),
					)
				)
			);
		}
	}

	/**
	 * Executes the SSH command.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @return int Command exit status.
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$pressable_site_id = null;
		try {
			$pressable_site_id = Site_Type::PRESSABLE === $this->site_type ? $this->get_pressable_site_id() : null;
			// If there is an error getting the Pressable site ID lets return
			// since the error is already emitted.
			if ( 1 === $pressable_site_id ) {
				return Command::FAILURE;
			}

			$ssh = $this->get_ssh_connection( $this->site_id, (string) $pressable_site_id );
			if ( ! $ssh ) {
				return $this->emit(
					array(
						'error'             => $this->site_type->value . '_ssh_failed', // wpcom_ssh_failed or pressable_ssh_failed
						'pressable_site_id' => $pressable_site_id,
						'details'           => sprintf( 'Could not establish %s SSH connection', strtoupper( $this->site_type->value ) ),
					)
				);
			}

			$ssh->setTimeout( $this->timeout );

			$wp_cli_command = sprintf( $this->shell_command );
			$result         = '';

			try {
				$ssh->exec(
					$wp_cli_command,
					function ( $str ) use ( &$result ) {
						$result = $str;
					}
				);
			} catch ( \Exception $e ) {
				return $this->emit(
					array(
						'error'             => 'wp_cli_command_failed',
						'pressable_site_id' => $pressable_site_id,
						'details'           => $e->getMessage(),
					)
				);
			} finally {
				$ssh->disconnect();
			}

			if ( empty( $result ) ) {
				return $this->emit(
					array(
						'error'             => 'empty_result',
						'pressable_site_id' => $pressable_site_id,
						'details'           => 'No response received from shell command.',
					)
				);
			}

			// Valid responses are JSON encoded. Test for errors first.
			$data = json_decode( $result, true );
			if ( ! $data ) {
				return $this->emit(
					array(
						'error'             => 'invalid_json',
						'pressable_site_id' => $pressable_site_id,
						'type'              => $this->site_type,
						'details'           => $result,
					)
				);
			}
			return $this->emit(
				array(
					'code'              => 'success',
					'pressable_site_id' => $pressable_site_id,
					'type'              => $this->site_type,
					'data'              => $data,
				),
				false
			);
		} catch ( \Exception $e ) {
			return $this->emit(
				array(
					'error'             => 'unknown_error',
					'pressable_site_id' => $pressable_site_id,
					'details'           => $e->getMessage(),
				)
			);
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Closure that buffers messages to output and returns a success or failure status.
	 *
	 * @param array $result The result of the SSH command.
	 * @param bool  $failed Whether the command failed.
	 *
	 * @return int Returns a success or failure status.
	 */
	private function emit( $result, $failed = true ): int {
		echo json_encode(
			array(
				...$result,
				'site_type' => $this->site_type->value,
				'site_id'   => $this->site_id,
			)
		);
		return $failed ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * Establishes an SSH connection.
	 *
	 * @param string $site_id      The site ID.
	 * @param string $pressable_id The Pressable site ID (if applicable).
	 *
	 * @return SSH2|null SSH connection instance or null if unavailable.
	 */
	protected function get_ssh_connection( string $site_id, ?string $pressable_id = null ): ?SSH2 {
		return match ( $this->site_type->value ) {
			'wpcom'     => WPCOM_Connection_Helper::get_ssh_connection( $site_id ),
			'pressable' => $pressable_id ? Pressable_Connection_Helper::get_ssh_connection( $pressable_id ) : null,
			default     => null,
		};
	}

	/**
	 * Gets the Pressable site ID.
	 *
	 * @return int|null Null if not a Pressable site, 1 on error and otherwise the Pressable site ID on success..
	 */
	protected function get_pressable_site_id(): int|null {
		$pressable_site_id = null;
		try {
			$pressable_site = get_pressable_site( $this->site_url );
			if ( ! $pressable_site ) {
				return $this->emit(
					array(
						'error'             => 'get_pressable_site_not_found',
						'pressable_site_id' => 0,
						'details'           => 'Error occurred while fetching Pressable site info for domain: ' . $this->site_url,
					)
				);
			}
			$pressable_site_id = $pressable_site->id;
		} catch ( \Exception $e ) {
			return $this->emit(
				array(
					'error'             => 'get_pressable_site_failed',
					'pressable_site_id' => $pressable_site_id,
					'details'           => 'Error occurred while fetching Pressable site info for domain: ' . $this->site_url,
				)
			);
		}
		return $pressable_site_id;
	}

	// endregion
}
