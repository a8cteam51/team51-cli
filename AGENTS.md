# Team51 CLI — Agent Instructions

This document provides project knowledge for AI coding agents working on the Team51 CLI. It is the single source of truth for conventions, architecture, and procedures.

## Project Overview

The **Team51 CLI** is a PHP command-line tool built by Automattic's Special Projects team (Team51). It automates provisioning WordPress sites on Pressable, connecting them to GitHub repositories, and configuring DeployHQ deployments. It integrates with WPCOM, Jetpack, GitHub, DeployHQ, and Pressable via a central OpsOasis REST API.

**CRITICAL**: This tool is internal and team-specific. It depends on 1Password, OpsOasis API access, and Automattic infrastructure. Do not assume it can be run by arbitrary users without those prerequisites.

---

## Tech Stack

| Component | Technology |
|-----------|------------|
| Language | PHP 8.3 |
| Framework | Symfony Console 7.x |
| Package Manager | Composer |
| Coding Standards | WordPress Coding Standards (WPCS) via `a8cteam51/team51-configs` |
| API Backend | OpsOasis REST API (`opsoasis.wpspecialprojects.com`) |
| Credentials | 1Password CLI (v8) |
| MCP | php-mcp/server ^3.3 |

**Required PHP extensions**: `gd`, `json`, `posix`, `readline`.

---

## Directory Structure

```
team51-cli/
├── team51-cli.php       # Entry point. Use `--mcp` to start MCP server instead of CLI.
├── mcp-server.php       # MCP server entry (also reachable via team51 --mcp)
├── environment-guard.php # Pre-autoload platform gate. Required first by both entry points.
├── self-update.php      # Git self-update logic (trunk branch, 7-day check)
├── load-identity.php    # Loads OPSOASIS credentials from 1Password
├── install-osx          # Mac installation script (composer install + symlink)
├── completion.sh        # Shell tab-completion script
├── composer.json        # Dependencies and scripts
├── .phpcs.xml           # PHPCS ruleset (extends team51-configs)
├── commands/            # All Symfony Console commands (one class per file)
├── includes/            # Shared functions and helpers
│   ├── api-helper.php           # REST API calls (DeployHQ, GitHub, Jetpack, Pressable, WPCOM, WPORG)
│   ├── abstract-connection-helper.php
│   ├── connection-helper-pressable.php
│   ├── connection-helper-wpcom.php
│   ├── functions.php            # Core helpers (console, input, process)
│   ├── functions-*.php           # Service-specific (1password, deployhq, github, jetpack, pressable, wporg, wpcom)
│   ├── parallel-process.php
│   ├── autocomplete-trait.php
│   └── enum-site-type.php
├── mcp/
│   └── Team51McpTools.php       # MCP tool definitions (McpTool attribute)
└── scaffold/            # Files to deploy (load-safety-net.php, pattern-extract.php)
```

---

## Commands

### Build / Install

```bash
# Full install (from project root)
./install-osx

# Or manually:
composer install
composer dump-autoload -o
# Symlink: sudo ln -sf "$(pwd)/team51-cli.php" /usr/local/bin/team51
```

**When to use**: After cloning, or when dependencies change. Run `ulimit -n 8192` before install if you hit "too many open files" later.

### Lint / Format

```bash
# Lint (PHPCS) — reports only, never writes
composer run lint:php
# or: phpcs --standard=.phpcs.xml --basepath=. . -s -v

# Auto-fix (PHPCBF) — rewrites files in place
composer run format:php
# or: phpcbf --standard=.phpcs.xml --basepath=. . -v
```

**When to use**: Before committing. The ruleset extends `vendor/a8cteam51/team51-configs/quality-assurance/phpcs.dist.xml`.

### Run the CLI

```bash
# List commands
team51 list

# Command help
team51 <command-name> --help

# Developer mode (skip self-update check)
team51 --dev <command-name>

# Force update check
team51 --force-update <command-name>

# Interactive shell (beta)
team51 --shell
```

**MCP server**: `team51 --mcp` — starts MCP server for AI assistants (Cursor, Claude Code).

### Tests

**There are no automated tests.** The project does not use PHPUnit or Pest. Manual verification is expected.

---

## Conventions

### Commit & Branch Naming

- Primary branch: `trunk`. The CLI self-updates from this branch.
- Use descriptive commit messages. No enforced format.
- PRs should target `trunk`.

### Code Style

- Follow `.editorconfig`: tabs for PHP, 2 spaces for JSON/YAML.
- Follow WordPress Coding Standards (via .phpcs.xml).
- Use `// region` and `// endregion` for logical grouping.
- Use `declare(strict_types=1);` where appropriate (some commands use it).

### Command Naming

- **Class file**: `Service_Action.php` (e.g. `Pressable_Site_Clone.php`).
- **Command name**: `service:action` (e.g. `pressable:clone-site`).
- Commands live in `WPCOMSpecialProjects\CLI\Command` namespace.
- Auto-discovery: `team51-cli.php` globs `commands/*.php` and instantiates each as `WPCOMSpecialProjects\CLI\Command\<Filename>`.

