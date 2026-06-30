<?php
/**
 * This file is responsible for ensuring that the CLI tool is up-to-date, and running on the correct branch.
 * Because of that, it should be as simple as possible, and not rely on any other files. For example, we can't
 * use anything coming from Composer because we can't load it until after we've updated.
 */

// region HELPER FUNCTIONS

/**
 * Outputs a message to the console, unless the `--quiet` flag is set.
 *
 * @param   string       $message The message to output.
 * @param   boolean|null $quiet   Whether to suppress the output.
 *
 * @return  void
 */
function team51_cli_print_message( string $message, ?bool $quiet = null ): void {
	$quiet ??= (bool) $GLOBALS['team51_cli_is_quiet'];
	if ( ! $quiet ) {
		echo $message . PHP_EOL;
	}
}

/**
 * Executes a system command.
 *
 * @param   string $command The command to run.
 *
 * @return  array
 */
function team51_cli_run_system_command( string $command ): array {
	$output      = null;
	$result_code = null;

	// Execute the command and redirect STDERR to STDOUT.
	exec( "$command 2>&1", $output, $result_code );

	if ( 0 !== $result_code ) {
		team51_cli_print_message( sprintf( 'Error running command: %s', $command ), false );
		foreach ( $output as $line ) {
			team51_cli_print_message( $line, false );
		}
	}

	return array(
		'output'      => $output,
		'result_code' => $result_code,
	);
}

/**
 * Ensures that the CLI tool is up-to-date and running on the correct branch.
 *
 * @return  void
 */
function team51_cli_self_update(): void {
	// Get the current branch.
	$command = team51_cli_run_system_command( sprintf( 'git -C %s branch --show-current', TEAM51_CLI_ROOT_DIR ) );

	// Maybe switch to trunk.
	if ( 'trunk' !== $command['output'][0] ) {
		team51_cli_print_message( 'Not on `trunk`. Switching...' );
		team51_cli_run_system_command( sprintf( 'git -C %s stash', TEAM51_CLI_ROOT_DIR ) );
		team51_cli_run_system_command( sprintf( 'git -C %s checkout -f trunk', TEAM51_CLI_ROOT_DIR ) );
	}

	// Reset branch.
	team51_cli_run_system_command( sprintf( 'git -C %s fetch origin', TEAM51_CLI_ROOT_DIR ) );
	team51_cli_run_system_command( sprintf( 'git -C %s reset --hard origin/trunk', TEAM51_CLI_ROOT_DIR ) );
}

// endregion

// region TIMESTAMP FUNCTIONS

/**
 * Gets the timestamp of the last update check.
 *
 * @return  int|null  The timestamp, or null if the file doesn't exist or doesn't contain a valid timestamp.
 */
function team51_cli_get_last_update_check_timestamp(): ?int {
	$timestamp_file = TEAM51_CLI_ROOT_DIR . '/.last-update-check';

	if ( ! file_exists( $timestamp_file ) ) {
		return null;
	}

	$content = file_get_contents( $timestamp_file );
	if ( empty( $content ) || ! is_numeric( $content ) ) {
		return null;
	}

	return (int) $content;
}

/**
 * Records the current time as the last update check.
 *
 * @return  void
 */
function team51_cli_update_last_update_check_timestamp(): void {
	$timestamp_file = TEAM51_CLI_ROOT_DIR . '/.last-update-check';

	file_put_contents( $timestamp_file, time() );
}

// endregion

// region EXECUTION LOGIC

$team51_cli_is_quiet        = file_exists( TEAM51_CLI_ROOT_DIR . '/.quiet' );
$team51_cli_dev_file_exists = file_exists( TEAM51_CLI_ROOT_DIR . '/.dev' );
$team51_cli_is_dev          = $team51_cli_dev_file_exists;
$team51_is_autocomplete     = false;
$team51_cli_force_update    = false;

foreach ( $argv as $arg ) {
	switch ( $arg ) {
		case 'completion':
		case '_complete':
			$team51_is_autocomplete = true;
			return; // Don't run the rest of the script.
		case '-q':
		case '--quiet':
			$team51_cli_is_quiet = true;
			break;
		case '--dev':
			$team51_cli_is_dev = true;
			break;
		case '--force-update':
			$team51_cli_force_update = true;
			break;
	}
}

// Print the ASCII art.
team51_cli_print_message( file_get_contents( TEAM51_CLI_ROOT_DIR . '/.ascii' ) );

// Check for updates. --force-update wins over dev mode so an explicit refresh always runs.
if ( $team51_cli_is_dev && ! $team51_cli_force_update ) {
	team51_cli_print_message( "\033[44mRunning in developer mode. Skipping update check.\033[0m" );
	if ( $team51_cli_dev_file_exists ) {
		team51_cli_print_message( "\033[33m.dev file detected — run `team51 --force-update <command>` periodically to pick up CLI changes, or `rm .dev` to re-enable the 7-day update buffer.\033[0m" );
	}
} else {
	$last_check_timestamp  = team51_cli_get_last_update_check_timestamp();
	$seven_days_in_seconds = 7 * 24 * 60 * 60; // 7 days in seconds

	$should_update = $team51_cli_force_update ||
					null === $last_check_timestamp ||
					( time() - $last_check_timestamp ) >= $seven_days_in_seconds;

	if ( $should_update ) {
		team51_cli_print_message( "\033[33mChecking for updates..\033[0m" );
		team51_cli_self_update();
		// Update the timestamp after checking for updates.
		team51_cli_update_last_update_check_timestamp();
	} else {
		team51_cli_print_message( "\033[33mSkipping update check (less than 7 days since last check).\033[0m" );
	}
}

// Update Composer.
team51_cli_run_system_command( sprintf( 'composer install --ignore-platform-reqs --working-dir %s --no-interaction', TEAM51_CLI_ROOT_DIR ) );
team51_cli_run_system_command( sprintf( 'composer dump-autoload -o --ignore-platform-reqs --working-dir %s --no-interaction', TEAM51_CLI_ROOT_DIR ) );

// endregion
