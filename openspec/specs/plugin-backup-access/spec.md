# Plugin Backup Access Specification

## Purpose

Secure storage and authenticated access to backup files produced by the
`system_updater` plugin. Backups MUST NOT be reachable via direct HTTP
request, and any legitimate download MUST require an authenticated
administrator session with a valid CSRF token.

## Requirements

### Requirement: Backup Storage Location

The system MUST resolve the backup directory outside the webroot
(`FS_FOLDER`) with this automatic chain (zero-ops contract, no manual
`mkdir`/`chown`/`mv`):

1. `FS_BACKUP_DIR` constant when defined — used **strictly** (it is the
   only candidate; an unusable override is a loud configuration error,
   never a silent fallback).
2. A sibling of the framework root with a random 16-hex suffix
   (`dirname(FS_FOLDER) . '/backups-<hex>'`) — outside the webroot and
   undiscoverable by URL scanners.
3. The web user's home directory with the same random suffix
   (`<home>/backups-<hex>`) — outside the webroot, writable in shared
   hosting with no admin step (also covers nginx).
4. A protected legacy dir inside the webroot
   (`FS_FOLDER/backups-<hex>`) as last resort — guarded by `.htaccess`
   (`Require all denied` + legacy `Order/Deny`) and `index.php`.

The directory MUST be created on demand with restrictive permissions
(`0700`), MUST be guarded by `.htaccess`/`index.php` on every run, MUST
be excluded from file backups when it lives inside the webroot, and MUST
surface a loud error when none of the candidates is usable. Legacy
backups found in the old fixed `FS_FOLDER/backups` dir MUST be migrated
best-effort (copy + size verify + cleanup). The random suffix MUST be
persisted in `tmp/system_updater_backup_dir.txt` under an exclusive lock
and re-adopted from the existing directory if the state file is lost.

#### Scenario: Default location lives outside the webroot

- GIVEN no `FS_BACKUP_DIR` constant defined and a writable sibling
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `dirname(FS_FOLDER) . '/backups-<16hex>'`
- AND the path is not a descendant of `FS_FOLDER`

#### Scenario: Sibling unusable falls back to the user home

- GIVEN no `FS_BACKUP_DIR` constant defined
- AND the sibling is not writable (e.g. `/var/www` without permissions)
- AND the web user's home IS writable
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `<home>/backups-<16hex>` (outside the webroot)

#### Scenario: Everything external fails falls back to protected legacy

- GIVEN no `FS_BACKUP_DIR` constant defined
- AND neither the sibling nor the home is writable
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `FS_FOLDER/backups-<16hex>` (inside the webroot)
- AND the directory is guarded by `.htaccess` and `index.php`
- AND a compatibility message informs the operator

#### Scenario: Operator override takes effect strictly

- GIVEN `FS_BACKUP_DIR = '/var/backups/fsframework'`
- WHEN `backup_manager` resolves the backup path
- THEN the path equals `/var/backups/fsframework`
- AND no other candidate is used even if it is writable

#### Scenario: Unwritable explicit override is a startup error

- GIVEN `FS_BACKUP_DIR` points to a directory that exists but is not writable
- WHEN a backup is requested
- THEN the operation fails with a clear error
- AND no partial file is left behind

#### Scenario: Legacy backups migrate automatically

- GIVEN the old fixed `FS_FOLDER/backups` directory still holds files
- WHEN `backup_manager` starts with a writable resolved directory
- THEN the files are copied to the resolved directory with size verification
- AND the legacy copies are removed
- AND a message reports the migration

### Requirement: Auth-Gated Download Endpoint

A new endpoint `download_backup.php` MUST require an authenticated
session and a valid CSRF token. It MUST stream the requested file with
`Content-Disposition: attachment` and `Content-Type: application/octet-stream`,
and MUST log every download (user, file, size, IP, timestamp).

The endpoint MUST refuse on the **session guard** before the plugin-token check, so an
anonymous caller always sees the documented 401 and never a 403. A CSRF token of any kind —
framework or plugin — MUST NOT convert an anonymous request into an authenticated one, and
a structurally valid session for a **non-administrator** MUST be refused with the same 401
and the same body as the anonymous case.

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

#### Scenario: Anonymous request carrying a self-minted framework token is 401

- GIVEN no session, and `_csrf_token` (or `_token`, or `X-CSRF-Token`) minted for the
  requester's own anonymous session by the public login page
- WHEN `download_backup.php?file=<valid name>` is called
- THEN HTTP 401 with a JSON error body
- AND the response is not a 403
- AND the plugin-token check was never reached

*(Previously unasserted: this request returned 403, which proved the guard had already
passed.)*

#### Scenario: Authenticated non-administrator is 401 with the anonymous body

- GIVEN a session written by a real login for a user whose `admin` flag is false
- AND a valid `su_csrf_token` for that session
- WHEN the endpoint is called
- THEN HTTP 401
- AND the body is byte-identical to the anonymous case
- AND no file stream is emitted