### Adding a New Command

1. Create `commands/<Service>_<Action>.php`.
2. Class extends `Symfony\Component\Console\Command\Command`.
3. Add `#[AsCommand(name: 'service:action')]` attribute.
4. Implement `configure()`, `initialize()` (if needed), `interact()` (optional), `execute()`.
5. Use `AutocompleteTrait` for tab completion.
6. Use helpers: `get_string_input()`, `get_enum_input()`, `get_bool_input()`, `get_pressable_site_input()`, etc. from `includes/functions.php` and service-specific includes.

See `.agents/subagents/add-cli-command.md` for a detailed runbook.

### Adding an MCP Tool

1. Add a public method to `mcp/Team51McpTools.php`.
2. Add `#[McpTool(name: 'tool_name')]` attribute.
3. Call `self::ensure_identity()` at the start (for 1Password credentials).
4. Use existing `get_*` functions from includes.
5. Return arrays (or error arrays with `'error' => '...'`). No STDOUT — reserved for JSON-RPC.

**MUST**: Annotate every write tool with `ToolAnnotations` (`readOnlyHint: false`, plus `destructiveHint: true` when the action is irreversible or impactful) so clients prompt before executing. Risk is gated by annotation, not by exclusion — high-risk tools (site creation, WP-CLI execution, collaborator removal, deployment project creation, shell access) are exposed and annotated. See README MCP section.

**For full details**: `.agents/skills/add-mcp-tool.md`

---

## Architectural Decisions

These are intentional; do not "fix" them without team discussion.

1. **OpsOasis as proxy**: All external API calls (Pressable, GitHub, DeployHQ, etc.) go through the OpsOasis REST API, not directly. Credentials come from 1Password.
2. **Self-update from trunk**: The CLI hard-resets to `trunk` and checks for updates every 7 days (or via `--force-update`). Use `--dev` to skip during development.
3. **1Password required**: `load-identity.php` must succeed before most commands. It sets `OPSOASIS_WP_USERNAME` and `OPSOASIS_APP_PASSWORD`.
4. **No Composer in self-update**: `self-update.php` does not use Composer or other includes; it runs before autoload.
5. **MCP identity laziness**: Identity is loaded on first MCP tool call, not at server startup, to avoid 1Password prompts when Cursor opens the project.
6. **STDOUT reserved in MCP**: All MCP server output goes to STDERR except JSON-RPC on STDOUT.
7. **Pre-autoload platform gate**: `environment-guard.php` runs from both entry points before the Composer autoloader and enforces the project floor (PHP 8.3+, `gd`/`json`/`posix`/`readline`). Install and self-update run `composer dump-autoload --ignore-platform-reqs`, which drops Composer's generated `platform_check.php`, so this is the *only* platform check at startup. It uses built-in functions only, and checks the project floor — not what installed dependencies require.
8. **MCP risk is annotation-gated**: High-risk tools are exposed rather than withheld; `ToolAnnotations` drive client-side confirmation. Do not remove a tool on risk grounds alone.

---

## Common Pitfalls

1. **Editing WordPress core** — This CLI does not include WordPress core. Do not look for or modify core files here.

2. **Assuming tests exist** — There are no PHPUnit/Pest tests. Do not suggest running tests or adding test commands unless explicitly asked.

3. **Skipping identity loading** — Commands that touch OpsOasis need credentials. `load-identity.php` is loaded after the app is instantiated. Autocomplete mode sets `$GLOBALS['team51_is_autocomplete']` and skips identity for faster shell completion.

4. **Wrong input helpers** — Use `get_string_input()` for required string input, `maybe_get_string_input()` when optional, `get_enum_input()` for constrained choices.

5. **run_app_command vs run_system_command** — `run_app_command()` runs another Team51 CLI command internally. `run_system_command()` runs shell commands via Symfony Process.

6. **Command name vs class name** — The `AsCommand` name (e.g. `pressable:clone-site`) is what users type. The class name (e.g. `Pressable_Site_Clone`) is the file/class.

7. **team51-configs as dev dependency** — PHPCS extends `vendor/a8cteam51/team51-configs/...`. Run `composer install` with dev dependencies.

8. **`.dev` file vs `--dev` flag** — These are different mechanisms. `--dev` skips the update check for a single invocation. An untracked `.dev` file in the project root (`self-update.php:113`) suppresses the 7-day update buffer *persistently*, so the CLI silently stops picking up trunk changes. If a CLI seems stale, check for `.dev` before debugging self-update; `rm .dev` restores normal behaviour.

---

## Where to Find More

- **README.md** — User-facing docs, installation, troubleshooting, MCP setup.
- **GitHub Wiki** — Command documentation. See README for updating it.
- **.agents/skills/** — Complex procedures loaded on demand:
  - `local-setup.md` — Environment setup, prerequisites, troubleshooting.
  - `add-mcp-tool.md` — Adding MCP tools with identity, return format, annotations.
  - `connection-helpers.md` — SSH/SFTP to Pressable and WPCOM sites.
- **.agents/subagents/** — Runbooks for repeatable tasks (e.g. `add-cli-command.md`).
