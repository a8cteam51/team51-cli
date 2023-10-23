#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$application = new \Symfony\Component\Console\Application();

$commands = \glob( __DIR__ . '/commands/*.php' );
foreach ( $commands as $command ) {
	$command = '\\WPCOMSpecialProjects\\CLI\\Command\\' . \basename( $command, '.php' );
	$application->add( new $command() );
}

$application->run();
