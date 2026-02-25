---
name: team51-cli-local-setup
description: >
  Use when setting up the Team51 CLI development environment locally, troubleshooting
  install or run failures, or guiding someone through onboarding. Covers prerequisites,
  installation steps, common errors, and verification.
---

# Team51 CLI — Local Setup Skill

## Purpose

This skill describes how to set up and verify the Team51 CLI development environment on macOS. Use it when an agent needs to help with installation, dependency issues, or run failures.

## Prerequisites (All Required)

1. **OpsOasis access** — Internal Automattic tool. Requires access to the Field Guide entry (p4Kr4c-dgn-p2#setting-up-opsoasis-1password).
2. **Homebrew** — `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`
3. **PHP 8.2+** (recommend 8.3) — `brew install php@8.3 brew-php-switcher` then `brew link php@8.3`. Verify with `php -v`.
4. **Composer** — `brew install composer`
5. **1Password v8** — Must be v8 (v7 does not auto-upgrade). Know master password.
6. **1Password CLI** — `brew install --cask 1password/tap/1password-cli`. Integrate with 1Password app per [1Password CLI docs](https://developer.1password.com/docs/cli/sign-in-sso/). Run `op vault ls` to verify and select Team51 as default.
7. **Git** — Repo at `git@github.com:a8cteam51/team51-cli.git`. SSH key must be set up for GitHub.

## Installation Steps

1. Clone: `git clone git@github.com:a8cteam51/team51-cli.git`
2. `cd team51-cli`
3. Run `./install-osx`
   - Installs Composer dependencies
   - Runs `composer dump-autoload -o`
   - Creates symlink: `/usr/local/bin/team51` → `team51-cli.php`
4. Verify: `team51 list`

## Common Errors and Fixes

| Error | Fix |
|-------|-----|
| `no such file or directory: ./install-osx` | Ensure you are in the repo root. Run `cd team51-cli`. |
| `composer: command not found` | `brew install composer` |
| `brew: command not found` | Install Homebrew (see prerequisites). |
| `env: php: No such file or directory` | Install PHP: `brew install php@8.3` and `brew link php@8.3` |
| `git@github.com: Permission denied (publickey)` | Set up SSH key for GitHub. See [GitHub SSH guide](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-github-agent). |
| `Your local changes to the following files would be overwritten by merge` | If unintentional: `git reset --hard` to discard local changes. |
| `failed to open stream: Too many open files` | Run `ulimit -n 8192` before commands that use many workers (e.g. remove-user). |
| Deprecated / Fatal PHP errors after long idle | Run `./install-osx` again to refresh dependencies. |

## Developer Flags

- `--dev` — Skip self-update check (useful when developing).
- `--force-update` — Force update check regardless of 7-day interval.
- `.dev` file in repo root — Disable updates for a week (timestamp-based).

## Verification Checklist

After setup:

1. `team51 list` — Shows all commands.
2. `team51 wpcom:list-sites --help` — Command help works.
3. `team51 --dev list` — Dev mode works.
4. `team51 --mcp` — MCP server starts (Ctrl+C to stop).

## Notes

- The CLI self-updates from the `trunk` branch every 7 days by default.
- Identity (1Password credentials) loads on first real command; autocomplete mode skips it.
- MCP: Identity loads on first tool call, not at server startup.
