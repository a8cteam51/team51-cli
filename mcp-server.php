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
 *   team51 --mcp
 *   php mcp-server.php
 *
 * Configuration (in .cursor/mcp.json or .mcp.json):
 *   {
 *     "mcpServers": {
 *       "team51": {
 *         "command": "team51",
 *         "args": ["--mcp"]
 *       }
 *     }
 *   }
 */

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Symfony\Component\Console\Output\StreamOutput;

// Set up constants needed by the CLI environment.
// When invoked via `team51 --mcp`, these are already defined by team51-cli.php.
if ( ! defined( 'TEAM51_CLI_ROOT_DIR' ) ) {
	define( 'TEAM51_CLI_ROOT_DIR', __DIR__ );
}
if ( ! defined( 'TEAM51_CLI_FILE' ) ) {
	define( 'TEAM51_CLI_FILE', __FILE__ );
}

// Fail clearly on an unsupported runtime before autoload (shared with team51-cli.php).
require_once TEAM51_CLI_ROOT_DIR . '/environment-guard.php';

// Load Composer autoloader (skip self-update.php — we don't want update checks or ASCII art in MCP mode).
require_once TEAM51_CLI_ROOT_DIR . '/vendor/autoload.php';

// Set up global output to STDERR so that helper functions (like console_writeln)
// don't pollute STDOUT, which is reserved for JSON-RPC communication.
$team51_cli_output = new StreamOutput( fopen( 'php://stderr', 'w' ) );

// Mark as non-autocomplete so that identity loading proceeds when needed.
$GLOBALS['team51_is_autocomplete'] = false;

// Identity (1Password credentials) is loaded lazily on first tool call,
// not at startup. This prevents Cursor from prompting for 1Password unlock
// every time a project is opened. See Team51McpTools::ensure_identity().

fwrite( STDERR, "[MCP] Starting MCP server (identity will load on first tool call)...\n" );

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
