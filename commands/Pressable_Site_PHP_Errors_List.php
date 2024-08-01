<?php

namespace WPCOMSpecialProjects\CLI\Command;

use phpseclib3\Net\SSH2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * CLI command for displaying the latest PHP errors of a Pressable site.
 */
#[AsCommand( name: 'pressable:list-site-php-errors' )]
final class Pressable_Site_PHP_Errors_List extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether to check all the sites for the ones with the highest number of errors.
	 *
	 * @var bool|null
	 */
	protected ?bool $is_audit = null;

	/**
	 * The Pressable site to display the errors for.
	 *
	 * @var \stdClass[]|null
	 */
	protected ?array $sites = null;

	/**
	 * The number of distinct errors to retrieve.
	 *
	 * @var int|null
	 */
	protected ?int $limit = null;

	/**
	 * The format to output the errors in.
	 *
	 * @var string|null
	 */
	protected ?string $format = null;

	/**
	 * The error severity to filter by.
	 *
	 * @var string|null
	 */
	protected ?string $severity = null;

	/**
	 * Where to retrieve the PHP errors from.
	 *
	 * @var string|null
	 */
	protected ?string $source = null;

	/**
	 * The default path to the PHP error log file.
	 *
	 * @var string
	 */
	protected const DEFAULT_PHP_ERROR_LOG_PATH = '/tmp/php-errors';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Displays the most recent PHP errors for a given Pressable site.' )
			->setHelp( 'This command allows you to figure out what is preventing a website from loading.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'ID or URL of the site to display the errors for.' )
			->addOption( 'limit', null, InputOption::VALUE_REQUIRED, 'The number of distinct PHP fatal errors to return.', 5 )
			->addOption( 'format', null, InputOption::VALUE_REQUIRED, 'The format to output the logs in. Accepts either `list`, `table` or `raw`.', 'list' )
			->addOption( 'severity', null, InputOption::VALUE_REQUIRED, 'The error severity to filter by. Valid values are "User", "Warning", "Deprecated", and "Fatal error". Default all.' )
			->addOption( 'source', null, InputOption::VALUE_REQUIRED, 'Where to retrieve the PHP errors from. Accepts either `file`, `api`, or `auto`.', 'auto' )
			->addOption( 'audit', null, InputOption::VALUE_NONE, 'Whether to check all the sites for the ones with the highest number of errors.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the site(s) to display the errors for.
		$this->is_audit = get_bool_input( $input, $output, 'audit' );

		if ( $this->is_audit ) {
			$sites       = get_pressable_sites();
			$this->sites = \array_combine(
				\array_column( $sites, 'id' ),
				$sites
			);
		} else {
			$site = get_pressable_site_input( $input, $output, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );

			$this->sites = array( $site->id => $site );
		}

		// Retrieve and validate the modifier options.
		$this->limit    = max( 1, (int) $input->getOption( 'limit' ) );
		$this->format   = get_enum_input( $input, $output, 'format', array( 'list', 'table', 'raw' ) );
		$this->severity = get_enum_input( $input, $output, 'severity', array( 'User', 'Warning', 'Deprecated', 'Fatal error' ) );
		$this->source   = get_enum_input( $input, $output, 'source', array( 'file', 'api', 'auto' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if ( $this->is_audit ) {
			$output->writeln( '<fg=magenta;options=bold>Performing an audit of PHP errors on all Pressable sites.</>' );
		}

		$stats_tables = array();
		foreach ( $this->sites as $site ) {
			$output->writeln( "<fg=magenta;options=bold>Analyzing the PHP errors on $site->displayName (ID $site->id, URL $site->url) from $this->source.</>" );

			$php_errors = $this->get_php_errors( $output, $site );
			if ( \is_null( $php_errors ) ) {
				$output->writeln( '<error>Could not retrieve the PHP errors.</error>' );
				continue;
			}
			if ( 0 === \count( $php_errors ) ) {
				$output->writeln( '<info>The PHP error log appears to be empty. Go make some errors and try again!</info>' );
				continue;
			}

			$stats_table               = $this->analyze_log_entries( $php_errors );
			$stats_tables[ $site->id ] = (object) array(
				'raw'   => $php_errors,
				'stats' => $stats_table,
			);
		}

		\uasort(
			$stats_tables,
			static fn ( object $a, object $b ) => \count( $b->stats ) <=> \count( $a->stats )
		);

		if ( $this->is_audit ) {
			$stats_tables = \array_slice( $stats_tables, 0, 10, true ); // Only show the top 10 sites with the most errors.

			$output->writeln( '<fg=magenta;options=bold>Results of the PHP errors audit:</>' );
			$output->writeln( '' );

			foreach ( $stats_tables as $site_id => $stats_table ) {
				$count = \count( $stats_table->stats );
				$output->writeln( "<fg=magenta;options=bold>Found $count unique errors on {$this->sites[ $site_id ]->displayName} (ID $site_id, URL {$this->sites[ $site_id ]->url}) from $this->source</>" );

				$stats_table_limit = \array_slice( $stats_table->stats, 0, $this->limit );
				if ( 'table' === $this->format ) {
					$this->output_table_error_log( $stats_table_limit, $output );
				} elseif ( 'list' === $this->format ) {
					$this->output_list_error_log( $stats_table_limit, $output );
				} elseif ( 'raw' === $this->format ) {
					$this->output_raw_error_log( $stats_table->raw, $output );
				}
			}
		} else {
			$site        = \reset( $this->sites );
			$stats_table = $stats_tables[ \array_key_first( $stats_tables ) ];

			if ( 'raw' === $this->format ) {
				$output->writeln( "<fg=magenta;options=bold>Listing the raw PHP errors on $site->displayName (ID $site->id, URL $site->url) from $this->source.</>" );
				$this->output_raw_error_log( $stats_table->raw, $output );
			} else {
				$output->writeln( "<fg=magenta;options=bold>Listing the last $this->limit distinct PHP errors on $site->displayName (ID $site->id, URL $site->url) from $this->source.</>" );

				$stats_table_limit = \array_slice( $stats_table->stats, 0, $this->limit );
				if ( 'table' === $this->format ) {
					$this->output_table_error_log( $stats_table_limit, $output );
				} elseif ( 'list' === $this->format ) {
					$this->output_list_error_log( $stats_table_limit, $output );
				}
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
		$question = new Question( '<question>Enter the site ID or URL to display the errors for:</question> ' );
		$question->setAutocompleterValues( \array_column( get_pressable_sites() ?? array(), 'url' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Searches for the PHP error log file and returns its path. For example, if WP_DEBUG is turned on, the path will be
	 * /srv/htdocs/wp-content/debug.log. If WP_DEBUG is turned off, the path will be /tmp/php-errors. If there is an error
	 * monitoring plugin active on the site, the path can be something completely different.
	 *
	 * @param   OutputInterface $output         The output object.
	 * @param   \stdClass       $site           The site object.
	 * @param   SSH2|null       $ssh_connection The SSH connection object.
	 *
	 * @return  string
	 */
	private function get_php_error_log_path( OutputInterface $output, \stdClass $site, ?SSH2 &$ssh_connection ): string {
		$output->writeln( '<comment>Finding the PHP error log location.</comment>', OutputInterface::VERBOSITY_VERBOSE );
		$return_path = self::DEFAULT_PHP_ERROR_LOG_PATH;

		$ssh_connection = \Pressable_Connection_Helper::get_ssh_connection( $site->id );
		if ( ! \is_null( $ssh_connection ) ) {
			$path_separator = \uniqid( 'team51', false ); // If the WP site has warnings or notices, this will help us separate the path from the rest of that messy output.
			$error_log_path = $ssh_connection->exec( "wp eval 'echo \"$path_separator\" . ini_get(\"error_log\");'" );
			if ( ! empty( $error_log_path ) && \str_contains( $error_log_path, $path_separator ) ) {
				$return_path = \explode( $path_separator, $error_log_path )[1];
			} else {
				$output->writeln( '<error>Failed to find the PHP error log location. Using default PHP error log location.</error>', OutputInterface::VERBOSITY_VERBOSE );
			}
			$ssh_connection->disconnect();
		} else {
			$output->writeln( '<error>Could not connect to the site via SSH. Using default PHP error log location.</error>', OutputInterface::VERBOSITY_VERBOSE );
		}

		return $return_path;
	}

	/**
	 * Returns a standardized array of PHP errors either from the API or from the error log file.
	 *
	 * @param   OutputInterface $output The output object.
	 * @param   \stdClass       $site   The site object.
	 *
	 * @return  object[]|null
	 */
	private function get_php_errors( OutputInterface $output, \stdClass $site ): ?array {
		$error_log_path = $this->get_php_error_log_path( $output, $site, $ssh_connection );
		$output->writeln( "<info>Using the PHP error log location: $error_log_path</info>" );

		if ( 'api' === $this->source || ( 'auto' === $this->source && self::DEFAULT_PHP_ERROR_LOG_PATH === $error_log_path ) ) {
			$output->writeln( '<comment>Retrieving the PHP error log contents via the API.</comment>', OutputInterface::VERBOSITY_VERY_VERBOSE );

			$error_log = get_pressable_site_php_logs( $site->id, $this->severity, 2000 );
			if ( \is_null( $error_log ) ) {
				$output->writeln( '<error>Failed to retrieve the PHP error log contents via the API. Aborting!</error>', OutputInterface::VERBOSITY_VERBOSE );
			}
		} else {
			$output->writeln( '<comment>Downloading the last 100k lines of the PHP error log.</comment>', OutputInterface::VERBOSITY_VERY_VERBOSE );

			$error_log = $ssh_connection->exec( "tail -n 100000 $error_log_path" );
			if ( false === $error_log ) {
				$output->writeln( '<error>Failed to download the PHP error log. Aborting!</error>', OutputInterface::VERBOSITY_VERBOSE );
				$error_log = null;
			} else {
				$output->writeln( '<comment>Parsing the error log file contents into something usable.</comment>', OutputInterface::VERBOSITY_VERY_VERBOSE );
				$error_log = $this->parse_error_log( $error_log, $output );
			}
		}

		return $error_log;
	}

	/**
	 * Parses a given error log string into its constituent error entries.
	 *
	 * @param   string          $error_log The raw string content of the error log.
	 * @param   OutputInterface $output    The output object.
	 *
	 * @return  object[]
	 */
	private function parse_error_log( string $error_log, OutputInterface $output ): array {
		$parsed_php_errors = array();

		$php_errors = \explode( "\n", $error_log ); // Pressable sites run on Linux, so the separator is always \n. PHP_EOL could be \r\n on Windows.
		foreach ( $php_errors as $php_error ) {
			$php_error = \trim( $php_error );

			// Ignore stack traces and other non-error log lines.
			if ( empty( $php_error ) || '[' !== $php_error[0] ) {
				continue;
			}

			// Separate the error into its constituent parts.
			$php_error    = \explode( ']', $php_error, 2 );
			$php_error[0] = \substr( $php_error[0], 1 ); // Remove the leading [.

			$php_error_datetime = \strtotime( $php_error[0] );
			if ( $php_error_datetime + 7 * 24 * 60 * 60 < \time() ) { // If the error is more than a week old, ignore it.
				continue;
			}

			$php_error_datetime = \gmdate( 'c', $php_error_datetime ); // Make sure the date is in ISO 8601 format.
			$php_error_message  = \trim( $php_error[1] );

			$php_error_severity = \explode( ':', $php_error_message, 2 )[0];
			$php_error_severity = \trim( \str_replace( 'PHP', '', $php_error_severity ) );
			if ( ! empty( $this->severity ) && $php_error_severity !== $this->severity ) { // If the error severity doesn't match the requested status, ignore it.
				continue;
			}

			\preg_match_all( '/.* in (.+)(?: on line |:)(\d+)/', $php_error_message, $php_error_file_and_line, PREG_SET_ORDER );
			if ( 0 !== \count( $php_error_file_and_line ) ) {
				// Some error messages contain both formats ("on line" and ":") so we need to pick the last one.
				$php_error_file_and_line = \end( $php_error_file_and_line );

				$php_error_file = $php_error_file_and_line[1];
				$php_error_line = (int) $php_error_file_and_line[2];
			} else {
				$output->writeln( "<comment>Failed to parse the PHP error file and line from the error message: $php_error_message</comment>", OutputInterface::VERBOSITY_DEBUG );
				$php_error_file = '';
				$php_error_line = 0;
			}

			// Put everything together in the same format as the API.
			$parsed_php_errors[] = (object) array(
				'message'        => $php_error_message,
				'severity'       => $php_error_severity,
				'kind'           => '', // TODO
				'name'           => '', // TODO
				'file'           => $php_error_file,
				'line'           => $php_error_line,
				'timestamp'      => $php_error_datetime,
				'atomic_site_id' => '', // TODO
			);
		}

		return \array_reverse( $parsed_php_errors );
	}

	/**
	 * Sorts the distinct error log entries by when they last happened.
	 *
	 * @param   object[] $php_errors The error log entries as parsed by the @parse_error_log method or as returned by the API.
	 *
	 * @return  object[]
	 */
	private function analyze_log_entries( array $php_errors ): array {
		$stats_table = array();

		// Count each distinct error and keep track of its most recent occurrence.
		foreach ( $php_errors as $php_error ) {
			$error_hash = \hash( 'md5', $php_error->message );
			if ( isset( $stats_table[ $error_hash ] ) ) {
				++$stats_table[ $error_hash ]->count;

				if ( \strtotime( $php_error->timestamp ) > \strtotime( $stats_table[ $error_hash ]->timestamp ) ) {
					$stats_table[ $error_hash ]->timestamp = $php_error->timestamp;
				}
			} else {
				$stats_table[ $error_hash ] = (object) array(
					'message'   => $php_error->message,
					'severity'  => $php_error->severity,
					'timestamp' => $php_error->timestamp,
					'count'     => 1,
				);
			}
		}

		// Sort fatal errors by timestamp.
		\usort(
			$stats_table,
			static fn ( object $a, object $b ) => \strtotime( $b->timestamp ) <=> \strtotime( $a->timestamp )
		);

		return $stats_table;
	}

	/**
	 * Outputs the raw error log to the console.
	 *
	 * @param   object[]        $error_log The error log as received by the API or as parsed by the @parse_error_log method.
	 * @param   OutputInterface $output    The output object.
	 *
	 * @return  void
	 */
	private function output_raw_error_log( array $error_log, OutputInterface $output ): void {
		\passthru( 'clear' );
		$output->write( \print_r( $error_log, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Outputs the error log as a formatted table.
	 *
	 * @param   object[]        $stats_table The sorted log entries table.
	 * @param   OutputInterface $output      The output object.
	 *
	 * @return  void
	 */
	private function output_table_error_log( array $stats_table, OutputInterface $output ): void {
		$table = new Table( $output );

		$table->setHeaderTitle( "The $this->limit most recent PHP Errors" );
		$table->setHeaders( array( '' ) );

		foreach ( $stats_table as $key => $table_row ) {
			$table->addRow( array( new TableCell( "Timestamp: $table_row->timestamp" ) ) );
			$table->addRow( array( new TableCell( "Severity: $table_row->severity" ) ) );
			$table->addRow( array( new TableCell( "Count: $table_row->count" ) ) );
			$table->addRow( array( new TableCell( "<fg=magenta>$table_row->message</>" ) ) );

			if ( \array_key_last( $stats_table ) !== $key ) {
				$table->addRow( new TableSeparator() );
			}
		}

		$table->setColumnMaxWidth( 0, 128 );
		$table->setStyle( 'box-double' );
		$table->render();
	}

	/**
	 * Outputs the error log entry by entry.
	 *
	 * @param   object[]        $stats_table The sorted log entries table.
	 * @param   OutputInterface $output      The output object.
	 *
	 * @return  void
	 */
	private function output_list_error_log( array $stats_table, OutputInterface $output ): void {
		$output->writeln( '' );
		$output->writeln( "-- The $this->limit most recent PHP Errors --" );
		$output->writeln( '' );

		foreach ( $stats_table as $table_row ) {
			$output->writeln( "<info>Timestamp: $table_row->timestamp</info>" );
			$output->writeln( "<info>Severity: $table_row->severity</info>" );
			$output->writeln( "<info>Count: $table_row->count</info>" );
			$output->writeln( "<fg=magenta>$table_row->message</>" );

			/* @noinspection DisconnectedForeachInstructionInspection */
			$output->writeln( '' );
		}
	}

	// endregion
}
