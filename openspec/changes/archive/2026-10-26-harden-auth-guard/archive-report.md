# Archive Report — harden-auth-guard

**Archived**: 2026-10-26
**Change**: `harden-auth-guard` (PLUGIN change — `plugins/system_updater/`)
**Artifact store**: plugin-local OpenSpec (`plugins/system_updater/openspec/`), `ownership: plugin-local`
**Archive path**: `plugins/system_updater/openspec/changes/archive/2026-10-26-harden-auth-guard/`
**Status**: **ARCHIVED** — six deltas merged into the canonical source of truth
**Core repository**: **untouched** (no file under `base/`, `src/`, `controller/`, `model/`, `themes/`, `api.php`, `cron.php` or the core `openspec/` was modified by this change or by this archive)
**Verdict at close**: **ARCHIVE-READY**

## Verdict history and how the gate was satisfied

| Pass | Verdict | Blocking set |
|---|---|---|
| Verify, first pass (`51df7f8`) | **NOT ARCHIVE-READY** | **C-1** (per-installation secret was keyed by the parent directory, so two installations under one parent shared one secret, measured end-to-end) and **C-2** (the operator pin lost to the persisted value, so `RACC-03`'s order and `RACC-06`'s escape 3 were false), plus **W-1**/**W-2** to amend before merge |
| Re-verification (`c4d74ba`) | "one item remains, and it is documentation only" | **N-1** — `--secret` printed a value the gate refused whenever a pin was defined; **S-7** precision on the symlink example |
| Archive phase (this report) | **ARCHIVE-READY** | C-1, C-2, W-1, W-2, W-3 reconciled; **N-1 closed in code** (`6a250a4`); **S-7 closed by `0a3e5e4`** |

The re-verification framed N-1 as a documentation item. That framing was rejected: a CLI that hands
the operator a credential the gate refuses is a **code** defect, and it was fixed in code. A
clearly-marked **"Final resolution note — N-1 closed in code"** was appended to `verify-report.md`
recording the correction, the commit and the code readback. Nothing in the first verdict or in the
re-verification section was rewritten or deleted; the appended note supersedes only N-1's
classification and closure suggestion.

## Pre-archive gates

| Gate | Requirement | Result |
|---|---|---|
| Artifacts present | `proposal.md`, `specs/` (six deltas), `tasks.md`, `verify-report.md` | **PASS** — 9 files, all present (this change carried no `design.md`; the spec phase's ordering and test-shape guidance lives in `tasks.md` under "Handed to this phase") |
| Verify blockers closed | C-1, C-2 genuinely fixed, W-1/W-2 amended, W-3 recorded, N-1 closed | **PASS** — each re-checked in the shipped tree and in the deltas (tables below) |
| N-1 fix present in code | `recovery_secret_cli_pin()` exists and `recovery_secret_cli_run()` resolves it first | **PASS** — `lib/recovery_secret.php:655-663` (resolver) and `:709-716` (pin resolved before `ensure()`/`rotate()`) |
| No unchecked implementation task | `tasks.md` | **PASS** — 5 unchecked boxes, every one a PR-body obligation, an explicit phase note or an operator item (see "Task gate") |
| Final suite green | plugin suite | **PASS** — 656 tests / 2573 assertions / 2 skipped, 0 failures |
| No entry left outside `archive/` | `openspec/changes/` | **PASS** — `harden-auth-guard/` no longer exists |

### The corrected statements, re-checked at archive time

| Finding | Correction | Present in the merged delta text? |
|---|---|---|
| **C-1** | The outside-webroot candidate is keyed on the **installation** (`<parent>/system_updater_recovery_<first 16 hex of sha256(realpath(root))>/system_updater_recovery_secret.php`), not on the parent | **YES** — `RACC-02` "How per-installation uniqueness is achieved", including the shared-parent scenario and the statement that the digest names a *location* only and is never a seed |
| **C-2** | The operator pin **wins** at the gate, always; `config.php` is read on every gate evaluation | **YES** — `RACC-01` (four binding properties of the read: error-suppressed, output-buffered, `Throwable`-caught incl. `ParseError`, idempotent, plus the damaged-file fallback), `RACC-03` (order `pin → persisted → generate`), `RACC-06` escape 3 ("primary override, not a lost-secret-only fallback") |
| **W-1** | A missing `config.php` is **HTTP 500**, plain text, naming the file and the directory | **YES** — `RACC-01` scenario "A missing `config.php` is the only hard stop" |
| **W-2** | "Loud" is scoped to the library, the CLI and the server log; the web path stays silent | **YES** — `RACC-02` body and its scenario "An unwritable secret location is loud on the operator surfaces and silent over HTTP" |
| **W-3** | The `fs_session_manager::isLoggedIn()` fallback is a **recorded, deferred** gap | **YES** — `AUTH-01` carries the paragraph and the failure-mode inventory carries row 5, both marked deferred and not accepted |
| **N-1** | The CLI read escape resolves the pin first, so it reports the value the gate accepts | **YES in code** (`6a250a4`), so `RACC-03`'s "identical in every consumer" and `RACC-06` escape 1 stay true **as written** — no delta amendment was needed and none was made |
| **S-7** | A symlink folds onto one directory only when it sits in the parent of the real installation | **YES** — `RACC-02`'s folding sentence, corrected by `0a3e5e4` |

## What shipped — nine slices, one merge commit each

| PR | Workstream | Requirements | Merge commit | Merge subject |
|---|---|---|---|---|
| 1 | W1 — guard identity resolution | AUTH-01, AUTH-02, AUTH-03, AUTH-06 | `a2966b6` | Merge PR 1: resolve the guard's identity from a real session |
| 2 | W2 — refusal surface, ordering, client tolerance, minting subset | AUTH-04, AUTH-05 (subset half), CSRF-01 (guard half), BA-01, BA-02 | `64a1cb4` | Merge PR 2: make the guard's refusal accountable |
| 3 | W3 — console secret lifecycle | RACC-02, RACC-03, RACC-06 | `800e824` | Merge PR 3: per-installation console secret lifecycle |
| 4 | W4 — console gate, marker, session bootstrap | RACC-04, RACC-05, RACC-08 | `09e3df6` | Merge PR 4: gate the recovery console behind a per-installation secret |
| 5 | W5 — console credential policy primitives | REC-02, REC-03, REC-04, REC-05 | `15b687d` | Merge PR 5: console credential policy primitives |
| 6 | W6 — console legacy verifier + login-form token | REC-07, REC-01 (token primitive) | `ae34f9f` | Merge PR 6: verify legacy hashes and mint the form token without the core |
| 7 | W7 — credential handler + core-dependency removal | REC-01, REC-05, REC-06, REC-08, RACC-01 | `4ffe24f` | Merge PR 7: make the recovery console independent of the core |
| 8 | W8 — two-gate mint + stepped restore drive | RACC-07, AUTH-05 (equality half), CSRF-01 (equality half) | `b465d6f` | Merge PR 8: let the recovery console restore again |
| 9 | W9 — core update stops writing the planning tree | PU-16 | `1088927` | Merge PR 9: stop the core update from writing the planning tree |

PR 1–4 merged 2026-10-25; PR 5–9 merged 2026-10-26. PR 9 was the detached slice: it shares no file,
no test seam and no ordering constraint with W1…W8.

**Post-verify commits on top of the nine slices** (all part of this archived candidate):

| Commit | Purpose |
|---|---|
| `549d5b9` | C-1 — key the recovery secret on the installation, not its parent |
| `94eaca9` | W-1 — answer a missing `config.php` with a 500 that names the directory |
| `7bd9126` | C-2 — let the operator pin win at the gate, always |
| `e1e8480` | Merge the post-verify criticals |
| `c2f521d`, `c4d74ba` | Record the criticals and reconcile `RACC-01/02/03/06` + `AUTH-01` with the shipped pin precedence |
| `6a250a4` | **N-1** — report from `--secret` the value the gate accepts |
| `0a3e5e4` | **S-7** — state the real condition behind `RACC-02`'s path folding |
| `0349d38` | Record the N-1 fix and the S-7 clause in `tasks.md` |

In one paragraph: identity for the standalone administrative scripts now comes only from a session
written by a real login, with the administrator flag re-read per request and failing closed; the
guard's failure modes are one uniform 401 refusal, and `start`/`begin`/`chunk` keep the plugin token
as their only authenticator with exactly two gated minting points; the recovery console depends on
`config.php` and nothing else from the core, is reachable only with a per-installation 64-hex secret
stored `0600` in a `0700` per-installation directory outside the webroot, is indistinguishable from a
missing path without it, carries authorization in its own session marker afterwards, and can actually
restore (stepped `begin`/`chunk`, token on every request, minted only after the secret **and** an
authenticated administrator session); and a core update no longer writes the core's planning tree
into the installation, with the allowlist and the nginx sample proven equal by a drift test.

## Delta → canonical merge

| Delta | Requirements / scenarios | Canonical target | Action | Scenarios merged |
|---|---|---|---|---|
| `specs/plugin-auth-guard/spec.md` | AUTH-01…AUTH-06 (6 / 20) | `openspec/specs/plugin-auth-guard/spec.md` | **CREATED** | 20 |
| `specs/plugin-recovery-access/spec.md` | RACC-01…RACC-08 (8 / 35) | `openspec/specs/plugin-recovery-access/spec.md` | **CREATED** | 35 |
| `specs/plugin-recovery-login/spec.md` | REC-01…REC-08 (8 / 28) | `openspec/specs/plugin-recovery-login/spec.md` | **CREATED** | 28 |
| `specs/plugin-csrf-token/spec.md` | CSRF-01 (1 / 5) | `openspec/specs/plugin-csrf-token/spec.md` | **MODIFIED** — one requirement section appended | 5 |
| `specs/plugin-backup-access/spec.md` | BA-01 (+4 scenarios, +1 clarification), BA-02 (+2 scenarios, +1 clarification) | `openspec/specs/plugin-backup-access/spec.md` | **MODIFIED** — two clarification paragraphs and six scenarios inserted under the existing headings | 6 |
| `specs/plugin-updates/spec.md` | PU-16 (1 / 9) | `openspec/specs/plugin-updates/spec.md` | **MODIFIED** — one requirement section appended | 9 |

Totals: **26 requirements** (22 in the three created capabilities, 2 modified, 2 added) and
**103 scenarios** merged. Measured at archive time from the archived deltas; `tasks.md` quotes
"26 requirements / 102 scenarios", which was true before the reconciliation added one scenario to
the amended `RACC` text.

### Promotion mechanics — the three created capabilities

Each delta was already requirement-shaped. The canonical file was composed mechanically as:

```
# <capability> Specification      <- new H1 in the canonical naming style
## Purpose                        <- new, written from the delta's Provenance
## Requirements                   <- the delta's requirement sections, extracted byte-for-byte
<delta's Failure-mode inventory>  <- carried verbatim (it is normative record, referenced by the deltas)
<delta's Testing notes>           <- carried verbatim
```

Removed: each delta's `## Provenance` block (change-scoped metadata: source finding, capability
status, consumer list) and — for `plugin-auth-guard` only — the `### Not from the proposal`
preamble (SPEC-ADD-1/SPEC-ADD-2 are narrative, not requirements, and the verify report directed its
removal). `plugin-recovery-login` keeps its **`## Recorded gap`** section verbatim: the disabled-
administrator decision is a decision, not an omission, and dropping it would erase it.

Readback: for all three files the requirement body was `diff`-ed against the same region of the
archived delta and returned **identical** (exit 0); requirement and scenario counts match the delta
exactly (6/20, 8/35, 8/28). Only the H1, the `## Purpose` and the `## Requirements` heading are new
text.

### Merge mechanics — `plugin-csrf-token` (MODIFIED, English)

The canonical file names its requirements **without IDs**. The delta's
`### Requirement: CSRF-01 — A CSRF token is never an authentication credential` was therefore
appended as `### Requirement: A CSRF Token Is Never an Authentication Credential`, with the
`CSRF-01` anchor kept as the first token of the body so traceability survives. Inserted at the end
of the `## Requirements` section, before `## Security Constraints`. Lines 1–104 of the canonical
file (everything before the insertion) were verified **identical** to the pre-merge revision. No
existing text changed; there is no contradiction with the canonical "Backward Compatibility"
requirement because CSRF-01 scopes its prohibition to the standalone session guard.

### Merge mechanics — `plugin-backup-access` (MODIFIED, English)

Two clarification paragraphs and six scenarios were inserted by anchor, in the delta's words:

- **`Auth-Gated Download Endpoint`** — the paragraph "The endpoint MUST refuse on the session guard
  before the plugin-token check …" after the existing requirement body, then the four delta
  scenarios before `### Requirement: Path Traversal Protection`.
- **`Stepped Resumable Restore`** — the paragraph "The restore rewrites the application database,
  `fs_users` included …" after the existing requirement body, then the two delta scenarios before
  `### Requirement: UI Download Action`.

The `BA-01`/`BA-02` heading prefixes were **not** carried (the canonical file names requirements
without IDs); the anchors remain in the deltas and in the verify report. The added scenario
"A genuine administrator is unaffected" restates, in more detail, the canonical "Admin downloads a
real backup" scenario; it was carried because it is the regression anchor the verify phase cites for
`BA-01`, and it adds the token precondition and the log-line assertion explicitly.

**Count correction:** the verify report's per-delta verdict says "insert six scenarios under
`Auth-Gated Download Endpoint` and two under `Stepped Resumable Restore`". The delta carries **four**
under the first and **two** under the second — six in total. The merge applied the delta's actual
content (23 → 29 scenarios in the canonical file).

### Merge mechanics — `plugin-updates` (MODIFIED, Spanish) and the language decision

Measured before merging: `plugin-updates`'s canonical file is **Spanish** with `### PU-NN — Title`
headings, WHEN/THEN prose and Spanish `#### Scenario:` blocks; `plugin-backup-access` and
`plugin-csrf-token` are **English**. The rule applied, matching the core archive's precedent, is that
a **created** capability follows the dominant language of the tree it joins and a **modified** file
keeps the language it already uses. Therefore:

- the three **created** capabilities are **English** (their two canonical siblings are English);
- `plugin-updates` received **PU-16 in Spanish**, using its `### PU-16 — …` heading shape and its
  WHEN/THEN phrasing;
- **no file is bilingual and no canonical file was translated.**

PU-16's sub-clause headings from the delta (`#### The prune belongs to …`, etc.) were rendered as
**bold lead-ins** in Spanish rather than `####` headings, so that `####` inside that file continues
to mean "scenario" and only one heading convention exists. The allowlist, both source branches, the
effective-source-root rule, the containment and symlink rules, idempotence, the unremovable-but-
non-fatal rule, the success-only rule, and the drift test with its always-runnable half and its
visible skip are all carried. The delta's "Recorded consequences" table was carried as
**"Consecuencias registradas (nombradas, no ocultas)"**; the change-gate bullet list ("Success
criteria this requirement contributes to") was **not** carried — it restates the scenarios and is
change-scoped.

### Text deliberately preserved, not paraphrased

- **The pin-wins order** — `pin → persisted → generate`, stated identically in `RACC-03`'s body, its
  "The operator pin wins" scenario, `RACC-06` escape 3, and `RACC-01`'s dependency contract.
- **The `config.php`-on-every-evaluation consequence** — with all four binding properties of the read
  (error-suppressed `@include`, output-buffered, `Throwable` catch covering `ParseError`, idempotent
  so nothing is redeclared) and the fallback: a damaged file yields "no pin" and the gate falls back
  to the persisted value.
- **`RACC-02`'s per-installation directory** — `<parent>/system_updater_recovery_<16-hex sha256 of
  realpath(root)>/system_updater_recovery_secret.php`, directory `0700`, file `0600`, fixed operator-
  facing file name, digest is a location and never a seed — **and its real symlink-folding
  condition**: trailing slash and redundant `..` fold; a symlink folds only when it sits in the
  parent directory of the real installation.
- **`RACC-07`'s two-gate mint** — `secret_compared` → `config_loaded` → `authenticated` →
  `token_minted`, with the **equality set** `{admin_updater, recovery.php}`, no `process_*` in it, no
  third point, `action=start` never offered, the token on every issued request, and the recorded
  statement that validation cannot tell the two minting points apart and MUST NOT try to.
- **`AUTH-01`'s W-3 deferred gap** — the `fs_session_manager::isLoggedIn()` fallback, unreachable in
  the standalone bootstrap, recorded as deferred and explicitly **not** accepted, with the warning
  that any future `require` of `base/fs_autoload.php` in that graph would turn it into an `AUTH-03`
  bypass.

## Test baseline (measured in this working tree, at archive time)

No implementation byte and no test byte was modified by this archive phase, so the baseline cannot
have moved. It was nevertheless re-measured:

| Command | Exact result | Exit |
|---|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` | **`Tests: 656, Assertions: 2573, Skipped: 2`** — 0 failures, 0 errors (8.2 s) | `0` |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root) | **`Tests: 3456, Assertions: 15004, Failures: 12, Skipped: 26`** | `1` |
| `ddev exec php vendor/bin/phpunit -c plugins/OidcProvider/phpunit.xml` | **`Tests: 668, Assertions: 2802, Failures: 12, Skipped: 2`** | `1` |

The plugin suite is exactly at the baseline its own `tasks.md` records for the N-1/S-7 branch
(656 / 2573 / 2 skipped, was 650 / 2515 / 2 before it). The **two skips are pre-existing and
unrelated**: `SelfUpdateSessionBindingTest::bindingIsEmptyWithoutASessionId` ("There is an active PHP
session in this process") and `SessionAuthTest::testResolveCookiePathUsesRootForDocumentRootInstall`
("FS_PATH ya estaba definido").

**The 12 root-suite failures are all `plugins/OidcProvider`** — a separate git repository this change
never touched. Running that plugin's own suite reproduces exactly the same twelve (668 tests, 12
failures), so no failure outside `OidcProvider` exists and none belongs to `system_updater`. The set:
8 × `admin_oidc_customer_access_htmxTest`, 1 × `admin_oidc_customer_access_validation_groupsTest::
testValidSelectionCallsSyncGruposThenApprove`, 2 × `ClienteRoleResolverIntegrationTest`
(`testMultiGrupoUnionResolvesAcrossDbRows`, `testOrphanGrupoMembershipIsSilentlySkipped`), 1 ×
`migration011_cliente_gruposTest::testCreatedTableCollationMatchesReferencedTables`.

PHPStan was **not** run and is **not applicable**: `plugins/system_updater` is not listed in the
core-owned `tools/phpstan-plugins.php`, and adding it would be a core-repository change. The static
gate for this plugin is the `php -l` sweep recorded in `verify-report.md` (0 failures) plus the
suite.

## What remains OPEN — NOT closed by this archive

**Archiving the SDD closes no finding and no gap.** Stated plainly:

1. **VULN-0001's live proof-of-concept is an operator retest.** The suite asserts the *mechanism*
   (CSRF verdict first, ban read before evaluation, one shared budget, one uniform body, one
   `password_verify()`); the end-to-end attack run — five rapid guesses from one address, a sixth,
   then a banned address — was never executed here. Probe **O.5**.
2. **`REC-08`'s demoted-administrator half needs a live `fs_users`.** The code re-reads
   `SELECT nick, admin` on the existing-session path and `die`s for a falsy flag, and the DB-free
   tests prove the marker alone renders the form — but "authenticate, clear `fs_users.admin`, reopen
   the console, observe the refusal" was not run. Probe **O.1**.
3. **The console-render → browser-cookie → `begin` leg is not exercised end to end.** The suite seeds
   the plugin session and asserts the minted token is accepted by `begin`/`chunk`; opening the
   console in a browser, logging in, clicking restore and observing `begin` then `chunk` accepted
   with the *minted* token needs a database. Probe **O.2**.
4. **`LoginThrottle`'s shared mode is still check-then-act.** `isThrottled()` then `recordFailure()`
   is two calls: under concurrency the shared path can admit more than the declared budget. The
   **fallback** limiter *is* atomic (one `flock()`, measured: 10 concurrent processes admit exactly
   six). Closing the shared window needs a `reserve()` on the core class — a core change, out of
   scope. Probe **O.4**.
5. **The legacy verifier is a second copy of the core algorithm by mandate.** `REC-07` requires a
   plugin-local SHA-1/MD5 bridge under A1, so `recovery_legacy_hash.php` reproduces the core's
   comparison set and order. The drift tripwire only fires where both repositories are present; a
   released plugin against a changed core is unprotected until the plugin is re-released.
6. **The `fs_session_manager::isLoggedIn()` fallback is a recorded deferred gap (W-3).** It is still
   wired (`lib/session_auth.php`), it never consults the administrator flag, it is dead in the
   standalone bootstrap because `base/fs_autoload.php` is not loaded there, and no test covers it. It
   is recorded in `AUTH-01` and in the failure-mode inventory as deferred, not accepted. Removing it,
   or gating it behind an explicit `admin` check, is a separate change.
7. **W-4 — `PU-16` still has two source-root resolvers.** The prune uses
   `resolveStagingSourceRoot()`; the copy uses `findFirstDirectory()`. They disagree on the flat
   uploaded-ZIP shape. Today no unpruned tree can be installed because `update_core()` rejects a
   source without `VERSION`, so the property holds for every reachable path — but by accident of an
   unrelated guard, not by construction. Probe **O.7**.
8. **A pin in force means `config.php` executes on every gate evaluation — and on `--secret`.** This
   is the accepted cost of the product owner's C-2 decision (the pin wins). It is bounded by the four
   properties the read carries (error-suppressed, output-buffered, `Throwable`-caught, idempotent)
   and by the fallback, and it is documented in `RACC-01`, `RACC-03` and `RACC-06` — but it does
   mean that a request which will be refused, and a CLI read escape, both execute the file.

Also still open, and **not** to be reported as closed:

- **W-5 — change size.** `git diff a9580ef..HEAD` measured at archive time (before the archive
  commit): **56 files, +24,037 / −314**, against a ~3,950-line forecast and an 800-line session
  review budget. The re-verification measured +23,546; the N-1 and S-7 fixes added the rest. A
  record-level warning, not a review-quality finding.
- **S-1…S-6** carried: `system_updater_session_has_user()` is dead production code with a fail-open
  shape (S-1); `RACC-08`'s third branch uses a different seed than the core (`'session:'` prefix
  missing) while a docblock says it is the same (S-2); the mint recorder labels a marker-authorized
  render `secret_compared` (S-3); `recovery.php` still echoes `$message` unescaped and unassigned —
  an XSS sink waiting for its first writer (**S-4**); the single token slot makes two console tabs
  invalidate each other (S-5); `AUTH-01`'s "no filesystem write" scenario is literally false on
  `download_backup.php`, whose bootstrap starts a session (S-6).
- **The disabled-administrator gap** (`fs_users.enabled` is not checked by the console) is an
  accepted, documented decision in `plugin-recovery-login`; no test asserts either direction.
- **`recovery_secret_resolve()` is still not consumed by any access decision.** It exists and is
  tested; the gate has its own call path. Dead-ish API that invites the C-2 confusion again.
- **O.3 and O.6** — a real core update on a disposable installation (both source branches), and
  cross-installation secret isolation on two real vhosts under one parent (the functional half was
  measured; the deployment half is owed).

## Operator handoff — carried unticked (O.1–O.7)

None of these is achievable from this repository, and none is undone by reverting the code. They are
carried exactly as recorded in `verify-report.md`, **unticked**:

| # | Probe | Closes | Why it cannot be asserted here |
|---|---|---|---|
| **O.1** | Authenticate an administrator, clear `fs_users.admin`, reopen the console → the "administrator required" path | REC-08 (second half) | Needs a live `fs_users`; the suite is DB-free |
| **O.2** | Open the console with the secret, log in, click restore, observe `begin` then `chunk` accepted with the *minted* token | RACC-07 (first scenario) | The authenticated render needs a database |
| **O.3** | A real core update on a disposable installation, with `git` available (clone branch) **and** without it (download branch), then `ls` the installation root for the eight names and `ls plugins/*/openspec` | PU-16 (end-to-end) | Needs network/`git` and a throwaway install; the suite stops at the staging directory |
| **O.4** | `LoginThrottle` shared mode under concurrency: N parallel submissions against a real cache | REC-04 (shared-mode ceiling) | Check-then-act in the core API; closing it needs a core `reserve()` |
| **O.5** | **VULN-0001 live PoC retest**: five rapid guesses from one address, then a sixth, then a banned address | VULN-0001 (whole) | The suite asserts the mechanism; the live attack run is an operator step |
| **O.6** | Cross-installation secret isolation on two real vhosts under one parent, each rotating independently and neither's `RACC-05` markers disturbed | RACC-02 (deployment half; the functional half is measured) | Needs two real installations |
| **O.7** | The two-resolver divergence (W-4) against a real flat uploaded ZIP on a live installation | PU-16 (flat-upload shape) | The suite builds the ZIP and calls `extractZipSafe()`, but never runs the full `update_core()` for that shape |

## Task gate — the five unchecked boxes

`tasks.md` carries **five** unchecked boxes. None is implementation work:

| Line | Unchecked item | Kind |
|---|---|---|
| `431` | PR 1 body states the measured before/after counts and names the AUTH-02-before-AUTH-01 order | **PR-body obligation** — no PR body exists in this phase |
| `555` | PR 2 body states that a 403-versus-401 assertion alone does not prove ordering, and what was asserted instead | **PR-body obligation** |
| `1142` | PR 6 body states the A1 ordering explicitly (REC-07 proven before the autoloader removal) — the box itself says "not applicable in this phase" | **PR-body obligation**, recorded in the implementation commit body instead |
| `1639` | PR 9 body states that `nginx-sample.conf` was read and not edited, and names the skip condition — "no PR was opened in the apply phase" | **PR-body obligation**, recorded in the apply report instead |
| `1801` | "**Not done here (separate phases)**: amend `RACC-01`…, `RACC-03`…, `RACC-06`…; re-run the verify phase; then archive" | **Phase note, now done** — `c4d74ba` amended the deltas and the re-verification was run. The box is **stale, not wrong**; `c4d74ba` did not touch `tasks.md`. It is carried unticked here because this phase may only append to `verify-report.md` |

They are carried into the archive **unticked**. Ticking them at archive time would convert an
unverified obligation into a false claim.

## Archive method and mechanical readback

- **Change folder move**: recursive snapshot to `/tmp/opencode/hag-snapshot/` (taken **after** the
  "Final resolution note" was appended) → `git mv openspec/changes/harden-auth-guard
  openspec/changes/archive/2026-10-26-harden-auth-guard` → `diff -r` snapshot vs destination:
  **empty, exit 0**. Counts match on both sides: **9 files** — `proposal.md`, `tasks.md`,
  `verify-report.md` and the six delta specs.
- **Naming convention**: `YYYY-MM-DD-{change-name}`, matching every existing entry in
  `openspec/changes/archive/` and the plugin's own `config.yaml`
  (`archive_root: plugins/system_updater/openspec/changes/archive/{YYYY-MM-DD}-{name}/`).
- `archive-report.md` (this file) is additive and did not exist in the source snapshot.
- **No entry for this change remains in `openspec/changes/` outside `archive/`** — confirmed:
  `harden-auth-guard/` no longer exists under `openspec/changes/`, `ls openspec/changes` lists only
  `archive` plus five unrelated active changes (`fix-backup-worker-recovery`,
  `public-plugin-updates-max-version`, `remote-catalog-install`, `restore-database-only`,
  `store-cascade-download`), and no markdown file outside the archive references the change name.

### Files this archive phase touched

| File | Action |
|---|---|
| `openspec/changes/archive/2026-10-26-harden-auth-guard/` | **MOVED** — 9 files, byte-identical (`diff -r` exit 0) |
| `openspec/changes/archive/2026-10-26-harden-auth-guard/verify-report.md` | **APPENDED** — "Final resolution note — N-1 closed in code"; the first verdict and the re-verification section untouched |
| `openspec/changes/archive/2026-10-26-harden-auth-guard/archive-report.md` | **CREATED** |
| `openspec/specs/plugin-auth-guard/spec.md` | **CREATED** — AUTH-01…AUTH-06, 20 scenarios |
| `openspec/specs/plugin-recovery-access/spec.md` | **CREATED** — RACC-01…RACC-08, 35 scenarios |
| `openspec/specs/plugin-recovery-login/spec.md` | **CREATED** — REC-01…REC-08, 28 scenarios + the recorded gap |
| `openspec/specs/plugin-csrf-token/spec.md` | **MODIFIED** — one requirement section appended (5 scenarios); lines 1–104 unchanged |
| `openspec/specs/plugin-backup-access/spec.md` | **MODIFIED** — two clarification paragraphs + six scenarios (23 → 29 scenarios) |
| `openspec/specs/plugin-updates/spec.md` | **MODIFIED** — PU-16 appended in Spanish (9 scenarios + recorded consequences) |

## Constraints honoured

- **No implementation code and no test was modified** by this phase.
- **The core repository was not touched**, and nothing under the core `openspec/` was created or
  edited. This change's SDD lived entirely in `plugins/system_updater/openspec/` (the anti-pattern
  of a core `openspec/changes/` entry for a plugin-only change was avoided throughout).
- The archived `proposal.md`, `tasks.md` and the six delta specs were not edited; the five unchecked
  obligations were carried, not ticked.
- The only pre-existing file modified is `verify-report.md`, and only by **appending** a clearly
  marked final resolution note — the original verdict, its findings, the re-verification section and
  their conclusions remain intact above it.
- No secret literal was introduced. The merged text carries no 64-hex value: the only hexadecimal
  strings are prose placeholders (`<digest>`, `d46af842…`, `8617b292…`) and the `su_csrf_token`
  *parameter name*.

### Precondition deviation (recorded)

The archive precondition is "working tree clean". At the start of this phase
`git status --porcelain` in the plugin repository reported **one** modified file:
`openspec/changes/harden-auth-guard/verify-report.md`, whose diff was the uncommitted
"Re-verification — 2026-10-26" section (247 added lines) — i.e. the record this phase was
instructed to build on and append to, and documentation rather than implementation. The phase
proceeded on that basis instead of stopping, and the append was carried into the archive with the
rest of the change. No implementation or test file was dirty at any point.

## Note on the core's VULN-0005

This change closes the plugin's two findings (`VULN-0001`, `VULN-0004`) and nothing in the core. The
companion core change `2026-10-25-harden-auth-boundaries` shipped the redaction sweep and the nginx
deny rules, but **the core's `VULN-0005` is still OPEN**: the published `admin` credential lives in
git history and in the public upstream repository, so it is closed only by (a) **rotating the `admin`
account's `log_key`** and (b) **applying the nginx deny rules on production** with `nginx -t` and a
reload. Neither is in any repository, and neither is done by this archive.

## SDD cycle complete

The change is archived and its requirements are the canonical source of truth: three new capabilities
(`plugin-auth-guard`, `plugin-recovery-access`, `plugin-recovery-login`), one added requirement in
`plugin-csrf-token` (`CSRF-01`), six scenarios and two clarifications in `plugin-backup-access`, and
`PU-16` in `plugin-updates`. The implementation was already live and green; this archive closes the
documentation gate that the verify phase opened — and it **closes no security finding**. VULN-0001
stays open until O.5 is run; the plugin-side controls are in place and pinned, but the live retest and
every other operator-owed probe are still owed.
