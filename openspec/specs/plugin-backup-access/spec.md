# Plugin Backup Access Specification

## Purpose

Secure storage and authenticated access to backup files produced by the
`system_updater` plugin. Backups MUST NOT be reachable via direct HTTP
request, and any legitimate download MUST require an authenticated
administrator session with a valid CSRF token.

## Requirements

### Requirement: Backup Storage Location

The system MUST resolve the backup directory to a path **outside** the
webroot (`FS_FOLDER`). The default SHALL be `dirname(FS_FOLDER) . '/backups'`.
Operators MAY override the default by defining a `FS_BACKUP_DIR` constant.
The directory MUST be created on demand with restrictive permissions
(`0700`) and MUST be rejected at startup if not writable.

#### Scenario: Default location lives outside the webroot

- GIVEN no `FS_BACKUP_DIR` constant defined
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `dirname(FS_FOLDER) . '/backups'`
- AND the path is not a descendant of `FS_FOLDER`

#### Scenario: Operator override takes effect

- GIVEN `FS_BACKUP_DIR = '/var/backups/fsframework'`
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `/var/backups/fsframework`

#### Scenario: Unwritable directory is a startup error

- GIVEN the resolved backup directory exists but is not writable
- WHEN a backup is requested
- THEN the operation fails with a clear error
- AND no partial file is left behind

### Requirement: Auth-Gated Download Endpoint

A new endpoint `download_backup.php` MUST require an authenticated
session and a valid CSRF token. It MUST stream the requested file with
`Content-Disposition: attachment` and `Content-Type: application/octet-stream`,
and MUST log every download (user, file, size, IP, timestamp).

#### Scenario: Admin downloads a real backup

- GIVEN an authenticated admin session and a valid CSRF token
- WHEN `download_backup.php?file=example-com_2026-06-26_19-52-42_db.sql.gz` is called
- THEN HTTP 200 with the file stream
- AND the response carries `Content-Disposition: attachment` and the correct `Content-Length`
- AND a log line is appended with user, file, size, IP, timestamp

#### Scenario: Unauthenticated request is rejected

- GIVEN no session or an expired session
- WHEN the endpoint is called
- THEN HTTP 401 with a JSON error body

#### Scenario: Invalid CSRF token is rejected

- GIVEN an authenticated session but an invalid `su_csrf_token`
- WHEN the endpoint is called
- THEN HTTP 403 with a JSON error body
- AND the failure is logged

### Requirement: Path Traversal Protection

The endpoint MUST reject any `?file=` value that resolves outside the
backup directory. MUST use `basename()` to strip directory components and
`realpath()` plus a `str_starts_with()` containment check against the
canonical backup path. Any rejected attempt MUST be logged as a security
event.

#### Scenario: Traversal via `..` is rejected

- GIVEN `?file=../../../../etc/passwd`
- WHEN the endpoint is called
- THEN HTTP 400 with a JSON error body
- AND a security log entry is appended

#### Scenario: Absolute path is rejected

- GIVEN `?file=/etc/shadow`
- WHEN the endpoint is called
- THEN HTTP 400 with a JSON error body

#### Scenario: Symlink escape is rejected

- GIVEN a symlink inside the backup dir that points outside it
- WHEN the endpoint is called with the symlink name
- THEN HTTP 400 with a JSON error body

#### Scenario: Missing file is a 404

- GIVEN a well-formed but non-existent filename
- WHEN the endpoint is called
- THEN HTTP 404 with a JSON error body
- AND no log entry is appended (not a security event)

### Requirement: Defense in Depth

The Apache `.htaccess` rule blocking `/backups/` MUST remain in place to
protect Apache/Plesk production environments where the directory is still
inside the webroot during the transition window. The new endpoint MUST
NOT rely on this rule for its security.

#### Scenario: Apache returns 403 for legacy webroot path

- GIVEN the legacy `FS_FOLDER/backups/` directory still exists on disk
- AND the Apache `.htaccess` rule is active
- WHEN an unauthenticated client requests `/backups/foo.sql.gz`
- THEN the server returns HTTP 403

### Requirement: Restore Compatibility

The existing `process_restore.php` flow MUST continue to work against
the new `BACKUP_DIR` without any UI or behavior change.

#### Scenario: Restore reads from the new directory

- GIVEN a backup file exists in the new `BACKUP_DIR`
- WHEN an admin triggers a restore from `admin_updater`
- THEN the restore succeeds and the source file matches the new path

### Requirement: UI Download Action

`admin_updater.html.twig` MUST render a "Download" button per backup
row that links to `download_backup.php?file={basename}&su_csrf_token={token}`.
The button MUST be hidden for non-admin users.

#### Scenario: Admin sees a download button per row

- GIVEN an authenticated admin viewing the backups list
- WHEN the template renders
- THEN each backup row has a link to `download_backup.php` with a fresh CSRF token

## Security Constraints

- MUST use `hash_equals()` for all token comparisons.
- MUST log every denied request as a potential probe.
- MUST NOT include the absolute backup path in any error response body.
