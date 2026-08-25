# Deploy notes — `secure-backup-access` change

This change relocates the backup directory outside the framework's webroot
by default and adds an auth-gated download endpoint (`download_backup.php`).

## Zero-ops contract (no admin skills required)

Installing/updating the plugin is enough — **there are no manual steps**.

At runtime the plugin resolves the backup directory automatically:

1. `FS_BACKUP_DIR` constant, when defined in `config.php` (explicit override).
2. A sibling of the framework root with a random suffix
   (`dirname(FS_FOLDER)/backups-<16hex>`) — outside the webroot, undiscoverable
   by URL scanners.
3. Automatic fallback to a protected legacy dir inside the webroot
   (`FS_FOLDER/backups-<16hex>`) when the sibling is not writable (e.g. a VPS
   where the web user cannot write outside `/var/www`). The fallback dir is
   still protected by its own `.htaccess` + `index.php` guards, by the core
   `RewriteRule ^backups` block, and by the auth+CSRF download endpoint.

Legacy backups found in the old fixed `FS_FOLDER/backups` dir are migrated
automatically (copy + size verification + cleanup) — no `mv`/`chown` needed.
If the state file that remembers the random suffix is lost (e.g. `tmp/`
cleaned), the plugin adopts the existing suffixed directory instead of
creating a new one, so backups are never lost.

The random directory name is a defence-in-depth layer, not the security
boundary: the real protections are the `.htaccess`/`index.php` guards, the
core `^backups` rewrite rule, and the auth+CSRF download endpoint.

## Optional hardening (only for people who want it)

Define `FS_BACKUP_DIR` in `config.php` to pin an exact external path:

```php
define('FS_BACKUP_DIR', '/var/backups/fsframework');
```

The directory must exist and be writable by the web user; the plugin reports
a loud error otherwise. This is optional — the default behaviour already
works everywhere.

## Smoke test (optional)

```bash
curl -I https://your-host/download_backup.php   # expect 401 without session
# As authenticated admin, open /index.php?page=admin_updater and click a
# backup's "Download" button.  Expect 200 + bytes match the source file
# (SHA-256 recorded in tmp/system_updater_debug.log).
```

## Rollback

```bash
git revert <merge-sha>   # restores the pre-secure-backup-access behaviour
```

## What stays as defence in depth

- `OidcProvider/.htaccess` (`RewriteRule ^backups(/.*)?$ - [F,L]`) stays in
  place and also covers `backups-*` names.
- The legacy `index.php?page=admin_updater&action=download_backup&file=...`
  controller arm is removed. A request to that URL now hits the controller's
  default switch case (no action) and renders the page without streaming —
  safe, but no longer functional. Update any external links.
- `plugins/system_updater/.htaccess` adds a `download_backup.php` block:
  `mod_deflate` off, `mod_security2` off, `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`. Belt-and-braces — the PHP script already
  emits equivalent headers.