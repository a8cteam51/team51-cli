# Team51 CLI MCP Integration

This document explains how to use the Team51 CLI with the Model Control Protocol (MCP) to make CLI commands available as tools that AI models can call directly.

## Overview

The MCP integration allows AI models (like ChatGPT, Claude, etc.) to call Team51 CLI commands directly through a standardized protocol. Instead of users typing commands manually, they can ask an AI assistant to perform tasks like:

- "Get me the list of PHP errors for site example.com"
- "Create a new Pressable site called 'my-project'"
- "List all WordPress.com sites managed by the team"

## Setup

### 1. Start the MCP Server

The MCP server exposes your Team51 CLI commands as tools. Start it with:

```bash
./start-mcp-server.sh
```

Or directly with PHP:

```bash
php mcp-server.php
```

### 2. Configure Your AI Client

#### For ChatGPT Desktop App

1. **Locate the configuration file:**
   - **macOS:** `~/Library/Application Support/ChatGPT/config.json`
   - **Windows:** `%APPDATA%\ChatGPT\config.json`
   - **Linux:** `~/.config/ChatGPT/config.json`

2. **Create the file if it doesn't exist** (it usually doesn't exist by default)

3. **Add this configuration** (replace `/Users/[my-user]/path/to/team51-cli` with your actual path):

```json
// config.json
{
  "mcpServers": {
    "team51-cli": {
      "command": "php",
      "args": ["/Users/[my-user]/path/to/team51-cli/mcp-server.php"],
      "env": {
        "PATH": "/usr/local/bin:/usr/bin:/bin"
      }
    }
  }
}
```

4. **Restart ChatGPT Desktop App** completely (quit and reopen)

#### For Claude Desktop App

1. **Download Claude Desktop** from [claude.ai/download](https://claude.ai/download) if you haven't already

2. **Locate the configuration file:**
   - **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
   - **Linux:** `~/.config/Claude/claude_desktop_config.json`

3. **Create the directory and file if they don't exist:**
   ```bash
   # macOS/Linux
   mkdir -p ~/Library/Application\ Support/Claude
   touch ~/Library/Application\ Support/Claude/claude_desktop_config.json
   ```

4. **Add this configuration** (replace `/Users/[my-user]/path/to/team51-cli` with your actual path):

```json
{
  "mcpServers": {
    "team51-cli": {
      "command": "/Users/[my-user]/path/to/team51-cli/start-mcp-server.sh",
      "args": [],
      "env": {
        "PATH": "/usr/local/bin:/usr/bin:/bin"
      }
    }
  }
}
```

5. **Restart Claude Desktop App** completely (quit and reopen)

6. **Look for MCP indicator** - Claude Desktop typically shows a small icon or indicator when MCP servers are connected

#### For Other MCP Clients

Use the provided `mcp-config.json` as a template and adjust paths as needed.

#### Verification

After configuration:
1. **Restart your AI desktop app** completely
2. **Look for MCP tools** - they should appear in the app's interface or be available when you ask
3. **Test with a simple request** like "What Team51 CLI tools are available?" or "List the available Team51 CLI commands"

## Available Tools

The MCP server exposes read-only Team51 CLI commands as tools for safety. Each command becomes a tool with the prefix `team51_`. For example:

- `wpcom:list-sites` becomes `team51_wpcom_list_sites`
- `wpcom:list-site-stickers` becomes `team51_wpcom_list_site_stickers`
- `wpcom:list-site-plugins` becomes `team51_wpcom_list_site_plugins`
- `jetpack:list-site-modules` becomes `team51_jetpack_list_site_modules`
- `jetpack:search-plugin` becomes `team51_jetpack_search_plugin`

**Note**: Only read-only commands (list, export, search) are available via MCP. Commands that modify data (create, update, delete) are intentionally excluded for security.

## Usage Examples

### Example 1: List Sites

**User**: "Show me all our WordPress.com sites"

**AI Model**: Calls `team51_wpcom_list_sites` (no additional parameters needed)

**Response**: The command output with site information is automatically captured and returned to the AI model.

### Example 2: List Site Stickers

**User**: "Show me a list of all the WP stickers on the website automattic.com"

**AI Model**: Calls `team51_wpcom_list_site_stickers` with parameters:
```json
{
  "site": "automattic.com"
}
```

**Response**: The command output showing all stickers (tags) associated with the site is automatically captured and returned to the AI model.

## JSON Output Format

All MCP tool responses follow this standardized format:

```json
{
  "success": true,
  "exit_code": 0,
  "data": {
    // Command-specific data here
  }
}
```

For errors:

```json
{
  "success": false,
  "exit_code": 1,
  "error": "Error message here"
}
```

## Command Compatibility

All Team51 CLI commands can be used via MCP. The MCP server automatically captures command output and returns it to the AI model in a standardized format:

```json
{
  "success": true,
  "exit_code": 0,
  "output": "Command output here"
}
```

Commands with structured output (tables, lists, etc.) will have their output captured exactly as displayed in the terminal.

## Troubleshooting

### Server Won't Start

1. Check PHP syntax:
```bash
php -l mcp-server.php
```

2. Check autoloader:
```bash
composer dump-autoload
```

3. Check logs in stderr when running the server

### Commands Not Working

1. Ensure the command works normally:
```bash
team51 command:name --help
```

2. Look for interactive prompts that may cause issues with MCP

### Connection Issues

1. Verify the MCP server is running and listening
2. Check file paths in your MCP client configuration
3. Ensure PHP is in your system PATH

### ChatGPT Desktop MCP Support

**Important Note**: As of early 2025, ChatGPT Desktop's MCP support may be limited or experimental. If you see options like "Apps" or "Connector" in ChatGPT Desktop, these are different features and not MCP.

**Current Status**:
- ChatGPT Desktop version 1.2025.260 and similar versions may not have full MCP support
- The configuration file approach may not work with all ChatGPT Desktop versions
- **Recommended**: Use Claude Desktop for the most reliable MCP experience

**Alternative for ChatGPT Users**:
- Use the web version of ChatGPT with custom GPTs (though this requires different setup)
- Wait for official MCP support in future ChatGPT Desktop updates
- Use Claude Desktop as your primary MCP client

## Security Considerations

- The MCP server runs with the same permissions as the user starting it
- All Team51 CLI authentication and authorization still applies
- Commands that require interactive input are automatically run with `--no-interaction`
- Sensitive operations still require proper credentials and permissions

## Development

### Adding New Commands

New commands are automatically discovered when you:
1. Add them to the `commands/` directory
2. Follow the existing naming convention
3. Restart the MCP server

### Testing

Test individual commands via MCP:
```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"team51_wpcom_list_site_stickers","arguments":{"site":"automattic.com"}}}' | php mcp-server.php
```

### Debugging

Enable verbose logging by modifying the `mcp_error_log()` calls in `mcp-server.php`.

## Architecture

```
AI Model (ChatGPT/Claude)
    ↓ (MCP Protocol - JSON-RPC 2.0)
MCP Server (mcp-server.php)
    ↓ (Symfony Console)
Team51 CLI Commands
    ↓ (API Calls)
External Services (Pressable, WPCOM, GitHub, etc.)
```

The MCP server acts as a bridge, translating MCP tool calls into Symfony Console command executions and returning structured JSON responses.
