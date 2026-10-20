---
name: fleet-scripts
description: >
  Use when asked to build a one-off script that checks or changes a set of fleet sites, usually
  over SSH: incident audits, bulk option or plugin changes, clean-ups across a list of sites or a
  CSV. Covers the Abstract_Fleet_Command base class, the run-and-retire workflow, and why such
  scripts fail (403s, SSH, WP-CLI quirks).
---

# One-off Fleet Scripts

## Purpose

A fleet script visits a set of sites one at a time, checks or changes something on each, and writes down what happened. `includes/abstract-fleet-command.php` (`Abstract_Fleet_Command`) handles site selection, platform routing, SSH, the ledger and the reports. A one-off only implements the per-site work, often in under 100 lines.

One-offs are **not merged**. A command that can write to every site in one go should not stay installed. Keep it on a branch, run it with `team51 --dev`, and delete it when the job is done. Merge it only if it turns into a standing command.

## When to use

- A list of sites (from Slack, a P2, a CSV) needs the same check or change.
- The work needs WP-CLI or files on the site. If an API already does it (`jetpack:plugin-update`, `wpcom:run-site-wp-cli-command` for one site), use that instead.

## What you get

| Option | Behavior |
|--------|----------|
| `--sites` | `production`, `staging` (by URL containing "staging"; Pressable flags many staging sites as production), `all`, `a.com,123456`, or a CSV path (first column; header rows are skipped). Defaults to `DEFAULT_SITES`, or the whole Jetpack fleet. |
| `--limit=N` | Process at most N sites this run. Try 1–2 sites first. |
| `--dry-run` | Write scripts only. Nothing is written to sites or to the ledger, and the preview follow-up list goes to `…-dry-run-follow-up.csv`, so the real one is never touched. It still connects, and connecting rotates passwords. |
| `--retry-failed` | Only the sites that failed last time. Without it, earlier failures are left out (also when `--sites` is a CSV), so `--limit` keeps moving through the fleet. Sites typed out in `--sites=a.com,b.com` are always processed. |
| `--log` | JSONL ledger, `<command>-ledger.jsonl` in the CLI directory by default. Append-only. It is also the skip-list for the next run. |
| `--report` | CSV of this run's results, `./<command>-report.csv` by default. Rows are written as sites finish. The ledger holds the full history. |
| `--yes` | Skip the confirmation prompt. |

Every site a person has to finish by hand goes to `<report>-follow-up.csv` (id, url, host, status, reason, todo). That file is the hand-off list. It is rebuilt each run from every site's latest outcome in the ledger, so a `--limit` run or a run on a few typed-out sites still hands over the whole job. Use a separate `--log` for a separate job. It is removed once nothing is left. The command exits non-zero while anything is on it.

## Writing one

1. Copy a template from `.agents/templates/` to `commands/<Service>_<Action>.php` and drop `.example`:
   - `Updraft_Central_Keys_Audit.php.example`: read-only, several facts per site via a tagged shell script.
   - `Updraft_Central_Keys_Remove.php.example`: a write, as check, dry-run stop, write, read back.
2. Set the `#[AsCommand]` name, the description, and the constants you need:

   | Constant | Default | Set it when |
   |----------|---------|-------------|
   | `READ_ONLY` | `false` | The script never writes. Removes `--dry-run`. |
   | `DEFAULT_SITES` | `[]` (whole fleet) | The job came with a list of sites. |
   | `SETTLED_STATUSES` | `['success','unchanged']` | An audit should re-check every site each run: use `[]`. |
   | `FOLLOW_UP_STATUSES` | `[]` (`failed` always counts) | Statuses that need a person, e.g. `compromised`. |
   | `REPORT_COLUMNS` | `[]` | Extra CSV columns, filled from the result's `columns`. |
   | `SSH_TIMEOUT` | `60` | The script greps wp-content or does other slow work. |

3. Implement `process_site( $site, $ssh, $platform ): array` and return `['status' => …, 'note' => …]`, plus optional `reason` and `columns`. Use `$this->failure( self::REASON_ERROR, '…' )` for a site a person must finish. Optional hooks:
   - `prepare()`: one-time setup, e.g. fetch a value from OpsOasis.
   - `filter_sites()`: narrow the sites, e.g. to those with a plugin.
   - `redact()`: strip secrets from notes.
   - `run_site()`: override when the script doesn't need SSH.
4. Use the helpers. Don't call `$ssh->exec()` by hand:
   - `$this->wp( $ssh, "option get 'x'" )` runs `wp --skip-plugins --skip-themes …`. It returns `output`, `error` and `exit` separately, and throws on timeout.
   - `$this->exec( $ssh, $script )` runs any shell command and returns the same shape.
   - `$this->parse_tagged( $output )` turns `TAG=value` lines into `['TAG' => [values…]]`.
5. Rules for `process_site()`:
   - Make it idempotent: read first, and return `unchanged` when there's nothing to do.
   - Respect `$this->dry_run`: stop at a `would-…` status.
   - Read the value back after a write. Don't trust the write command's own output.
   - Quote every value you put into a shell command with `escapeshellarg()`.
   - Never print or record secrets (keys, logs, settings URLs). Count or test them on the site, and redact anything else.
   - Keep real site lists and incident links out of anything that could be merged. **The repository is public**, and a blog ID resolves to its URL through the public WordPress.com API, so a list of affected sites discloses them. Put the list in a CSV outside the repo and pass it with `--sites`. If `DEFAULT_SITES` must hold real IDs, keep that commit off GitHub.
