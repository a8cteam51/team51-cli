#!/usr/bin/env php
<?php

use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

const TEAM51_CLI_ROOT_DIR = __DIR__;
const TEAM51_CLI_FILE     = __FILE__;
require_once TEAM51_CLI_ROOT_DIR . '/self-update.php';
require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

$team51_cli_app        = new Application();
$team51_cli_input      = new ArgvInput();
$team51_cli_output     = new ConsoleOutput();
$team51_cli_dispatcher = new EventDispatcher();

// Handle errors gracefully.
$team51_cli_app->setDispatcher( $team51_cli_dispatcher );
$team51_cli_dispatcher->addListener(
	ConsoleEvents::ERROR,
	function ( ConsoleErrorEvent $event ) {
		$message = implode( ' ', array( $event->getError()->getMessage(), 'Aborting!' ) );
		$event->getOutput()->writeln( "<error>$message</error>" );
	}
);

// Must be loaded after the application is instantiated, so we can use all the helper functions.
if ( ! $GLOBALS['team51_is_autocomplete'] ) {
	require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';
}

foreach ( glob( __DIR__ . '/commands/*.php' ) as $command ) {
	$command = '\\WPCOMSpecialProjects\\CLI\\Command\\' . basename( $command, '.php' );
	$team51_cli_app->add( new $command() );
}
foreach ( $team51_cli_app->all() as $command ) {
	$command->addOption( '--dev', null, InputOption::VALUE_NONE, 'Run the CLI tool in developer mode.' );
	$command->addOption( '--force-update', null, InputOption::VALUE_NONE, 'Force update check regardless of when the last check was performed.' );
	$command->addOption( '--no-autocomplete', null, InputOption::VALUE_NONE, 'Do not provide options to initialization questions.' );
}

$is_shell_mode = in_array( '--shell', $_SERVER['argv'], true );
if ( $is_shell_mode ) {
	// Remove the flag so that Symfony Console does not choke on an unknown option.
	$_SERVER['argv'] = array_values(
		array_filter(
			$_SERVER['argv'],
			static fn ( string $arg ): bool => $arg !== '--shell'
		)
	);

	// Keep the process alive after each command.
	$team51_cli_app->setAutoExit( false );

	$io = new \Symfony\Component\Console\Style\SymfonyStyle( new ArgvInput(), $team51_cli_output );
	$io->title( '🔧  Team51 CLI interactive shell' );

	// Enable tab‑completion for command names.
	if ( function_exists( 'readline_completion_function' ) ) {
		readline_completion_function(
			static function ( string $partial ) use ( $team51_cli_app ): array {
				$names = array_keys( $team51_cli_app->all() );
				return array_values( array_filter( $names, static fn ( string $name ): bool => str_starts_with( $name, $partial ) ) );
			}
		);
	}

	// REPL loop.
	while ( true ) {
		$line = readline( 'team51> ' );

		if ( $line === false ) { // Control‑D pressed.
			$io->newLine();
			break;
		}

		$line = trim( $line );
		if ( $line === '' ) {
			continue;
		}
		if ( in_array( $line, array( 'exit', 'quit' ), true ) ) {
			$io->success( 'Good‑bye!' );
			break;
		}

		readline_add_history( $line );

		try {
			$team51_cli_app->run( new \Symfony\Component\Console\Input\StringInput( $line ), $team51_cli_output );
		} catch ( \Throwable $e ) {
			$io->error( $e->getMessage() );
		}
	}

	exit; // We are done with the shell.
}

$team51_cli_app->run( $team51_cli_input, $team51_cli_output );
