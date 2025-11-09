#!/bin/bash

# Team51 CLI MCP Server Startup Script
# Starts the MCP server with proper error handling and logging

set -e

# Change to the Team51 CLI directory
cd "$(dirname "$0")"

# Log startup
echo "[MCP Startup] $(date '+%Y-%m-%d %H:%M:%S') - Starting Team51 CLI MCP Server..." >&2

# Set shorter timeout for MCP server (30 seconds instead of 120)
# Avoids Claude/LLM wait the full 2 minutes before getting a timeout error
export TEAM51_MCP_TIMEOUT=30

# Use exec to replace the shell process with PHP, ensuring proper signal handling
# This ensures that signals (like SIGTERM when Claude closes) are handled correctly
exec php -d max_execution_time=0 -d memory_limit=256M mcp-server.php