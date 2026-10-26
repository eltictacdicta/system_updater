# Tasks: Harden the plugin authentication guard

- **Change**: `harden-auth-guard` (plugin change — `plugins/system_updater/`)
- **Artifact store**: plugin-local OpenSpec (`plugins/system_updater/openspec/`); nothing is
  created in the core `openspec/`
- **Phase**: tasks
- **Inputs**: `proposal.md` (amended 2026-10-25 — provenance note, Scope table, risk register),
  the six deltas under `specs/` (**26 requirements / 102 scenarios**):
  `plugin-auth-guard` AUTH-01…06, `plugin-recovery-access` RACC-01…08,
  `plugin-recovery-login` REC-01…08, `plugin-csrf-token` CSRF-01,
  `plugin-backup-access` BA-01…02, `plugin-updates` PU-16 (added 2026-10-26 by product-owner
  decision after the other five were written); `openspec/config.yaml` (`strict_tdd: true`)
- **Delivery strategy**: `auto-chain`
- **Review budget**: **800 changed lines** per slice (session `review_budget_lines`)
- **Total estimate**: **~3,950 changed lines / ~28 files** → must be sliced. Nine slices, of which
  eight form the declared chain and W9 is detached (see "Review Workload Forecast — W9").
- **Added scope (2026-10-26)**: `PU-16` — a core update must stop writing the core's planning tree
  into the installation. It shares no file, no test seam and no ordering constraint with W1…W8:
  W9 touches `lib/core_updater.php`, not `recovery.php` or the guard, and it **may ship before any
  other slice**.
- **Core repository**: **out of scope**. No file under `base/`, `src/`, `controller/`, `model/`,
  `themes/` or the core `openspec/` is modified.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~3,950 (source/JS ~1,420, tests ~2,550) |
| 400-line budget risk | **High** — five of nine slices exceed the repository's 400-line default; **Low** against the 800-line session budget |
| Chained PRs recommended | **Yes** for W1…W8; **No** for W9, which is detached |
| Suggested split | 9 slices: 8 sequential (W1…W8) plus W9, independent and mergeable at any point |
| Delivery strategy | auto-chain (W1…W8); W9 may ship first, in parallel, or last |
| Chain strategy | Sequential to `main`, declared merge order, no tracker branch; W9 joins the same order at any position |
| Session review budget | 800 changed lines per slice |
| Largest single slice | ~615 lines (PR 4) — 77% of the 800-line budget |
| Smallest single slice | ~270 lines (PR 6) |
| W9 (detached slice) | ~360 lines — 45% of the 800-line budget, 0 dependencies, **may ship before PR 1** |

Decision needed before apply: **Yes — three items (D1 blocking; D2 and D3 have a stated default)**
Chained PRs recommended: **Yes for W1…W8; W9 is detached**
Chain strategy: **Sequential to `main`**
400-line budget risk: **High as shipped per slice (~3,600 in the chain + ~360 in W9); Low per slice against the 800-line budget**

**Forecast in plain terms.** The chain is **~3,600 changed lines across ~26 files** — about
9× the repository's 400-line review default and 4.5× the 800-line session budget if shipped as
one unit. It cannot ship as a single PR, and unlike the companion core change
(`2026-10-25-harden-auth-boundaries`, ~1,180 lines / 6 slices / largest ~230) it cannot fit in
slices *under* 400 either: the tests dominate because the plugin suite has **no database**, so
almost every requirement is asserted through a purpose-built seam (pure decision functions, a
counting verifier, a temp install root with a sentinel `config.php`, a `php -S` subprocess with a
cookie jar). Eight chained slices of 270–615 lines each land under the 800-line session budget and
each is independently revertible. Every slice except PR 2, PR 3 and PR 6 exceeds the repository's
400-line default *because of its test file*, which is the same pattern the core slices exhibited;
the forecast below does not pretend otherwise. W9 (`PU-16`, added 2026-10-26) is counted separately
below: it is ~360 lines, has no dependency on any other slice, and may ship before PR 1.

### Review Workload Forecast — W9 (detached slice, added 2026-10-26)

| Field | Value |
|-------|-------|
| Requirement | `PU-16` (delta `specs/plugin-updates/spec.md`) |
| Estimated changed lines | **~360** (source ~110, tests ~250) |
| Files touched | `lib/core_updater.php`, `tests/CoreUpdaterPruneTest.php` (new) — **2 files** |
| 400-line budget risk | **Low** — ~360 lines, no test harness to build, no subprocess seam |
| 800-line session budget | **Low** — 45% of the budget |
| Dependencies | **None.** Independent of W1…W8 in both directions |
| Ordering constraints | **None.** Touches `lib/core_updater.php`, which no other slice edits |
| May ship before the rest? | **Yes.** It can be the first PR merged, run in parallel with any other slice, or merged last; it rebases cleanly because it shares no file with W1…W8 |
| Why it is not folded into another slice | It has nothing in common with the recovery console. Folding it into W4 or W7 would mix two unrelated security surfaces in one review |

**W9 in plain terms.** ~360 lines, one decision and its drift test. The source change is two call
sites around one helper; the review weight is the test, which has to build a staging tree, run a
real `ZipArchive` extraction and read the core's `nginx-sample.conf` without becoming vacuous when
that file is absent. It is the one slice in this change a reviewer can read end-to-end in a single
sitting.

