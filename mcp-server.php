#!/usr/bin/env php
<?php
/**
 * Team51 CLI - MCP Server Entry Point
 *
 * This script starts an MCP (Model Context Protocol) server that exposes
 * Team51 CLI functionality as tools for AI assistants.
 *
 * It uses stdio transport, meaning the server communicates via STDIN/STDOUT
 * using JSON-RPC. All debug output MUST go to STDERR.
 *
 * Usage:
 *   php mcp-server.php
 *
 * Configuration (in .cursor/mcp.json):
 *   {
 *     "mcpServers": {
 *       "team51": {
 *         "command": "php",
 *         "args": ["/path/to/team51-cli/mcp-server.php"]
 *       }
 *     }
 *   }
 */

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

// Set up constants needed by the CLI environment.
const TEAM51_CLI_ROOT_DIR = __DIR__;
const TEAM51_CLI_FILE     = __FILE__;

// Load Composer autoloader (skip self-update.php — we don't want update checks or ASCII art in MCP mode).
require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

// Set up global output to STDERR so that helper functions (like console_writeln)
// don't pollute STDOUT, which is reserved for JSON-RPC communication.
$team51_cli_output = new StreamOutput( fopen( 'php://stderr', 'w' ) );

// Mark as non-autocomplete so that identity loading proceeds.
$GLOBALS['team51_is_autocomplete'] = false;

// Load the Team51 identity (1Password credentials).
// This is required for all API calls to work.
try {
	require_once TEAM51_CLI_ROOT_DIR . '/load-identity.php';
} catch ( \Throwable $e ) {
	fwrite( STDERR, "[MCP] Failed to load identity: {$e->getMessage()}\n" );
	exit( 1 );
}

fwrite( STDERR, "[MCP] Identity loaded. Starting MCP server...\n" );

// Build and start the MCP server.
try {
	$server = Server::make()
		->withServerInfo( 'Team51 CLI', '1.0.0' )
		->build();

	// Discover MCP tools from the mcp/ directory.
	$server->discover(
		basePath: TEAM51_CLI_ROOT_DIR,
		scanDirs: array( 'mcp' ),
		excludeDirs: array( 'vendor', 'commands', 'includes' ),
	);

	// Start listening via stdio transport.
	$transport = new StdioServerTransport();
	$server->listen( $transport );
} catch ( \Throwable $e ) {
	fwrite( STDERR, "[MCP CRITICAL] {$e->getMessage()}\n" );
	fwrite( STDERR, $e->getTraceAsString() . "\n" );
	exit( 1 );
}
