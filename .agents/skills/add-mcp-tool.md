---
name: add-mcp-tool
description: >
  Use when adding a new MCP tool to the Team51 CLI. Invoke when the user asks to
  expose a new operation as an MCP tool, add a tool to Team51McpTools.php, or
  extend the MCP server with new AI-assistant capabilities.
---

# Add an MCP Tool

## Purpose

This skill describes how to add a new tool to the Team51 MCP server. Tools are auto-discovered from `mcp/Team51McpTools.php` — no registration step is required.

## File and Class

- **File**: `mcp/Team51McpTools.php`
- **Class**: `WPCOMSpecialProjects\CLI\Mcp\Team51McpTools` (final)

## Tool Definition Pattern

### 1. Add a public method

```php
#[McpTool( name: 'snake_case_tool_name' )]
public function tool_name( string $param1, string $param2 = 'default' ): array {
```

- Method name: snake_case matching the tool name (e.g. `wpcom_list_sites`).
- Parameters become MCP tool parameters. Use PHPDoc `@param` for descriptions.
- Return type MUST be `array`. Return associative arrays for data; use `array( 'error' => '...' )` for failures.

### 2. Ensure identity is loaded

Call `self::ensure_identity()` at the start of every tool. Return immediately if it returns an error:

```php
$identity_error = self::ensure_identity();
if ( $identity_error ) {
    return $identity_error;
}
```

### 3. Use existing helpers

Reuse functions from `includes/` (loaded via Composer autoload and manual includes):

- `get_wpcom_sites()`, `get_wpcom_site()`, `get_wpcom_site_plugins()`, etc.
- `get_pressable_sites()`, `get_pressable_site()`, `pressable_get_php_errors()`, etc.
- `get_github_*`, `get_jetpack_*`, `get_deployhq_*` families.

### 4. Return format

- **Success**: Return an associative array. Structure it for AI consumption (e.g. `count`, `sites`, `plugins`).
- **Failure**: `return array( 'error' => 'Human-readable message' );`
- Do not throw exceptions; catch and return error arrays.

### 5. Write tools: add ToolAnnotations

For tools that modify data (not read-only), add `annotations` so MCP clients can prompt for confirmation:

```php
#[McpTool(
    name: 'pressable_add_collaborator',
    annotations: new ToolAnnotations(
        title: 'Add Pressable Site Collaborator',
        readOnlyHint: false,
        destructiveHint: false,   // true if the action is irreversible or impactful
        idempotentHint: true,    // true if running twice has same effect as once
        openWorldHint: true,
    )
)]
```

Use `destructiveHint: true` for password rotation, removal, key rotation. Use `readOnlyHint: true` only for purely read tools (most read tools omit annotations).

### 6. CRITICAL: No STDOUT

STDOUT is reserved for JSON-RPC. Never use `echo`, `print`, or write to STDOUT.

- Debug output: `fwrite( STDERR, "[MCP] Debug message\n" );` (with phpcs:ignore if needed)
- `console_writeln()` uses the global output, which is set to STDERR in MCP mode — safe to use

### 7. Do NOT add high-risk tools

The following are intentionally excluded from MCP:

- Site creation (Pressable or WPCOM)
- User deletion
- Deployment triggers

If the user requests one, explain that these operations are excluded by design and must be run via the CLI.

**Sanctioned exception — command execution with guardrails.** WP-CLI and SSH execution are exposed, but only because they ship with guardrails: WP-CLI tools (`*_run_wp_cli_command`) use a restrictive allowlist (`is_allowed_wp_cli_command()`); SSH tools (`*_run_ssh_command`) use a catastrophic-command denylist (`is_blocked_ssh_command()`). Both audit-log every call (`audit_wp_cli_command()` / `audit_ssh_command()`) and carry a `destructiveHint: true` annotation. The MCP server cannot itself enforce human approval (no elicitation support in `php-mcp/server`), so per-command approval relies entirely on the client's approval prompt — these tools must never be auto-approved/allowlisted in the client. Follow this same pattern (guardrail + audit + destructive annotation) for any new execution-style tool; do not add an unguarded one.

## Verification

1. Restart the MCP server: `team51 --mcp` (Ctrl+C to stop).
2. In Cursor, confirm the new tool appears under MCP tools.
3. Invoke the tool and verify the response shape.

## Example reference

See `wpcom_list_sites`, `wpcom_get_site` (read-only, no annotations) and `pressable_add_collaborator`, `wpcom_rotate_sftp_password` (write tools with annotations) in `mcp/Team51McpTools.php`.
