#!/usr/bin/env php
<?php

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;

const TEAM51_CLI_ROOT_DIR = __DIR__;
require_once TEAM51_CLI_ROOT_DIR . '/self-update.php';
require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

$team51_cli_app    = new Application();
$team51_cli_input  = new ArgvInput();
$team51_cli_output = new ConsoleOutput();

// Must be loaded after the application is instantiated so we can use all the helper functions.
require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';

foreach ( glob( __DIR__ . '/commands/*.php' ) as $command ) {
	$command = '\\WPCOMSpecialProjects\\CLI\\Command\\' . basename( $command, '.php' );
	$team51_cli_app->add( new $command() );
}
foreach ( $team51_cli_app->all() as $command ) {
	$command->addOption( '--dev', null, InputOption::VALUE_NONE, 'Run the CLI tool in developer mode.' );
}

$team51_cli_app->run( $team51_cli_input, $team51_cli_output );
