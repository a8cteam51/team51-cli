#!/bin/bash

# Team51 CLI MCP Server Startup Script
# This script pre-authenticates with 1Password before starting the MCP server

set -e

# Change to the Team51 CLI directory
cd "$(dirname "$0")"

# Log startup
echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Starting Team51 CLI MCP Server with pre-authentication..." >&2

# Pre-authenticate with 1Password by running a simple CLI command
echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Pre-authenticating with 1Password..." >&2
php team51-cli.php --version > /dev/null 2>&1

# Check if pre-authentication was successful
if [ $? -eq 0 ]; then
    echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Pre-authentication successful, starting MCP server..." >&2
else
    echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Pre-authentication failed, attempting to start MCP server anyway..." >&2
fi

# Set shorter timeout for MCP server (30 seconds instead of 120)
# Avoids Claude/LLM wait the full 2 minutes before getting a timeout error
export TEAM51_MCP_TIMEOUT=30

# Start the MCP server with error handling
echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Starting MCP server process..." >&2

# Use exec to replace the shell process with PHP, ensuring proper signal handling
exec php -d max_execution_time=0 -d memory_limit=256M mcp-server.php