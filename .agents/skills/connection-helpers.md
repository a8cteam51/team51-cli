---
name: connection-helpers
description: >
  Use when implementing commands that need SSH or SFTP access to Pressable or
  WordPress.com sites. Invoke when adding remote file operations, shell access,
  WP-CLI over SSH, or similar functionality.
---

# Connection Helpers (SSH / SFTP)

## Purpose

This skill describes how to use the connection helpers to open SSH and SFTP connections to Pressable and WordPress.com sites. Credentials are obtained via OpsOasis and rotated as needed.

## Available Helpers

| Helper | Sites | SSH Host | SFTP Host |
|--------|-------|---------|-----------|
| `Pressable_Connection_Helper` | Pressable | `ssh.atomicsites.net` | `sftp.pressable.com` |
| `WPCOM_Connection_Helper` | WordPress.com (Atomic) | `ssh.atomicsites.net` | `sftp.wp.com` |

## Site Identifier

- **Pressable**: Site ID (numeric string) or site URL. Aliases are resolved via `pressable_maybe_resolve_site_alias()`.
- **WPCOM**: Site ID or domain. Both helpers accept what their respective `get_*_site_*` functions accept.

## Getting a Connection

### SSH (for running commands)

```php
$ssh = \Pressable_Connection_Helper::get_ssh_connection( $site_id );
// or
$ssh = \WPCOM_Connection_Helper::get_ssh_connection( $site_id_or_url );
```

Returns `SSH2|null` (phpseclib3). Use for `exec()`, `read()`, etc.

### SFTP (for file upload/download)

```php
$sftp = \Pressable_Connection_Helper::get_sftp_connection( $site_id );
// or
$sftp = \WPCOM_Connection_Helper::get_sftp_connection( $site_id_or_url );
```

Returns `SFTP|null` (phpseclib3). Use `put()`, `get()`, `chdir()`, etc.

## Important Patterns

### 1. Always disconnect when done

```php
$ssh = Pressable_Connection_Helper::get_ssh_connection( $site_id );
try {
    // ... use $ssh ...
} finally {
    $ssh?->disconnect();
}
```

### 2. New Pressable sites: SSH may not be ready immediately

Right after cloning or creating a site, the server may accept connections but not execute commands. Use the polling helper:

```php
$ssh = wait_on_pressable_site_ssh( $site_id, $output );
if ( \is_null( $ssh ) ) {
    // The site never became reachable — handle it; do not proceed as though it had.
}
```

This retries `get_ssh_connection()` (which also verifies the server responds to `ls -la`) up to `$max_attempts` times (default 60, 5 seconds apart), then reports the timeout and returns `null` — callers must handle that. SFTP is typically ready sooner than SSH for new sites.

### 3. SFTP path convention

Pressable/WPCOM sites use `htdocs` as the web root. Paths are absolute from the connection root:

- `/htdocs/wp-content/mu-plugins/`
- `/htdocs/wp-content/plugins/`
- `/htdocs/wp-config.php`

### 4. SSH exec output and exit status

```php
$output = $ssh->exec( 'ls -la' );
$exit_code = $ssh->getExitStatus();
if ( 0 !== $exit_code ) {
    // Command failed
}
```

For streaming/callback style, `exec()` can accept a callback. See `Pressable_Site_Clone.php` for examples.

### 5. SFTP put

```php
$result = $sftp->put(
    '/htdocs/wp-content/uploads/example.png',
    file_get_contents( $local_path )
);
if ( ! $result ) {
    // Upload failed
}
```

For writing a small text file over an existing SSH connection, a quoted heredoc avoids opening a second
(SFTP) connection — see `write_safety_net_loader()` in `includes/functions-safety-net.php` for the pattern.

### 6. Credential handling (internal)

Connection helpers obtain credentials via OpsOasis:

- **Pressable**: `get_pressable_site_sftp_user()` + `rotate_pressable_site_sftp_user_password()` (uses `concierge@wordpress.com` by default for CLI)
- **WPCOM**: `get_wpcom_site_ssh_username()` + `rotate_wpcom_site_sftp_user_password()`

Credentials are cached per `$site_identifier` during the request. Do not call these directly from commands; use the connection helpers.

## When to Use Which

| Need | Use |
|------|-----|
| Run shell commands (WP-CLI, bash) | `get_ssh_connection()` |
| Upload/download files | `get_sftp_connection()` |
| New Pressable site, need SSH | `wait_on_pressable_site_ssh()` |
| Need host for external SSH (e.g. `ssh user@host`) | `Pressable_Connection_Helper::SSH_HOST` or `::SFTP_HOST` |

## Extending: Adding a New Connection Helper

To support a new hosting provider:

1. Extend `Abstract_Connection_Helper`.
2. Define `SSH_HOST` and `SFTP_HOST` constants.
3. Implement `get_credentials( string $site_identifier ): ?stdClass` returning `{ username, password }`.
4. Use `static::` so the parent's `get_ssh_connection` and `get_sftp_connection` use your `get_credentials`.

See `connection-helper-pressable.php` and `connection-helper-wpcom.php` for reference.

## Example Commands

- **SSH**: `Pressable_Site_Clone` (SafetyNet install + loader via heredoc), `Pressable_Site_WP_CLI_Command_Run`, `Pressable_Site_Shell_Open`, `WPCOM_Site_WP_CLI_Command_Run`
- **SFTP**: `Pressable_Site_Icon_Upload`, `Pressable_Site_Plugins_Download`, `GitHub_Pattern_To_Repo_Export`
