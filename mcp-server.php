#!/usr/bin/env php
<?php

/**
 * Team51 CLI MCP Server
 *
 * This script implements the Model Control Protocol (MCP) to expose Team51 CLI commands
 * as tools that AI models can call directly.
 */

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log errors to stderr for debugging
function mcp_error_log($message) {
    fwrite(STDERR, "[MCP Server] " . date('Y-m-d H:i:s') . " - " . $message . "\n");
}

// Set up Team51 CLI environment
const TEAM51_CLI_ROOT_DIR = __DIR__;
const TEAM51_CLI_FILE     = __FILE__;

// Disable autocomplete for MCP context
$GLOBALS['team51_is_autocomplete'] = true;

require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WPCOMSpecialProjects\CLI\MCP\MCPServer;

try {
    mcp_error_log("Starting Team51 CLI MCP Server...");

    // Initialize the MCP server
    mcp_error_log("Initializing MCP server...");
    $mcp_server = new MCPServer();

    // Load all Team51 CLI commands
    mcp_error_log("Loading Team51 CLI commands...");
    $team51_cli_app = new Application();
    $command_count = 0;
    
    foreach ( glob( __DIR__ . '/commands/*.php' ) as $command_file ) {
        try {
            $command_class = '\\WPCOMSpecialProjects\\CLI\\Command\\' . basename( $command_file, '.php' );
            if (class_exists($command_class)) {
                $team51_cli_app->add( new $command_class() );
                $command_count++;
            } else {
                mcp_error_log("Warning: Command class $command_class not found");
            }
        } catch (Exception $e) {
            mcp_error_log("Error loading command from $command_file: " . $e->getMessage());
        }
    }
    
    mcp_error_log("Loaded $command_count commands");

    // Register commands as MCP tools
    mcp_error_log("Registering commands as MCP tools...");
    $mcp_server->registerCommandsFromApplication( $team51_cli_app );

    // Start the MCP server
    mcp_error_log("Starting MCP server main loop...");
    $mcp_server->run();
    
} catch (Throwable $e) {
    mcp_error_log("Fatal error: " . $e->getMessage());
    mcp_error_log("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