6. Lint: `composer run lint:php`. There are no tests, so the try-out run is the test.

## Running one

Run it from a branch, always with `--dev`. Self-update runs `git checkout -f trunk` and a hard reset, so without `--dev` it can switch you off the branch mid-job.

```bash
team51 --dev <command> --sites=/full/path/sites.csv --dry-run --limit=2   # does the script do the right thing?
team51 --dev <command> --sites=/full/path/sites.csv --limit=2             # try a real change on two sites
team51 --dev <command> --sites=/full/path/sites.csv --yes                 # everything else (settled sites are skipped)
team51 --dev <command> --sites=/full/path/sites.csv --retry-failed        # go back for the failures
```

Then hand over the follow-up CSV, along with the manual steps for each reason (below).

## Retiring one

- Delete the command file once the follow-up list is done.
- Ledgers and reports are gitignored (`*-ledger.jsonl`, `*-report.csv`, `*-follow-up.csv`). Keep them for as long as the incident needs a record.
- If the script found something reusable, move it into the base class or this doc, not into a new one-off.

## Why sites fail (and what the follow-up says)

| Reason | What it means | What a person does |
|--------|---------------|--------------------|
| `no-access` | A WordPress.com site where the API wouldn't give out the SSH user. **A 403 means the concierge user is not the site owner (the primary connected user).** The concierge user is our WordPress.com account, username `a8cteam51`. Being an administrator isn't enough: only the owner can manage SSH users through the API, so the CLI can't get the username or rotate the password. With no 403, SSH just isn't enabled on the site yet. | Have the owner make the change or transfer ownership. Or use phpMyAdmin or wp-admin. |
| `ssh-failed` | The connection didn't open. On Pressable, the usual cause is no SFTP user for the concierge user, because opening a connection never adds the collaborator (that would be a write). Otherwise the site is unreachable or SSH is disabled. | Retry with `--retry-failed`, or add the collaborator in MyPressable. Otherwise use phpMyAdmin. |
| `external` | Neither WordPress.com (Atomic) nor Pressable claims the site. The CLI can't reach it. | Use the site's own host. |
| `not-in-fleet` | The identifier isn't in the Jetpack-connected fleet (typo, disconnected, or not ours). | Check the site and its Jetpack connection. |
| `error` | The script ran but couldn't finish. WP-CLI didn't boot, the command timed out, or the read-back didn't match. The note says which. | Read the note. Usually the fix is phpMyAdmin or a closer look at the site. |

## Gotchas learned the hard way

- **Connecting rotates the password.** Every SSH connection rotates the site's SFTP/SSH password, on WordPress.com and Pressable, dry runs included. Don't loop over sites "just to look" more often than you need.
- **Put the WP-CLI flags first.** On Atomic, use `wp --skip-plugins --skip-themes option get x`. After the subcommand, the flags fail with "unknown --skip-plugins parameter". `$this->wp()` puts them first. Skip plugins unless the command truly needs them: a broken or malicious plugin then can't break or own the script.
- **Prefer subcommands that return structured output over `wp eval`.** `wp eval` can return nothing at all. Use `option list --search=… --format=count`, `--field=` or `--format=json`.
- **Don't trust stdout.** Plugin notices and PHP warnings end up in the output. Take the last line of a count, check the exit code, or tag your lines (`KEYS=1`) and use `parse_tagged()`.
- **Platform routing never guesses:**
  - `*.wpcomstaging.com` means WordPress.com.
  - `*.mystagingwebsite.com` means Pressable.
  - Otherwise `is_wpcom_atomic` decides WordPress.com.
  - Otherwise Pressable has to claim the site.
  - "Not Atomic" doesn't mean Pressable. Looking up a non-Pressable site prints an OpsOasis API error (often a 500), and that's expected.
- **Staging vs production goes by URL.** The URL must contain "staging". A production site without its own domain counts as staging.
- **Paths:** wp-content is `/srv/htdocs/wp-content` over SSH on both platforms. Over SFTP the root is `/htdocs`. See `connection-helpers.md`.
- **Object cache:** a change made with SQL may not show until the cache is flushed. Flush it from MyPressable or the WordPress.com hosting settings. This doesn't apply to WP-CLI changes, which go through WordPress.
- **Shell pitfalls when running the command:**
  - zsh doesn't expand `~` after `=`, so `--sites=~/x.csv` fails. Use full paths.
  - A value that starts with `--` is read as an option. Put `--` before it.
- **Premium plugins:** builds like UpdraftPlus Premium (2.x) don't update from WordPress.org. Plugin-update commands won't touch them, so plan to handle them by hand.

## No SSH: doing it in phpMyAdmin

For `no-access` or `ssh-failed` sites, open phpMyAdmin from the site's hosting dashboard. Back up first.

```sql
SHOW TABLES LIKE '%options';                                  -- the table prefix isn't always wp_
SELECT option_name, LENGTH(option_value) FROM wp_options WHERE option_name = 'the_option';
DELETE FROM wp_options WHERE option_name = 'the_option' LIMIT 1;
SELECT option_value FROM wp_options WHERE option_name = 'active_plugins';
SELECT ID, user_login, user_email, user_registered FROM wp_users ORDER BY user_registered DESC LIMIT 20;
```

Flush the object cache afterwards. SQL can't see file contents. For those, use SFTP, the plugin list in wp-admin, or Jetpack Scan.