#### Scenario: The guard runs before the token check

- GIVEN an anonymous request carrying a `su_csrf_token` that is valid in some session
- WHEN the endpoint is called
- THEN the refusal is produced by the session guard
- AND the order is asserted by instrumenting the guard's verdict, not inferred from the
  status code alone

#### Scenario: A genuine administrator is unaffected

- GIVEN an administrator session written by the application login and a valid
  `su_csrf_token`
- WHEN the endpoint is called with a legitimate filename
- THEN HTTP 200 with the file stream, `Content-Disposition: attachment` and the correct
  `Content-Length`
- AND a log line is appended

### Requirement: Path Traversal Protection

The endpoint MUST reject any `?file=` value that resolves outside the
backup directory. MUST canonicalize the backup dir with `realpath()`
before building the containment prefix, MUST reject values containing
directory separators or `.`/`..` components **by shape** as traversal,
MUST use `basename()` for valid simple filenames, and MUST use
`realpath()` plus a `str_starts_with()` containment check against the
canonical backup path. Any rejected attempt MUST be logged as a security
event (shape-based rejections included — they are probes, not missing
files).

#### Scenario: Traversal via `..` is rejected

- GIVEN `?file=../../../../etc/passwd`
- WHEN the endpoint is called
- THEN HTTP 400 with a JSON error body
- AND a security log entry is appended

#### Scenario: Traversal by shape is rejected as a security event

- GIVEN `?file=../evil.sql.gz`
- WHEN the endpoint is called
- THEN HTTP 400 with a JSON error body
- AND a security log entry is appended (not a silent 404)

#### Scenario: Absolute path is rejected

- GIVEN `?file=/etc/shadow`
- WHEN the endpoint is called
- THEN HTTP 400 with a JSON error body

#### Scenario: Non-canonical backup dir still serves legitimate files

- GIVEN the resolved backup dir contains `..` components or symlinks
- WHEN the endpoint is called with a legitimate filename
- THEN the file is served (the containment prefix is canonicalized first)

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

`process_restore.php` MUST resolve the backup from the new `BACKUP_DIR`.

> Changed 2026-09-28: the restore is no longer a single long-lived SSE request.
> The UI and transport changed on purpose; see "Stepped Resumable Restore".

#### Scenario: Restore reads from the new directory

- GIVEN a backup file exists in the new `BACKUP_DIR`
- WHEN an admin triggers a restore from `admin_updater`
- THEN the restore resolves the source file from the new path

### Requirement: Stepped Resumable Restore

`process_restore.php` MUST expose `action=begin` and `action=chunk` so that no
single HTTP request performs more than a bounded amount of restore work. The
production host kills the PHP process a few seconds into a request, so a single
request restore can never complete.

The restore state MUST be persisted so an interrupted restore resumes from the
last executed statement instead of restarting the import.

The restore rewrites the application database, `fs_users` included. A `progress`/`status`
poll served during that window re-reads `fs_users` to verify the administrator flag
(`AUTH-03`, fail-closed). The polling client MUST therefore treat a **transient** denial
during an in-progress restore as retryable: it MUST retry with backoff and MUST NOT present
it as a failed restore, and it MUST NOT cancel or abort the server-side restore (which
continues under `ignore_user_abort`).

#### Scenario: begin arms state, heartbeat and maintenance

- GIVEN an authenticated admin with a valid plugin CSRF token
- WHEN `action=begin` is requested for a backup
- THEN it heals any stale restore lock, activates maintenance with a heartbeat,
  creates the restore state and executes only the first bounded unit

#### Scenario: chunk resumes from the persisted checkpoint

- GIVEN an in-progress restore state
- WHEN `action=chunk` is requested
- THEN it executes one bounded unit, persists the checkpoint after every executed
  statement, refreshes the maintenance heartbeat, and reports `done` on the last step

#### Scenario: a killed process does not leave the site in maintenance

- GIVEN a restore lock whose heartbeat is older than the stale threshold
- WHEN any plugin request evaluates the lock
- THEN the orphan lock is cleared and public routes stop returning 503

#### Scenario: chunk requires CSRF

- GIVEN `action=chunk` without a valid `su_csrf_token`
- THEN the guard responds with an SSE error and no state is mutated

#### Scenario: state never carries credentials

- GIVEN a persisted restore state
- THEN it contains no database password or other secret

#### Scenario: A transient denial during restore does not surface as failure

- GIVEN an in-progress stepped restore whose `fs_users` table is momentarily unavailable
- WHEN a `progress` poll receives the guard's denial
- THEN the client retries with backoff
- AND the restore modal keeps showing progress, not an error
- AND no abort request is issued

#### Scenario: A permanent denial still surfaces

- GIVEN a session that is no longer valid after the restore finished
- WHEN a poll is issued and keeps being denied after the retry budget
- THEN the UI reports that the session must be re-established
- AND it does not claim a successful restore on unverified evidence

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
