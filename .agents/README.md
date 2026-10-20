# .agents — Agent Context Directory

This directory contains progressive, on-demand instructions for AI coding agents working on the Team51 CLI. The root `AGENTS.md` is the primary source of truth and is always loaded. Content here is loaded when an agent needs it for a specific task.

## Structure

| Path | Purpose |
|------|---------|
| `skills/` | Skills: knowledge for complex, repeatable procedures. Load when the agent needs to perform that procedure. |
| `subagents/` | Subagents: imperative runbooks for multi-step tasks. Load when the agent is delegated to execute that task. |
| `templates/` | Worked examples to copy (`*.php.example`, so the CLI does not load them). Referenced from a skill. |

## When to Use What

- **AGENTS.md** — Project knowledge, conventions, commands, pitfalls. Use first.
- **Skill** — Complex procedure with many conditions or tooling (e.g. local environment setup).
- **Subagent** — Clear step-by-step execution flow (e.g. add a new CLI command).

## Skills

| Skill | Trigger |
|-------|---------|
| `local-setup.md` | Setting up the Team51 CLI development environment locally; troubleshooting install/run issues; onboarding a new developer. |
| `add-mcp-tool.md` | Adding a new MCP tool to Team51McpTools.php; exposing a new operation for AI assistants. |
| `connection-helpers.md` | Implementing commands that need SSH or SFTP access to Pressable/WPCOM sites; remote file operations or shell access. |
| `fleet-scripts.md` | Building a one-off script that checks or changes a set of fleet sites (incident audits, bulk changes from a list or CSV); diagnosing why such a script fails on a site (403, SSH, WP-CLI). |

## Subagents

| Subagent | Trigger |
|----------|---------|
| `add-cli-command.md` | Adding a new command to the CLI; creating a new `commands/*.php` file; extending the CLI with a new capability. |
