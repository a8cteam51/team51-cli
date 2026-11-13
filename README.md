# Team51 CLI

## What even is this?

Glad you asked.

This is a small utility written by some fine folks at Automattic.com. It automates and standardizes provisioning new WordPress sites (on Pressable.com), connecting them to code repositories (on GitHub.com) and configuring deploy automation (via Pressable's GitHub integration, or DeployHQ.com for sites not yet moved off it). It does a few other things, but that's the gist.

## How do I use this?

You probably don't.

That said, with minimal work and especially if you're already using any of the three service providers we are, you can fork this repo and adapt it to your own needs. Or, perhaps you'll use this tool as inspiration to build something similar which automates a repeatable task you or your team are responsible for.

## May I contribute to this?

Probably not.

This tool is fairly specific to our team's workflow...and we intend to keep it that way vs. abstracting it for anyone and everyone's needs. That said, we're open to hearing from you and feel free to reach us by opening an issue- please include a reference/link to your favorite song as a clue that you've read this =)

## Why is this named "Team51"

It's the nickname for our Special Projects team at Automattic.

## Anything else?

Be well, be kind, make things and set them free.

## Installation

1. Ensure you have OpsOasis access. Details: p4Kr4c-dgn-p2#setting-up-opsoasis-1password (Not familiar with the link? Check the Field Guide for "Public GitHub Repository Shorthand".)
1. Open the Terminal on your Mac and install [Homebrew](https://brew.sh/) (if you haven't already).
	- `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"`
1. Ensure that you are running PHP 8.3+ in your terminal by running `php -v` and cross-checking the outputted version; We actually recommend running the latest version of PHP. If you’re not, then run `brew install php@8.3 brew-php-switcher` followed by `brew link php@8.3`
1. Keep your Terminal open and clone this repository by running:
	- `git clone git@github.com:a8cteam51/team51-cli.git`
	- The Terminal will ask you for a so-called SSH Passphrase which you must type and hit `Enter` (if you're unsure what the passphrase is, try entering the same you'd use for the AutoProxxy).
1. Make sure [Composer](https://getcomposer.org/) is installed on your computer.
	- The easiest way to install composer is by running `brew install composer`.
1. Make sure you're using [version 8 of 1Password](https://1password.com/mac/).
	- If you've installed it recently, then you probably are.
	- If you've been using 1Password for a while, then you probably need to upgrade manually since v7 doesn't automatically upgrade to v8.
	- Make sure you know the master password to all your 1Password accounts since you'll need to enter it again after the upgrade to v8.
1. Make sure [1Password CLI](https://developer.1password.com/docs/cli/get-started/) is installed on your computer.
	- The easiest way to install 1Password CLI is by running `brew install --cask 1password/tap/1password-cli`.
	- The terminal will ask you for a password which should be the same one you use to log in into your computer. After typing it in, hit `Enter`.
	- After installation, [integrate 1Password with the CLI](https://developer.1password.com/docs/cli/sign-in-sso/#step-1-connect-1password-cli-with-the-1password-app) to link your existing accounts with the CLI tool and for convenient unlocking of your vaults later.
	- Run a simple command, like `op vault ls`, to verify that it works and to select your default account (select the Team51 account if you have more than one).
1. Now let's install the CLI! `cd` to the `team51-cli` directory and run `./install-osx`.
	- If you get an error `no such file or directory: ./install-osx`, try running `cd team51-cli` first.
1. To verify the tool was installed successfully, you can run `team51 list` from your Terminal to see a list of all current commands.

### For developers

The CLI tool automatically updates itself. It does a hard reset to the latest version of the `trunk` branch and it will also try to switch to the `trunk` branch if it's not already on it. To improve performance, it only checks for updates once every 7 days. To bypass this behavior, you can:

- Run the tool with the `--dev` flag to skip update checks entirely.
- Run the tool with the `--force-update` flag to force an update check regardless of when the last check was performed.
- Add a `.dev` file to the root folder containing your current timestamp to disable updates for a week.

## AI Assistants (MCP)

The CLI no longer includes an MCP server. Team51 tools for AI assistants (Cursor, Claude Code) are in the `team51` provider of [context-a8c](https://mc.a8c.com/ai/context-a8c/). If you still have `team51 --mcp` set up (in `.cursor/mcp.json`, or through `claude mcp add`), remove it: it now prints a pointer to context-a8c and exits.

## Usage

This CLI tool is self-documenting. You can view a list of available commands with `team51 list`.
You can then do `team51 <command-name> --help`.

A copy of that documentation is also available on the [Github Wiki for this repository](https://github.com/a8cteam51/team51-cli/wiki). When developing, if you add any new commands or update any descriptions, help, or arguments, [follow these instructions to update the documentation](https://github.com/a8cteam51/team51-cli/wiki/Updating-the-CLI-command-documentation).

### WPCOM GitHub Deployment webhooks

When running `wpcom:connect-site-repository`, Team51 CLI now also creates a WordPress.com deployment webhook and attempts to sync the one-time webhook secret to OpsOasis so signature verification works on:

- `https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/webhooks/v1/wpcom-deployments`

Standalone webhook commands are also available:

```bash
# Create and sync a deployment webhook secret to OpsOasis (default behavior)
team51 wpcom:site:deployment:webhook:create <site> [deployment_id]

# Skip automatic secret sync (prints structured payload for manual sync)
team51 wpcom:site:deployment:webhook:create <site> [deployment_id] --no-sync-secret

# Optional management commands
team51 wpcom:site:deployment:webhook:list <site> [deployment_id]
team51 wpcom:site:deployment:webhook:delete <site> [deployment_id] <webhook_id>

# Manual secret sync fallback (if automatic sync fails)
team51 wpcom:site:deployment:webhook:sync-secret <site> <deployment_id> <webhook_id> <secret>
```

### Download site plugins

You can download installed plugins from a Pressable or WPCOM site as a `.tar.gz` archive:

```bash
# Downloads only wp-content/plugins (does not include mu-plugins)
team51 pressable:download-site-plugins <site> [--destination=/path/to/plugins.tar.gz]

# Downloads only wp-content/plugins (does not include mu-plugins)
team51 wpcom:download-site-plugins <site> [--destination=/path/to/plugins.tar.gz]
```

### Review a PR with Poseidon

`poseidon:pr-review` runs a review on your computer and prints it in the terminal. Use `--model` to select the model:

```bash
team51 poseidon:pr-review <PR> [--model=<model>]
```

| `--model` value | Provider | Requires |
|---|---|---|
| `claude-opus-5-5` (default) | Anthropic | `claude` CLI |
| `claude-<id>` or `anthropic/<id>` | Anthropic | `claude` CLI |
| `openai/gpt-6-luna` | OpenAI | `codex` CLI |
| `openai/gpt-5.6-terra` | OpenAI | `codex` CLI |
| `openai/gpt-5.6-luna` | OpenAI | `codex` CLI |
| `lmstudio/qwen/qwen3.8-27b` | Local | `claude` CLI and LM Studio with its server on |

The OpenAI models that you can use depend on your sign-in. To see your list, run `codex debug models` and look for the `slug` values where `visibility` is `list`. If you enter a model that your ChatGPT sign-in cannot use, the CLI stops and lists the models that it can use.

Before your first OpenAI review, the CLI asks you to confirm that your account is on a paid plan. Enterprise plans count as paid. Local reviews are slow: test reviews took 30 to 40 minutes.

### Conventions around defaults

If you run a command without any arguments, the CLI will ask you for the necessary information. Most of the time, a reasonable default will be provided, and you can just hit `Enter` to accept it.

For `yes/no` type of questions, the default option is the capitalized one. For example, if the CLI asks you `Do you want to continue? [Y/n]`, you can just hit `Enter` to accept the default option, which is `Y`.

For multiple choice questions, the default option is the one in square brackets. For example, if the CLI asks you `Which option do you want to choose? [Option 2]`, you can just hit `Enter` to accept the default option, which is `Option 2`.

## Troubleshooting

### Before anything else

If you haven't used the CLI in a while, and you're getting a lot of `Deprecated` notices or PHP `Fatal error`, try running `./install-osx` again to make sure all the dependencies are up-to-date.

Remember: you need be inside the `team51-cli` directory in order to execute the installation command.

### `error: Your local changes to the following files would be overwritten by merge: ...`

If you see this error when running a command, and you don't remember intentionally changing the mentioned files on your computer, you should run `git reset --hard` to discard all the changes and get back to the state of the remote repository.

### `no such file or directory: ./install-osx`

Most likely you are not inside the team51 directory. Try with `cd team51-cli`. If that doesn't work, verify where you are located by running `pwd` and then use the *change directory* command (`cd`) to navigate to the team51-cli directory we cloned in the steps above.

### `composer: command not found`

If you get the error `./install-osx: line 2: composer: command not found`, you can run `brew install composer` to install Composer on your computer.

### `brew: command not found`

If you don't have [brew](https://brew.sh/) yet, install it by executing this from your Terminal: `/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"` — Tip: you can use brew to install all sort of apps on your Mac. Give it [a try](https://formulae.brew.sh/cask/zoom)

### `env: php: No such file or directory`

This is likely because you don't have PHP installed on your system. If you recently updated to MacOS Monterey, that could be the culprit as it doesn't come bundled with PHP anymore. If you have `brew` installed, it's easy to get PHP added by running the following command in the terminal: `brew install php@8.3 brew-php-switcher`. This will install the latest version of PHP 8.3 as well as a handy utility for switching between PHP versions if you need to.

You will then need to run `brew link php@8.3` so that PHP gets symlinked properly to your system's PHP files. Running `team51` now should work just fine.

### `git@github.com: Permission denied (publickey)`

This is likely to happen if you moved/migrated from another computer and the key you used to authenticate with GitHub is not working anymore. This [guide](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent) should help you set up a new SSH key.  
