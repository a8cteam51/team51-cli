#!/usr/bin/env php
<?php

const TEAM51_CLI_ROOT_DIR = __DIR__;
require_once __DIR__ . '/self-update.php';
require_once __DIR__ . '/vendor/autoload.php';

$application = new \Symfony\Component\Console\Application();

$commands = \glob( __DIR__ . '/commands/*.php' );
foreach ( $commands as $command ) {
	$command = '\\WPCOMSpecialProjects\\CLI\\Command\\' . \basename( $command, '.php' );
	$application->add( new $command() );
}

$application->run();
