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

// Enable authentication for MCP context (needed for 1Password integration)
$GLOBALS['team51_is_autocomplete'] = false;

require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WPCOMSpecialProjects\CLI\MCP\MCPServer;

// Load Team51 identity from 1Password (same as main CLI)
// mcp_error_log("Loading Team51 identity from 1Password...");
// try {
//     require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';
//     mcp_error_log("Authentication loaded successfully");
// } catch (Throwable $e) {
//     mcp_error_log("Authentication failed: " . $e->getMessage());
//     mcp_error_log("This may require 1Password CLI authentication. Please run a Team51 CLI command manually first to authenticate.");
//     throw $e;
// }

try {
    mcp_error_log("Starting Team51 CLI MCP Server...");

    // Initialize the MCP server
    mcp_error_log("Initializing MCP server...");
    $mcp_server = new MCPServer();

    // Create the Symfony Console application first
    mcp_error_log("Creating Symfony Console application...");
    $team51_cli_app = new Application();

    // Load Team51 identity from 1Password (after application is instantiated)
    mcp_error_log("Loading Team51 identity from 1Password...");
    try {
        require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';
        mcp_error_log("Authentication loaded successfully");
    } catch (Throwable $e) {
        mcp_error_log("Authentication failed: " . $e->getMessage());
        mcp_error_log("This may require 1Password CLI authentication. Please run a Team51 CLI command manually first to authenticate.");
        throw $e;
    }

    // Define whitelist of read-only commands allowed in MCP
    $whitelisted_commands = [
        // List commands (read-only data retrieval)
        'Pressable_Site_PHP_Errors_List',
        'WPCOM_Sites_List',
        'WPCOM_Sites_With_Sticker_List',
        'WPCOM_Sites_Stats_Summary_List',
        'WPCOM_Sites_Stats_Orders_List',
        'WPCOM_Site_Stickers_List',
        'WPCOM_Site_Plugins_List',
        'Jetpack_Site_Modules_List',
        
        // Export commands (read-only data export)
        'CLI_Commands_Export',
        'GitHub_Pattern_To_Repo_Export',
        'Jetpack_Site_Plugins_Export',
        
        // Search commands (read-only search operations)
        'Jetpack_Module_Search',
        'Jetpack_Plugin_Search',
    ];

    // Load whitelisted Team51 CLI commands only
    mcp_error_log("Loading whitelisted Team51 CLI commands...");
    $command_count = 0;
    
    foreach ( glob( __DIR__ . '/commands/*.php' ) as $command_file ) {
        try {
            $command_name = basename( $command_file, '.php' );
            
            // Skip if not in whitelist
            if (!in_array($command_name, $whitelisted_commands)) {
                mcp_error_log("Skipping non-whitelisted command: $command_name");
                continue;
            }
            
            $command_class = '\\WPCOMSpecialProjects\\CLI\\Command\\' . $command_name;
            if (class_exists($command_class)) {
                $team51_cli_app->add( new $command_class() );
                $command_count++;
                mcp_error_log("Loaded whitelisted command: $command_name");
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

// Add shutdown handler to log when server exits
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        mcp_error_log("PHP Fatal Error on shutdown: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    } else {
        mcp_error_log("MCP Server shutdown normally");
    }
});
