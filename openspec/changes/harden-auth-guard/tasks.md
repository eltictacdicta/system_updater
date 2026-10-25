# Tasks: Harden the plugin authentication guard

- **Change**: `harden-auth-guard` (plugin change — `plugins/system_updater/`)
- **Artifact store**: plugin-local OpenSpec (`plugins/system_updater/openspec/`); nothing is
  created in the core `openspec/`
- **Phase**: tasks
- **Inputs**: `proposal.md` (amended 2026-10-25 — provenance note, Scope table, risk register),
  the five deltas under `specs/` (**25 requirements / 93 scenarios**):
  `plugin-auth-guard` AUTH-01…06, `plugin-recovery-access` RACC-01…08,
  `plugin-recovery-login` REC-01…08, `plugin-csrf-token` CSRF-01,
  `plugin-backup-access` BA-01…02; `openspec/config.yaml` (`strict_tdd: true`)
- **Delivery strategy**: `auto-chain`
- **Review budget**: **800 changed lines** per slice (session `review_budget_lines`)
- **Total estimate**: **~3,600 changed lines / ~26 files** → must be sliced. Eight slices.
- **Core repository**: **out of scope**. No file under `base/`, `src/`, `controller/`, `model/`,
  `themes/` or the core `openspec/` is modified.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~3,600 (source/JS ~1,310, tests ~2,300) |
| 400-line budget risk | **High** — five of eight slices exceed the repository's 400-line default; **Low** against the 800-line session budget |
| Chained PRs recommended | **Yes** |
| Suggested split | 8 sequential slices, one per workstream (largest ~615 lines) |
| Delivery strategy | auto-chain |
| Chain strategy | Sequential to `main`, declared merge order, no tracker branch |
| Session review budget | 800 changed lines per slice |
| Largest single slice | ~615 lines (PR 4) — 77% of the 800-line budget |
| Smallest single slice | ~270 lines (PR 6) |

Decision needed before apply: **Yes — three items (D1 blocking; D2 and D3 have a stated default)**
Chained PRs recommended: **Yes**
Chain strategy: **Sequential to `main`**
400-line budget risk: **High as shipped per slice (~3,600 total); Low per slice against the 800-line budget**

**Forecast in plain terms.** This change is **~3,600 changed lines across ~26 files** — about
9× the repository's 400-line review default and 4.5× the 800-line session budget if shipped as
one unit. It cannot ship as a single PR, and unlike the companion core change
(`2026-10-25-harden-auth-boundaries`, ~1,180 lines / 6 slices / largest ~230) it cannot fit in
slices *under* 400 either: the tests dominate because the plugin suite has **no database**, so
almost every requirement is asserted through a purpose-built seam (pure decision functions, a
counting verifier, a temp install root with a sentinel `config.php`, a `php -S` subprocess with a
cookie jar). Eight slices of 270–615 lines each land under the 800-line session budget and each is
independently revertible. Every slice except PR 2, PR 3 and PR 6 exceeds the repository's 400-line
default *because of its test file*, which is the same pattern the core slices exhibited; the
forecast below does not pretend otherwise.

