---
name: add-cli-command
description: >
  Use when adding a new command to the Team51 CLI. Invoke when the user asks to
  add a command, create a new CLI capability, or implement a new commands/*.php file.
type: subagent
---

# Add a New CLI Command

## Purpose

Creates a new Symfony Console command in the Team51 CLI, following the project's conventions and structure.

## Trigger

Invoke this subagent when:
- The user asks to add a new command to the Team51 CLI.
- The user wants to implement a new capability exposed as `team51 <service>:<action>`.
- The user provides a command name and expects the scaffolding to be created.

## Inputs

| Input | Type | Required | Description |
|-------|------|----------|-------------|
| `service` | string | yes | Service prefix (e.g. `pressable`, `wpcom`, `github`, `jetpack`, `deployhq`) |
| `action` | string | yes | Action name in kebab-case (e.g. `clone-site`, `list-sites`) |
| `description` | string | yes | Short description for the command |
| `arguments` | array | no | List of `{name, description, required}` |
| `options` | array | no | List of `{name, description, shortcut, valueRequired}` |

## Skills Referenced

- AGENTS.md (command conventions, helpers, structure)
- Existing command files in `commands/` (e.g. `Pressable_Site_Clone.php`, `WPCOM_Sites_List.php`)

## Steps

### 1. Determine the class and file names

- **Class name**: `{Service}_{PascalCase(Action)}` (e.g. `Pressable_Site_Clone`, `WPCOM_Sites_List`)
- **File name**: `commands/{Service}_{PascalCase(Action)}.php`
- **Command name**: `{service}:{action}` (e.g. `pressable:clone-site`)

### 2. Create the command file

Create `commands/{Service}_{Action}.php` with:

- Namespace: `WPCOMSpecialProjects\CLI\Command`
- Class extends `Symfony\Component\Console\Command\Command`
- Use `AutocompleteTrait` from `WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait`
- `#[AsCommand(name: 'service:action')]` attribute
- Implement: `configure()`, `initialize()` (if needed), `interact()` (optional), `execute()`
- Use `// region` and `// endregion` for grouping
- Use `declare(strict_types=1);` if consistent with nearby commands

### 3. Configure the command in `configure()`

- `$this->setDescription(...)` and `$this->setHelp(...)`
- Add arguments: `$this->addArgument('name', InputArgument::REQUIRED|OPTIONAL, '...')`
- Add options: `$this->addOption('name', shortcut, InputOption::VALUE_REQUIRED|VALUE_NONE, '...')`

### 4. Implement input handling

Use helpers from `includes/functions.php` and service includes:

- `get_string_input($input, 'name', fn() => $this->prompt_...( $input, $output ))`
- `get_enum_input($input, 'name', $valid_values, fn() => ..., $default)`
- `get_bool_input($input, 'name')`
- `get_pressable_site_input()`, `get_wpcom_site_input()` (from service functions)

Set values back on input if you compute them: `$input->setArgument('name', $value)`.

### 5. Implement confirmation in `interact()` (if destructive)

For commands that create, delete, or modify resources:

- Use `ConfirmationQuestion` with `$this->getHelper('question')->ask()`
- On decline: `exit(2)` after writing a message

### 6. Implement logic in `execute()`

- Use `API_Helper::make_{service}_request()` for API calls
- Use `run_app_command()` to invoke other Team51 commands
- Use `run_system_command()` for shell commands
- Use `console_writeln()` for output (respects verbosity)
- Return `Command::SUCCESS` or `Command::FAILURE`

### 7. Verify

- Run `team51 list` — new command appears
- Run `team51 {service}:{action} --help` — help text is correct
- Run `composer run lint:php` — no PHPCS violations
- Run `composer run format:php` if needed

## Outputs

- New file: `commands/{Service}_{Action}.php`
- Command discoverable via `team51 list`
- Code passes `composer run lint:php`

## Example Reference

See `commands/Pressable_Site_Clone.php` for a command with:
- Multiple arguments and options
- `initialize()` for resolving inputs
- `interact()` for confirmation
- `run_app_command()` to call another command
- API and SSH usage

See `commands/WPCOM_Sites_List.php` for a read-only list command with simpler flow.