**D1 (blocking).** PR 1, PR 4, PR 5 and PR 8 sit between 500 and 620 lines. Auto-chain accepts
them under the 800-line budget; the repository's 400-line default does not. Decide before apply:
ship the eight chained slices as declared (recommended — each is one coherent security property plus
its harness), or split PR 1 / PR 4 / PR 5 / PR 8 into ~12 slices of 250–300 lines at the cost of
splitting a single decision across two PRs (PR 1's reader fix and its administrator verdict are
the same failure; PR 4's gate and its marker are the same request chain). W9 is not part of this
decision: at ~360 lines it fits the 400-line default.

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
| 9 | W9 — core update stops writing the planning tree | PU-16 | ~300 | **No dependency on W1…W8.** Touches `lib/core_updater.php`, not the console. May merge first, in parallel, or last |

### Chain Dependency Diagram

```
                            main
                             │
                             │   PR 9  W9 core update prune ──► main   (detached: no arrow in,
                             │        (PU-16)                            no arrow out; may
                             │                                           merge at any point)
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

Merge order: **1 → 2 → 3 → 4 → 5 → 6 → 7 → 8**, with **PR 9 insertable at any position**. PR 3,
PR 5 and PR 6 are code-independent of PR 1
and PR 2 and may be *authored* in parallel; they must still merge in the declared order because
PR 7's tests reference their symbols. PR 7 will not compile without PR 1 (the guard decision),
PR 4 (the marker), PR 5 (the policy) and PR 6 (the verifier and the login token), so it is authored
last of that group. PR 8 must be authored after PR 7. **PR 9 shares no file with any other slice**:
it edits `lib/core_updater.php` and adds `tests/CoreUpdaterPruneTest.php`, so it can be the first PR
merged (recommended if the planning tree is the more urgent exposure), merged in parallel, or merged
last; it rebases cleanly in every position. No tracker branch is used: every PR merges to `main`
and the next one rebases on it.

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
- **Deleting the planning tree from an installation that already received it.** `PU-16` confines the
  prune to the staging directory and forbids it from running against `$this->rootPath`; a
  pre-existing `openspec/` in the root is an operator action, and `cleanupRootDirectories()` is not
  extended with the eight names (see the W9 section).
- **Deploying or editing `nginx-sample.conf`.** W9's test *reads* it (read-only, degraded gracefully
  when absent) to prove the two lists match. No core file is modified, which keeps the
  "core repository all of it" boundary intact.
- **Pruning plugin ZIP installs** (`plugin_downloader::extractZipSafe()` at `:1441`,
  `updater_manager::extractZipSafe()` at `:839`): they extract into `plugins/`, so a plugin's own
  `openspec/` is exactly what must survive.

## Handed to this phase by the spec phase

The spec phase closed with **four** items that belong to ordering and test shape, not to
requirements. They are binding here (item 4 was added on 2026-10-26 with `PU-16`):

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
4. **The two-list anti-drift test has an always-runnable half.** `PU-16` requires the plugin's
   allowlist and the core sample's planning deny block to be proven equal, and requires the test to
   degrade gracefully where the core repository is absent (this plugin ships standalone too). The
   shape is therefore fixed: the eight-name assertion runs **unconditionally**; the comparison
   against `dirname(__DIR__, 3) . '/nginx-sample.conf'` runs when that file exists and otherwise
   `markTestSkipped`s with a message naming the path it looked for. A bare `return`, an
   `if (!file_exists) { $this->assertTrue(true); }`, or any skip without a reason is the vacuous
   pass the requirement forbids. The block to compare against is the planning block
   (`nginx-sample.conf:50-61`), never every deny in the file.

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
| PU-16 | PR 9 | U9.1…U9.6 |

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

> **Closed in W2** (see the "Applied note (folded into PR 2)" under PR 2): both paths now classify
> the reader environment before connecting, refuse with `null` exactly as before, and write one
> operator-visible line per distinct cause.

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

- [x] 1.1 **[TEST] [RED] Uniform refusal.** Assert that `system_updater_deny_unauthenticated_request()`
  emits the *same* message for every reason, that the JSON body is
  `{"success":false,"message":"<the constant>"}`, and that the SSE event payload's `message` is the
  **same string** (AUTH-04: "the transport difference must never become a denial-reason difference").
  RED today because the strings are literals in two functions while the guard has no reason
  vocabulary. Seed the constant in the same commit only after the assertion is red.
- [x] 1.2 Introduce `SYSTEM_UPDATER_GUARD_DENIAL` (or an equivalent single constant) and consume it
  in both emitters. No message text may change: the shipped string stays the operator-facing text.
- [x] 1.3 **[TEST] [RED] Ordering is asserted, not inferred.** Two complementary assertions:
  (a) a source ordering assertion on `download_backup.php` — the
  `system_updater_process_init(... 'plain' ...)` call precedes the first
  `system_updater_csrf_read_from_request()` call; (b) a behavioural assertion with the guard
  instrumented: with an injected deny verdict and a valid-looking token, the handler body is not
  reached (a sentinel callable is never invoked). Note in the test why the status code alone cannot
  prove the order (a 403 would prove the opposite, a 401 could be produced either way).
- [x] 1.4 **[TEST] BA-02 client tolerance** (source assertions on `view/admin_updater.html.twig`):
  a `!response.ok` denial during a restore is retried (`restoreConsecutiveFailures`), the modal keeps
  the progress state rather than switching to a terminal error, no abort request is issued, and
  after the retry budget the message says the session must be re-established **without** claiming a
  successful restore. RED because that last branch does not exist (today's message talks about
  resuming and releasing maintenance). Implementation is a message/branch change in the same file.
- [x] 1.5 **[TEST] `CsrfMintingPointsTest::noUnauthorizedEntryPointCanMint()`** — enumerate every
  `.php` file under `plugins/system_updater/` that references `system_updater_csrf_generate(`,
  `system_updater_csrf_field(` or `system_updater_csrf_meta(`, resolve each to its HTTP entry point
  (`lib/*` → the entry points that include it), and assert every entry point is in
  `{admin_updater, recovery.php}` and that no `process_*.php` or `download_backup.php` is in the set.
  Green at the end of PR 2 (the console does not mint yet, and the subset allows that). This is the
  **subset** half; the equality half is U8.6. State both in the test's docblock so no later reader
  "fixes" it back to a single-element assertion.

### PR 2 verification

- [x] `--filter GuardDecisionTest` (34 / 115), `--filter CsrfMintingPointsTest` (1 / 12),
  `--filter DownloadBackupScriptTest` (11 / 27), `--filter DownloadBackupHandlerTest` (15 / 37),
  `--filter RestoreSteppedUiTest` (12 / 37), `--filter AdminBackendSupportTest` (23 / 109)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **379 / 976 /
  2 skipped**, 0 failures, 0 errors (W1 was **362 / 870 / 2 skipped**)
- [x] `ddev exec php -l` on every changed PHP file
- [ ] PR body states that a 403-versus-401 assertion alone does not prove ordering, and what was
  asserted instead

**Applied note (folded into PR 2).** The gap W1 recorded under "Known remaining silent-deny path
(recorded, not fixed)" is closed here, in the same failure class as the unsupported-backend fix and
by the same means: `system_updater_admin_read_availability()` classifies the reader **environment**
(no `mysqli` extension, or a `config.php` without FS_DB_HOST/FS_DB_USER/FS_DB_NAME) **after** the
backend and **before** any connection, refuses with `null` exactly as before, and writes one
operator-visible line per distinct cause (AUTH-04 holds: the caller's refusal is byte-identical to
the anonymous one, asserted). No PostgreSQL driver was added; the gate remains one client and one
backend. Tests: six new cases in `tests/AdminBackendSupportTest.php` (23 / 109 total).

**Two deviations worth naming.** (1) U2.2 also introduced
`system_updater_denial_payload()` / `system_updater_denial_sse_payload()`, because "the same string
in both transports" is not assertable in-process while the emitters build their JSON inline and
`header()`/`exit` are untestable under the CLI SAPI. The seam is pure and the emitted bytes are
unchanged. (2) The U2.3 behavioural half runs the real `system_updater_require_authenticated_session()`
in a CLI subprocess with a throwaway install root (no session, no cookie, no database, no
`config.php`) and checks that the sentinel line after it never executes — a status code cannot prove
the order, and the plugin's HTTP harness arrives in PR 4.


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
| U3.5 | **[TEST]** unwritable location is a loud error, never a per-request regeneration | ~45 | `chmod 0555` on a temp dir (skipped, visibly, where the suite runs privileged) | Revert test additions |

**Unit → command.** U3.1/U3.3/U3.5 `--filter RecoverySecretTest`; U3.2/U3.4 `ddev exec php -l plugins/system_updater/lib/recovery_secret.php` plus the CLI probes inside `RecoverySecretTest` (they spawn `php <tmproot>/plugins/system_updater/recovery.php`, never the real installation).

### Phase 1: The value exists and survives (TDD)

- [x] 1.1 **[TEST] [RED]** Shape and persistence: the resolved secret is 64 hex characters
  (`strlen === 64 && ctype_xdigit`); a second resolution in the same temp root returns the identical
  value and does not rewrite the file; two different temp roots produce two different values; the
  value is not `sha1($root)`, not `md5($root)`, not a substring of either, and does not contain the
  database name (RACC-02 "MUST NOT be derived solely from values that are guessable or shared").
  Permissions asserted with `fileperms() & 0777 === 0600`. The stored location must be **outside**
  the install root when a writable sibling exists — assert the resolved path is not inside the temp
  root used as the webroot in that fixture.
- [x] 1.2 `lib/recovery_secret.php` — `recovery_secret_generate(): string` (`bin2hex(random_bytes(32))`),
  `recovery_secret_store(string $value): bool`, `recovery_secret_path(): string` (candidate list:
  outside-webroot sibling first, inside-webroot last resort), and the documented write with
  `chmod 0600`. A failed write returns `false` and never regenerates per request.
- [x] 1.3 **[TEST] [RED] Resolution order.** With `SU_RECOVERY_SECRET` defined in the sentinel
  `config.php`: the constant wins and the persisted file is neither read nor rewritten (assert the
  file's mtime and contents are unchanged). Without the constant and with a **fatally broken**
  `config.php` (a file containing invalid PHP): the persisted value is still returned, no class is
  loaded (assert by deleting `vendor/`, `base/` and `src/` from the temp root), and no parse error
  is emitted. **Read "Delta conflict resolved at apply time" below first** — the pin branch and the
  "wrong secret never loads `config.php`" scenario cannot both hold literally, and the test must
  encode the resolution, not one horn of the contradiction.
- [x] 1.4 Resolution + CLI. `recovery_secret_resolve(): string` implements the documented order.
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
- [x] 1.5 **[TEST]** Unwritable location: create the install root, `chmod 0555` the only candidate
  parent, delete the persisted file, resolve → the operator-visible error names the failure, no new
  secret is generated, and two consecutive requests produce the same error (no per-request
  regeneration).

### PR 3 verification

- [x] `--filter RecoverySecretTest` — OK (19 tests, 135 assertions)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **399 / 1114 / 2 skipped**, 0 failures (baseline **380 / 979 / 2 skipped**)
- [x] `ddev exec php -l plugins/system_updater/lib/recovery_secret.php` — also `recovery.php`, `tests/RecoverySecretTest.php` and `tests/Support/RecoverySecretFixture.php`
- [x] Confirm no secret file was created anywhere under the real `FS_FOLDER`
  (`git status --porcelain` in the plugin stays clean apart from the intended files)

### PR 3 review fixes (CodeRabbit, three findings)

- [x] **Finding 1 — the fallback install root.** `__DIR__` is `<root>/plugins/system_updater/lib`,
  so two `dirname()` calls named `<root>/plugins`; `dirname()` of that put the "outside the webroot"
  candidate at `<root>/system_updater_recovery_secret.php`, i.e. **inside** the webroot, for the one
  caller that passes no root and runs without `FS_FOLDER`. Corrected to three levels and, more
  importantly, no longer trusted as arithmetic: the plugins container must be named `plugins` and
  `<root>/plugins/<plugin>` must resolve to this file's own directory, otherwise the resolution
  yields **no root and no candidates** — the loud error, never a guessed path.
  Pinned by `testTheFallbackInstallRootIsTheInstallationAndNotTheWebroot`,
  `testTheFallbackFirstUsePersistsOutsideTheWebroot` and
  `testTheFallbackRefusesAWebrootItCannotVerify`, all in a fresh process where `FS_FOLDER` does not
  exist (the reason the existing cases never took the branch).
- [x] **Finding 2 — the unwritable-location cases under a privileged host.** `chmod 0555` does not
  restrict root, so on a root CI container the location stayed writable, a secret was generated and
  both cases failed. The precondition is now *observed* instead of assumed
  (`makeSecretLocationsUnwritable(): bool`) and the case skips visibly when the host cannot produce
  the state. Verified: uid 1000 runs both cases (2 tests, 19 assertions), uid 0 reports two skips
  and zero failures.
- [x] **Finding 3 — the temp file holding the secret.** `file_put_contents()` created it with the
  process umask (typically `0644`) and the `chmod(0600)` landed afterwards; a partial write returned
  `false` and left the prefix on disk; the byte count was never verified. The write now runs under
  `umask(0077)` restored in a `finally` (including when it throws), verifies
  `written === strlen($payload)`, and removes the temp file on **every** failure path — short write,
  failed write, failed rename and throw. Still temp + `rename`, still `0600` on the final file.
  Pinned by `testTheSecretFileIsCreatedUnderAnOwnerOnlyUmask` and
  `testAPartialWriteIsRejectedAndLeavesNoTemporaryFileBehind`, observed through
  `tests/Support/PartialWriteStream.php` (the only seat from which creation-time umask and a short
  write are observable without racing the writer).
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **404 / 1138 / 2 skipped**, 0 failures
- [x] `ddev exec php -l` on `lib/recovery_secret.php`, `tests/RecoverySecretTest.php`,
  `tests/Support/RecoverySecretFixture.php` and `tests/Support/PartialWriteStream.php`

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

- [x] 1.0 `tests/Support/ConsoleHttpHarness.php` — `boot(array $files = [], string $configBody = '')`
  copies `recovery.php`, `lib/recovery_secret.php` and their dependencies into
  `<tmp>/plugins/system_updater/`, writes `<tmp>/config.php` whose **first statement** writes
  `<tmp>/config_loaded.sentinel` (so "config.php was loaded" and "a DB connection was attempted"
  become filesystem facts), optionally omits `vendor/`, `base/`, `src/` entirely (the A1 damaged-core
  fixture), starts `php -S 127.0.0.1:<port> -t <tmp>`, and exposes
  `get(string $path, bool $withCookies = false): array{status:int, headers:string, body:string}` via
  `curl -s -D - -o - -w '\n%{http_code}'`. Teardown kills the PID and removes the root **even on
  failure** (`try/finally` in the test, plus a `register_shutdown_function` backstop). Keep every
  fixture inside `sys_get_temp_dir()`; never bind a fixed port.
- [x] Note in the harness docblock, for the next reader: `php-cgi` is not installed, `php -S` is the
  only way to observe status/headers/cookies here, and the server's own 404 body echoes the path —
  so it cannot be used as the "non-existent path" baseline byte-for-byte (see risk D3).

### Phase 2: The gate (TDD)

- [x] 2.1 **[TEST] [RED]** Three requests to the console script with **no** parameter, an **empty**
  parameter and a **64-hex wrong** parameter: each returns 404, the three bodies are byte-identical
  to each other, none contains `Modo Recuperación`, the plugin name, a backup name or a login field,
  no `Set-Cookie` header is present, `<tmp>/config_loaded.sentinel` does not exist, and no session
  file appears in the configured save path. RED today (the console renders the login form and
  connects to the DB).
- [x] 2.2 Gate in `recovery.php`: resolve the secret **without** loading `config.php`
  (`lib/recovery_secret.php` only), compare with `hash_equals()` on the full 64-hex string, and on
  failure emit the single 404 path — **one code path for absent, empty and wrong** (no early return
  for "no parameter"), no session start, no DB, no backup directory read, `Referrer-Policy:
  no-referrer` only on the success path. `config.php` is loaded only after the comparison succeeds
  (the one documented exception: the operator-pin branch of "Delta conflict resolved at apply time"
  below, reachable only when no persisted value exists).
- [x] 2.3 **[TEST] [RED]** Correct secret in the query string: status 200, the response has
  `Referrer-Policy: no-referrer`, the console's login form is present, and the secret value appears
  in **none** of body, `Location`, or a form `action`.
- [x] 2.4 Ensure the success path emits that header and never echoes the secret. Keep the rest of
  the rendered page as today (no UI redesign beyond what the gate requires).

### Phase 3: The marker (TDD)

- [x] 3.1 **[TEST] [RED]** Cookie-jar continuity: request 1 carries the secret and obtains the
  cookie; request 2 posts the login form **without** any query parameter and is accepted for
  credential evaluation (today it re-renders or fails because nothing is remembered). Then the
  negative halves: a session holding **only** the marker is refused by
  `download_backup.php` and by a `process_*` action; a request without a valid secret receives the
  PR 4 404 and leaves no marker in the store.
- [x] 3.2 Write the marker **only** after a successful constant-time comparison, under a
  console-only namespace inside the session the console opened (`$_SESSION['recovery_console']` —
  D2's default), never in `_sf2_attributes`, never `_csrf`, never an identity key. It is regenerated
  by `session_regenerate_id(true)` together with REC-06's rotation (asserted in PR 7). No other
  plugin script may read it: add the source assertion that `recovery_console` appears only in
  `recovery.php` and its plugin-local includes.
- [x] 3.3 **[TEST]** RACC-08: with `vendor/`, `base/` and `src/` absent and `FS_SESSION_NAME`
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

- [x] `--filter RecoveryGateTest` — OK (5 tests, 81 assertions)
- [x] `--filter RecoveryMarkerTest` — OK (4 tests, 21 assertions)
- [x] `--filter RecoverySessionBootstrapTest` — OK (2 tests, 13 assertions)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **419 / 1283 / 2 skipped**, 0 failures (baseline **404 / 1138 / 2 skipped**); still DB-free: every fixture's `FS_DB_*` points at `127.0.0.1:1` and the suite establishes that `config.php` never ran on a refused request
- [x] `ddev exec php -l` on `recovery.php`, `lib/recovery_session.php`,
  `tests/Support/ConsoleHttpHarness.php`, `tests/Support/ConsoleHttpTestCase.php`,
  `tests/RecoveryGateTest.php`, `tests/RecoveryMarkerTest.php`,
  `tests/RecoverySessionBootstrapTest.php`
- [x] D3 recorded: measured `/nope.php` 541 bytes vs `/other.php` 542 bytes, the built-in server's
  404 echoes the requested path (`/plugins/system_updater/nope.php` → 564 bytes), and the asserted
  subset is status 404 + identical bodies across absent/empty/wrong + the header set PHP controls +
  no `Set-Cookie` + no session file + no sentinel `config.php` + no console marker in the body
- [x] Applied while measuring: the refusal sent `X-Powered-By: PHP/8.3.33`, which the server's own 404
  does not, so it announced its own existence independently of the secret. `header_remove()` on the
  refusal path makes its header names a subset of a missing path's; pinned by
  `testARequestWithoutTheSecretIsIndistinguishableFromAMissingPath`

**Applied deviations (W4).** Two, both forced by the requirement to observe the success path
without a database, and both recorded here rather than papered over.

1. **The database connection is no longer a hard stop.** `recovery.php` used to `die()` when the
   connection was refused, so a request with the correct secret on a DB-less installation answered
   `200` with `Error crítico: …` and never reached the login form — which made U4.3's "the login
   form is present" unobservable in a DB-free suite, and contradicted RACC-01's "a missing
   `config.php` is the only hard stop". The connection is now attempted, the failure remembered, and
   credentials evaluated only when a connection exists; otherwise the form is re-rendered with a
   plain reason (fail closed). The administrator re-check for an existing session is skipped in that
   state for the same reason.
2. **The marker read may load `config.php` — silently, and only when the request already carries a
   cookie.** The console's session name lives in `config.php` (`FS_SESSION_NAME`, or the
   `FS_SECRET_KEY` the name is derived from), and the marker must be readable without the secret.
   The read is buffered, error-suppressed, `Throwable`-caught, skips a file that already ran in the
   request, and changes no body or header. A request with **no** cookie never reaches it, which is
   what keeps RACC-04's three failure cases free of any `config.php` execution (the sentinel proves
   it). This is the same class of documented exception as the gate's lost-secret branch.

### PR 4 review follow-up — CodeRabbit findings (three, all resolved)

Applied on the same branch as three additional commit pairs (RED then fix), after the eleven W4
commits. Nothing under `openspec/` is committed: this section is working-tree only.

- [x] **MAJOR — the marker read inspected one save path and started the session in another.**
  `recovery_console_read_marker()` did `is_file(recovery_session_save_path() . '/sess_' . $id)` and
  then called `session_start()` *before* `recovery_session_configure()` applied that path, so on any
  installation that defines `FS_SESSION_SAVE_PATH` the `files` handler looked for the id in the ini
  `session.save_path`, created an **empty** file there, and never found the marker: the login POST
  without `?secret=` got the gate's 404 (RACC-05 broken on those installations) and the refusal left
  a stray session file behind. Fixed by making the inspected path and the started session the same
  path on **every** branch: one helper, `recovery_session_apply_save_path()`, applies
  `recovery_session_save_path()` (constant → ini, normalized through
  `recovery_session_normalize_save_path()`, which is the `N;/path` and empty-ini normalizer both
  sides now share) and then **reads the path back from the session module** and compares it with the
  value about to be inspected. `is_file()` runs only after that verified equality (so `configure()`
  and the marker read can never disagree), and a declared path PHP refuses (no directory,
  `open_basedir`, a locked ini) yields "not authorized" instead of a guess at the ini directory —
  which is also what keeps the refusal free of session state. Test:
  `RecoveryMarkerTest::testTheMarkerIsReadFromTheSavePathTheSessionActuallyUses` — the fixture server
  runs with `-d session.save_path=<scratch>`, the config declares a *different* existing directory,
  the accepted request's file must appear there, the cookie-only POST must be 200, and the ini
  directory must hold no session file.
- [x] **MINOR — the HTTP harness could accept a foreign server on the chosen port.**
  `waitForServer()` accepted the first `fsockopen()` that connected. On a port another process
  already held, `php -S` could not bind and exited while the connect succeeded against the stranger:
  every request then reached a server that never read the fixture, and the *absence* assertions (no
  `Set-Cookie`, no session file, no console marker in the body) passed without the console ever being
  observed. Acceptance is now two facts checked while waiting: the spawned process is still alive
  (`proc_get_status()`), and the fixture's own probe file comes back with a random token that only
  its document root holds (the token is in the **body**, never the file name — the built-in server's
  404 echoes the requested path, so a name carrying the token would let a stranger "serve" it by not
  having the file at all). The probe uses a socket rather than curl so it cannot be the reason the
  seam is skipped. A start that satisfies neither fails loudly with the port and the server's own
  output, which names the collision (`Failed to listen on … Address already in use`). `boot()` gained
  an optional pinned port so the collision is staged rather than hoped for. Tests:
  `tests/ConsoleHarnessPortTest.php` — a real `php -S` holds a kernel-assigned port, the harness is
  pinned to it and must throw naming that port, plus a positive control that the same pinned seam
  still serves the fixture on a free port.
- [x] **MINOR — rotating the secret did not invalidate an already-issued marker.**
  `recovery_console_mark_authorized()` stored `authorized => true` with no link to the value that
  granted it, so `--rotate-secret` changed what `recovery_secret_gate()` compares against while every
  session that had already opened the console kept entering for the rest of its lifetime — the leak
  rotation exists to close stayed open. The marker now stores
  `hash_hmac('sha256', 'recovery_console', $grantedValue)` and is verified with `hash_equals()`
  against the credential the gate would answer against **now**. That question has exactly one
  definition, `recovery_secret_gate_value()` (persisted value first, operator pin only while nothing
  is persisted), which the gate itself now consumes, so the two orders cannot drift. A marker whose
  fingerprint does not match is the normal 404 path and is **not** rewritten (asserted byte-for-byte
  on the seeded session file) — no silent upgrade. The marker never stores the secret nor a plain
  digest of it: a bare SHA-256 of a 64-hex secret is a password-equivalent for anyone who can read
  the session file, while an HMAC keyed by a context string is only useful for this comparison.
  **Operator-pin decision:** the fingerprint is always over the value the gate answers against, so a
  pin-granted marker (reachable only when nothing is persisted, because a persisted value outranks
  the pin at the gate) keeps working while the pin is still that value — refusing it would send the
  operator back to the secret on every reload, which is what the marker is for — and dies the moment
  a rotation **persists** a value, since that is the moment the pin stops being what the gate accepts.
  Rotating the persisted value while a pin is in force changes nothing about what grants access, so
  retiring that marker would be theatre; the pin itself has to be retired. The gate also stops
  re-including `config.php` when the pin is already visible, which the marker path needs (a second
  include of a config that declares more than constants is a redeclaration). Tests:
  `RecoveryMarkerTest::testRotatingTheSecretRetiresTheMarkerItGranted`,
  `testAMarkerGrantedByTheOperatorPinDiesWhenARotationPersistsAValue`,
  `testAMarkerGrantedByTheOperatorPinKeepsWorkingWhileThePinIsCurrent` (the positive control, so the
  decision cannot be satisfied by refusing pin markers outright), and
  `testAMarkerThatCannotNameItsGrantingSecretIsRefused` (unbound and foreign-bound markers).

**Re-measured after the three fixes** (`ddev exec php vendor/bin/phpunit -c
plugins/system_updater/phpunit.xml`): **426 / 1314 / 2 skipped**, 0 failures, 0 errors (W4 was
419 / 1283 / 2 skipped).

**Known limitation, recorded rather than papered over.** The pin-granted marker is verified only
when the pin is visible to the request that reads it. That holds in practice — the marker path
resolves the session name from `config.php`, which is where `SU_RECOVERY_SECRET` lives — but a
deployment that got the session name from an already-loaded `SessionManager` would hide the pin from
the fingerprint check and refuse the marker. That direction is fail-closed (the operator re-supplies
the pin) and is why the fingerprint is compared against the gate's own value instead of against a
second copy of the resolution order.

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

- [x] 1.1 **[TEST] [RED]** A submission whose login token was rejected records **zero** attempts:
  assert the shared throttle counter, the fallback counter and the `ip.log` content are all
  unchanged, and the verifier was never called. Then the positive half: a valid token plus a wrong
  password records **exactly one** failure, in **exactly one** counter set (never both).
  RED today because there is no policy module at all, and RED-against-today also demonstrates the
  real defect: the shipped handler has no counter of any kind.
- [x] 1.2 Implement `recovery_policy_should_count(string $phase): bool` and make the pipeline record
  exactly one failure in exactly one mode (shared when `LoginThrottle` is loadable, fallback
  otherwise).

### Phase 2: The ban read (TDD)

- [x] 2.1 **[TEST] [RED]** Table-driven parser cases over literal lines, mirroring
  `fs_ip_filter::read_line()`/`is_banned()`: `203.0.113.7;6;<future>` → banned;
  `203.0.113.7;5;<future>` → not banned (the core uses `>` not `>=`);
  `203.0.113.7;9;<past>` → not banned; a two-field line → ignored; an empty line → ignored; a
  different IP → not banned; an absent file → not banned; a malformed `count` (non-numeric) → not
  banned. Where `fs_ip_filter` is loadable in the test process, add a **parity assertion**: for the
  same input file, `fs_ip_filter::is_banned()` and the plugin parser agree (this is the drift guard
  REC-03 asks for). One case writes a real fixture into a temp `tmp/<FS_TMP_NAME>ip.log`.
- [x] 2.2 Implement the parser over `FS_FOLDER/tmp/<FS_TMP_NAME>ip.log`, read-only. Forbid writes:
  assert the file's `md5_file` and `mtime` are unchanged after any sequence of calls (REC-03 "the
  endpoint never writes this file").

### Phase 3: The fallback limiter (TDD)

- [x] 3.1 **[TEST] [RED]** Six failures for one nick from one IP inside the window → the seventh is
  refused before evaluation; 20 failures for one account across IPs → refused; a success clears the
  counter used for recording; an elapsed window re-opens the budget (inject the clock; do not sleep).
  Declared constants must equal `LoginThrottle::MAX_ATTEMPTS` (6), `::THROTTLE_WINDOW` (600),
  `::ACCOUNT_MAX_ATTEMPTS` (20), `::ACCOUNT_WINDOW` (900) — assert by reading the core constants
  when the class is loadable, and `markTestSkipped` with a recorded reason when it is not (REC-04's
  drift test).
- [x] 3.2 Implement the plugin-local counter under `sys_get_temp_dir()`/`FS_TMP_NAME`, with
  `flock()` (the `system_updater_save_progress()` pattern), no more permissive than the core in
  either dimension, and a message that states the console is running its local limiter.

### Phase 4: The verdict and the failure body (TDD)

- [x] 4.1 **[TEST] [RED]** Three submissions — unknown nick, existing nick with a wrong password,
  existing non-administrator with the correct password — produce the **same** outcome token and the
  **same** body, and each performs **exactly one** `password_verify()` (counting verifier). The
  unknown-nick path uses a **declared constant** bcrypt hash: assert `password_get_info()` reports
  bcrypt, assert the constant is used (no `password_hash()` call at request time — spy on it), and
  assert the verdict calls the verifier exactly once for all three inputs. Timing: do **not**
  assert wall-clock ratios in the suite (flaky); assert the mechanism that produces comparable
  timing, and leave the sampled comparison to the operator note.
- [x] 4.2 Implement `recovery_policy_verify_credentials()` returning one of `ok` / `deny` only, with
  the dummy-hash branch and the single verification. The failure message constant lives here so
  PR 7 renders it in one place.

### PR 5 verification

- [x] `--filter RecoveryBanListTest` (17 / 30), `--filter RecoveryFallbackLimiterTest` (13 / 72),
  `--filter RecoveryCredentialVerdictTest` (45 / 129)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **515 / 1769 /
  2 skipped**, 0 failures, 0 errors (baseline **440 / 1538 / 2 skipped**; both skips pre-existing:
  `SelfUpdateSessionBindingTest::bindingIsEmptyWithoutASessionId` and
  `SessionAuthTest::testResolveCookiePathUsesRootForDocumentRootInstall`). **The drift checks did
  not skip**: `LoginThrottle` and `base/fs_ip_filter.php` are both present, and the ban parity case
  ran the real `fs_ip_filter` in a child process against the same fixture file
- [x] `ddev exec php -l plugins/system_updater/lib/recovery_policy.php`
- [x] `git diff --stat` shows **no** change to `recovery.php` in this PR (the split is the point)

### Applied note (W5) — working-tree only, not committed

**Files**: `lib/recovery_policy.php` (new, 841 lines), `tests/RecoveryBanListTest.php`,
`tests/RecoveryFallbackLimiterTest.php`, `tests/RecoveryCredentialVerdictTest.php`,
`tests/Support/PolicyProbe.php`. **No other file changed; `recovery.php` is untouched.**

**Budget overrun, stated plainly.** 2,089 changed lines against the ~565 estimate for this slice,
and against the 800-line session budget. The forecast under-counted because it assumed four small
test files; in practice REC-02/03/04/05 are four independent gates with four independent failure
modes, and the docblocks carry the reasoning the drift risks require (`REC-03`'s "second parser of
an internal format", `REC-04`'s "not a second budget in any single install state"). Reviewable in
the eight commits as delivered; if the budget is enforced, the natural split is **W5a** (REC-02 +
REC-03: `should_count`/`count_bucket`/`evaluate` + the ban read) and **W5b** (REC-04 + REC-05: the
limiter and the verdict), which share no symbol.

**Deviations from the task text, all deliberate:**

1. **`recovery_policy_verify_credentials()` takes the whole row, not `(hash, plain)`.** REC-05's
   three cases include "authenticated but not administrator", so the administrator flag has to be
   read *after* the single verification. Splitting it into a hash comparison plus a separate admin
   check would let a caller check the flag first and reintroduce the oracle.
2. **No spy on `password_hash()`.** A language function cannot be intercepted in the global
   namespace, so the assertion is a source one: the module contains no `password_hash(` call and
   the dummy is a declared `const`. `password_get_info()` proves it is a real bcrypt hash (cost 10),
   and `password_verify()` proves no candidate matches it.
3. **`recovery_policy_evaluate()` takes the gate *facts*, not the request.** The console still owns
   the three reads (token, ban file, throttle) and the row lookup; what the function guarantees is
   that a fact which closed a gate can never be followed by an evaluation. The order of the reads is
   therefore asserted in W7, and the order of the *decisions* is asserted here.
4. **The uniform body has one documented exception**: a throttled refusal while the local limiter is
   in force carries `RECOVERY_POLICY_LOCAL_LIMITER_NOTE` (REC-04's third scenario requires it). In
   the default state every refusal — token, submission, banned, throttled, credential — renders the
   same string.
5. **The fallback counter is one JSON file per installation**, keyed by a digest of the install root.
   *(Corrected by the review round below: it lives in `<root>/tmp/`, not in `sys_get_temp_dir()`.)*

**Left for W7 (the wiring), so this is not mistaken for done:** `recovery.php` still calls
`password_verify()`/`LegacyCompatibility` directly, still has three distinct failure strings, still
counts nothing, and never consults `ip.log` or `LoginThrottle`. W7 must (a) mint and validate the
form token and pass `token_valid`, (b) resolve the row and pass `nick`, `ip`, `input_valid`,
`row`, `plain` and `now`, (c) call `recovery_policy_is_ip_banned()`, (d) pass
`recovery_policy_verify_credentials()` bound with W6's verifier as the verdict and
`recovery_policy_fallback_reserve()` / `_clear()` as the reservation seams in the fallback bucket
(the shared bucket keeps `LoginThrottle`'s two calls), (e) record only when `record_failure` is
true — never after the pipeline already reserved —, (f) render
`recovery_policy_failure_message()` in the one failure body, and (g) do the REC-06 session
rotation — **none** of which is in this slice.

### Review round (W5) — working-tree only, not committed

Two CodeRabbit findings, fixed as four commits on the same branch (the eight are untouched):
`test(system_updater): pin the atomic fallback reservation… (RED)`,
`fix(system_updater): reserve the fallback budget atomically before evaluation`,
`test(system_updater): pin the fallback counter inside the install (RED)`,
`fix(system_updater): keep the fallback counter inside the installation`, plus one
`refactor` that reconciles the contract prose with the reservation.

**Finding 1 — the check/record race (MAJOR).** `count()` read and `record()` incremented under two
`flock()`s, so N concurrent submissions all read a count below the threshold, all reached
`password_verify()`, and the ceiling became a function of concurrency. Both the per-pair and the
per-account dimension had the defect, in the mode where this limiter is the only defence.
`recovery_policy_fallback_reserve()` now reads, prunes, decides and increments under **one** lock;
a refusal writes nothing. Both writers share `recovery_policy_fallback_transact()`, so there is one
opener and one lock discipline. The resulting order, encoded in `recovery_policy_precheck()` +
`recovery_policy_evaluate()` and pinned case by case:

1. token verdict → `token_rejected`, no reserve, no evaluation;
2. submission (empty nick or empty password, or `input_valid => false`) → `input_rejected`, no reserve;
3. ban read (read-only) → `banned`, no reserve;
4. **atomic reserve** — refusal → `throttled`, verdict never reached;
5. credential verdict, exactly once;
6. release on success (`$release` → `recovery_policy_fallback_clear()`), leave reserved on failure.

REC-02 still holds: nothing is reserved before the token verdict, and a refusal never touches the
file (asserted by `md5_file`). The empty-submission rule holds twice over — by the `input_valid`
fact and by derivation from an empty nick/password, so a console that forgets the fact cannot
charge an empty form. `record_failure` is false whenever the pipeline already charged, so one
submission is never counted twice.

**How far the concurrency proof goes, honestly.** `theBudgetCannotBeExceededByConcurrentReservations`
starts ten separate PHP processes that each reserve once against one counter file; exactly six are
admitted, four are refused, and each admitted child reports a distinct count (1..6), which is what
proves the increments were serialised by the lock rather than merely close in time. It is an
invariant test, not a race detector: a run where the kernel happens to serialise the children
perfectly passes too — what it forbids is any interleaving that exceeds the budget. The
deterministic half is `anInterleavedDoubleCannotTakeTheLastSlotTwice`, which models the exact
sequence the review described (two requests when one slot is left). No thread-based or
timing-based detector is claimed.

**Finding 2 — the counter file in a shared temp dir (MINOR).** The path depended only on
`sys_get_temp_dir()`, `FS_TMP_NAME` and `sha1($root)`, and the file was opened `c+b` then
truncated, so a local user could pre-create it `0666` and delete the counts, inflate them, or plant
a symlink. The default directory is now `<root>/tmp/` (the installation's own, already holding
`ip.log`, needing nothing from the core); the per-install digest and the `FS_TMP_NAME` prefix stay.
`recovery_policy_fallback_open()` refuses a link before opening and compares `fstat()` with
`lstat()` afterwards, which is the `O_NOFOLLOW` property PHP exposes no flag for; a looser mode is
narrowed to `0600`, and the reader refuses a link as well. The previous assertion that the counter
lived under `sys_get_temp_dir()` was the defect stated as a contract and is replaced.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` —
**529 / 1899 / 2 skipped**, 0 failures, 0 errors (baseline 515 / 1769 / 2 skipped; both skips
pre-existing). `ddev exec php -l plugins/system_updater/lib/recovery_policy.php` clean.
`recovery.php` is still untouched.

**Still open, and not fixed here:** the shared mode keeps `LoginThrottle::isThrottled()` +
`::recordFailure()` as two calls, so its ceiling remains check-then-act. Closing that is a core
change (a `reserve()` on the throttle) and out of scope for this slice; the asymmetry is stated in
the module's docblock rather than hidden.

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

- [x] 1.1 **[TEST] [RED]** Each variant from REC-07 in the exact core order and with `hash_equals()`:
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
- [x] 1.2 Implement the bridge as pure functions over `(storedHash, plain, ?salt)`. No
  `class_exists()`, no `require`, no Composer symbol.

### Phase 2: The console's form token (REC-01 primitive, TDD)

- [x] 2.1 **[TEST] [RED]** Minting stores the value under a console-only namespace
  (`$_SESSION['recovery_console']['login_token']` — D2), the value is 64 hex, validation uses
  `hash_equals()`, a token minted in one session is refused in another, and after minting
  `$_SESSION['system_updater']['csrf_tokens']` is **absent** and `$_SESSION['_csrf']` is untouched.
  This is the assertion that keeps the login form from handing an unauthenticated visitor a value
  that `start`/`begin`/`chunk` would accept.
- [x] 2.2 Implement mint/read/validate with a 4-hour TTL consistent with the plugin token, no
  dependency on `lib/csrf_token.php` (importing it would open the plugin session inside the console
  and write the wrong store).

### PR 6 verification

- [x] `--filter RecoveryLegacyHashTest` (38 / 76), `--filter RecoveryLoginTokenTest` (10 / 51)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **577 / 2026 /
  2 skipped**, 0 failures, 0 errors (baseline **529 / 1899 / 2 skipped**)
- [x] `ddev exec php -l` on both new files, both new tests and `tests/RecoveryMarkerTest.php`
- [ ] PR body states the A1 ordering explicitly: REC-07 proven **before** PR 7 removes the autoloader
  — **not applicable in this phase**: no PR is opened from here. The ordering is recorded in the
  implementation commit body instead.

**Applied notes (W6, working tree only — nothing under `openspec/` is committed).**

- **REC-07 is satisfied and proven.** `lib/recovery_legacy_hash.php` reproduces the core set in the
  core order — `password_verify()`, `sha1($salt . $plain)` (only with a non-null salt),
  `sha1($plain)`, `sha1(mb_strtolower($plain, 'UTF8'))`, `md5($plain)` — with `hash_equals()` on
  each comparison. The **salted variant is legacy-schema-only** and unreachable from the current
  schema: `model/table/fs_users.xml` has no `salt` column, so the console passes `null` for every
  row it can read today; it is kept so a legacy schema keeps working and so a salted digest cannot
  fall through to the unsalted set.
- **The drift is enforced, not prose.** `theComparisonSetMatchesTheCoreAlgorithm()` runs the same
  matrix through `PasswordHasherService::verifyLegacyHash()` and skips with the class it looked for
  when the plugin is checked out standalone. Proved by mutation: removing the folded-sha1 comparison
  turns it red (`the local verifier disagrees with the core algorithm on: folded sha1`).
- **Ordering deviation, stated.** `password_verify()` runs *first* and the modern-format detector
  runs *after* it, as the gate that closes the legacy block. Both sentences of REC-07 hold, and the
  reverse shape (detector first) would deny a format PHP accepts but the detector has not learned
  yet — the one failure mode this requirement exists to prevent. The detector is
  `password_get_info()` plus the blowfish spellings it reports as `unknown` while `password_verify()`
  accepts them (`$2a$`, `$2b$`, `$2x$`; measured on PHP 8.3.33).
- **An empty password never verifies**, even when the row holds `sha1('')` — that digest is valid,
  so an emptied row would otherwise authenticate free cross-site submissions. `REC-02` closes the
  same door earlier in the pipeline; this is the last line.
- **REC-01's token primitive is satisfied and proven.** `lib/recovery_login_token.php` mints 64 hex
  into `$_SESSION['recovery_console']['login_token']`, validates with `hash_equals()`, is bound to
  the session that minted it, has a 4 h TTL aligned with the plugin token (held together by a drift
  test that reads the plugin definition as text instead of importing it), and writes nothing into
  `system_updater.csrf_tokens` (asserted from both directions: minting leaves it absent, and a value
  it holds is refused) or into `_csrf` (sentinel unchanged). Minting without an open session is
  refused.
- **One W4 assertion was extended, deliberately.**
  `RecoveryMarkerTest::testTheMarkerNamespaceIsConfinedToTheConsole()` listed the two files that know
  the console namespace; the login token joins the same namespace on purpose (one console-owned
  bucket, not two), so the list is now three. Observed RED before the correction, and the property
  that test protects is unchanged: no file reachable from the process scripts or
  `download_backup.php` may appear in the list.
- **`#[RunInSeparateProcess]` is method-level**, not class-level: PHPUnit 11 allows the attribute on
  methods only (measured). It is applied to the cross-session case, the one assertion an inherited
  session file could satisfy by accident; every test already re-initialises its own session
  (identifier, private `save_path`, empty `$_SESSION`).
- **Line budget overrun, measured.** ~1,161 changed lines (source ~376, tests ~785) against a ~270
  estimate and the 800-line session budget. The excess is documentation and data-provider matrices,
  in the same house style as W3 and W5; no behaviour was added to reach it.
- **Not done here, by design**: `recovery.php` is untouched, so nothing is wired; the form does not
  render the field and the handler does not validate it yet (W7). REC-07 is proven *before* the
  autoloader removal, so W7 cannot lock out a legacy-hash administrator.

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

- [x] 1.1 **[TEST] [RED]** In order, each as an explicit assertion:
  - invalid or missing login token → one generic CSRF refusal, **no** ban read, **no** throttle
    consult, **no** account lookup, **no** verification, **no** counter movement (a recorder proves
    the order, not just the absence of effects);
  - valid token + banned IP → uniform failure before any lookup or verification;
  - valid token + throttled nick → uniform failure before evaluation;
  - valid token + unknown nick / wrong password / non-administrator → one identical body, one
    verification each;
  - a format-invalid stored hash → uniform failure, no identity written.
- [x] 1.2 Wire `recovery.php`'s POST branch to the policy primitives in that order; render the
  console's login token as a hidden field (from `lib/recovery_login_token.php`, **not**
  `system_updater_csrf_field()` — that helper auto-generates `su_csrf_token`, which is exactly what
  REC-01 and the "console mints nothing before authentication" scenario forbid). Keep the shipped
  three failure strings only as internal reasons; one message is rendered.

### Phase 2: Rotation and identity (TDD)

- [x] 2.1 **[TEST] [RED]** A successful login rotates the identifier (`session_id()` differs from
  the pre-login id) and the write order is `regenerate → identity`, recorded by a step recorder; the
  written session contains `user_nick`, `user_id`, `login_time`, `last_activity`; feeding that
  session array to `system_updater_guard_decision()` with an injected `true` verdict → accept with
  the right nick and **no** CSRF token required (REC-06 scenarios 2-4). Note the `user_id` half
  explicitly: `system_updater_session_is_valid()` resolves from `user_nick`, so a session carrying
  only `user_id` would silently lose access.
- [x] 2.2 Implement rotation before the writes; clear the shared budget on success; keep the
  existing revalidation branch's administrator re-check (REC-08) and add the assertion that the
  `recovery_console` marker alone renders the login form and never the backup list.

### Phase 3: A1 dependency removal (TDD, last)

- [x] 3.1 **[TEST] [RED]** Static include graph: enumerate every `require`/`include` reachable from
  `recovery.php` plus its plugin-local files; exactly one resolved path lies outside
  `plugins/system_updater/`, and it is `FS_FOLDER/config.php`. No `vendor/autoload.php` reference
  remains. Then the behavioural half: build a temp root with `vendor/`, `base/` and `src/` **absent**
  and a sentinel `config.php` pointing at an unreachable DB host; open the console with the correct
  secret → no fatal, no `Class not found`, no autoload attempt, and the failure that appears is the
  plain DB-connection error (the expected boundary), not a class error.
- [x] 3.2 Remove the `vendor/autoload.php` load (`recovery.php:25-28`) and the
  `lib/session_auth.php` require (`:34-37`). `lib/backup_manager.php` and `lib/SqlDumpReader.php`
  stay (plugin-local, verified to contain no core `require`). Replace the removed session helpers
  with `lib/recovery_session.php` from PR 4 — if PR 4's implementation still calls
  `system_updater_configure_php_session()`, this is where that call goes.
- [x] **Do not** change `process_restore.php`: it stays shared with `admin_updater` and must not
  require the secret (RACC-07 point 8).

### PR 7 verification

- [x] `--filter RecoveryHandlerTest` (16/102), `--filter RecoveryRotationTest` (6/23), `--filter RecoveryIncludeGraphTest` (7/52)
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **608 / 2212 /
  2 skipped**, 0 failures (baseline **578 / 2028 / 2 skipped**)
- [x] `ddev exec php -l recovery.php` — also `lib/recovery_handler.php` and every new test file
- [x] Manual confirm the console still renders against the **real** ddev installation is **not**
  performed here (it would generate a real secret); the operator probe for RACC-01 is recorded in
  the PR body instead

### Applied notes (W7) — working-tree only, not committed

**Files**: `lib/recovery_handler.php` (new, 512 lines), `recovery.php` (rewired), five test files
(`tests/RecoveryHandlerTest.php`, `tests/RecoveryRotationTest.php`,
`tests/RecoveryIncludeGraphTest.php`, plus corrections in `tests/RecoveryMarkerTest.php` and
`tests/RecoverySessionBootstrapTest.php`) and three fixtures
(`tests/Support/ConsoleHttpHarness.php`, `tests/Support/RecoverySecretFixture.php`,
`tests/RecoveryGateTest.php`). Nothing under `openspec/` is committed.

**The ordering was obeyed, and the evidence is a subprocess.** `aLegacyHashAdministratorAuthenticatesWithNoAutoloaderAndNoCore`
loads the console's whole graph over a tree with no `vendor/`, `base/` or `src/`, asserts
`class_exists('FSFramework\Security\SessionManager')` is `false` (so no autoloader is reachable),
authenticates a `sha1($plain)` row through the real handler, and asserts `get_included_files()`
names nothing outside the plugin. It is proved non-vacuous by appending
`require_once FS_FOLDER . '/base/config2.php';` to `lib/recovery_session.php`, which makes the probe
fail on the missing file.

**Include graph, before and after** (this is the RACC-01 statement in graph form):

| | `FS_FOLDER/config.php` | variable include | plugin-local files |
|---|---|---|---|
| before | yes | `$autoloadFile` (`vendor/autoload.php`), `$sessionAuthFile` (`lib/session_auth.php`), `$file` (the defensive config read) | `recovery_secret`, `recovery_session`, `backup_manager`, `SqlDumpReader` |
| after | yes | `$file` only — the `realpath()`ed config read in `recovery_session_read_config_constants()` | the same four plus `recovery_handler`, `recovery_policy`, `recovery_legacy_hash`, `recovery_login_token` |

The single unresolvable include is asserted separately
(`theOnlyVariableIncludeIsTheDefensiveConfigRead`) rather than waved through, and the expected
internal set is an exact list rather than a count, so a file joining the graph has to be re-read for
what it loads.

**Deviations from the task text, all deliberate:**

1. **The row is read inside the verdict closure, not before `recovery_policy_evaluate()`.** The
   policy takes gate *facts*, and `row` is one of them — but passing it up front would perform the
   account lookup before the ban read and before the reservation, which is the order the tasks
   require. So `$facts['row']` is `null` and the verdict resolves the row on first call, after every
   gate passed. **Consequence, stated:** with the shared throttle the lookup happens after the
   throttle read but before the reservation, because in that mode the reservation is the core's
   check-then-act and there is nothing to charge atomically. In the fallback mode — the one a damaged
   core always produces — the order is exactly reserve → lookup → verify.
2. **`user_id` keeps holding the nick.** REC-06 asks for `user_nick` and `user_id`; it does not ask
   the numeric id. `recovery.php` has always written the nick there, and the revalidation branch
   binds it to `fs_users.nick`. The revalidation branch now reads `user_nick`, which is the key that
   makes a console session pass `system_updater_session_is_valid()` at all; a session carrying only
   `user_id` no longer authenticates, and that is the correct direction (it never could).
3. **A token-less POST is refused before the database boundary.** `recovery_handler_token_refusal()`
   is a second, earlier ask of the real validator, so the CSRF verdict is unconditional rather than
   "whenever a database happens to be reachable". The pipeline asks again on its own; two session
   reads and two `hash_equals()`, no I/O.
4. **`recovery_handler_write_identity()` landed with the wiring, not after it.** The identity write is
   the tail of the same call. `RecoveryRotationTest` was therefore green on arrival and its
   falsifiability was proved by mutation instead: dropping `user_nick` from the key list fails three
   cases (the guard answers `deny`), and moving the write above `session_regenerate_id(true)` fails
   `theIdentifierRotatesBeforeTheFirstIdentityWrite`.
5. **The handler refuses a revoked legacy-session shape, and the suite says so.**
   `aSessionCarryingOnlyUserIdNeverPassesTheGuard` is kept as a case because the defect it names
   (identity under `user_id` alone) is exactly what the previous implementation wrote.

**REC-08, verified rather than assumed.** The re-check keeps its original behaviour (re-read the row,
`die('Acceso denegado: Se requieren privilegios de administrador.')` for a demoted administrator) and
the only change is the key it reads. The "demoted administrator" half stays operator-owned: it needs
a live `fs_users`, and this suite is DB-free (recorded gap 2). The "the marker alone does not
authenticate" half is covered by `RecoveryMarkerTest::testTheMarkerAuthorizesNothingOutsideTheConsole`
and by the DB-free rendering path, which paints the form and never the listing.

**Security review of the changed surface** (fsframework-security-review checklist): SQL is two
prepared statements, no concatenation; the two database-sourced values this file echoes (the session
nick and the backup `base_name`) are now escaped, as is the failure text; the stored digest and the
salt are stripped from the row the handler returns; no `md5`/`sha1`/`password_hash` call exists
outside `lib/recovery_legacy_hash.php` (REC-07's verification-only use); `session_regenerate_id(true)`
precedes the identity write; no upload, no redirect, no stack trace, no `var_dump`.

**Line budget, measured.** 2,028 changed lines against the ~490 estimate (source ~670, tests ~1,358)
and the 800-line session budget. The excess is the four test files, in the same house style as W3,
W5 and W6; no behaviour was added to reach it. Natural split if the budget is enforced: **W7a** (the
handler + the wiring: `lib/recovery_handler.php`, `recovery.php`, `RecoveryHandlerTest`) and **W7b**
(the removal + its proof: `RecoveryIncludeGraphTest` and the two deleted requires), which share only
the file the first one introduces.

### PR 7 review follow-up — one budget per account, not one per spelling

Review finding (MAJOR) on `lib/recovery_handler.php`: the typed `nick` reached the throttle
machinery, so each spelling could get its own counter bucket.

**Premise, checked rather than assumed.** `fs_users.xml` declares no charset/collation, so MySQL
creates the table with `utf8mb4_unicode_ci` (`fs_mysql::table_charset_collation_sql()` and
`fs_schema::getMysqlTableOptions()`); the lookup is therefore **case- and accent-insensitive**. The
second half of the finding held only in part: `recovery_policy_counter_key()` already lower-cased
before hashing, so the ASCII case variants (`admin`/`Admin`/`ADMIN`) shared one bucket, while
accented spellings (`Ádmin`, `ádmin`, `áDmín`) each opened a new one. Measured on the real counter:
seven reservations admitted against a six-attempt ceiling.

- [x] **[TEST] [RED]** Four cases, asserted on the counter and on the seam arguments, not on a
  message: `everySpellingOfOneAccountSharesOneBudget` (six admitted, seventh refused, exactly two
  keys in the file, sixth charge on the shared key), `aSuccessClearsTheBudgetWhateverSpellingAsksForIt`
  (clear names the charged subject), `theCounterSeamsReceiveOneFoldedSubjectWhileTheLookupKeepsTheTypedOne`
  (fallback reserve/release folded, lookup typed, identity the row's bytes),
  `theSharedThrottleAndItsAccountingReceiveOneFoldedSubject` (shared consult + record + clear, one
  subject). RED evidence: 7/7 admitted and 8 keys; counts 0 vs 2; seam saw `ÁDMIN`.
- [x] One definition: `recovery_policy_counter_subject()` (lower-case, accent-transliterated,
  `[a-z0-9]`, idempotent, multi-byte lower-case fallback when nothing transliterates). Consumed by
  `recovery_policy_counter_key()` — so reserve, record, release, clear and the policy's own consult
  cannot drift — and once in the handler for the shared throttle and the two accounting seams.
  `recovery_policy_evaluate()` hands the folded subject to `$reserve`/`$release`.
- [x] **Direction: over-count.** Accented and punctuation-folded subjects may share a bucket, which
  can shorten a budget the collation keeps apart (a recoverable lockout). Under-counting is the
  vulnerability: a budget a spelling can multiply is not a ceiling. Stated in the function docblock.
- [x] **Identity untouched.** The lookup keeps the typed nick; the session carries the row's own
  bytes (`Ádmin`), pinned byte-for-byte by the new handler case. Budget constants unchanged; no
  limit weakened.
- [x] `recovery.php` not modified: it already passes the trimmed typed nick to the handler, which is
  the boundary that folds.
- [x] Verification: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` —
  **612 / 2232 / 2 skipped**, 0 failures (W7 baseline 608 / 2212 / 2 skipped); `php -l` on both
  changed library files.

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

- [x] 1.1 **[TEST] [RED] Step recorder.** The console's request pipeline accepts an optional recorder
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
- [x] 1.2 Implement: mint with `system_updater_csrf_generate()` **after** both gates, preserving and
  restoring the console session around the plugin-session switch; store the minted value for the
  rendered page's controls (the value is emitted only on an authenticated render, never into a 404,
  never into a log line). The marker is not the token and the token is not the marker: assert both
  directions (RACC-07 point 7).

### Phase 2: The restore drive (TDD)

- [x] 2.1 **[TEST] [RED]** Rendered output: every restore control's issued URL carries
  `su_csrf_token=`; the flow issues `action=begin` then at least one `action=chunk`; `action=start`
  appears nowhere in the console; `restoreEventSource`/`new EventSource` appear nowhere; no control
  is rendered whose behaviour is known to be refused. RED today on all four.
- [x] 2.2 Port the driver: `startRestore` → `runRestoreStep('begin')` → on each response parse the
  SSE framing and either finish (`done` + `errors_count`/`warnings_count`) or issue
  `runRestoreStep('chunk')`; `busy` waits 2 s; transport failure retries with backoff up to a bound
  and reports the checkpoint; the token is carried on **every** request. Keep the modal and its
  existing element ids so the visual contract does not change. The console must not send restore
  parameters without the secret: a request with restore parameters and no valid secret already
  receives PR 4's 404 and mints nothing (pin it here as a regression).
- [x] 2.3 Assert the "no dead control" and "listing still works" halves on rendered output with an
  injected backup list (a fake `list_backups_grouped()` result; no DB, no real files).
- [x] 2.4 Review fix (post-W8): the drive retries the action **in flight** — `begin` until a response
  carries progress, `chunk` after — instead of always re-issuing `chunk`, and a 4xx is terminal with
  a message that names the session instead of being charged to the retry budget as a network cut.
  Same fix in both drivers (`lib/recovery_view.php` and `view/admin_updater.html.twig`). Asserted by
  executing the emitted console script in node (`--filter RestoreDriveRetryTest`) and, for the
  template, on its failure branch (`--filter RestoreSteppedUiTest`).

### Phase 3: The contract flips to equality (TDD)

- [x] 3.1 **[TEST] [RED]** Add `mintingReachesExactlyTwoGatedEntryPoints()`: the enumerated set of
  HTTP entry points able to reach the three minting helpers is **exactly**
  `{admin_updater, recovery.php}`, no `process_*`, no third point. RED before U8.2 (the console does
  not mint yet), GREEN after. Keep `noUnauthorizedEntryPointCanMint()` from PR 2 unchanged: the
  subset assertion is still the guard against a future third point, and the two together are the
  two-gate contract. **Never** rewrite either into a single-element assertion.

### PR 8 verification

- [x] `--filter RecoveryMintOrderTest` — 13 tests, 57 assertions; `--filter RecoveryRestoreDriveTest` —
  6 tests, 38 assertions; `--filter CsrfMintingPointsTest` — 2 tests, 16 assertions
- [x] `--filter RestoreDriveRetryTest` — 7 tests, 32 assertions; `--filter RestoreSteppedUiTest` —
  14 tests, 48 assertions
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — **641 / 2382 /
  2 skipped**, 0 failures (baseline **612 / 2232 / 2 skipped**); each of the four W8 commits is green
  on its own: **622 / 2289**, **628 / 2329**, **629 / 2332**, **632 / 2339**; the review fix adds
  **639 / 2371** and **641 / 2382**
- [x] `ddev exec php -l recovery.php`, `-l lib/recovery_csrf.php`, `-l lib/recovery_view.php`,
  `-l lib/session_name.php`, `-l lib/csrf_token.php` — no syntax errors. The emitted restore driver
  was additionally parsed with `node --check` in both shapes (token and no token), since it is now
  generated rather than hand-written inline
- [x] Root-suite sanity: `ddev exec php vendor/bin/phpunit --testsuite Plugins` — **3429 tests, 14763
  assertions, 12 failures, 26 skipped**; all 12 failures are `plugins/OidcProvider` and pre-existing
- [x] PR body states that the console's restore path is now the stepped protocol and that a
  console-minted token is a CSRF credential for `start`/`begin`/`chunk` only

**Applied deviations, recorded.** (a) U8.1's recorder lives on the mint function
(`recovery_csrf_mint_token()`) rather than on the whole script: `recovery.php` is a script with no
injection point, and the three recorded steps are exactly the gate walk the mint performs, so the
order is still a runtime fact. The script-level halves are covered separately — the login render
leaves no store in any session file, and a restore request without the secret is the plain 404 — both
asserted over `php -S`. (b) RACC-07 point 5's "opening the plugin session" needed
`lib/csrf_token.php` in the console's graph, which required moving the plugin session-name
derivation to `lib/session_name.php` so `lib/session_auth.php` stays out of it (PR 7's property). The
console's graph gains three plugin-local files and still no core class; `RecoveryIncludeGraphTest`'s
exact list was extended accordingly. (c) `BackupManagerRestoreTest::testRecoveryTemplateSupportsDatabaseOnlyRestore`
now reads `lib/recovery_view.php`, where the markup moved; `RecoveryRestoreDriveTest` asserts the
same property on the generated output. (d) The review fix (two commits after W8's four) records that
the restore drive's retry policy was wrong in both copies of the driver: the retry always re-issued
`chunk`, even when the failed request was the `begin` that arms the state on disk, and every non-2xx
response was charged to the retry budget as a suspected network cut — including 401/403 from the
session and CSRF guards. The drive now tracks `restoreArmed` (progress seen ⇒ state on disk) and
re-issues the action in flight, a 4xx is terminal with a message that names the session, and only a
transport error or a 5xx is retried. `RestoreSteppedUiTest`'s retry pin moved from a hardcoded
`chunk` to `runRestoreStep(retryAction)` — the property it always meant — and its 4xx-ordering case is
anchored on the budget branch, because the rejection is now classified before the budget is charged.
The behaviour is executed, not read: `RestoreDriveRetryTest` runs the console's emitted script in node
against a stubbed browser (`tests/Support/node/restore-drive-harness.js`) and asserts on the request
log, with `node --check` as the parse gate for both render shapes. The suite gains `node` as an
optional dependency and skips those cases when it is absent.

---

## PR 9 — W9: A core update stops writing the planning tree (PU-16)

**Est. changed lines**: ~360 (source ~110, tests ~250)
**Depends on**: **nothing.** Independent of W1…W8 in both directions: it edits
`lib/core_updater.php`, which no other slice touches, and adds one new test file. It **may ship
before PR 1**, in parallel with any other slice, or last.
**Rollback boundary**: revert the allowlist constant, the prune helper and the two call sites. The
updater returns to copying the whole branch tree, i.e. today's behaviour, with no persisted state,
no schema and no operator action to undo.

**Why this is its own slice.** The requirement has nothing in common with the recovery console: no
`recovery.php`, no session, no token, no CSRF, no HTTP harness. Folding it into W4 or W7 would put
two unrelated surfaces in one review and would make the prune revertible only together with the
console's gate.

**The code path being changed** (verified in this working tree, 2026-10-26):

| Reference | Today |
|---|---|
| `lib/core_updater.php:102` | `$extractPath = $this->rootPath . '/tmp/core_update'` — the staging directory, inside the installation |
| `lib/core_updater.php:222` | `prepareCoreSource()` returns the git clone (`:241`) or the ZIP extraction directory (`:251`) |
| `lib/core_updater.php:354-360` | the ZIP source is the whole branch archive of `master`/`main`, so the planning tree is always inside it |
| `lib/core_updater.php:563`, `:589` | `extractZipSafe()` → `$zip->extractTo($destination)`; **this is where the prune belongs** |
| `lib/core_updater.php:428` | `findFirstDirectory()` returns the archive's own top-level directory (`fs-framework-master/`) — the effective source root, and therefore the base the prune must use |
| `lib/core_updater.php:146` | `copyDirectorySelective()` writes the tree into the installation root |
| `lib/core_updater.php:667` | `coreRootCopyExcludes()` does not exclude any of the eight |
| `lib/core_updater.php:158-160` | the staging directory is deleted only *after* the copy |

### Work Units

| Unit | Goal | Est. lines | Seam | Rollback boundary |
|------|------|-----------|------|-------------------|
| U9.1 | **[TEST] [RED]** `tests/CoreUpdaterPruneTest.php` — the temp staging tree: all eight removed, runtime entries untouched, second run a no-op | ~75 | Temp tree + `ReflectionMethod` (the `CoreUpdaterTest` pattern) | Revert the new file |
| U9.2 | The single allowlist declaration + `pruneNonRuntimeDirectories(string $baseDir): array` with containment, symlink and idempotence rules | ~60 | — | Remove the constant and the helper |
| U9.3 | **[TEST] [RED]** refusal outside the base, a symlink not traversed, a missing name not an error, an unremovable name reported and non-fatal | ~55 | Temp tree + observed `chmod` precondition | Revert test additions |
| U9.4 | Wire both call sites: after a successful `extractTo()` in `extractZipSafe()`, and on the clone directory before `copyDirectorySelective()`; surface the outcome in `update_core()`'s result and its progress message | ~50 | — | Revert the two calls and the result keys |
| U9.5 | **[TEST] [RED]** end-to-end through `extractZipSafe()` with synthetic `ZipArchive` fixtures in **both** shapes (`<repo>-<branch>/` top-level directory, and the core tree at the archive root); and the failure half — a refused archive prunes nothing | ~70 | `ZipArchive` built in the test (verified available: PHP 8.3.33) | Revert test additions |
| U9.6 | **[TEST]** the anti-drift test against `<core>/nginx-sample.conf`, with the always-runnable eight-name assertion and a **visible** skip when the core sample is absent | ~50 | `file_get_contents` + regex over `location ^~ /<name>/`; no write | Revert the new methods |

**Unit → command.** U9.1/U9.3/U9.5/U9.6 `--filter CoreUpdaterPruneTest`; U9.2/U9.4 also
`ddev exec php -l plugins/system_updater/lib/core_updater.php`.

### Phase 1: The allowlist and the helper (TDD)

- [x] 1.1 **[TEST] [RED]** Build a temp staging tree containing the eight names plus `vendor/`,
  `themes/`, `view/`, `plugins/` (with `plugins/system_updater/openspec/specs/pu.md`), `src/`,
  `base/`, `model/`, `controller/`, `images/`, `imgs/`, `tmp/`, `backups/`, `config.php`,
  `.htaccess`, and one file inside each. Assert after the prune: none of the eight exists; every
  other entry exists and its contents are byte-identical; `plugins/system_updater/openspec/specs/pu.md`
  is still there. RED today: nothing prunes, all eight survive.
- [x] 1.2 Declare the allowlist **once** (a class constant or a private static method returning the
  eight literal names, the house shape of `coreRootCopyExcludes()`), and implement
  `pruneNonRuntimeDirectories(string $baseDir): array` returning `['removed' => [...],
  'failed' => [name => reason]]`. Rules, all from `PU-16`: closed list; single path segment only
  (reject anything containing `/`, `\` or `..`); resolve and reject a candidate outside `$baseDir`;
  never traverse a symlink; a missing candidate is a silent no-op; a failed removal is recorded and
  does not throw and does not change the caller's return value. Note in the docblock that
  `coreRootCopyExcludes()` exclusion is **not** the mechanism and MUST NOT become the only one.
- [x] 1.3 **[TEST] [RED]** The negative cases: a symlink inside the base pointing outside →
  refused and reported, the target's contents unchanged; a candidate whose resolved path escapes the
  base → refused; a run on a tree already pruned → `removed` and `failed` both empty; an unwritable
  directory (precondition **observed**, `markTestSkipped` with a recorded reason on a privileged
  host — the `RecoverySecretTest` precedent) → named in `failed`, the other names still attempted,
  no exception. Assert `$this->rootPath` is never the base: a pre-existing `openspec/` in the
  installation root is untouched by the whole update (source assertion that no call site passes
  `$this->rootPath`/`FS_FOLDER`).

### Phase 2: Both source branches (TDD)

- [x] 2.1 **[TEST] [RED]** Synthetic ZIPs holding the eight names plus runtime entries, extracted
  through the production `extractZipSafe()` (via `ReflectionMethod`), in **both** shapes:
  (a) the branch-archive shape, where everything sits one level down under
  `<repo>-<branch>/` — the shape every real update has, because GitHub archives are named after the
  branch (`core_updater.php:357-360`), and the shape that makes a naive prune of the outer
  destination find nothing and pass vacuously; (b) the uploaded-ZIP shape, where the core tree is the
  archive root. Assert in both: afterwards none of the eight exists in the directory
  `findFirstDirectory()` returns (the one the copy reads) and runtime entries do. Then the failure
  half: an archive carrying a `../` entry is refused and **no** directory was removed from the
  destination (the prune did not run on a failed extraction). RED today on the positive halves.
- [x] 2.2 Wire it: in `extractZipSafe()` immediately after `$result = $zip->extractTo($destination)`
  returns `true` and before the method returns (never before, never when `$result` is falsy), and in
  `prepareCoreSource()` on the clone directory before it is returned, so the branch the updater
  prefers when `git` is available is covered by the same list and the same helper. **Resolve the
  effective source root** (the `findFirstDirectory()` result) and prune there; pruning only the
  outer `$destination` would leave the tree one level down with an all-green test. Record the
  helper's report into the instance state `update_core()` already returns, so a surviving directory
  is visible in the result (`pruned_directories`, `prune_failures`) and in one progress message.
  The prune outcome MUST NOT change `success`, and MUST NOT add an entry to `$this->errors`.

### Phase 3: The anti-drift test (TDD, last)

- [x] 3.1 **[TEST]** `CoreUpdaterPruneTest::theAllowlistMatchesTheCoreSampleDenyBlock()`:
  - **always runs**: the plugin's declared allowlist equals the eight literal names stated by
    `PU-16` (so a silent removal or addition is RED without the core repository present);
  - **drift half**: read `dirname(__DIR__, 3) . '/nginx-sample.conf'` (the file sits at
    `<core>/nginx-sample.conf`; `__DIR__` is one level deeper than the plugin root), extract the
    top-level directory names denied in the "Planning, documentation and analysis trees" block, and
    assert set equality with the allowlist. **Do not** absorb `/vendor/`, `/tmp/`, `/backups/`,
    `/.git/` or the file-level denies: those are runtime and operator paths the allowlist must never
    contain, and treating them as members would widen it silently;
  - **absent file**: `markTestSkipped` with a message that names the resolved path and states that
    the two-list comparison did not run. Never a bare `return`; never a green suite with an
    unremarked skip. The always-runs assertion above MUST still execute first.
  - The test reads the file and writes nothing.

### PR 9 verification

- [x] `--filter CoreUpdaterPruneTest` — record tests/assertions and whether any case skipped
  → **14 tests, 224 assertions, 0 skipped** (the drift half ran against this checkout's
  `nginx-sample.conf`); the baseline-measuring run before U9.1 was 426 tests / 1314 assertions /
  2 skipped
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — record counts
  against the baseline measured immediately before U9.1 → **440 tests, 1538 assertions, 2 skipped,
  0 failures** (the 2 skips are the pre-existing ones; +14 tests, +224 assertions)
- [x] Counts re-measured at the review-fix commit. The first recording of this block was wrong: it
  said **12 tests / 203 assertions** for the filtered run and **438 / 1517 / 2** for the suite, while
  the same two commands reported **13 / 209** and **439 / 1523 / 2**. The values above are the
  current measured ones and include the separator-containment test added for the review
  (`14 = 13 + 1` tests, `224 = 209 + 15` assertions). All eight slice commits touch only
  `lib/core_updater.php` and `tests/CoreUpdaterPruneTest.php`, so the whole suite delta against the
  426 / 1314 baseline belongs to this slice and the arithmetic closes on both sides.
- [x] `ddev exec php -l plugins/system_updater/lib/core_updater.php plugins/system_updater/tests/CoreUpdaterPruneTest.php`
- [x] `git status --porcelain` shows only `lib/core_updater.php` and the new test file (plus
  `openspec/`, which is working-tree only); **no** file under the core repository is modified
- [ ] PR body states that `nginx-sample.conf` was read and not edited, and names the skip condition
  (no PR was opened in the apply phase; the statement is recorded in the apply report)

**Recorded consequences of this slice** (they belong in the PR body, not only here): `docs/` and
`extras/` leave an installation on the first update after this ships (because
`cleanupRootDirectories()` already deletes them and the pruned source can no longer restore them);
the other six persist on installations that already received them, because the prune is confined to
the staging directory; and a developer who runs the updater inside a git checkout loses the
directories from the working tree (`git checkout -- .` restores them). None of the eight is a
runtime dependency, which is the whole basis of the decision.

---

## Delta conflict resolved at apply time (RACC-03 vs RACC-01/RACC-04)

> **SUPERSEDED on 2026-10-26 by the product owner, in the post-verify fix branch
> `fix/harden-auth-guard-criticals`.** The resolution below is what the apply phase shipped and
> what the verify phase measured as **C-2**. The product owner has decided the opposite: **the
> operator pin wins, always**, and `config.php` is consulted on every gate evaluation as the
> accepted cost. See "Post-verify fixes (C-1, C-2, W-1)" at the end of this file for the new
> binding order, the preservation properties and the evidence. Step 3 below (the pin as a
> *lost-file* escape only) is no longer the behaviour; steps 1 and 2 are no longer the gate's
> comparison order.

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

---

## Post-verify fixes (C-1, C-2, W-1)

Branch `fix/harden-auth-guard-criticals`, three commits off `master` (`51df7f8`). Each fix was
written RED first against the defect the verify phase measured; each commit is green on its own
(`phpunit -c plugins/system_updater/phpunit.xml`).

### C-1 — `RACC-02` per-installation uniqueness (commit `549d5b9`)

- [x] **RED** `tests/RecoverySecretSharedParentTest.php` — two install roots under one parent
  (`<parent>/vhostA`, `<parent>/vhostB`). Measured before the fix: both resolved
  `<parent>/system_updater_recovery_secret.php`, both read the same 64-hex value, and install B's
  gate returned `granted: true` for install A's secret. Three cases, three failures.
- [x] `recovery_secret_install_directory()`: the outside-webroot candidate now lives in
  `<parent>/system_updater_recovery_<first 16 hex of sha256(realpath(root))>/`.
  `recovery_secret_candidates()` returns that path first, `<root>/tmp/…` second (already keyed on
  the root, so it needed no change).
- [x] Still outside the webroot (survives a damaged core), still `0600` inside a `0700` directory
  (asserted), still the same operator-facing file name.
- [x] The value is still `random_bytes(32)`: the digest names a *location* and is never a seed
  (asserted against md5/sha1/sha256 of the install path).
- [x] GREEN regression: B's gate rejects A's secret, accepts its own, and the mirror holds.
- [x] Both test harnesses (`RecoverySecretFixture`, `ConsoleHttpHarness`) now take the candidate
  path from `recovery_secret_candidates()` instead of re-deriving it. The duplicated formula is
  what kept the defect invisible: each fixture allocated its own parent, so the copy agreed with
  itself.

### C-2 — `RACC-03` / `RACC-06` escape 3: the operator pin wins (commit `7bd9126`)

- [x] **Decision (product owner, 2026-10-26)**: the resolution order is `SU_RECOVERY_SECRET` from
  `config.php` first, the persisted per-installation value second. `config.php` is loaded on every
  gate evaluation — the accepted cost. The deltas are **not** amended here; that is a separate
  phase.
- [x] **RED** at the library level: `testTheOperatorPinWinsOverADifferentPersistedValue` failed on
  "the operator pin is what the gate answers against"; at the HTTP level: "the pin must open the
  console with the secret file present: `Not Found` / Failed asserting that 404 is identical to
  200" — the verify phase's measurement, reproduced.
- [x] `recovery_secret_gate()` consults the pin first (`recovery_secret_operator_pin()`), falls back
  to `recovery_secret_gate_value()` (same order), and only then to first-use generation.
- [x] `recovery_secret_operator_pin()` is now idempotent (skips a `config.php` this request already
  ran, and one whose pin is already visible) and uses `@include`, so it is safe to call on every
  evaluation and cannot redeclare anything the console or the session-name reader loads.
- [x] Preservation property 1 — a damaged/unparseable `config.php` is silent and falls back to the
  persisted value: `testADamagedConfigFallsBackToThePersistedValueSilently` (no parse error in body
  or stderr) and `testAConfigThatWarnsLeavesNothingInTheResponseOrTheLog` (with
  `display_errors=0`, a warning from the file does not reach the error log either; the test goes
  red if the `@` is removed).
- [x] Preservation property 2 — the refusal shape is unchanged: absent/empty/wrong stay one
  byte-identical 404 with the same header set, both with and without a pin configured
  (`testTheRefusalShapeIsUnchangedWhenAPinIsConfigured`).
- [x] Preservation property 3 — no secret value in a body, a header or a log (asserted on the pin,
  the persisted value and the generation line).
- [x] Tests that pinned the old order were rewritten, not deleted: the persisted-first comparison
  case, the "pin grants only when nothing is persisted" case, and the marker case that asserted a
  rotation displaces the pin (now: the pin still wins, so the marker survives).

### W-1 — `RACC-01` missing `config.php` is a 500 that names the directory (commit `94eaca9`)

- [x] **RED** `testAMissingConfigIsAFiveHundredThatNamesTheDirectory`: measured `200` with
  `Error: No se encuentra el archivo config.php.` — no directory, no 500.
- [x] The branch now sets `Content-Type: text/plain; charset=utf-8`, answers `500` and names
  `FS_FOLDER`.
- [x] It cannot be confused with the gate's 404: the gate is decided first, and the same missing
  file without the secret still answers 404 (asserted in the same case). No stack trace, no
  credential, no secret.
- [x] The config path stays a literal in `require_once`, so `RACC-01`'s statically enumerated
  include graph still resolves that one external entry.

### Verification of this branch

- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` →
  **650 tests / 2515 assertions / 2 skipped, 0 failures** (baseline was 641 / 2382 / 2).
- [x] Each commit green on its own: `549d5b9` → 645/2411/2; `94eaca9` → 646/2431/2; `7bd9126` →
  650/2515/2.
- [x] No `openspec/` file is committed on this branch: `tasks.md` edits and `verify-report.md` stay
  in the working tree for the delta-amendment phase.
- [ ] **Not done here (separate phases)**: amend `RACC-01`'s "a wrong secret never reaches
  `config.php`" scenario, `RACC-03`'s "the gate answers before `config.php` is loaded" scenario and
  `RACC-06`'s escape-3 wording to the decided order; re-run the verify phase; then archive.

### Operator note on the C-1 relocation (no migration step)

The outside-webroot candidate moved from `<parent>/system_updater_recovery_secret.php` to
`<parent>/system_updater_recovery_<digest>/system_updater_recovery_secret.php`. There is deliberately
**no legacy-path fallback**: adopting an old file would hand one installation a copy of its
neighbour's secret, which is the defect being removed. The cost is a one-time retirement of any
value written before this branch. It has no installed base — the per-installation secret is
introduced by this same unreleased change — and, if a branch build did write one, the documented
RACC-06 sinks recover the new value (`php recovery.php --secret`, `--rotate-secret`, or the single
generation line in the PHP error log).
