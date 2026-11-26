# Set up the model providers for `poseidon:pr-review`

`team51 poseidon:pr-review <PR> --model=<model>` runs the review through the agent CLI of the model provider. Each provider uses your own sign-in. OpsOasis and 1Password do not supply these credentials.

You only need the provider that you use. The default model, `claude-opus-5-5`, needs only the `claude` CLI.

| `--model` value | Provider | Agent CLI | PR code goes to |
|---|---|---|---|
| `claude-opus-5-5` (default), `claude-<id>`, `anthropic/<id>` | Anthropic | `claude` | Anthropic |
| `openai/<id>`, for example `openai/gpt-6.2-astra` | OpenAI | `codex` | OpenAI |
| `xai/<id>` or `grok-<id>`, for example `xai/grok-4.7` | xAI | `grok` | xAI |
| `lmstudio/<id>`, for example `lmstudio/qwen/qwen3.8-27b` | LM Studio | `claude` and `lms` | This computer |

To see the model values, type `--model=` and press the Tab key. Shell completion must be installed (see `completion.sh`).

## Requirements for all providers

1. Install the GitHub CLI and sign in: `gh auth login`. The CLI fetches the PR data with your `gh` sign-in.
2. Install `git`. The CLI clones the PR unless you add `--no-clone`.

The review agent gets no GitHub token and no network tools. The CLI fetches the PR data before the agent starts.

## Anthropic: the `claude` CLI

1. Install Claude Code. Use one of these commands:
   ```bash
   brew install --cask claude-code
   npm install --global @anthropic-ai/claude-code
   ```
2. Sign in. The command opens a browser:
   ```bash
   claude auth login
   ```
3. Check the sign-in:
   ```bash
   claude auth status
   ```
4. Check the version. `claude --help` must list `--restricted`, `dontAsk`, and `--strict-mcp-config`. If it does not, run `claude update`.

## OpenAI: the `codex` CLI

1. Install the Codex CLI. Use one of these commands:
   ```bash
   brew install --cask codex
   npm install --global @openai/codex
   ```
2. Check the version. The review needs version 0.166.0 or later:
   ```bash
   codex --version
   ```
   To update, use the command that matches your install:
   ```bash
   brew upgrade --cask codex
   npm install --global @openai/codex@latest
   ```
3. Sign in with an Enterprise or paid plan. The command opens a browser:
   ```bash
   codex login
   ```
   On a computer with no browser, use `codex login --device-auth`.
4. Check the sign-in:
   ```bash
   codex login status
   ```
5. List the models of your sign-in. Use the `slug` values where `visibility` is `list`:
   ```bash
   codex debug models
   ```

A wrapper such as the one from cmux can put `codex` on your `PATH` with no real binary behind it. If `codex --version` fails, install the real CLI.

## xAI: the `grok` CLI

1. Install the Grok Build CLI. Use one of these commands:
   ```bash
   curl -fsSL https://x.ai/cli/install.sh | bash
   npm install --global @xai-official/grok
   ```
2. Check the install:
   ```bash
   grok --version
   ```
   To update, run `grok update`.
3. Sign in with an Enterprise or paid plan. The command opens a browser:
   ```bash
   grok login
   ```
   On a computer with no browser, use `grok login --device-auth`. As an alternative, set `XAI_API_KEY` to an API key from https://console.x.ai. A saved sign-in takes precedence over the API key.
4. List the models of your sign-in:
   ```bash
   grok models
   ```

Grok saves each session in `~/.grok/sessions`. A session contains the PR code that the agent read. Delete the folder contents if you do not want to keep them.

## LM Studio: a model on this computer

1. Install the `claude` CLI (see the Anthropic section). You do not need to sign in.
2. Install LM Studio, or download it from https://lmstudio.ai/download:
   ```bash
   brew install --cask lm-studio
   ```
3. Open LM Studio one time, so that it sets up the `lms` command. Then open a new terminal.
4. Download a model that can use tools. Large models can use many gigabytes:
   ```bash
   lms get qwen/qwen3.8-27b
   ```
5. Start the server:
   ```bash
   lms server start --port 1234
   ```
6. Check that the server lists the model with the `tool_use` capability:
   ```bash
   curl -s http://localhost:1234/api/v0/models
   ```

The model must load with a context of 32768 tokens or more. Local reviews are slow. Test reviews took 30 to 40 minutes.

## The paid-plan confirmation

Before your first OpenAI review and your first xAI review, the CLI asks you to confirm a paid plan. Enterprise plans count as paid. The CLI cannot check the plan. It saves your answer and shows a warning on later runs.

Answer the question yourself in an interactive terminal. An AI agent must not answer it and must not edit the saved answer.

## When a provider is not ready

The command checks the provider before it fetches the PR. If a check fails, the command prints the steps to fix the problem and a prompt that you can paste into an AI agent. The setup steps in this file and those messages come from `includes/poseidon-review-drivers.php`. Change both together.
