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

3. **Add this configuration** (replace `/Users/[my-user]/www/team51-cli` with your actual path):

```json
// config.json
{
  "mcpServers": {
    "team51-cli": {
      "command": "php",
      "args": ["/Users/[my-user]/www/team51-cli/mcp-server.php"],
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

4. **Add this configuration** (replace `/Users/[my-user]/www/team51-cli` with your actual path):

```json
{
  "mcpServers": {
    "team51-cli": {
      "command": "/Users/[my-user]/www/team51-cli/start-mcp-server.sh",
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

The MCP server automatically discovers and exposes all Team51 CLI commands as tools. Each command becomes a tool with the prefix `team51_`. For example:

- `pressable:list-site-php-errors` becomes `team51_pressable_list_site_php_errors`
- `wpcom:list-sites` becomes `team51_wpcom_list_sites`
- `github:create-repository` becomes `team51_github_create_repository`

## Usage Examples

### Example 1: Check PHP Errors

**User**: "Get me the PHP errors for the site mysite.pressable.com"

**AI Model**: Calls `team51_pressable_list_site_php_errors` with parameters:
```json
{
  "site": "mysite.pressable.com",
  "limit": "5",
  "format": "json"
}
```

**Response**: JSON data containing the PHP errors, timestamps, and error counts.

### Example 2: List Sites

**User**: "Show me all our WordPress.com sites"

**AI Model**: Calls `team51_wpcom_list_sites` with parameters:
```json
{
  "format": "json"
}
```

**Response**: JSON data with site information including names, URLs, and IDs.

### Example 3: Create a Site

**User**: "Create a new Pressable site called 'client-project'"

**AI Model**: Calls `team51_pressable_create_site` with parameters:
```json
{
  "name": "client-project",
  "format": "json"
}
```

**Response**: JSON data with the new site details and creation status.

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

### Commands with JSON Support

Commands that already have `--format json` option work seamlessly:
- `pressable:list-site-php-errors`
- `wpcom:list-sites`
- Most listing and reporting commands

### Commands without JSON Support

Commands without built-in JSON support will return their output in this format:

```json
{
  "success": true,
  "exit_code": 0,
  "output": "Raw command output here"
}
```

## Adding JSON Support to Commands

To add JSON support to a command:

1. Use the `JsonOutputTrait`:
```php
use WPCOMSpecialProjects\CLI\Helper\JsonOutputTrait;

class MyCommand extends Command {
    use JsonOutputTrait;
    
    protected function configure(): void {
        $this->addJsonFormatOption(); // Adds --format option
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($this->isJsonOutput($input)) {
            $data = ['result' => 'success'];
            $this->outputJson($output, $data);
            return Command::SUCCESS;
        }
        
        // Regular output logic
    }
}
```

2. Update the format option description to include `json`:
```php
->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, list, json', 'table')
```

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
./team51-cli.php command:name --help
```

2. Check if the command supports `--format json`

3. Look for interactive prompts that need `--no-interaction` flag

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
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"team51_pressable_list_site_php_errors","arguments":{"site":"example.com"}}}' | php mcp-server.php
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
