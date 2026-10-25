# Delta for plugin-backup-access

## Provenance

- **Source**: `proposal.md` → "Relationship to this plugin's canonical specs" (first bullet)
  and Capabilities note: *"`plugin-backup-access` gains a scenario, not a requirement
  change: it already demands an authenticated session."*
- **Change class**: **MODIFIED** requirement, scenarios only. No requirement is added or
  removed and no normative sentence of the existing requirement changes. The canonical text
  was correct; the code contradicted it (`VULN-0004` made the guard pass on a
  self-minted framework token, so an anonymous request produced 403 instead of the
  documented 401).
- **Identifier note**: the canonical capability names its requirements without stable IDs.
  `BA-01`/`BA-02` below are introduced by this delta as anchors for the added scenarios;
  the requirement headings keep their canonical names so a merge is a pure append.

## MODIFIED Requirements

### Requirement: BA-01 — Auth-Gated Download Endpoint

*(Requirement text unchanged. The following scenarios are added; the existing
"Admin downloads a real backup", "Unauthenticated request is rejected" and "Invalid CSRF
token is rejected" scenarios remain in force and are not restated here.)*

The endpoint MUST refuse on the **session guard** before the plugin-token check, so an
anonymous caller always sees the documented 401 and never a 403. A CSRF token of any kind —
framework or plugin — MUST NOT convert an anonymous request into an authenticated one, and
a structurally valid session for a **non-administrator** MUST be refused with the same 401
and the same body as the anonymous case.

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

### Requirement: BA-02 — Stepped Resumable Restore

*(Requirement text unchanged. One scenario is added about client tolerance of a transient
denial while the restore is rewriting `fs_users`.)*

The restore rewrites the application database, `fs_users` included. A `progress`/`status`
poll served during that window re-reads `fs_users` to verify the administrator flag
(`AUTH-03`, fail-closed). The polling client MUST therefore treat a **transient** denial
during an in-progress restore as retryable: it MUST retry with backoff and MUST NOT present
it as a failed restore, and it MUST NOT cancel or abort the server-side restore (which
continues under `ignore_user_abort`).

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

## Traceability

| Requirement | Capability | Finding / source | Proposal success criterion |
|---|---|---|---|
| BA-01 | plugin-backup-access | VULN-0004 | anonymous + core token → 401; non-admin → 401 |
| BA-02 | plugin-backup-access | spec-phase consequence of `AUTH-03` | genuine administrator still polls/downloads |

## Testing notes — this suite has no database

- `BA-01` is asserted at the guard level (injected session maps and an injected
  administrator verdict) and, for the ordering scenario, at the script level with the guard
  instrumented — the existing `DownloadBackupScriptTest` sandbox pattern is reused.
- `BA-02` is asserted on the polling client's retry policy with a stubbed transport that
  returns a denial a bounded number of times and then a success.
- No scenario in this delta requires a live `fs_users`.
