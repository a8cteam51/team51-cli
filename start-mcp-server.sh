#!/bin/bash

# Team51 CLI MCP Server Startup Script
# This script starts the MCP server for Team51 CLI

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MCP_SERVER_SCRIPT="$SCRIPT_DIR/mcp-server.php"

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "Error: PHP is not installed or not in PATH" >&2
    exit 1
fi

# Check if the MCP server script exists
if [ ! -f "$MCP_SERVER_SCRIPT" ]; then
    echo "Error: MCP server script not found at $MCP_SERVER_SCRIPT" >&2
    exit 1
fi

# Make sure the script is executable
chmod +x "$MCP_SERVER_SCRIPT"

# Start the MCP server
echo "Starting Team51 CLI MCP Server..." >&2
exec php "$MCP_SERVER_SCRIPT"
