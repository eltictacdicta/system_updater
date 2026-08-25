# Deploy notes — `secure-backup-access` change

This change relocates the backup directory from inside the framework's
webroot (`FS_FOLDER/backups/`) to a sibling path (`dirname(FS_FOLDER) . '/backups'`)
and adds an auth-gated download endpoint (`download_backup.php`).

## Required deploy steps

```bash
# 1. Move existing backups to the new location (SIBLING, not child of FS_FOLDER).
NEW_BACKUP_DIR="$(dirname /var/www/html)/backups"   # adjust FS_FOLDER to your real path
mkdir -p "$NEW_BACKUP_DIR"
chmod 0700 "$NEW_BACKUP_DIR"
chown -R <web-user>:<web-group> "$NEW_BACKUP_DIR"
mv /var/www/html/backups/* "$NEW_BACKUP_DIR/"

# 2. (Optional but recommended) override FS_BACKUP_DIR explicitly in config.php:
#    define('FS_BACKUP_DIR', '/var/backups/fsframework');
#    The new code honours FS_BACKUP_DIR when defined.

# 3. Smoke test BEFORE removing the old directory:
curl -I https://your-host/backups/                      # expect 403/404
curl -I https://your-host/download_backup.php           # expect 401
# As authenticated admin, open /index.php?page=admin_updater and click a
# backup's "Download" button.  Expect 200 + bytes match the source file
# (compare SHA-256 in tmp/system_updater_debug.log).

# 4. Only after confirming the new path works, remove the legacy directory:
rm -rf /var/www/html/backups
```

## Rollback

```bash
# Revert BACKUP_DIR:
git revert <merge-sha>          # restore BACKUP_DIR='backups' + delete download_backup.php
# Or manually:
#   1. lib/backup_manager.php: revert resolve_backup_dir() to FS_FOLDER . '/backups'.
#   2. rm plugins/system_updater/download_backup.php.
#   3. view/admin_updater.html.twig: restore `&action=download_backup&file=...` links.
#   4. controller/admin_updater.php: restore case 'download_backup' + actionDownloadBackup().
#   5. OidcProvider/.htaccess:137 may now be the only line blocking /backups/.
```

## What stays as defence in depth

- `OidcProvider/.htaccess:136-137` (the `RewriteRule ^backups(/.*)?$ - [F,L]`) stays
  in place.  The new sibling path is unreachable by definition, but if a
  legacy install still has `FS_FOLDER/backups/` on disk, Apache will
  continue to 403 it.

- The legacy `index.php?page=admin_updater&action=download_backup&file=...`
  controller arm is removed.  A request to that URL now hits the
  controller's default switch case (no action) and renders the page
  without streaming — safe, but no longer functional.  Update any
  external links.

- `plugins/system_updater/.htaccess` adds a `download_backup.php`
  `FilesMatch` block: `mod_deflate` off, `mod_security2` off,
  `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.  These
  are belt-and-braces — the PHP script already emits equivalent headers.
