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
	$command->addOption( '--no-autocomplete', null, InputOption::VALUE_NONE, 'Do not provide options to initialization questions.' );
}

$team51_cli_app->run( $team51_cli_input, $team51_cli_output );