**D1 (blocking).** PR 1, PR 4, PR 5 and PR 8 sit between 500 and 620 lines. Auto-chain accepts
them under the 800-line budget; the repository's 400-line default does not. Decide before apply:
ship the eight slices as declared (recommended — each is one coherent security property plus its
harness), or split PR 1 / PR 4 / PR 5 / PR 8 into ~12 slices of 250–300 lines at the cost of
splitting a single decision across two PRs (PR 1's reader fix and its administrator verdict are
the same failure; PR 4's gate and its marker are the same request chain).

**D2 (default stated).** Which session the console opens. RACC-05 says the authorization marker
must be scoped to "the session that the console itself opened" and must not use a framework
session key. Default taken here: the console keeps opening the **framework session**
(`FS_SESSION_NAME`) so the panel single-sign-on and REC-08's existing-session re-check keep
working, and the marker lives in a **console-only namespace inside it**
(`$_SESSION['recovery_console']`), never in `_sf2_attributes`, never in `_csrf`, never as an
identity key, and read by no other script. If apply reads RACC-05 as requiring a dedicated session
name, the marker and the login form move to `RECOVERY_SESS_*`, single-sign-on is lost, and that
must be recorded as an applied deviation.

**D3 (default stated).** RACC-04 demands a body "byte-identical to a request for a non-existent
path". Measured on 2026-10-25: the `php -S` 404 body echoes the requested path
(`/nope.php` → 541 bytes, `/other.php` → 542 bytes), and under Apache/nginx the body is the
server's, not PHP's. No PHP script can match it byte-for-byte, so the literal scenario is
unachievable at the PHP level. Default: assert the falsifiable subset — HTTP 404, byte-identical
body across **absent / empty / wrong**, no console marker, no `Set-Cookie`, no session file, no
`config.php` load — and record the fidelity gap in `verify-report.md`.

### Suggested Work Units

| PR | Workstream | Requirements | Est. lines | Hard constraints |
|----|-----------|--------------|-----------|------------------|
| 1 | W1 — guard identity resolution | AUTH-01, AUTH-02, AUTH-03, AUTH-06 | ~510 | **AUTH-02 (reader) must land before AUTH-01 (branch deletion)** — same PR, reader first. Lands before PR 2, PR 7 and PR 8 |
| 2 | W2 — refusal surface, ordering, client tolerance, minting subset | AUTH-04, AUTH-05 (subset half), CSRF-01 (guard half), BA-01, BA-02 | ~280 | Depends on PR 1's decision function |
| 3 | W3 — console secret lifecycle | RACC-02, RACC-03, RACC-06 | ~385 | Independent of PR 1/2. **RACC-02/RACC-03 must land before RACC-04** (PR 4) |
| 4 | W4 — console gate, marker, session bootstrap | RACC-04, RACC-05, RACC-08 | ~615 | Depends on PR 3. Introduces the `php -S` HTTP harness and the temp-install-root fixture |
| 5 | W5 — console credential policy primitives | REC-02, REC-03, REC-04, REC-05 (pure half) | ~565 | Independent of PR 3/4 (pure functions only, no `recovery.php` edit) |
| 6 | W6 — console legacy verifier + login-form token | REC-07, REC-01 (token primitive) | ~270 | **REC-07 is load-bearing for PR 7's autoload removal** |
| 7 | W7 — console credential handler + A1 dependency removal | REC-01, REC-05, REC-06, REC-08 (wiring), RACC-01 | ~490 | Depends on PR 1, PR 4, PR 5, PR 6. **Internal order: wire REC-07 → then delete `vendor/autoload.php` and `lib/session_auth.php` from the console's graph.** The removal must NOT precede the local verifier |
| 8 | W8 — two-gate mint + stepped restore drive | RACC-07, AUTH-05 (equality half), CSRF-01 (equality half) | ~490 | Depends on PR 4 and PR 7; flips PR 2's subset contract to equality |

### Chain Dependency Diagram

```
                            main
                             │
  PR 1  W1 guard identity ───┼──► PR 2  W2 refusal surface + minting subset
       (AUTH-01/02/03/06)    │         (AUTH-04, BA-01, BA-02, AUTH-05 subset)
                             │              │
  PR 3  W3 secret ───────────┼──► PR 4  W4 gate + marker + bootstrap
       (RACC-02/03/06)       │         (RACC-04/05/08)
                             │              │
  PR 5  W5 policy ───────────┼──┐           │
       (REC-02/03/04/05)     │  │           │
                             │  │           │
  PR 6  W6 legacy + token ───┼──┤           │
       (REC-07, REC-01)      │  │           │
                             │  ▼           ▼
                             │  PR 7  W7 credential handler + A1 removal
                             │        (REC-01/05/06/08, RACC-01)
                             │   ▲ needs PR 1, PR 4, PR 5, PR 6
                             │              │
                             │              ▼
                             └──────► PR 8  W8 two-gate mint + stepped restore
                                          (RACC-07, AUTH-05 equality)
                                          ▲ needs PR 2 and PR 4
                                          │
                                          ▼
                                         main
```

Merge order: **1 → 2 → 3 → 4 → 5 → 6 → 7 → 8**. PR 3, PR 5 and PR 6 are code-independent of PR 1
and PR 2 and may be *authored* in parallel; they must still merge in the declared order because
PR 7's tests reference their symbols. PR 7 will not compile without PR 1 (the guard decision),
PR 4 (the marker), PR 5 (the policy) and PR 6 (the verifier and the login token), so it is authored
last of that group. PR 8 must be authored after PR 7. No tracker branch is used: every PR merges to
`main` and the next one rebases on it.

## Global Constraints

**Strict TDD is active** (`openspec/config.yaml`: `strict_tdd: true`). Every unit that changes
behaviour starts with the failing test **observed RED**, as its own commit, before the
implementation commit. Units are marked **[TEST]** when they write or correct a test. A test that
is *corrective* (it currently encodes the old contract) lands alone and is proven RED against the
unchanged production code before any implementation commit touches the same behaviour.

**Load-bearing orderings — verified, not assumed:**

| Ordering | Why it cannot be reordered |
|---|---|
| AUTH-02 before AUTH-01 | `system_updater_read_session_snapshot()` returns at the **first non-empty** session (`lib/session_auth.php:387-389`), and `SU_SESS_*` is always non-empty because it holds `su_csrf_token`. Deleting the token branch first leaves genuine administrators with no path to identity: every `status`/`progress` poll and every download breaks |
| RACC-02/RACC-03 before RACC-04 | The gate's first-run behaviour is "generate, then compare". A gate written before generation exists has nothing to compare against and would either fail open or fail closed for everyone |
| REC-07 before the autoload removal | `recovery.php:97-99` currently reaches the legacy bridge through `vendor/autoload.php`. Removing the autoloader before the plugin-local verifier exists locks out every administrator whose hash is still SHA-1/MD5 — the exact operator the console exists for |

**Test baseline — measured, not inherited.** Run on 2026-10-25 in this working tree, before any
slice:

| Measurement | Value |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` | **`Tests: 309, Assertions: 685, Skipped: 2`**, 0 failures, 0 errors |
| `system_updater_is_logged_in()` coverage | **zero tests today** — no shipped test calls it (only `ProcessRestoreTest` mentions the bootstrap guard in a comment). PR 1 therefore *creates* the RED, it does not reuse one |
| Token-minting-point contract test | **does not exist today** in any form (no test enumerates minting call sites; the proposal's risk 5 asks one to be added and forbids the single-element shape). See "Handed to this phase" item 1 |
| PHPStan | **not applicable.** `plugins/system_updater` is not listed in the core-owned `tools/phpstan-plugins.php`, and adding it would be a core-repository change (out of scope). `ddev exec php -l <file>` plus the suite are the static gates |
| Root suite | The root `phpunit.xml` auto-discovers `plugins/*/tests/`, so every new file also runs there. That is not this change's gate; the 12 pre-existing `plugins/OidcProvider` failures are a separate repository |

Re-measure the plugin suite **immediately before PR 1** and record both numbers in the PR body.
Never attribute a delta to this change without that measurement.

**Test-seam doctrine — this suite has no database.** `plugins/system_updater/phpunit.xml`
bootstraps the root `tests/bootstrap.php`, which defines constants only (`FS_FOLDER`, `FS_TMP_NAME`,
`FS_DB_*` pointing at the ddev service, `FS_IP_WHITELIST`, `FS_PATH`). No test may open a database
connection, and none may use the real installation. The five seams this change is allowed to use,
all verified to work in this container on 2026-10-25:

| Seam | What it proves | Used by |
|---|---|---|
| **Pure function over injected data** — a candidate-session map, a request array, a `callable` admin verdict, a `(row\|null, plain, verifier)` tuple, a literal `ip.log` line list | Decisions, fail-closed branches, uniformity, ordering of evaluation | AUTH-01/02/03/04/06, REC-02/03/04/05, CSRF-01 |
| **Counting verifier** — a `callable` that increments a counter and delegates to `password_verify()` | "exactly one evaluation", "no evaluation on the rejection path" | REC-02, REC-05, REC-06 |
| **Temp install root + sentinel `config.php`** — `<tmp>/plugins/system_updater/` holds a copy of the console; `<tmp>/config.php` starts with a line that writes a sentinel file, so "was `config.php` loaded?" is a filesystem fact | RACC-01, RACC-03, RACC-04 ("no config load, no DB connection") | RACC-01…06 |
| **`php -S` subprocess + `curl`** — ephemeral port, `-D` for headers, `-c`/`-b` for a cookie jar, `-w '%{http_code}'`. Both binaries exist in the ddev web container (`php`, `curl`; **`php-cgi` is NOT installed**), and `ddev exec` runs as uid 1000, so `chmod 0555` really does block writes | Status codes, headers, `Set-Cookie`, byte-identical bodies, cookie-jar continuity | RACC-02 (unwritable location), RACC-04, RACC-05, RACC-06 (CLI), RACC-07 |
| **Source-tree enumeration** — a file scan that resolves an identifier to its HTTP entry point | "the set of minting entry points is exactly two", "no `process_*` mints", "the identifier is gone" | AUTH-01, AUTH-05, CSRF-01, RACC-07 |

**Not available, do not write a unit that assumes it:** no JS test runner (BA-02 and the console's
restore drive are asserted by source inspection, the `RestoreSteppedUiTest` pattern); no
`fastcgi_finish_request` under CLI; `headers_list()` is empty and `header()`/`setcookie()` are
no-ops under the CLI SAPI, so **header assertions only exist through the `php -S` seam**; no
`mysqli` double, so anything past the console's DB boundary is operator-owned (see "Known
spec-fidelity gaps").

**Safety rules for the console's own tests.** No test may invoke `recovery.php` against the real
`FS_FOLDER`: a first run there would generate and log a real secret and a real unlock value. Every
subprocess runs from a temp install root created in `setUp()` and removed in `tearDown()`, with an
ephemeral port bound by the harness (`php -S 127.0.0.1:0` is not supported — bind explicitly from a
`random_int(20000, 40000)` range, retry once on failure, and always `kill` the PID in `tearDown()`,
including on assertion failure).

**Per-unit verification commands** (paths in a `-c` invocation resolve from the repository root,
not the plugin directory — verified 2026-10-25):

```bash
# class or method
ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter <ClassOrMethodName>
# single file
ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml plugins/system_updater/tests/<File>.php
# syntax on every changed PHP file
ddev exec php -l plugins/system_updater/<path>
# PR gate
ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml
```

**No Composer dependency is added**, so `vendor/` is untouched and the archive-time check of
"`plugins/{name}/vendor/` is committed" has nothing new to verify. `.gitignore` stays `.atl/`.

**Out of scope — do not create a task for these:**

- **The core repository, all of it** (`base/`, `src/`, `controller/`, `model/`, `themes/`, the core
  `openspec/`, the core PHPStan gate).
- **Post-authentication object-level authorization** across customer, sales, catalogue and company
  controllers — untouched, unclaimed.
- **The disabled-administrator gap** (`fs_users.enabled` is not checked by the console). It is a
  recorded decision in `plugin-recovery-login` ("Recorded gap"), not a task: no test in this change
  asserts either direction.
- Removing or relocating the recovery endpoint, deployment-side controls, CSP, HSTS,
  `system_updater_session_is_valid()`'s missing-`SessionPolicy` fail-open, and the unescaped nick
  at `recovery.php:194` — all named non-goals in `proposal.md`.

## Handed to this phase by the spec phase

The spec phase closed with three items that belong to ordering and test shape, not to
requirements. They are binding here:

1. **The token-minting contract test is written in the two-gate form, never the single-point
   form.** No such test exists today, and the proposal's risk 5 still describes the superseded
   "only minting point" framing that `AUTH-05`, `CSRF-01` and `RACC-07` reverse. The contract is
   therefore created as **one file with two methods**, sequenced so nothing lands red:
   `tests/CsrfMintingPointsTest.php::noUnauthorizedEntryPointCanMint()` (PR 2) asserts the
   **subset** property — every file that references `system_updater_csrf_generate()`,
   `_field()` or `_meta()` belongs to the authorized set, and no `process_*` script is in it — which
   is true today and turns red the moment a third point appears. `::mintingReachesExactlyTwoGatedEntryPoints()`
   (PR 8) asserts the **equality** property — the set is exactly `{admin_updater, recovery.php}` —
   which is red until PR 8 mints from the console and green after. The file must **never** assert
   that the set has one element, and must never mention "only" as a synonym for "authorized".
   Any draft or leftover assertion in the single-point shape found during apply is corrected in
   that same commit, and the commit body says so.
2. **The console's restore JavaScript is rewritten to `begin` + `chunk` with the token on every
   request.** `recovery.php:335` opens `process_restore.php?action=start&file=…` with no token, so
   every console restore has been refused since `plugin-csrf-token`, and `action=start` is the
   legacy single-request path that the canonical `plugin-backup-access` requirement "Stepped
   Resumable Restore" states can never complete on the production host. PR 8 replaces the inline
   `EventSource` block (`recovery.php:319-391`) with the `fetch` driver that
   `view/admin_updater.html.twig` already implements from `:1297` onward (`startRestore` →
   `runRestoreStep` → `parseRestoreSseEvents` → `finishRestoreAs*`) — `runRestoreStep('begin')` then
   `runRestoreStep('chunk')`, `su_csrf_token` on every request, `busy` → wait 2 s and continue,
   bounded retries with backoff, `parseRestoreSseEvents()` for the SSE framing, `done` decided on
   `errors_count`/`warnings_count`. The console's transport stays `fetch` + `Accept: text/event-stream`
   so the SSE-shaped error events from `csrf_guard.php` and the session bootstrap keep parsing.
3. **The minting order is asserted with a step recorder.** RACC-07 requires minting to happen
   **after** the secret comparison **and** after administrator authentication. PR 8 injects a
   recorder into the console's request pipeline that appends `secret_compared`, `admin_authenticated`,
   `token_minted` (and `config_loaded`), then asserts the recorded order, asserts that a failed
   secret or an unauthenticated request records **no** `token_minted`, and additionally asserts the
   session store afterwards (`system_updater.csrf_tokens` absent, `recovery_console` marker intact).
   An order asserted only by reading the code is not acceptable.

## Requirement traceability

| Requirement | Slice | Units |
|---|---|---|
| AUTH-01 | PR 1 | U1.1, U1.2, U1.4, U1.6 |
| AUTH-02 | PR 1 | U1.1, U1.2, U1.3 |
| AUTH-03 | PR 1 | U1.3, U1.4 |
| AUTH-04 | PR 2 | U2.1, U2.2, U2.3 |
| AUTH-05 | PR 2 + PR 8 | U2.5 (subset), U8.6 (equality) |
| AUTH-06 | PR 1 | U1.5 |
| RACC-01 | PR 7 | U7.5, U7.6 |
| RACC-02 | PR 3 | U3.1, U3.2, U3.5 |
| RACC-03 | PR 3 | U3.3, U3.4 |
| RACC-04 | PR 4 | U4.1, U4.2, U4.3, U4.4 |
| RACC-05 | PR 4 | U4.5, U4.6 |
| RACC-06 | PR 3 | U3.4, U3.5 |
| RACC-07 | PR 8 | U8.1…U8.6 |
| RACC-08 | PR 4 | U4.6, U4.7 |
| REC-01 | PR 6 + PR 7 | U6.3, U6.4, U7.2 |
| REC-02 | PR 5 + PR 7 | U5.1, U5.2, U7.1 |
| REC-03 | PR 5 + PR 7 | U5.3, U5.4, U7.2 |
| REC-04 | PR 5 + PR 7 | U5.5, U5.6, U7.2 |
| REC-05 | PR 5 + PR 7 | U5.7, U5.8, U7.2 |
| REC-06 | PR 7 | U7.3, U7.4 |
| REC-07 | PR 6 + PR 7 | U6.1, U6.2, U7.2, U7.6 |
| REC-08 | PR 7 | U7.4 |
| CSRF-01 | PR 2 + PR 8 | U2.5, U8.6 |
| BA-01 | PR 1 + PR 2 | U1.5, U2.1, U2.3 |
| BA-02 | PR 2 | U2.4 |

---

## PR 1 — W1: Guard identity resolution (AUTH-01, AUTH-02, AUTH-03, AUTH-06)

**Est. changed lines**: ~510 (source ~210, tests ~300)
**Depends on**: nothing. **Must land before** PR 2, PR 7 and PR 8.
**Internal order is load-bearing**: AUTH-02 (reader) at U1.2 **before** AUTH-01 (branch and helper
deletion) at U1.4. Deleting first breaks every genuine administrator.
**Rollback boundary**: revert the pure decision function, the all-candidates reader, the admin
resolver shell and the restored token branch together. `system_updater_is_logged_in()` returns to
its shipped body; no schema, no session state, no data.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U1.1 | **[TEST] [RED]** `tests/GuardDecisionTest.php` — the pure decision over injected candidate sessions and an injected admin verdict | ~150 | Pure function; no `session_start()`, no I/O | Revert the new file |
| U1.2 | `lib/session_auth.php` — pure decision + a candidate reader that evaluates **every** candidate (AUTH-02) | ~110 | Pure | Revert the function and restore the first-non-empty reader |
| U1.3 | **[TEST] [RED]** fail-closed matrix: `true` / `false` / `null` / throwing resolver, deleted row, falsy flag, demotion between two calls | ~70 | Injected `callable` verdict | Revert test additions |
| U1.4 | Production admin resolver shell + wire the decision into the guard; **delete** `system_updater_request_has_valid_csrf()` and the branch (AUTH-01, AUTH-03) | ~100 | Static shape + pure branches | Restore the helper and the `if (system_updater_request_has_valid_csrf())` block |
| U1.5 | **[TEST]** AUTH-06 regression: a genuine admin session is accepted, expiry still denies | ~55 | Pure (candidate map + injected verdict) | Revert test additions |
| U1.6 | **[TEST]** negative contract: the guard file names no framework CSRF symbol, and the deleted identifier appears nowhere | ~25 | Source enumeration over `plugins/system_updater/**/*.php` | Revert test additions |

**Unit → command.** U1.1/U1.3/U1.5 `--filter GuardDecisionTest`; U1.6 `... phpunit.xml plugins/system_updater/tests/SessionAuthTest.php`; U1.2/U1.4 `ddev exec php -l plugins/system_updater/lib/session_auth.php`. Every command is prefixed with `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` unless it is the `php -l` form.

### Phase 1: The decision is a pure function (TDD)

- [x] 1.1 **[TEST] [RED] `tests/GuardDecisionTest.php`** (namespace `Tests\SystemUpdater`, the
  house shape of `tests/RestoreSessionStateTest.php`). Given a candidate map and a request array,
  the decision returns `['verdict' => 'accept'|'deny', 'nick' => ?string, 'reason' => string]`.
  Required cases, all from the delta's scenarios:
  - **AUTH-02 scenario 1 (the defect).** `SU_SESS` candidate holding only
    `system_updater.csrf_tokens.system_updater_csrf`, plus `FSSESS` holding `user_nick`,
    `login_time`, `last_activity`, and a request with **no** token of any kind → `accept`, and
    `nick` is the framework session's `user_nick`. This is the case today's reader misses.
  - **AUTH-02 scenario 2.** Only the `SU_SESS` candidate, no identity key → `deny`, `nick === null`.
  - **AUTH-02 scenario 3.** No active PHP session, no `class_exists` call, no `session_start()`;
    assert `session_status() === PHP_SESSION_NONE` before **and** after the call.
  - **AUTH-01 scenario 1.** Request carrying `_csrf_token` / `_token` / `X-CSRF-Token`, session
    content `['_csrf' => 'x']` → `deny`, and the injected admin verdict is **never invoked**
    (counter on the callable).
  - **AUTH-01 scenario 3.** Empty candidate map and empty request → `deny`, verdict not invoked.
  - **AUTH-04 uniformity.** Anonymous, expired, unknown nick, non-administrator and
    resolver-throws produce the same `reason` token and the same message string (exact equality,
    not a prefix match).
  - **AUTH-06 scenario 2.** A candidate whose `login_time`/`last_activity` are past
    `SessionPolicy::getAbsoluteTimeout()` → `deny`, exactly as before this change.
  - Case sensitivity and shape: `_sf2_attributes.user_nick` resolves; `user_id` alone does **not**
    resolve a nick (REC-06 explains why both keys are written); a whitespace-only nick denies.
  - Verify RED: the function does not exist → `Error: Call to undefined function`. Record the
    output in the commit body.
- [x] 1.2 `lib/session_auth.php` — add `system_updater_guard_decision(array $sessions, array $request, callable $isAdmin): array`.
  Rewrite the candidate enumeration: `system_updater_read_session_snapshot()` becomes
  `system_updater_read_session_candidates(): array` returning **all** readable sessions keyed by
  session name, and `system_updater_is_logged_in()` asks the decision over that map. A candidate
  carrying only `system_updater` token state is "no evidence", never "evidence that failed".
  The reader must not stop at the first non-empty session; a candidate that fails to open is
  skipped, not fatal.
  - Verify: `--filter GuardDecisionTest` green; `--filter SessionAuthTest` still green.
- [x] 1.3 **[TEST] [RED] Fail-closed matrix** (extend `tests/GuardDecisionTest.php::
  administratorVerdictFailsClosed`). Inject a verdict callable returning `true`, `false`, `null`,
  and throwing `\RuntimeException`; add the deleted-row case (nick resolves, verdict `false`) and
  the demotion case (two consecutive calls, verdict `true` then `false` → second denies).
  Add a static companion: the production resolver body contains no `static` cache, no
  `CacheManager`, no `fs_cache` (AUTH-03 "the verdict MUST NOT be cached across requests").
- [x] 1.4 `lib/session_auth.php` — add `system_updater_resolve_admin_verdict(string $nick): ?bool`:
  a single authoritative read through `\FSFramework\Security\UserAdapter::fromNick($nick)` (which
  re-reads the row via `fs_user::get()` and reads `admin` from the fresh row), `null` when the
  adapter class is unavailable or an exception is thrown, `false` when the row is missing or the
  flag is falsy. Then delete `system_updater_request_has_valid_csrf()` (`:395-409`) and the
  `if (system_updater_request_has_valid_csrf()) { return true; }` block (`:430-435`), and route
  `system_updater_is_logged_in()` through the decision with this resolver.
  - **Do not** touch `download_backup.php`, `lib/process_bootstrap.php`, `lib/csrf_token.php` or
    `lib/csrf_guard.php`. `start`/`begin`/`chunk` must keep authenticating on the plugin token
    alone (AUTH-05 first scenario) — asserted in PR 2.
  - Keep `system_updater_resolve_framework_session_name()` (other scripts depend on it). PR 4 adds
    the console's core-free sibling; it does not replace this one.
  - Verify: `--filter GuardDecisionTest` green; `--filter SessionAuthTest` green;
    `ddev exec php -l plugins/system_updater/lib/session_auth.php`.
- [x] 1.5 **[TEST]** AUTH-06 regression pins (green by design, and they must be proven green
  **before and after** U1.4): a genuine administrator candidate is accepted; a `status`/`progress`-
  shaped request resolves the same nick on repeated calls.
- [x] 1.6 **[TEST]** `tests/SessionAuthTest.php` gains the negative contract: the string
  `system_updater_request_has_valid_csrf` is absent from every `.php` file under
  `plugins/system_updater/` except this test, and the guard file names none of `CsrfManager`,
  `_csrf`, `_token`, `X-CSRF-Token`. (One file scan, no globals.)

### PR 1 verification

- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter GuardDecisionTest` — OK (28 tests, 82 assertions)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml plugins/system_updater/tests/SessionAuthTest.php` — OK (15 tests, 22 assertions, 1 skipped)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **339 / 772 / 2 skipped**, 0 failures (baseline **309 / 685 / 2 skipped**)
- [x] `ddev exec php -l` on `lib/session_auth.php`
- [ ] PR body states the measured before/after counts and names the AUTH-02-before-AUTH-01 order

**Applied deviation (U1.4).** The production resolver does **not** go through
`\FSFramework\Security\UserAdapter::fromNick()`. The standalone process scripts bootstrap only
`config.php` plus the Composer autoloader, where `class_exists('fs_user')` is `false` (measured);
`fromNick()` answers `null` for that reason alone and would deny every administrator — the exact
AUTH-06 regression this change must avoid. `system_updater_resolve_admin_verdict()` therefore reads
the row with a prepared `mysqli` statement, the same path `recovery.php` and `backup_manager`
already use in this runtime, which `AUTH-03` permits as reading `fs_users` "directly". Verified
read-only against the installation: `admin` → `true`, `joaquin` (`admin=0`) → `false`,
absent nick → `false`.

**End-to-end evidence (web SAPI, `curl` against a temporary endpoint, probe deleted).** With a real
`SU_SESS_*` session open first (as the bootstrap does) and both cookies present: candidates =
`[SU_SESS_*, FSSESS_*]`, resolved nick `admin`, verdict `true`, decision `accept`,
`is_logged_in() === true`, and the caller's session preserved (`SU_SESS_*` / same id / still
active). Non-administrator session → `false`. Plugin session alone → no candidate identity and
`false`.

### PR 1 review follow-up — CodeRabbit findings (three, all resolved)

Applied on the same branch as three additional commit pairs (RED then fix), after the six W1
commits. Nothing under `openspec/` is committed: this section is working-tree only.

- [x] **MAJOR — backend blindness.** `system_updater_resolve_admin_verdict()` connects with
  `mysqli` whatever `FS_DB_TYPE` says, so on a PostgreSQL install it returns `null` and every
  administrator is denied with the anonymous "unauthenticated" shape. **Established fact:** the
  plugin is *not* MySQL-only across the board — `lib/backup_manager.php` is backend-aware
  (`normalize_database_type()`, `pg_dump`/`psql` branches, `restore_database_postgresql()`) — but
  every *in-process PHP* connection is `mysqli`: `recovery.php:60`, the PHP-native backup path and
  this guard. The plugin therefore supports PostgreSQL **backups** without ever giving PHP a
  PostgreSQL reader, and it claims no backend-agnostic database access anywhere. Fix chosen: **no
  PostgreSQL driver**; classify the backend before connecting, refuse with `null` (fail-closed,
  unchanged) and report a distinct operator diagnostic on `error_log`, once per process, naming the
  backend and the missing client. AUTH-04 is untouched: the caller's denial stays byte-identical to
  the anonymous one (asserted). Tests: `tests/AdminBackendSupportTest.php`, 17 cases / 70 assertions.
- [x] **MINOR — `Set-Cookie` on the isolated read.** `system_updater_read_session_file()` now
  suspends `session.use_cookies` and `session.cache_limiter` for the duration of the candidate read
  and restores both in a `finally`; `system_updater_configure_php_session()` gained
  `$withCookieParams = false` so the isolated path never calls `session_set_cookie_params()` (it
  warns while cookies are suspended). Ordering is fixed by PHP's rules and documented in place:
  configure, then close the caller's session, then suspend, then read, then restore, then reopen.
  Tests: `tests/SessionReadCookieTest.php` over the `php -S` seam, 5 cases / 24 assertions — no
  `Set-Cookie` and no cache headers for an isolated read, no foreign cookie over an open caller
  session, ini restored.
- [x] **MINOR — `require_once` depended on `FS_FOLDER`.** The file-scope require and `guardSource()`
  now resolve from `dirname(__DIR__)`. Pinned by a subprocess load probe
  (`testContractFileLoadsWithoutTheInstallRootConstant`) that requires only the Composer autoloader
  and asserts the file loads with nothing defined.

**Re-measured after the three fixes** (`ddev exec php vendor/bin/phpunit -c
plugins/system_updater/phpunit.xml`): **362 / 870 / 2 skipped**, 0 failures, 0 errors
(W1 was 339 / 772 / 2 skipped; baseline 309 / 685 / 2 skipped).

**Known remaining silent-deny path (recorded, not fixed).** A MySQL install without the `mysqli`
extension, or with `FS_DB_HOST`/`FS_DB_USER`/`FS_DB_NAME` undefined, still returns `null` from the
resolver without a diagnostic. It is the same failure class as the finding above and is left for
W2's refusal surface rather than widened into this review fix.

---

## PR 2 — W2: Refusal surface, ordering, client tolerance, minting subset (AUTH-04, AUTH-05 subset, CSRF-01 guard half, BA-01, BA-02)

**Est. changed lines**: ~280 (source ~45, tests ~235)
**Depends on**: PR 1 (the decision function and the wired guard).
**Rollback boundary**: revert the single refusal constant and the ordering assertions; the client
retry branch in `view/admin_updater.html.twig` is independently revertible.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U2.1 | **[TEST]** denial uniformity across all five reasons, on the decision and on the deny emitter | ~70 | Pure + source | Revert test additions |
| U2.2 | One refusal constant shared by the JSON 401 and the SSE `error` message (AUTH-04) | ~25 | Source | Restore the two literals |
| U2.3 | **[TEST]** BA-01 ordering: anonymous + a valid `su_csrf_token` is refused by the **guard**, not by the token check | ~70 | Instrumented verdict callable + source ordering assertion | Revert test additions |
| U2.4 | **[TEST]** BA-02 client tolerance, added to `tests/RestoreSteppedUiTest.php`, asserted against `view/admin_updater.html.twig` | ~40 | Source inspection (the same pattern that file already uses) | Revert test additions |
| U2.5 | **[TEST]** `tests/CsrfMintingPointsTest.php::noUnauthorizedEntryPointCanMint()` — the subset half | ~80 | Source enumeration | Revert the new file |

**Unit → command.** U2.1/U2.2 `--filter GuardDecisionTest`; U2.3 `--filter DownloadBackupScriptTest` and `--filter DownloadBackupHandlerTest`; U2.4 `--filter RestoreSteppedUiTest`; U2.5 `--filter CsrfMintingPointsTest`.

### Phase 1: One refusal, one order (TDD)

- [ ] 1.1 **[TEST] [RED] Uniform refusal.** Assert that `system_updater_deny_unauthenticated_request()`
  emits the *same* message for every reason, that the JSON body is
  `{"success":false,"message":"<the constant>"}`, and that the SSE event payload's `message` is the
  **same string** (AUTH-04: "the transport difference must never become a denial-reason difference").
  RED today because the strings are literals in two functions while the guard has no reason
  vocabulary. Seed the constant in the same commit only after the assertion is red.
- [ ] 1.2 Introduce `SYSTEM_UPDATER_GUARD_DENIAL` (or an equivalent single constant) and consume it
  in both emitters. No message text may change: the shipped string stays the operator-facing text.
- [ ] 1.3 **[TEST] [RED] Ordering is asserted, not inferred.** Two complementary assertions:
  (a) a source ordering assertion on `download_backup.php` — the
  `system_updater_process_init(... 'plain' ...)` call precedes the first
  `system_updater_csrf_read_from_request()` call; (b) a behavioural assertion with the guard
  instrumented: with an injected deny verdict and a valid-looking token, the handler body is not
  reached (a sentinel callable is never invoked). Note in the test why the status code alone cannot
  prove the order (a 403 would prove the opposite, a 401 could be produced either way).
- [ ] 1.4 **[TEST] BA-02 client tolerance** (source assertions on `view/admin_updater.html.twig`):
  a `!response.ok` denial during a restore is retried (`restoreConsecutiveFailures`), the modal keeps
  the progress state rather than switching to a terminal error, no abort request is issued, and
  after the retry budget the message says the session must be re-established **without** claiming a
  successful restore. RED because that last branch does not exist (today's message talks about
  resuming and releasing maintenance). Implementation is a message/branch change in the same file.
- [ ] 1.5 **[TEST] `CsrfMintingPointsTest::noUnauthorizedEntryPointCanMint()`** — enumerate every
  `.php` file under `plugins/system_updater/` that references `system_updater_csrf_generate(`,
  `system_updater_csrf_field(` or `system_updater_csrf_meta(`, resolve each to its HTTP entry point
  (`lib/*` → the entry points that include it), and assert every entry point is in
  `{admin_updater, recovery.php}` and that no `process_*.php` or `download_backup.php` is in the set.
  Green at the end of PR 2 (the console does not mint yet, and the subset allows that). This is the
  **subset** half; the equality half is U8.6. State both in the test's docblock so no later reader
  "fixes" it back to a single-element assertion.

### PR 2 verification

- [ ] `--filter GuardDecisionTest`, `--filter CsrfMintingPointsTest`, `--filter DownloadBackupScriptTest`, `--filter DownloadBackupHandlerTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
- [ ] `ddev exec php -l` on every changed PHP file
- [ ] PR body states that a 403-versus-401 assertion alone does not prove ordering, and what was
  asserted instead

---

## PR 3 — W3: Console secret lifecycle (RACC-02, RACC-03, RACC-06)

**Est. changed lines**: ~385 (source ~200, tests ~185)
**Depends on**: nothing (independent of PR 1/2). **Must land before PR 4.**
**Rollback boundary**: delete `lib/recovery_secret.php` and its call sites. The console behaves as
at `2.11.15`. A persisted secret file is orphaned and read by nothing, exactly as the proposal's
rollback plan states.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U3.1 | **[TEST] [RED]** `tests/RecoverySecretTest.php` — generation shape, persistence, restart, cross-installation uniqueness, permissions | ~100 | Temp roots; `fileperms()` | Revert the new file |
| U3.2 | `lib/recovery_secret.php` — generation + persistence with `0600`, loud failure on unwritable | ~105 | — | Delete the file |
| U3.3 | **[TEST] [RED]** resolution order: operator pin → persisted value; branch 2 needs no `config.php` and loads no class | ~60 | Temp roots + sentinel `config.php` | Revert test additions |
| U3.4 | Resolution + CLI `--secret` / `--rotate-secret` + first-generation log line + web-flag inertness | ~80 | `php` subprocess (CLI) and `php -S` (web) | Remove the CLI branches |
| U3.5 | **[TEST]** unwritable location is a loud error, never a per-request regeneration | ~45 | `chmod 0555` on a temp dir (uid 1000 in this container) | Revert test additions |

**Unit → command.** U3.1/U3.3/U3.5 `--filter RecoverySecretTest`; U3.2/U3.4 `ddev exec php -l plugins/system_updater/lib/recovery_secret.php` plus the CLI probes inside `RecoverySecretTest` (they spawn `php <tmproot>/plugins/system_updater/recovery.php`, never the real installation).

### Phase 1: The value exists and survives (TDD)

- [ ] 1.1 **[TEST] [RED]** Shape and persistence: the resolved secret is 64 hex characters
  (`strlen === 64 && ctype_xdigit`); a second resolution in the same temp root returns the identical
  value and does not rewrite the file; two different temp roots produce two different values; the
  value is not `sha1($root)`, not `md5($root)`, not a substring of either, and does not contain the
  database name (RACC-02 "MUST NOT be derived solely from values that are guessable or shared").
  Permissions asserted with `fileperms() & 0777 === 0600`. The stored location must be **outside**
  the install root when a writable sibling exists — assert the resolved path is not inside the temp
  root used as the webroot in that fixture.
- [ ] 1.2 `lib/recovery_secret.php` — `recovery_secret_generate(): string` (`bin2hex(random_bytes(32))`),
  `recovery_secret_store(string $value): bool`, `recovery_secret_path(): string` (candidate list:
  outside-webroot sibling first, inside-webroot last resort), and the documented write with
  `chmod 0600`. A failed write returns `false` and never regenerates per request.
- [ ] 1.3 **[TEST] [RED] Resolution order.** With `SU_RECOVERY_SECRET` defined in the sentinel
  `config.php`: the constant wins and the persisted file is neither read nor rewritten (assert the
  file's mtime and contents are unchanged). Without the constant and with a **fatally broken**
  `config.php` (a file containing invalid PHP): the persisted value is still returned, no class is
  loaded (assert by deleting `vendor/`, `base/` and `src/` from the temp root), and no parse error
  is emitted. **Read "Delta conflict resolved at apply time" below first** — the pin branch and the
  "wrong secret never loads `config.php`" scenario cannot both hold literally, and the test must
  encode the resolution, not one horn of the contradiction.
- [ ] 1.4 Resolution + CLI. `recovery_secret_resolve(): string` implements the documented order.
  CLI: `php <root>/plugins/system_updater/recovery.php --secret` prints the current value and opens
  no DB connection (assert the sentinel `config.php` was not executed); `--rotate-secret` prints and
  persists a **new** value and a later request carrying the old value is refused by PR 4's gate.
  First generation writes the value **exactly once** to `error_log` under a fixed, documented
  prefix — assert by running the CLI with `error_log` redirected to a temp file and counting
  occurrences (exactly 1) and re-running (still 1).
  Web inertness: over `php -S`, `?--secret` / `?secret=...`-style flags change nothing — the
  response is PR 4's 404 and the secret never appears in a body or a header. Until PR 4 exists,
  assert the weaker, still-meaningful half: the CLI branches are guarded by
  `PHP_SAPI === 'cli'` and the secret is not echoed on the web path.
- [ ] 1.5 **[TEST]** Unwritable location: create the install root, `chmod 0555` the only candidate
  parent, delete the persisted file, resolve → the operator-visible error names the failure, no new
  secret is generated, and two consecutive requests produce the same error (no per-request
  regeneration).

### PR 3 verification

- [ ] `--filter RecoverySecretTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
- [ ] `ddev exec php -l plugins/system_updater/lib/recovery_secret.php`
- [ ] Confirm no secret file was created anywhere under the real `FS_FOLDER`
  (`git status --porcelain` in the plugin stays clean apart from the intended files)

---

## PR 4 — W4: The gate, the marker, the session bootstrap (RACC-04, RACC-05, RACC-08)

**Est. changed lines**: ~615 (source ~180, tests ~435 including the harness)
**Depends on**: PR 3 (resolution and persistence).
**Rollback boundary**: delete the gate, the marker read/write and `lib/recovery_session.php`; the
console returns to rendering unconditionally with the shipped session resolution. Nothing persists.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U4.0 | **[TEST] [INFRA]** `tests/Support/ConsoleHttpHarness.php` — temp install root, sentinel `config.php`, `php -S` on an explicit port, `curl` with a cookie jar, guaranteed teardown | ~140 | Subprocess | Revert the new file |
| U4.1 | **[TEST] [RED]** absent / empty / wrong secret → 404, identical bodies, no `Set-Cookie`, no session file, no `config.php` load | ~90 | `php -S` + curl | Revert test additions |
| U4.2 | Gate in `recovery.php`, before `config.php` is loaded | ~70 | — | Remove the gate block |
| U4.3 | **[TEST] [RED]** correct secret → console renders, `Referrer-Policy: no-referrer`, the secret appears in no body, redirect target or form action | ~60 | `php -S` + curl | Revert test additions |
| U4.4 | Correct-secret path: `Referrer-Policy`, and the secret is not re-emitted anywhere | ~25 | — | Revert |
| U4.5 | **[TEST] [RED]** marker: second request without the secret is authorized; the marker authorizes nothing outside the console; no secret → no marker | ~90 | Cookie jar across two requests | Revert test additions |
| U4.6 | Marker write/read in a console-only namespace + `lib/recovery_session.php` (core-free name resolution and session configure) | ~80 | — | Remove the marker and restore the shipped bootstrap |
| U4.7 | **[TEST]** RACC-08: name resolves from `FS_SESSION_NAME` with no class loadable; a mismatch renders the login form with no error and no security log | ~60 | Subprocess + sentinel | Revert test additions |

**Unit → command.** U4.0 `ddev exec php -l plugins/system_updater/tests/Support/ConsoleHttpHarness.php` plus its own smoke test run through `--filter RecoveryGateTest`; U4.1/U4.2 `--filter RecoveryGateTest`; U4.3/U4.4 `--filter RecoveryGateTest`; U4.5/U4.6 `--filter RecoveryMarkerTest`; U4.7 `--filter RecoverySessionBootstrapTest`.

### Phase 1: The harness (do this first — every later console test depends on it)

- [ ] 1.0 `tests/Support/ConsoleHttpHarness.php` — `boot(array $files = [], string $configBody = '')`
  copies `recovery.php`, `lib/recovery_secret.php` and their dependencies into
  `<tmp>/plugins/system_updater/`, writes `<tmp>/config.php` whose **first statement** writes
  `<tmp>/config_loaded.sentinel` (so "config.php was loaded" and "a DB connection was attempted"
  become filesystem facts), optionally omits `vendor/`, `base/`, `src/` entirely (the A1 damaged-core
  fixture), starts `php -S 127.0.0.1:<port> -t <tmp>`, and exposes
  `get(string $path, bool $withCookies = false): array{status:int, headers:string, body:string}` via
  `curl -s -D - -o - -w '\n%{http_code}'`. Teardown kills the PID and removes the root **even on
  failure** (`try/finally` in the test, plus a `register_shutdown_function` backstop). Keep every
  fixture inside `sys_get_temp_dir()`; never bind a fixed port.
- [ ] Note in the harness docblock, for the next reader: `php-cgi` is not installed, `php -S` is the
  only way to observe status/headers/cookies here, and the server's own 404 body echoes the path —
  so it cannot be used as the "non-existent path" baseline byte-for-byte (see risk D3).

### Phase 2: The gate (TDD)

- [ ] 2.1 **[TEST] [RED]** Three requests to the console script with **no** parameter, an **empty**
  parameter and a **64-hex wrong** parameter: each returns 404, the three bodies are byte-identical
  to each other, none contains `Modo Recuperación`, the plugin name, a backup name or a login field,
  no `Set-Cookie` header is present, `<tmp>/config_loaded.sentinel` does not exist, and no session
  file appears in the configured save path. RED today (the console renders the login form and
  connects to the DB).
- [ ] 2.2 Gate in `recovery.php`: resolve the secret **without** loading `config.php`
  (`lib/recovery_secret.php` only), compare with `hash_equals()` on the full 64-hex string, and on
  failure emit the single 404 path — **one code path for absent, empty and wrong** (no early return
  for "no parameter"), no session start, no DB, no backup directory read, `Referrer-Policy:
  no-referrer` only on the success path. `config.php` is loaded only after the comparison succeeds
  (the one documented exception: the operator-pin branch of "Delta conflict resolved at apply time"
  below, reachable only when no persisted value exists).
- [ ] 2.3 **[TEST] [RED]** Correct secret in the query string: status 200, the response has
  `Referrer-Policy: no-referrer`, the console's login form is present, and the secret value appears
  in **none** of body, `Location`, or a form `action`.
- [ ] 2.4 Ensure the success path emits that header and never echoes the secret. Keep the rest of
  the rendered page as today (no UI redesign beyond what the gate requires).

### Phase 3: The marker (TDD)

- [ ] 3.1 **[TEST] [RED]** Cookie-jar continuity: request 1 carries the secret and obtains the
  cookie; request 2 posts the login form **without** any query parameter and is accepted for
  credential evaluation (today it re-renders or fails because nothing is remembered). Then the
  negative halves: a session holding **only** the marker is refused by
  `download_backup.php` and by a `process_*` action; a request without a valid secret receives the
  PR 4 404 and leaves no marker in the store.
- [ ] 3.2 Write the marker **only** after a successful constant-time comparison, under a
  console-only namespace inside the session the console opened (`$_SESSION['recovery_console']` —
  D2's default), never in `_sf2_attributes`, never `_csrf`, never an identity key. It is regenerated
  by `session_regenerate_id(true)` together with REC-06's rotation (asserted in PR 7). No other
  plugin script may read it: add the source assertion that `recovery_console` appears only in
  `recovery.php` and its plugin-local includes.
- [ ] 3.3 **[TEST]** RACC-08: with `vendor/`, `base/` and `src/` absent and `FS_SESSION_NAME`
  defined in the sentinel `config.php`, the console adopts that session name (assert the `Set-Cookie`
  name over `php -S`). With a name no browser cookie matches, the console renders the login form with
  no error banner and writes no security log line. Implementation: `lib/recovery_session.php` with
  `class_exists('FSFramework\\Security\\SessionManager', false)` (autoload **disabled**, so the
  branch cannot pull core code and violate RACC-01) → `FS_SESSION_NAME` → plugin-local derivation;
  plus the cookie-parameter configuration the shipped `system_updater_configure_php_session()`
  already applies (path, httponly, samesite, secure detection without a core class).
  `recovery.php` stops calling `system_updater_configure_php_session()` and
  `system_updater_resolve_framework_session_name()`; the **removal** of the `lib/session_auth.php`
  require belongs to PR 7 (it must not happen before REC-07 exists).

### PR 4 verification

- [ ] `--filter RecoveryGateTest`, `--filter RecoveryMarkerTest`, `--filter RecoverySessionBootstrapTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts; the
  suite must still be DB-free (no connection attempt, no `db` hostname resolved)
- [ ] `ddev exec php -l` on `recovery.php`, `lib/recovery_session.php`
- [ ] PR body records the D3 finding (the server-generated 404 body echoes the path) with the
  measured byte counts and states exactly what was asserted instead

---

## PR 5 — W5: Console credential policy primitives (REC-02, REC-03, REC-04, REC-05 pure half)

**Est. changed lines**: ~565 (source ~205, tests ~360)
**Depends on**: nothing. Pure functions only; **no `recovery.php` edit** in this PR, so it is
independently reviewable and revertible.
**Rollback boundary**: delete `lib/recovery_policy.php` and its tests. Nothing is wired yet, so the
console is unchanged.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U5.1 | **[TEST] [RED]** zero-attempt guarantee for a rejected verdict (REC-02) | ~60 | Counting verifier + spy counters | Revert test additions |
| U5.2 | `recovery_policy_should_count()` + the single-count rule in the verdict pipeline | ~35 | Pure | Revert |
| U5.3 | **[TEST] [RED]** native `ip.log` parser reproduces `fs_ip_filter::is_banned()` semantics (REC-03) | ~95 | Literal line arrays + one fixture file | Revert test additions |
| U5.4 | `recovery_policy_is_banned(array $lines, string $ip, int $now): bool` | ~45 | Pure | Revert |
| U5.5 | **[TEST] [RED]** fallback limiter: 6/600 s per nick+IP, 20/900 s per account, no-more-permissive drift check against `LoginThrottle` (REC-04) | ~110 | Temp directory + injected clock; real `LoginThrottle` constants for the drift assertion | Revert test additions |
| U5.6 | `recovery_policy_fallback_*` counter (declared constants + file counter) | ~80 | — | Revert |
| U5.7 | **[TEST] [RED]** credential verdict: exactly one verification, uniform outcome, unknown-nick uses the fixed dummy hash (REC-05) | ~95 | Counting verifier | Revert test additions |
| U5.8 | `recovery_policy_verify_credentials(?array $row, string $plain, callable $verifier): string` returning one outcome token | ~45 | Pure | Revert |

**Unit → command.** U5.1/U5.2 `--filter RecoveryCredentialVerdictTest`; U5.3/U5.4 `--filter RecoveryBanListTest`; U5.5/U5.6 `--filter RecoveryFallbackLimiterTest`; U5.7/U5.8 `--filter RecoveryCredentialVerdictTest`.

### Phase 1: Counting (TDD)

- [ ] 1.1 **[TEST] [RED]** A submission whose login token was rejected records **zero** attempts:
  assert the shared throttle counter, the fallback counter and the `ip.log` content are all
  unchanged, and the verifier was never called. Then the positive half: a valid token plus a wrong
  password records **exactly one** failure, in **exactly one** counter set (never both).
  RED today because there is no policy module at all, and RED-against-today also demonstrates the
  real defect: the shipped handler has no counter of any kind.
- [ ] 1.2 Implement `recovery_policy_should_count(string $phase): bool` and make the pipeline record
  exactly one failure in exactly one mode (shared when `LoginThrottle` is loadable, fallback
  otherwise).

### Phase 2: The ban read (TDD)

- [ ] 2.1 **[TEST] [RED]** Table-driven parser cases over literal lines, mirroring
  `fs_ip_filter::read_line()`/`is_banned()`: `203.0.113.7;6;<future>` → banned;
  `203.0.113.7;5;<future>` → not banned (the core uses `>` not `>=`);
  `203.0.113.7;9;<past>` → not banned; a two-field line → ignored; an empty line → ignored; a
  different IP → not banned; an absent file → not banned; a malformed `count` (non-numeric) → not
  banned. Where `fs_ip_filter` is loadable in the test process, add a **parity assertion**: for the
  same input file, `fs_ip_filter::is_banned()` and the plugin parser agree (this is the drift guard
  REC-03 asks for). One case writes a real fixture into a temp `tmp/<FS_TMP_NAME>ip.log`.
- [ ] 2.2 Implement the parser over `FS_FOLDER/tmp/<FS_TMP_NAME>ip.log`, read-only. Forbid writes:
  assert the file's `md5_file` and `mtime` are unchanged after any sequence of calls (REC-03 "the
  endpoint never writes this file").

### Phase 3: The fallback limiter (TDD)

- [ ] 3.1 **[TEST] [RED]** Six failures for one nick from one IP inside the window → the seventh is
  refused before evaluation; 20 failures for one account across IPs → refused; a success clears the
  counter used for recording; an elapsed window re-opens the budget (inject the clock; do not sleep).
  Declared constants must equal `LoginThrottle::MAX_ATTEMPTS` (6), `::THROTTLE_WINDOW` (600),
  `::ACCOUNT_MAX_ATTEMPTS` (20), `::ACCOUNT_WINDOW` (900) — assert by reading the core constants
  when the class is loadable, and `markTestSkipped` with a recorded reason when it is not (REC-04's
  drift test).
- [ ] 3.2 Implement the plugin-local counter under `sys_get_temp_dir()`/`FS_TMP_NAME`, with
  `flock()` (the `system_updater_save_progress()` pattern), no more permissive than the core in
  either dimension, and a message that states the console is running its local limiter.

### Phase 4: The verdict and the failure body (TDD)

- [ ] 4.1 **[TEST] [RED]** Three submissions — unknown nick, existing nick with a wrong password,
  existing non-administrator with the correct password — produce the **same** outcome token and the
  **same** body, and each performs **exactly one** `password_verify()` (counting verifier). The
  unknown-nick path uses a **declared constant** bcrypt hash: assert `password_get_info()` reports
  bcrypt, assert the constant is used (no `password_hash()` call at request time — spy on it), and
  assert the verdict calls the verifier exactly once for all three inputs. Timing: do **not**
  assert wall-clock ratios in the suite (flaky); assert the mechanism that produces comparable
  timing, and leave the sampled comparison to the operator note.
- [ ] 4.2 Implement `recovery_policy_verify_credentials()` returning one of `ok` / `deny` only, with
  the dummy-hash branch and the single verification. The failure message constant lives here so
  PR 7 renders it in one place.

### PR 5 verification

- [ ] `--filter RecoveryBanListTest`, `--filter RecoveryFallbackLimiterTest`, `--filter RecoveryCredentialVerdictTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts, and
  record whether the drift check skipped
- [ ] `ddev exec php -l plugins/system_updater/lib/recovery_policy.php`
- [ ] `git diff --stat` shows **no** change to `recovery.php` in this PR (the split is the point)

---

## PR 6 — W6: Console legacy verifier and login-form token (REC-07, REC-01 token primitive)

**Est. changed lines**: ~270 (source ~90, tests ~180)
**Depends on**: nothing. **Load-bearing for PR 7**: the verifier must exist and be proven before the
autoloader is removed from the console's graph.
**Rollback boundary**: delete `lib/recovery_legacy_hash.php` and `lib/recovery_login_token.php`.
Nothing is wired yet; the console is unchanged.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U6.1 | **[TEST] [RED]** `tests/RecoveryLegacyHashTest.php` — salted SHA-1, plain SHA-1, lower-cased SHA-1, MD5, bcrypt-first, unrecognizable hash | ~110 | Pure (no core class, no autoload) | Revert the new file |
| U6.2 | `lib/recovery_legacy_hash.php` — the plugin-local bridge | ~40 | — | Delete the file |
| U6.3 | **[TEST] [RED]** `tests/RecoveryLoginTokenTest.php` — own namespace, `hash_equals()`, session-bound, never `system_updater.csrf_tokens`, never `_csrf` | ~70 | Session in `#[RunInSeparateProcess]` | Revert the new file |
| U6.4 | `lib/recovery_login_token.php` — mint/read/validate for the console's form | ~50 | — | Delete the file |

**Unit → command.** U6.1/U6.2 `--filter RecoveryLegacyHashTest`; U6.3/U6.4 `--filter RecoveryLoginTokenTest`; both files also with `ddev exec php -l`.

### Phase 1: The legacy bridge (TDD)

- [ ] 1.1 **[TEST] [RED]** Each variant from REC-07 in the exact core order and with `hash_equals()`:
  `sha1($salt . $plain)` when a salt is supplied, `sha1($plain)`,
  `sha1(mb_strtolower($plain, 'UTF8'))`, `md5($plain)`. Plus: a bcrypt row is verified by
  `password_verify()` and the legacy comparisons are **not attempted** (assert by injecting a
  counter into the comparison path, or by a source assertion that the modern check returns before
  the legacy block); an unrecognizable stored value is a plain failure (**never** an
  "unknown format accepted" bypass); a `null` salt is passed when the row has no `salt` key (the
  current schema has none).
  Run this test with `vendor/autoload.php` never loaded in the test's process path — the plugin test
  bootstrap loads it globally, so assert instead that the module's source references no core class
  (`LegacyCompatibility`, `PasswordHasherService`, `class_exists`) and that all four comparisons are
  present with `hash_equals`.
- [ ] 1.2 Implement the bridge as pure functions over `(storedHash, plain, ?salt)`. No
  `class_exists()`, no `require`, no Composer symbol.

### Phase 2: The console's form token (REC-01 primitive, TDD)

- [ ] 2.1 **[TEST] [RED]** Minting stores the value under a console-only namespace
  (`$_SESSION['recovery_console']['login_token']` — D2), the value is 64 hex, validation uses
  `hash_equals()`, a token minted in one session is refused in another, and after minting
  `$_SESSION['system_updater']['csrf_tokens']` is **absent** and `$_SESSION['_csrf']` is untouched.
  This is the assertion that keeps the login form from handing an unauthenticated visitor a value
  that `start`/`begin`/`chunk` would accept.
- [ ] 2.2 Implement mint/read/validate with a 4-hour TTL consistent with the plugin token, no
  dependency on `lib/csrf_token.php` (importing it would open the plugin session inside the console
  and write the wrong store).

### PR 6 verification

- [ ] `--filter RecoveryLegacyHashTest`, `--filter RecoveryLoginTokenTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
- [ ] `ddev exec php -l` on both new files
- [ ] PR body states the A1 ordering explicitly: REC-07 proven **before** PR 7 removes the autoloader

---

## PR 7 — W7: The credential handler and the A1 dependency removal (REC-01, REC-05, REC-06, REC-08, RACC-01)

**Est. changed lines**: ~490 (source ~170, tests ~320)
**Depends on**: PR 1 (guard decision, for REC-06's cross-check), PR 4 (gate and marker), PR 5 (policy
primitives), PR 6 (verifier and login token).
**Internal order is load-bearing** (each is a separate commit inside this PR):
1. wire the ban gate → throttle → verdict → uniform failure (REC-01/02/03/04/05),
2. wire rotation and the identity keys (REC-06),
3. keep REC-08's re-check,
4. **then** remove `vendor/autoload.php` and the `lib/session_auth.php` require and assert the
   include graph (RACC-01).
**Rollback boundary**: revert the handler wiring and restore the two requires. REC-07 is already
proven, so a partial revert cannot lock out a legacy-hash administrator.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U7.1 | **[TEST] [RED]** handler pipeline: token verdict first, then ban, then throttle, then exactly one evaluation, then one uniform body (REC-01…05) | ~130 | Injected row + counting verifier + recorders | Revert test additions |
| U7.2 | Handler wiring, form token field, rejection path inert | ~70 | — | Restore the shipped inline check |
| U7.3 | **[TEST] [RED]** rotation before the identity write, and the resulting session passes the guard (REC-06) | ~80 | Real session in a separate process + recorder | Revert test additions |
| U7.4 | `session_regenerate_id(true)` before writing `user_nick`, `user_id`, `login_time`, `last_activity`; clear the budget on success | ~45 | — | Revert |
| U7.5 | **[TEST] [RED]** RACC-01 include graph: exactly one external entry, `config.php`; console boots with `vendor/`, `base/`, `src/` absent and reaches the DB boundary without a class error | ~110 | Source graph + temp root subprocess + sentinel | Revert test additions |
| U7.6 | Remove `vendor/autoload.php` and `require_once __DIR__.'/lib/session_auth.php'` from `recovery.php`; keep REC-08's re-read of the row for an existing session | ~55 | — | Restore both requires |

**Unit → command.** U7.1/U7.2 `--filter RecoveryHandlerTest`; U7.3/U7.4 `--filter RecoveryRotationTest`; U7.5/U7.6 `--filter RecoveryIncludeGraphTest` plus `ddev exec php -l plugins/system_updater/recovery.php`.

### Phase 1: The pipeline (TDD)

- [ ] 1.1 **[TEST] [RED]** In order, each as an explicit assertion:
  - invalid or missing login token → one generic CSRF refusal, **no** ban read, **no** throttle
    consult, **no** account lookup, **no** verification, **no** counter movement (a recorder proves
    the order, not just the absence of effects);
  - valid token + banned IP → uniform failure before any lookup or verification;
  - valid token + throttled nick → uniform failure before evaluation;
  - valid token + unknown nick / wrong password / non-administrator → one identical body, one
    verification each;
  - a format-invalid stored hash → uniform failure, no identity written.
- [ ] 1.2 Wire `recovery.php`'s POST branch to the policy primitives in that order; render the
  console's login token as a hidden field (from `lib/recovery_login_token.php`, **not**
  `system_updater_csrf_field()` — that helper auto-generates `su_csrf_token`, which is exactly what
  REC-01 and the "console mints nothing before authentication" scenario forbid). Keep the shipped
  three failure strings only as internal reasons; one message is rendered.

### Phase 2: Rotation and identity (TDD)

- [ ] 2.1 **[TEST] [RED]** A successful login rotates the identifier (`session_id()` differs from
  the pre-login id) and the write order is `regenerate → identity`, recorded by a step recorder; the
  written session contains `user_nick`, `user_id`, `login_time`, `last_activity`; feeding that
  session array to `system_updater_guard_decision()` with an injected `true` verdict → accept with
  the right nick and **no** CSRF token required (REC-06 scenarios 2-4). Note the `user_id` half
  explicitly: `system_updater_session_is_valid()` resolves from `user_nick`, so a session carrying
  only `user_id` would silently lose access.
- [ ] 2.2 Implement rotation before the writes; clear the shared budget on success; keep the
  existing revalidation branch's administrator re-check (REC-08) and add the assertion that the
  `recovery_console` marker alone renders the login form and never the backup list.

### Phase 3: A1 dependency removal (TDD, last)

- [ ] 3.1 **[TEST] [RED]** Static include graph: enumerate every `require`/`include` reachable from
  `recovery.php` plus its plugin-local files; exactly one resolved path lies outside
  `plugins/system_updater/`, and it is `FS_FOLDER/config.php`. No `vendor/autoload.php` reference
  remains. Then the behavioural half: build a temp root with `vendor/`, `base/` and `src/` **absent**
  and a sentinel `config.php` pointing at an unreachable DB host; open the console with the correct
  secret → no fatal, no `Class not found`, no autoload attempt, and the failure that appears is the
  plain DB-connection error (the expected boundary), not a class error.
- [ ] 3.2 Remove the `vendor/autoload.php` load (`recovery.php:25-28`) and the
  `lib/session_auth.php` require (`:34-37`). `lib/backup_manager.php` and `lib/SqlDumpReader.php`
  stay (plugin-local, verified to contain no core `require`). Replace the removed session helpers
  with `lib/recovery_session.php` from PR 4 — if PR 4's implementation still calls
  `system_updater_configure_php_session()`, this is where that call goes.
- [ ] **Do not** change `process_restore.php`: it stays shared with `admin_updater` and must not
  require the secret (RACC-07 point 8).

### PR 7 verification

- [ ] `--filter RecoveryHandlerTest`, `--filter RecoveryRotationTest`, `--filter RecoveryIncludeGraphTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
- [ ] `ddev exec php -l recovery.php`
- [ ] Manual confirm the console still renders against the **real** ddev installation is **not**
  performed here (it would generate a real secret); the operator probe for RACC-01 is recorded in
  the PR body instead

---

## PR 8 — W8: Two-gate mint and the stepped restore drive (RACC-07, AUTH-05 equality, CSRF-01 equality)

**Est. changed lines**: ~490 (source ~210 — about 130 of it the replaced console JavaScript — tests ~280)
**Depends on**: PR 4 (gate and marker) and PR 7 (an authenticated console identity). Depends on PR 2
for the contract file that this PR flips to equality.
**Rollback boundary**: revert the mint, the session hand-off and the JavaScript block. The console
returns to a listing-only page with the shipped `action=start` button, i.e. exactly today's
(non-functional) restore path. Nothing else changes.

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U8.1 | **[TEST] [RED]** mint order via the step recorder: `secret_compared` → `admin_authenticated` → `token_minted`; no mint on the login render, on a failed secret, or before authentication | ~90 | Injected recorder + session store assertions | Revert test additions |
| U8.2 | Mint after both gates, opening `SU_SESS` without destroying the console session (identity keys and the marker survive); token bound to the minting session | ~70 | — | Remove the mint call |
| U8.3 | **[TEST] [RED]** the console's restore drive: `begin` then at least one `chunk`, the token on **every** request, no `action=start`, no `EventSource` | ~90 | Source inspection of `recovery.php` (rendered output) + issued-URL extraction | Revert test additions |
| U8.4 | Replace the console's restore JavaScript with the `fetch` `begin`/`chunk` driver ported from `view/admin_updater.html.twig`, carrying `su_csrf_token` on every request, with `busy` handling and bounded backoff | ~130 | — | Restore the shipped `EventSource` block |
| U8.5 | **[TEST]** the console offers no dead control and still lists every backup group | ~50 | Rendered output with an injected backup list | Revert test additions |
| U8.6 | **[TEST]** `CsrfMintingPointsTest::mintingReachesExactlyTwoGatedEntryPoints()` — the equality half | ~60 | Source enumeration | Revert the method |

**Unit → command.** U8.1/U8.2 `--filter RecoveryMintOrderTest`; U8.3/U8.4/U8.5 `--filter RecoveryRestoreDriveTest`; U8.6 `--filter CsrfMintingPointsTest`.

### Phase 1: The mint (TDD)

- [ ] 1.1 **[TEST] [RED] Step recorder.** The console's request pipeline accepts an optional recorder
  callable (default: a no-op) and appends a step at each gate. Assert:
  - correct secret + authenticated administrator ⇒ the recorded sequence contains
    `secret_compared`, `admin_authenticated`, `token_minted` **in that order** (assert the relative
    index of the three, not merely presence);
  - failed secret ⇒ `secret_compared` only, and `$_SESSION['system_updater']['csrf_tokens']` is
    absent afterwards;
  - passed secret, no authenticated administrator, login form rendered ⇒ no `token_minted`, the
    `system_updater.csrf_tokens` store is still absent, and the console's login token is **not** in
    it (REC-01's exclusion);
  - after a successful mint the console session still holds its identity keys and the
    `recovery_console` marker (RACC-07 point 5: opening the plugin session must not corrupt the
    console's own session).
- [ ] 1.2 Implement: mint with `system_updater_csrf_generate()` **after** both gates, preserving and
  restoring the console session around the plugin-session switch; store the minted value for the
  rendered page's controls (the value is emitted only on an authenticated render, never into a 404,
  never into a log line). The marker is not the token and the token is not the marker: assert both
  directions (RACC-07 point 7).

### Phase 2: The restore drive (TDD)

- [ ] 2.1 **[TEST] [RED]** Rendered output: every restore control's issued URL carries
  `su_csrf_token=`; the flow issues `action=begin` then at least one `action=chunk`; `action=start`
  appears nowhere in the console; `restoreEventSource`/`new EventSource` appear nowhere; no control
  is rendered whose behaviour is known to be refused. RED today on all four.
- [ ] 2.2 Port the driver: `startRestore` → `runRestoreStep('begin')` → on each response parse the
  SSE framing and either finish (`done` + `errors_count`/`warnings_count`) or issue
  `runRestoreStep('chunk')`; `busy` waits 2 s; transport failure retries with backoff up to a bound
  and reports the checkpoint; the token is carried on **every** request. Keep the modal and its
  existing element ids so the visual contract does not change. The console must not send restore
  parameters without the secret: a request with restore parameters and no valid secret already
  receives PR 4's 404 and mints nothing (pin it here as a regression).
- [ ] 2.3 Assert the "no dead control" and "listing still works" halves on rendered output with an
  injected backup list (a fake `list_backups_grouped()` result; no DB, no real files).

### Phase 3: The contract flips to equality (TDD)

- [ ] 3.1 **[TEST] [RED]** Add `mintingReachesExactlyTwoGatedEntryPoints()`: the enumerated set of
  HTTP entry points able to reach the three minting helpers is **exactly**
  `{admin_updater, recovery.php}`, no `process_*`, no third point. RED before U8.2 (the console does
  not mint yet), GREEN after. Keep `noUnauthorizedEntryPointCanMint()` from PR 2 unchanged: the
  subset assertion is still the guard against a future third point, and the two together are the
  two-gate contract. **Never** rewrite either into a single-element assertion.

### PR 8 verification

- [ ] `--filter RecoveryMintOrderTest`, `--filter RecoveryRestoreDriveTest`, `--filter CsrfMintingPointsTest`
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
- [ ] `ddev exec php -l recovery.php`
- [ ] Root-suite sanity: `ddev exec php vendor/bin/phpunit --testsuite Plugins` — record the count;
  the 12 `plugins/OidcProvider` failures are pre-existing and unrelated
- [ ] PR body states that the console's restore path is now the stepped protocol and that a
  console-minted token is a CSRF credential for `start`/`begin`/`chunk` only

---

## Delta conflict resolved at apply time (RACC-03 vs RACC-01/RACC-04)

The deltas contradict each other in one place, and the contradiction is load-bearing for PR 3 and
PR 4. It is recorded here rather than papered over; the resolution below is binding for apply and
must be reported in `verify-report.md`.

- RACC-03 orders secret resolution as **1) `SU_RECOVERY_SECRET` from `config.php`, then
  2) the persisted value**, and adds that `config.php` MUST NOT be required to take branch 2.
- RACC-01's scenario "A wrong secret never reaches `config.php`" requires that a request **without**
  a valid secret must not load `config.php` at all (a damaged `config.php` must not echo a parse
  error).
- RACC-04 requires the gate to answer **before** `config.php` is loaded.

Read literally, branch 1 can never be reached: knowing whether the candidate equals the pin requires
loading the file that contains the pin, which the other two requirements forbid on the failing path.

**Resolution (binding for apply):**

1. Compare the candidate against the **persisted value** first — core-free, always reachable
   (RACC-03 branch 2, RACC-04).
2. If a persisted value exists and does not match → 404, **without** loading `config.php`
   (RACC-01's scenario holds in the normal state and in the damaged-config state).
3. Only when **no persisted value exists** (the lost-secret state `RACC-06` exists for) load
   `config.php` inside `try { @include … } catch (\Throwable $e) {}` — a PHP parse error is a
   `Throwable` and is swallowed, so a broken file still yields 404 and no echoed error — and compare
   against `SU_RECOVERY_SECRET` if defined. Otherwise 404.
4. `--rotate-secret` (RACC-06) is the escape for the state in step 3 that does not need
   `config.php`, and the first-generation `error_log` line is the one for operators without a shell.

The narrow residual gap is explicit: in the state where the secret file is **also** missing, a wrong
secret causes a silent `config.php` load. That state has no persisted secret to protect, the include
is error-suppressed, and no body or header changes. Record it in `verify-report.md` as the accepted
deviation; do not "fix" it by dropping the operator pin (that would turn step 3 into a permanent
lockout, which RACC-06 forbids) and do not "fix" it by dropping the "no `config.php` on a wrong
secret" scenario (that is RACC-01's only proof that a damaged config cannot leak).

## Known spec-fidelity gaps (record them in `verify-report.md`)

These are places where a scenario's literal wording cannot be asserted by this suite. Each is
narrowed to what *is* observable; none is silently dropped.

| # | Scenario | Why the literal form is unavailable | What is asserted instead |
|---|---|---|---|
| 1 | RACC-04 "byte-identical to a request for a non-existent path" | Measured 2026-10-25: the `php -S` 404 body echoes the requested path (`/nope.php` 541 bytes vs `/other.php` 542 bytes); under Apache/nginx the body is the server's. No PHP response can match it for arbitrary paths | 404 status; **byte-identical body across absent / empty / wrong**; no console marker; no `Set-Cookie`; no session file; no `config.php` sentinel. Operator probe compares the production 404 shape (see D3) |
| 2 | RACC-01 "the console renders the backup list with a damaged core" | Rendering the list requires a live `fs_users`/backup directory; the suite is DB-free and must not use the real installation | Static include graph (exactly one external entry, `config.php`); a temp root with `vendor/`, `base/`, `src/` absent that boots to the DB boundary without a class error; the "renders the list" half is an operator-owned live probe |
| 3 | BA-02 "stubbed transport returns a denial N times" | No JavaScript test runner exists in this suite | Source assertions on `view/admin_updater.html.twig` (retry, backoff, no abort, session message after the budget), the `RestoreSteppedUiTest` pattern |
| 4 | REC-05 "response times are not an order of magnitude apart" | Wall-clock assertions are flaky in CI | The mechanism: exactly one `password_verify()` on all three paths and a **constant** bcrypt dummy hash (no per-request `password_hash()`); the sampled comparison is an operator note |
| 5 | REC-04 drift test against the core constants | `LoginThrottle` is loadable in this run (Composer autoload in `tests/bootstrap.php`), so the drift test should run; if it ever skips, the skip must be recorded | Equality of the four declared constants; a recorded skip reason if the class is missing |
| 6 | REC-06 "the old session id is no longer usable" | `session_regenerate_id(true)` is observable, but "no longer usable" needs a second process holding the old id | The id changes and the write order (recorded); the old-id rejection is an operator probe |

## Operator notes (not tasks)

- **The secret on first use.** The console generates its secret on the first gated request and
  writes it **once** to the PHP error log under a documented prefix. Operators should read it from
  the log or from `php recovery.php --secret`; it is never shown over HTTP. Losing it is recoverable
  three ways (`--secret`, `--rotate-secret`, `SU_RECOVERY_SECRET` in `config.php`) and none of them
  requires core code.
- **No deployment step.** No schema change, no Composer dependency, no configuration change. A
  revert leaves the persisted secret orphaned and harmless.
- **`--rotate-secret` invalidates the old value immediately**; every browser that has the old
  console URL must be re-opened with the new one.
