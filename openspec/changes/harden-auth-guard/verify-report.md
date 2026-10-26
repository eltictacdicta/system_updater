# Verify Report: `harden-auth-guard`

- **Change**: `harden-auth-guard` (PLUGIN change — `plugins/system_updater/`)
- **Artifact store**: plugin-local OpenSpec (`plugins/system_updater/openspec/`); the core
  `openspec/` has **no** entry for this change (verified: `ls openspec/changes | grep -i harden`
  → the only match is the archived core change `2026-10-25-harden-auth-boundaries`)
- **Phase**: verify (independent)
- **Verifier**: SDD executor. Verification was performed by reading the shipped code and running
  the commands directly. `tasks.md` checkmarks and the per-PR "Applied notes" were treated as
  **claims, not evidence**. No implementation file, test, delta, canonical spec, `tasks.md`,
  `proposal.md` or `design.md` was modified. The only file created is this report.
- **Revision verified**: plugin `master` at **`51df7f863f085a1afc6e8623bfe6ff16c28edcd6`**
  (`docs(openspec): check off the PR 8 work units in harden-auth-guard`), working tree clean, with
  all nine slices merged: PR 1 (`0915926`), PR 2 (`7430660`), PR 3 (`2a9aeff`…), PR 9 (`1088927`),
  PR 5 (`15b687d`), PR 6 (`ae34f9f`), PR 7 (`4ffe24f`), PR 8 (`b465d6f`).
- **Core repository**: untouched. Core HEAD is `7228ab0` and `git status` is clean.
- **Date**: 2026-10-26

---

## Verdict

**NOT ARCHIVE-READY. The implementation is complete and green, but one requirement is violated by a
live defect and two requirements state outcomes the shipped code does not produce. Those must be
fixed or the deltas amended before `plugin-recovery-access` is merged into `openspec/specs/`.**

The security work itself is in good shape: every control the change set out to add is present,
reachable, and pinned by a test that can go red; the plugin suite is exactly at its documented
baseline; and not one of the 12 root-suite failures belongs to this plugin. The blockers are:

1. **C-1** — the "outside the webroot" secret location is keyed only by the *parent directory*, so
   two installations that share a parent (staging next to production is the ordinary layout) resolve
   the **same file**, share **one secret**, and each one's gate accepts the other's value. Measured
   end-to-end. `RACC-02`'s "differs between installations" MUST is false for that layout, and the
   uniqueness test cannot see it because its two fixtures have different parents.
2. **C-2** — the operator pin (`SU_RECOVERY_SECRET`) **loses** to the persisted value. With a
   persisted secret on disk, supplying the pin answers `Not Found`; with the file deleted, the same
   pin renders the console. `RACC-03`'s "The operator pin wins" scenario and `RACC-06`'s escape 3
   ("overrides the persisted value") are therefore false in the normal operating state.

Neither blocker weakens authentication — both fail closed. They are reachability/availability
defects plus false normative statements. Merging them verbatim would write two false `MUST`s into a
brand-new canonical capability, which is the same defect class the core change's verify report
classified as CRITICAL.

**Issue counts: CRITICAL 2 · WARNING 5 · SUGGESTION 6.**

| Finding | Class | Blocks archive? |
|---|---|---|
| **C-1** — `RACC-02` per-installation uniqueness is false for installations sharing a parent directory (measured: one file, one value, cross-acceptance) | CRITICAL | **Yes** |
| **C-2** — `RACC-03`'s pin-wins order and `RACC-06`'s escape 3 are false while a persisted secret exists (measured) | CRITICAL | **Yes** |
| **W-1** — `RACC-01`'s "missing `config.php`" scenario promises HTTP 500 and a named *directory*; the code answers **200** and names only the file | WARNING | No, but amend before merge |
| **W-2** — `RACC-02`'s "unwritable location is a loud error" is not observable from the console (web path is the plain 404 by design); only the library/CLI is loud | WARNING | No, but amend before merge |
| **W-3** — the pre-existing `fs_session_manager::isLoggedIn()` fallback is the one identity path that never checks `admin`; unreachable in the standalone bootstrap (measured), untested, absent from AUTH-01's failure inventory | WARNING | No |
| **W-4** — `PU-16`'s prune and the copy use two different source-root resolvers; they disagree on the flat uploaded-ZIP shape (rejected earlier by the `VERSION` gate today) | WARNING | No |
| **W-5** — change size: 18,106 insertions / 46 files against a ~3,950 forecast; four slices overshot the 800-line session budget by 1.4×–2.6× | WARNING | No (record) |
| **S-1…S-6** | SUGGESTION | No |

---

## Commands run (via `ddev exec` only)

| # | Command | Exact measured result | Exit |
|---|---|---|---|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` | `OK, but some tests were skipped!` — **`Tests: 641, Assertions: 2382, Skipped: 2`**, 0 failures, 0 errors (7.3 s) | `0` |
| 2 | `… --display-skipped` (same config) | Same counts; the two skips are `SelfUpdateSessionBindingTest::bindingIsEmptyWithoutASessionId` ("There is an active PHP session in this process") and `SessionAuthTest::testResolveCookiePathUsesRootForDocumentRootInstall` ("FS_PATH ya estaba definido") — both pre-existing and unrelated | `0` |
| 3 | 25 change-scoped test files (see below) | `OK, but some tests were skipped!` — **`Tests: 378, Assertions: 1789, Skipped: 1`** | `0` |
| 4 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | **`Tests: 3441, Assertions: 14813, Failures: 12, Skipped: 26`** | `1` |
| 5 | `ddev exec php vendor/bin/phpunit -c plugins/OidcProvider/phpunit.xml` | **`Tests: 668, Assertions: 2802, Failures: 12, Skipped: 2`** — the same twelve, all `OidcProvider` | `1` |
| 6 | `php -l` sweep over every production and test PHP file under `plugins/system_updater` (root scripts, `lib/`, `tests/`, `tests/Support/`) | `lint_failures=0` | `0` |

Command 3 was:

```
ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml \
  plugins/system_updater/tests/{GuardDecisionTest,SessionAuthTest,AdminBackendSupportTest,CsrfMintingPointsTest,DownloadBackupScriptTest,DownloadBackupHandlerTest,RestoreSteppedUiTest,RestoreDriveRetryTest,RecoverySecretTest,RecoveryGateTest,RecoveryMarkerTest,RecoverySessionBootstrapTest,RecoveryBanListTest,RecoveryFallbackLimiterTest,RecoveryCredentialVerdictTest,RecoveryLegacyHashTest,RecoveryLoginTokenTest,RecoveryHandlerTest,RecoveryRotationTest,RecoveryIncludeGraphTest,RecoveryMintOrderTest,RecoveryRestoreDriveTest,ConsoleHarnessPortTest,SessionReadCookieTest,CoreUpdaterPruneTest}.php
```

The twelve root-suite failures, enumerated (none in this plugin — `grep -c system_updater` on the
run output is `0`):

```
1–8   OidcProvider\Controller\admin_oidc_customer_access_htmxTest::*
9     OidcProvider\Controller\admin_oidc_customer_access_validation_groupsTest::testValidSelectionCallsSyncGruposThenApprove
10–11 OidcProvider\Service\ClienteRoleResolverIntegrationTest::testMultiGrupoUnionResolvesAcrossDbRows / testOrphanGrupoMembershipIsSilentlySkipped
12    OidcProvider\Service\migration011_cliente_gruposTest::testCreatedTableCollationMatchesReferencedTables
```

### Baseline reconciliation

| Signal | Documented baseline | Measured today | Verdict |
|---|---|---|---|
| Plugin suite | 641 / 2382 / 2 skipped | **641 / 2382 / 2 skipped** | matches exactly |
| Root `Plugins` suite failures | 12, all `plugins/OidcProvider` | **12, all `plugins/OidcProvider`** | matches |
| Skips | the same two | the same two | matches |
| PHPStan | not applicable (plugin not in `tools/phpstan-plugins.php`; adding it would be a core change) | not run, by design | N/A |

### Independent behavioural probes (container-side, no repository file created)

Five probes were run against a copy of the console graph placed in the container's `/tmp`
(`vendor/`, `base/` and `src/` absent, sentinel `config.php`, database on `127.0.0.1:1`). Results:

| Probe | Result |
|---|---|
| **RACC-01 include graph at runtime** — boot the console with the core stripped | `get_included_files()` outside `plugins/system_updater/` = **`config.php` only**; no `vendor/autoload.php`, no `base/`, no `src/` |
| **RACC-04 three failures** — absent / empty / wrong candidate | Three bodies **byte-identical** (`md5 9d1ead73…`), body `Not Found`, `config.php` **not loaded** (sentinel absent) on all three, no plugin file outside the console's own graph other than my harness |
| **RACC-04 success path** | `config.php` loaded, login form rendered (`recovery_login_token` present), **`su_csrf_token` absent** from the login render, secret never echoed |
| **RACC-01 missing `config.php`** — correct secret, `config.php` deleted | `STATUS=HTTP/1.1 200 OK`, body `Error: No se encuentra el archivo config.php.` → see **W-1** |
| **RACC-03/RACC-06 operator pin** — persisted secret present vs deleted | Pin with file present → `Not Found`; the *same* pin with the file deleted → login form → see **C-2** |
| **RACC-02 uniqueness across a shared parent** — `/tmp/verify_shared/rootA`, `/tmp/verify_shared/rootB` | Both resolve path `/tmp/verify_shared/system_updater_recovery_secret.php`, both read the **same** 64-hex value, and root B's gate returns `granted: YES` for root A's secret → see **C-1** |

The secret file created by the first probe is `-rw-------` (0600) — `RACC-02`'s permission requirement
independently confirmed.

---

## Requirement-by-requirement compliance

Legend: **SATISFIED** = the code does what the requirement says and a test that can go red pins it.
**PARTIAL** = the control exists but the requirement's own wording is not literally true.
**NOT SATISFIED** = the code contradicts the requirement.

### Delta: `plugin-auth-guard` (NEW capability — canonical dir absent)

| ID | Status | Evidence |
|---|---|---|
| **AUTH-01** Identity only from a login-written session; the helper is deleted; no framework CSRF | **SATISFIED** | `lib/session_auth.php:658-681` — `system_updater_guard_decision()` accepts an injected `$request` and **never reads it**; identity comes from `system_updater_resolve_session_identity()` (`:614-628`) over candidate sessions only. `system_updater_request_has_valid_csrf()` is **gone**: a tree scan finds the identifier only inside the negative-contract test (`tests/SessionAuthTest.php:181`), which excludes itself. `grep -n "CsrfManager\|_csrf\|X-CSRF-Token\|_token" lib/session_auth.php` → **no matches** (`SessionAuthTest::testGuardFileNamesNoFrameworkCsrfSymbol`). The old branch and its helper are deleted in `0915926`, after the reader fix in `ac6ef65` — the load-bearing order. |
| **AUTH-02** Every candidate session evaluated; a non-empty session never shadows identity; pure resolver | **SATISFIED** | `system_updater_collect_session_candidates()` (`:436-465`) loops over **all** names and never returns at the first non-empty; `system_updater_read_session_candidates()` (`:539-567`) reads the plugin session **and** the framework session (plus the ini name and `PHPSESSID`). A candidate with no cookie or no content is skipped, never treated as failed evidence. The decision is pure (`GuardDecisionTest::testDecisionNeverStartsASession`). The defect this closes is real and was the reason the old CSRF branch "worked": `SU_SESS_*` is non-empty by construction. |
| **AUTH-03** Administrator flag re-read per request, fail closed, no cache | **PARTIAL — W-3** | `system_updater_resolve_admin_verdict()` (`:954-1033`) is one `SELECT admin FROM fs_users WHERE nick = ? LIMIT 1` with no cache; `false` for a missing row/falsy flag, `null` for an unreachable backend, unsupported backend, missing `mysqli`, or missing `FS_DB_*` constants; every non-`true` value denies. Demotion is pinned by `GuardDecisionTest::testDemotionTakesEffectOnTheNextRequest`. **However** `system_updater_is_logged_in()` (`:1035-1052`) still falls back to `fs_session_manager::isLoggedIn()` when the decision denies — a pre-existing path that reads `user_nick`/legacy cookies and **never checks `admin`**. Established by reading the boot path: the legacy autoloader is not loaded in the standalone bootstrap (composer autoload is PSR-4 only; `base/fs_autoload.php` is required only by `PluginSchemaSynchronizer`), so `class_exists('fs_session_manager', false)` is `false` there and the path is dead today. It is still the one identity path outside the administrator verdict, and no test covers it. |
| **AUTH-04** One uniform refusal, guard always first, 401 never 403 | **SATISFIED** | One constant (`SYSTEM_UPDATER_GUARD_DENIAL`, `:601-603`) consumed by both emitters — `system_updater_denial_payload()` (JSON 401) and `system_updater_denial_sse_payload()` (SSE `event: error`) — so the transport cannot become a reason difference. `download_backup.php:42` runs the guard inside `system_updater_process_init(['mode'=>'plain'])` (`lib/process_bootstrap.php:144`) **before** `system_updater_csrf_read_from_request()` at `:57`; `DownloadBackupScriptTest::guardRunsBeforeThePluginTokenCheck` and `::aDeniedRequestNeverReachesTheTokenCheck` assert the order behaviourally (a sentinel after the guard never runs), not by status code. `RecoveryMintOrderTest::aConsoleMintedTokenGrantsNothingOnTheAdminSurface` measures the real 401 for an anonymous request carrying a valid plugin token. |
| **AUTH-05** Token mechanism unchanged; two gated minting points; validation never asks which | **SATISFIED** | The whole `lib/csrf_token.php` diff is `require_once session_auth.php` → `require_once session_name.php` (the name derivation moved, unchanged). Storage `$_SESSION['system_updater']['csrf_tokens']`, 64-hex format, 4 h TTL and `hash_equals()` are untouched. `lib/process_bootstrap.php:135-145` keeps `start`/`begin`/`chunk` on `ensure_request_csrf()` and **not** on the guard. Minting: exactly two entry points reach the helpers — `controller/admin_updater.php:148` → `lib/twig_compat.php:121` (page is `#[\FSFramework\Attribute\AdminOnly]`) and `recovery.php:35` → `lib/recovery_csrf.php:124` behind both gates; `lib/csrf_guard.php` only validates. `CsrfMintingPointsTest` asserts the **subset** and the **equality** halves separately and says in the docblock that neither implies the other. |
| **AUTH-06** A genuine administrator keeps working | **SATISFIED (decision level; the file stream is statically asserted, not streamed)** | `GuardDecisionTest::testGenuineAdministratorSessionIsAccepted` and `::testRepeatedPollsResolveTheSameNick`; expiry still denies (`::testExpiredSessionIsDeniedAsBefore`). The download's attachment headers are asserted statically (`DownloadBackupScriptTest::downloadBackupScriptEmitsAttachmentHeadersAndReadfile`); no test streams a real backup — pre-existing coverage, not a regression. |

### Delta: `plugin-recovery-access` (NEW capability — canonical dir absent)

| ID | Status | Evidence |
|---|---|---|
| **RACC-01** `config.php` is the only core dependency | **PARTIAL — W-1** | Dependency contract holds: `RecoveryIncludeGraphTest` asserts the static graph (`EXPECTED_INTERNAL` is an exact list, the one unresolvable include is the defensive config read, and the external set is `['FS_FOLDER/config.php']`), and my runtime probe with `vendor/`, `base/`, `src/` deleted found `config.php` as the only outside include. The damaged-core boot is asserted; **"the console renders the backup list" and "the restore starts" halves need a live database and are operator-owed** (recorded gap 2). **The "missing `config.php`" scenario is false as written**: it promises HTTP **500** and "a plain-text body that names the missing file **and the directory it was expected in**"; measured over `php -S` the answer is **`200 OK`** with `Error: No se encuentra el archivo config.php.` (no directory, no 500). The rest of the scenario holds (no other core file, no DB attempt, no credential, no stack trace). |
| **RACC-02** Per-installation secret: CSPRNG, persisted, unique, 0600, outside the webroot | **PARTIAL — one MUST violated (C-1)** | `random_bytes(32)`/64-hex, `0600` (measured `-rw-------`), survives restarts, written outside the webroot when possible, and the inside-webroot payload begins with `http_response_code(404); exit;` so a direct request discloses nothing. **But** the outside-webroot candidate is `dirname($root) . '/system_updater_recovery_secret.php'` (`lib/recovery_secret.php:294-297`) — keyed by the *parent*, not by the installation. Two roots under one parent resolve the **same** path and the **same** value, and root B's gate grants root A's secret (measured). `testTwoInstallationsNeverShareASecret…` cannot see this because each fixture has its own `<base>`. **W-2**: the "unwritable location is a loud error" scenario is only observable through the library/CLI (`recovery_secret_last_error()`, stderr, exit 1); the web path deliberately answers the plain 404. |
| **RACC-03** Secret resolvable without core code; documented order pin → persisted | **PARTIAL — C-2** | Core-free resolution holds: no `class_exists()`, no autoloader, no include; `recovery_secret_resolve()` implements the documented order and never loads `config.php`; the gate answers before `config.php` (probe: sentinel absent on all three failures). **The order the gate actually enforces is the reverse**, and that is what decides access: `recovery_secret_gate_value()` reads the persisted value first and only consults the pin when nothing is persisted, so **"The operator pin wins … the persisted value is neither read nor rewritten" is false** (the persisted value is read, wins, and is not consulted only when absent). Measured both directions. |
| **RACC-04** Without the secret the console does not exist | **SATISFIED (literal byte-identity vs a missing path is the recorded D3 gap)** | One code path for absent/empty/wrong (`recovery.php:75-96`): `hash_equals()` on the full value, no early return for "no parameter", `header_remove('X-Powered-By')`, `http_response_code(404)`, `echo 'Not Found'`. `RecoveryGateTest::testARequestWithoutTheSecretIsIndistinguishableFromAMissingPath` asserts 404, byte-identical bodies across the three inputs, no `Set-Cookie`, no `Referrer-Policy`, no `Location`, no console marker, no session file, **`config.php` not loaded**, and that the refusal's controllable header set is a subset of a missing path's. My probe reproduced the identical bodies and the absent sentinel. The literal "byte-identical to a request for a non-existent path" is unachievable because the server echoes the path (D3) — honestly recorded, and the substituted subset is the right one. |
| **RACC-05** Authorization carried by the session after the gate | **SATISFIED** | Marker written only when `$su_secret_gate['granted']` (`recovery.php:137-140`) into `$_SESSION['recovery_console']`, never `_sf2_attributes`/`_csrf`; HMAC-bound to the granting value (`recovery_session.php:450-512`) so `--rotate-secret` retires it; read only from an existing session file after the save path is applied and verified (`:530-585`); no marker without the secret (`RecoveryMarkerTest::testARequestWithoutTheSecretWritesNoMarkerAndNoSession`); the cookie-only login POST is accepted (`::testTheLoginPostDoesNotNeedTheSecretAgain`); the marker authorizes nothing outside the console (`::testTheMarkerAuthorizesNothingOutsideTheConsole`). D2's documented default (framework session name, console-only namespace) is what ships. |
| **RACC-06** A lost secret is never a permanent lockout | **PARTIAL — C-2 (escape 3 narrowed), W-2** | CLI read (`--secret`) and rotation (`--rotate-secret`) work, are `PHP_SAPI === 'cli'`-gated, print no value on failure, exit non-zero, and never load `config.php`; the first generation logs the value **once** under a documented prefix and never to a response. **Escape 3 does not do what the requirement says**: `SU_RECOVERY_SECRET` only wins when *no persisted value exists*, so an operator who can edit `config.php` but still has the secret *file* on disk is refused by their own pin (measured). It is an escape only in the lost-*file* state. |
| **RACC-07** The console's restore path works, behind two gates; minting only after both | **SATISFIED** | Order: `recovery_csrf_mint_token()` (`lib/recovery_csrf.php:90-129`) records and checks `secret_granted` → `config_loaded` → `authenticated` and only then calls the unchanged `system_updater_csrf_generate()`; the call site (`recovery.php:328`) is **after** the login-form `exit` and passes `authenticated => is_array($user)`. `RecoveryMintOrderTest` asserts the recorded order, that a failed secret records only `secret_compared` with no store, that the login render mints nothing, that a request without `config.php` mints nothing, that the console session's identity keys and marker survive the mint, that a token from another session is refused, and that marker and token are not interchangeable. Drive: `recovery_view_restore_driver()` emits `runRestoreStep('begin')` then `chunk`, puts `su_csrf_token` on **every** URL (single `restoreUrl()` builder), has no `action=start` and no `EventSource`, retries the action in flight, and treats 4xx as terminal. `RecoveryRestoreDriveTest` asserts the rendered contract; `RestoreDriveRetryTest` executes the emitted script in node. The admin surface: `download_backup.php` denies at the guard before the token is read (401 measured) and `ensure_request_csrf()` is consumed only by the process scripts. `process_restore.php` is **not** in the diff and does not require the secret. |
| **RACC-08** Session bootstrap degrades without core code | **SATISFIED (S-2 note on the derivation)** | `recovery_session_name()` (`lib/recovery_session.php:265-280`): `class_exists('FSFramework\\Security\\SessionManager', false)` (autoload disabled) → `FS_SESSION_NAME` (read defensively, buffered, `Throwable`-caught, never double-included) → plugin-local derivation. A name mismatch renders the login form with no error and no security log (`RecoverySessionBootstrapTest::testANameNoBrowserCookieMatchesAsksForCredentialsWithoutDenying`). See **S-2**: the third branch does not reproduce the core's formula (the core seeds with `'session:' . secret`). |

### Delta: `plugin-recovery-login` (NEW capability — canonical dir absent)

| ID | Status | Evidence |
|---|---|---|
| **REC-01** The CSRF verdict is authoritative and first | **SATISFIED** | The field is `recovery_login_token` from `lib/recovery_login_token.php` (namespace `recovery_console`, slot `login_token`, 64 hex, `hash_equals()`, 4 h TTL, session-bound), **never** `system_updater.csrf_tokens` and never `_csrf` (`RecoveryLoginTokenTest` asserts both directions). `recovery.php:219-229` asks `recovery_handler_token_refusal()` before the database boundary and before `recovery_handler_authenticate()`; the handler asks the real validator again as its own first step (`recovery_handler.php:235-242`). Missing and wrong produce the same body (`RecoveryHandlerTest::aMissingTokenBehavesExactlyLikeAWrongOne`). |
| **REC-02** A rejected verdict records ZERO attempts | **SATISFIED** | `recovery_policy_precheck()` returns `token_rejected` before the ban read, the reservation, the lookup and the verifier; `recovery_policy_should_count()` is true **only** for `credential_failed`; `record_failure` is `false` when the pipeline already reserved, so one submission is never counted twice. Pinned by `RecoveryHandlerTest::aRejectedTokenIsRefusedBeforeAnyOtherRead`, `::aFailedLoginIsChargedExactlyOnce`, `RecoveryFallbackLimiterTest::aRejectedTokenBurnsNeitherTheFallbackCounterNorTheBanBucket`. |
| **REC-03** Ban gate read before evaluation, from the core's state, never written | **SATISFIED** | `recovery_policy_ip_log_path()` is `FS_FOLDER/tmp/<FS_TMP_NAME>ip.log`; `recovery_policy_parse_ban_lines()` requires three fields and `expire > now`, `recovery_policy_is_banned()` keeps the core's loose `==` on the address and strict `>` on `count > 5` (`RECOVERY_POLICY_BAN_MAX_ATTEMPTS = 5`); absent/malformed → not banned. Read-only: no writer exists in the plugin, and `RecoveryBanListTest::theBanReadNeverWritesTheBucket` pins it. `::theCoreReaderAndThePluginParserAgree` runs the real `fs_ip_filter` in a child process against the same fixture. |
| **REC-04** Shares the primary login's budget, with a no-more-permissive fallback | **SATISFIED** | Shared mode: `LoginThrottle::isThrottled()`/`recordFailure()`/`clear()` on the folded subject, detected with `class_exists(..., false)` so the console cannot pull the class in. Fallback: `<root>/tmp/<prefix>system_updater_recovery_throttle_<digest>.json` with `6/600 s` per nick+IP and `20/900 s` per account, charged by `recovery_policy_fallback_reserve()` under **one** `flock()` (read → prune → decide → increment), refusal writes nothing, 10 concurrent processes admit exactly six. Constants equal the core's (`RecoveryFallbackLimiterTest::theDeclaredBudgetIsTheCoreBudget`, drift half **did not skip** — `LoginThrottle` is loadable here). **The folded subject holds**: `recovery_policy_counter_subject()` lower-cases, transliterates and strips to `[a-z0-9]`; `everySpellingOfOneAccountSharesOneBudget` admits exactly the first six of `admin/Admin/ADMIN/aDmin/Ádmin/ádmin/áDmín` and asserts the two counter keys are `pair_md5('admin|ip')` and `account_md5('admin')`. Lookup keeps the typed nick; the session carries the row's own bytes. **Known open, out of scope:** the shared mode keeps check-then-act (no `reserve()` on the core class). |
| **REC-05** One failure body and one comparable response time | **SATISFIED (mechanism; the sampled timing is an operator note)** | `recovery_policy_verify_credentials()` (`lib/recovery_policy.php:1001-1016`) calls the verifier **exactly once** — against `RECOVERY_POLICY_DUMMY_HASH` (a declared bcrypt `const`, `password_get_info` reports bcrypt, cost 10) when the row has no stored credential — and only then reads the admin flag, so unknown nick / wrong password / non-admin share one body and one status. The single documented exception is a *throttled* refusal in fallback mode (`RECOVERY_POLICY_LOCAL_LIMITER_NOTE`), which is not one of the three reasons. |
| **REC-06** Credential evaluated once; success rotates and writes the canonical identity | **SATISFIED (the "old id no longer usable" half is an operator probe)** | `recovery_handler_write_identity()` (`:391-414`) calls `session_regenerate_id(true)` **before** the first write; the keys are `user_nick`, `user_id`, `login_time`, `last_activity`. `RecoveryRotationTest::theIdentifierRotatesBeforeTheFirstIdentityWrite` observes the order through an injected regenerate callable (the keys are still absent when it runs); `::theWrittenSessionPassesThePluginGuardOnItsOwnMerits` feeds the resulting array to `system_updater_guard_decision()` with an injected `true` and requires **no** CSRF token. `user_id` holds the nick by an applied deviation (documented, and the re-check binds it to `fs_users.nick`; `::aSessionCarryingOnlyUserIdNeverPassesTheGuard` keeps the defect named). |
| **REC-07** The legacy hash bridge, plugin-local | **SATISFIED** | `lib/recovery_legacy_hash.php:140-161`: `password_verify()` first, then a modern-format gate (`password_get_info` plus the `$2a$/$2b$/$2x$` spellings PHP accepts but reports as `unknown`, measured), then `sha1($salt.$plain)` (only with a non-null salt), `sha1($plain)`, `sha1(mb_strtolower($plain,'UTF8'))`, `md5($plain)` — all with `hash_equals()`. No `class_exists`, no `require`, no Composer symbol. Unrecognizable hash → `false` → the uniform failure; empty password never verifies. Drift tripwire runs here (core present) and is proven by mutation per the apply notes. |
| **REC-08** Reusing an existing session keeps the administrator re-check | **SATISFIED (the demoted-administrator half is operator-owed)** | `recovery.php:180-197` re-reads `SELECT nick, admin FROM fs_users WHERE nick = ?` from `$_SESSION['user_nick']` and `die`s with the existing administrator message for a falsy flag; the marker is never consulted. The "marker alone" half is covered (`RecoveryMarkerTest::testTheMarkerAuthorizesNothingOutsideTheConsole`, and the DB-free render paints the form, never the listing). **The demoted-administrator half needs a live `fs_users` and was not run.** |

### Delta: `plugin-csrf-token` (MODIFIED — one ADDED requirement)

| ID | Status | Evidence |
|---|---|---|
| **CSRF-01** A CSRF token is never an authentication credential | **SATISFIED** | The guard decision never reads a token (`lib/session_auth.php:658-681`) and the file names no framework CSRF symbol (grep + test). Provenance is stated and enforced as **two** gated points; validation is mechanical and cannot tell them apart. A token alone opens nothing: `RecoveryMintOrderTest::aConsoleMintedTokenGrantsNothingOnTheAdminSurface` measures `401` from `download_backup.php` with a valid plugin token and no identity, and no header/body leaks the value; `::theSteppedActionsAcceptTheTokenTheConsoleMinted` shows the same value is accepted by `begin` — the intended asymmetry. `lib/csrf_token.php` is behaviourally unchanged. |

### Delta: `plugin-backup-access` (MODIFIED — scenarios only, `BA-01`, `BA-02` anchors)

| ID | Status | Evidence |
|---|---|---|
| **BA-01** Auth-gated download refuses at the guard, before the token | **SATISFIED** | Guard inside `system_updater_process_init` before `system_updater_csrf_read_from_request()`; the 401 body is the single denial constant; a non-administrator produces the same value because there is only one emitter. Ordering asserted behaviourally, not by status code. The anonymous-plus-framework-token scenario is covered at the decision level (`GuardDecisionTest::testAPluginTokenAloneConfersNoIdentity`, `::testEmptyRequestAndEmptyCandidateMapAreDeniedWithoutALookup`) and end-to-end by the 401 measurement above. |
| **BA-02** Stepped restore: the client tolerates a transient denial | **SATISFIED (source-asserted; no JS runner)** | `view/admin_updater.html.twig` adds `restoreArmed`, retries the action in flight (`retryAction`), treats 4xx as terminal with a message naming the session, retries transport/5xx up to 5 times, never issues an abort, and after the budget says "Vuelve a iniciar sesión … No se puede confirmar que la restauración haya terminado". Pinned by `RestoreSteppedUiTest` (14 cases, including `testTransientDenialDuringRestoreIsRetriedNotReportedAsFailure`, `testExhaustedRetryBudgetAsksForANewSessionWithoutClaimingSuccess`, `testRestoreDriveIssuesNoAbortRequest`). |

### Delta: `plugin-updates` (ADDED requirement to an existing capability)

| ID | Status | Evidence |
|---|---|---|
| **PU-16** A core update must not write the planning tree | **SATISFIED — W-4 note** | The allowlist is declared **once** (`core_updater::nonRuntimeDirectories()`, `lib/core_updater.php:734-746`, eight literal names) and consumed only by the prune. The prune runs in `extractZipSafe()` **only when `extractTo()` returned `true`** (`:619-629`) and in `prepareCoreSource()` on the **git clone** (`:267-277`) — both branches, one helper, one list. It resolves the effective source root (`resolveStagingSourceRoot()`, `:1024-1050`) so the branch-archive shape is pruned one level down and a flat tree in place. Confinement: single-segment names, `realpath` inside the base, symlink refused and reported, nested links unlinked as links (never traversed), missing name a silent no-op, unremovable recorded in `prune_failures` **without** touching `$this->errors` or `success`. `$this->rootPath` is never a base (`testNoCallSiteEverPrunesTheInstallationRoot` pins both call-site arguments as `['$clonePath', '$destination']`). The drift test is an exact set comparison restricted to the planning block, with an always-runnable eight-name half and a **visible** `markTestSkipped` naming the path it looked for; it ran (0 skipped) against this checkout's `nginx-sample.conf`, whose planning block is exactly those eight. `plugins/system_updater/openspec/` survives (the copy never touches `plugins/`, and only top-level names are candidates). **W-4**: the copy uses `findFirstDirectory()` while the prune uses `resolveStagingSourceRoot()`; they differ on the flat uploaded-ZIP shape. |

---

## Findings

### CRITICAL

**C-1 — `RACC-02`'s "differs between installations" is false for installations that share a parent
directory, and the resolved secret is genuinely shared.**

`lib/recovery_secret.php:287-298` derives the outside-webroot candidate as

```php
return [
    dirname($root) . '/' . recovery_secret_filename(),
    $root . '/tmp/' . recovery_secret_filename(),
];
```

The file name is fixed and the location is keyed by the **parent**, not by the installation. Any two
installations under one parent resolve the same file:

| Root | Resolved path | Value |
|---|---|---|
| `/tmp/verify_shared/rootA` | `/tmp/verify_shared/system_updater_recovery_secret.php` | `57e116ef…` |
| `/tmp/verify_shared/rootB` | `/tmp/verify_shared/system_updater_recovery_secret.php` | `57e116ef…` |

and `recovery_secret_gate(<rootA's value>, '/tmp/verify_shared/rootB')` answers **`granted: YES`**.

That layout is the ordinary one — `public_html` next to `staging`, or any two vhosts under
`/var/www`. The consequences:

- installation B's console is reachable with **installation A's** operator secret (the administrator
  password is still required, so this is reachability, not authentication);
- `--rotate-secret` in one installation silently invalidates the other's console URL and its
  `RACC-05` markers (the marker fingerprint is bound to the gate value, which just changed);
- the value is per-host-directory, not per-installation, which is exactly the property
  `RACC-02` states as a MUST ("differs between installations … MUST NOT be derived solely from
  values that are guessable or shared").

`testTwoInstallationsNeverShareASecretAndItIsNotADigestOfTheSharedInputs` cannot detect this: each
`RecoverySecretFixture` allocates its own `<base>`, so the two candidate paths differ by
construction. The test is honest about what it asserts (the *derivation*), but the requirement's
operational claim is untested.

*Required action*: either key the outside-webroot candidate per installation (a digest of the root
in the file name, or a per-installation directory outside the webroot), or amend `RACC-02` to state
the shared-parent limitation — and in either case add a case with **two roots under one parent**.

**C-2 — `RACC-03`'s "operator pin wins" and `RACC-06`'s escape 3 are false while a persisted secret
exists.**

`recovery_secret_gate_value()` (`lib/recovery_secret.php:671-684`) reads the persisted value first
and only consults `SU_RECOVERY_SECRET` when nothing is persisted; `recovery_secret_gate()` uses that
order, and it is the only path that decides access. `recovery_secret_resolve()` does implement the
documented order, but no access decision consumes it.

Measured, with `define('SU_RECOVERY_SECRET', 'operator-pin-…')` in `config.php`:

| State | Candidate | Result |
|---|---|---|
| persisted secret file present | the pin | `Not Found` (404 path) |
| persisted secret file deleted | the same pin | the login form renders |

`RACC-03` says the resolution order **MUST** be "1. `SU_RECOVERY_SECRET` … 2. the persisted value",
with the scenario "The operator pin wins … the persisted value is neither read nor rewritten".
`RACC-06` says the pin "overrides the persisted value" and requires three independent escapes so
that "any operator able to operate the server at all can regain access". In the normal state (the
file exists) escape 3 does not work: an operator who can edit `config.php` but has lost the *value*
is refused by their own pin. The tests pin the opposite of the delta
(`RecoverySecretTest::testTheGateComparesAgainstThePersistedValueBeforeAnyConfigLoad` asserts
`'a pin value does not grant while a persisted value exists'`).

The apply phase recorded the *mechanism* in `tasks.md` ("Delta conflict resolved at apply time") but
only asked for the narrower gap to be reported — "in the state where the secret file is **also**
missing, a wrong secret causes a silent `config.php` load". The consequence above was never
reconciled into `RACC-03` or `RACC-06`, which still state the opposite.

*Required action*: decide which wins, then make the losing text agree. Changing the code (compare the
pin first when it is already visible, which it never is before `config.php` loads) trades away
`RACC-01`'s "a wrong secret never reaches `config.php`"; amending the deltas (pin is the
lost-*file* escape only) is the cheaper, safer direction. Either way `RACC-06`'s "three independent
escapes … any operator able to operate the server" must be restated.

### WARNING

**W-1 — `RACC-01`'s "missing `config.php`" scenario promises a 500 and a directory; the code gives a
200 and a file name.**

`recovery.php:115-119` calls `die("Error: No se encuentra el archivo config.php.")` with no
`http_response_code()`. Measured over `php -S` with the correct secret and no `config.php`:
`STATUS=HTTP/1.1 200 OK`. The scenario requires "HTTP 500 and a plain-text body that names the
missing file **and the directory it was expected in**". The safety half holds (no other core file
loaded, no DB attempt, no credential, no stack trace). A 200 for a hard stop is also an operational
signal problem: uptime checks and access logs will not see it as an error.

*Suggested action*: `http_response_code(500)` before the `die`, and name `FS_FOLDER` in the message
(the delta's own wording), or narrow the scenario.

**W-2 — `RACC-02`'s "unwritable location is a loud error" is not visible from the console.**

The requirement's scenario says "WHEN the console is opened with a plausible secret THEN the
operator sees an error naming the failure". In the web path that state cannot be distinguished from
a wrong secret: `recovery_secret_gate()` returns `granted: false` and the console answers the
`RACC-04` 404. The loud error lives in the library (`recovery_secret_last_error()`) and in the CLI
(non-zero exit, diagnostic on stderr) — both asserted. This is the correct security choice
(`RACC-04` forbids disclosure), but the delta states an observable that the design deliberately does
not provide.

*Suggested action*: add the same carve-out `RACC-04` already implies ("the web path stays silent; the
operator-visible surface is the CLI and the server log"), or the merged requirement will read as a
false promise.

**W-3 — The one identity path that never checks `admin` is still wired, and no test covers it.**

`system_updater_is_logged_in()` (`lib/session_auth.php:1035-1052`) falls back to
`fs_session_manager::isLoggedIn()` whenever the guard decision denies. That method reads
`$_SESSION['user_nick']` + validity, or the legacy `user`/`logkey` cookies, and **never consults the
administrator flag**. It predates this change (blame `72981d7`), it is not in `AUTH-01`'s
failure-mode inventory, and it is the shape `AUTH-03` exists to forbid.

Reachability, measured by reading the boot path: the standalone scripts load `config.php`,
`base/config2.php` and `vendor/autoload.php`; composer's autoload is PSR-4 only (`FSFramework\`,
`FSFramework\Plugins\`, `Tests\`), and `base/fs_autoload.php` — the map that would register
`fs_session_manager` — is required only by `src/Core/Plugin/PluginSchemaSynchronizer.php`. So
`class_exists('fs_session_manager', false)` is `false` in those requests and the fallback is dead
today. It is still a loaded gun: any future `require` of the legacy autoloader in that path turns it
into an `AUTH-03` bypass, and nothing in the suite would notice.

*Suggested action*: delete the fallback (the decision already covers the session shapes it reads), or
gate it behind an explicit `admin` check, and record it in `AUTH-01`'s inventory either way.

**W-4 — `PU-16` has two source-root resolvers, and they disagree on one documented shape.**

The prune resolves the effective root with `resolveStagingSourceRoot()` (single child ⇒ child,
otherwise the destination). The copy resolves it with `findFirstDirectory()` (`:458-477`), which
returns the **first directory entry** whatever it is. For the flat uploaded-ZIP shape (the core tree
at the archive root, explicitly required by `PU-16`), the prune prunes the destination while
`prepareCoreSource()` returns the **first directory entry** — `<extract>/.ddev` in `scandir` order —
so the directory handed to `copyDirectorySelective()` is not the one that was pruned.

Today no unpruned tree can be installed: `update_core()` rejects a source without `VERSION`
(`:139-146`), and a flat upload's "first directory" has no `VERSION`. The reachable upload path
(branch archive with a single top-level directory) is consistent, and the end-to-end test builds
exactly that shape. So the property holds for every reachable path — but it holds by accident of an
unrelated guard, not by construction, and the requirement's sentence "the required property is stated
over the directory handed to `copyDirectorySelective()`" is only true because that directory is
unreachable in the divergent shape.

*Suggested action*: have `prepareCoreSource()` return `resolveStagingSourceRoot($extractPath)`
instead of `findFirstDirectory()`, or state the limitation.

**W-5 — Change size and review budget.**

`git diff a9580ef..HEAD` is **18,106 insertions / 313 deletions across 46 files**, against a
~3,950-line forecast and an 800-line session review budget. Four slices overshot badly: W5 2,089 and
W6 ~1,161 and W7 2,028 as recorded in `tasks.md`, and W8 **2,531 insertions** measured across its
six commits (`git log --reverse --format=%h 9cc35fb..HEAD`). The split that was proposed in
each case (W5a/W5b, W7a/W7b) was not taken. Nothing here is unreviewable — every slice is one
coherent property plus its harness — but the chain is ~4.6× its forecast and the record should not
pretend the budget was met.

### SUGGESTION

**S-1 — `system_updater_session_has_user()` is now dead production code.** `lib/session_auth.php:163-172`
has no production caller (only `SessionAuthTest`). It also treats `_sf2_attributes.user_logged_in`
alone as "has user" — a fail-open shape if anyone rewires it. Delete it, or keep it with a note that
it is not an authorization predicate.

**S-2 — `RACC-08`'s third branch does not reproduce the core's derivation.** The core computes
`FSSESS_` + `sha1(normalize('session:' . SecretManager::getSecret()))[0..12]`
(`SessionManager::installSeed()`); the console computes the same prefix over the raw secret/install
root (`recovery_session_derived_name()`, `:195-201`), i.e. **without** the `'session:'` prefix. The
file's docblock says "the same seed the core uses", which is inaccurate. Impact is fail-safe and
bounded: the mismatch only exists in the damaged-core state (where branch 1 is unavailable), and
`RACC-08` already says a name mismatch means "asked to log in", not "denied". Correct the sentence,
or mirror the prefix.

**S-3 — the mint recorder overstates one step.** `recovery_csrf_mint_token()` records
`secret_compared` unconditionally, but on a marker-authorized render (`RACC-05`) no comparison
happened in that request. The step vocabulary is the evidence the order is asserted with, so the
label should say what happened (`console_authorized`) or the docblock should say the step means "the
secret gate passed, by comparison or by marker".

**S-4 — `recovery.php:368-372` still echoes `$message` unescaped.** `$message` is initialised to `''`
and never assigned, so it is dead code today — which is exactly why it will be missed: the first
person to write to it inherits an XSS sink in the one screen that runs with the most privilege.
Escape it (`htmlspecialchars`) or delete the block.

**S-5 — the single token slot makes two open tabs invalidate each other.** Every authenticated
console render calls `system_updater_csrf_generate('system_updater_csrf')`, which overwrites the one
slot; a second console tab (or a console render while an `admin_updater` tab is open) invalidates the
first tab's token and its next restore step fails with a CSRF error. Not a requirement violation and
not a security issue; worth one sentence in the operator notes if multi-tab use is expected.

**S-6 — AUTH-01's "no filesystem write" scenario is literally false.** `download_backup.php` with no
cookies runs `system_updater_start_authenticated_session()`, which starts a fresh session on the
plugin name (`lib/session_auth.php:1071-1074`) and therefore creates a session file. The *guard*
writes nothing and queries nothing (which is the property that matters), but the scenario's wording
should say so. Pre-existing bootstrap behaviour, not introduced here.

---

## Per-delta merge verdict

| Delta | Ready to merge into `openspec/specs/`? | Reason |
|---|---|---|
| `plugin-auth-guard` (AUTH-01…06) | **YES, with one amendment** | Every requirement is true against the code and pinned. Shape: the canonical directory does not exist, so archive must **create** `openspec/specs/plugin-auth-guard/spec.md`; the delta is already requirement-shaped but needs a `## Purpose` and the removal of the `### Not from the proposal` preamble (SPEC-ADD-1/2 are narrative, not requirements). Amend AUTH-01's failure-mode inventory with **W-3** (the `fs_session_manager` fallback), otherwise the merged text implies the administrator verdict is the only identity path. |
| `plugin-recovery-access` (RACC-01…08) | **NO — as written** | **C-1** makes RACC-02's uniqueness MUST false in a real layout and requires a code or text decision; **C-2** inverts RACC-03's stated order and breaks RACC-06's escape 3; **W-1** and **W-2** state observables the code deliberately does not produce. RACC-04, RACC-05, RACC-07 and RACC-08 are true and can merge unchanged. |
| `plugin-recovery-login` (REC-01…08) | **YES** | All eight requirements are literally true; the two unassertable observables (sampled timing, "old id no longer usable") are declared as operator notes by the delta itself, and the disabled-administrator gap is an explicit recorded decision rather than an omission. Merge = create `openspec/specs/plugin-recovery-login/spec.md` (needs a `## Purpose`). |
| `plugin-csrf-token` (ADDED CSRF-01) | **YES** | One requirement, true and pinned, in the canonical file's language (English). Merge = append one `### Requirement:` section; adapt the heading to the canonical style, which has **no** IDs (the body can keep the `CSRF-01` anchor). No existing text needs to change; there is no contradiction with the canonical "Backward Compatibility" requirement, because CSRF-01 scopes the prohibition to the standalone session guard. |
| `plugin-backup-access` (MODIFIED BA-01, BA-02) | **YES** | Pure scenario append under two existing headings; the requirement text is unchanged apart from the added clarification, which the code satisfies. Merge = insert six scenarios under "Auth-Gated Download Endpoint" and two under "Stepped Resumable Restore"; drop the `BA-01`/`BA-02` heading prefixes (the canonical file names requirements without IDs). Language matches (English). |
| `plugin-updates` (ADDED PU-16) | **YES on content — format work required** | The requirement is true and the drift test is not vacuous. The canonical file, however, is **Spanish** with `### PU-01 — …` headings and WHEN/THEN prose (some scenarios in Spanish), while the delta is English with `### Requirement: PU-16 — …`. Merging verbatim produces a mixed-language file with two heading conventions. Decide the language once (the core precedent merged its delta in the canonical's language) and use the `### PU-16 — …` heading shape. |

**No delta carries an unredacted value.** A scan of every file under
`openspec/changes/harden-auth-guard/` finds **zero** runs of 16+ hexadecimal characters, no
`logkey=`/`auth_sig=` assignment, and no `FSSESS_` value other than the `FSSESS_*` prose placeholder.
The single `su_csrf_token=` occurrence (`tasks.md:1438`) is a parameter name.

---

## VERIFIED vs OPERATOR-OWED

### VERIFIED by this run (code read + a test that can go red, or a measured probe)

| Item | Where |
|---|---|
| AUTH-01 token branch and helper deleted; the account identity path is session-only; no framework CSRF symbol in the guard | `lib/session_auth.php:658-681`; `tests/SessionAuthTest.php:160-215`; grep |
| AUTH-02 all candidates evaluated; plugin session alone resolves nothing; pure decision with no session start | `lib/session_auth.php:436-465,539-567`; `GuardDecisionTest` (24 cases) |
| AUTH-03 fail-closed matrix (true/false/null/throw/missing row/demotion), no cache | `lib/session_auth.php:954-1033`; `GuardDecisionTest::administratorVerdictFailsClosed` family |
| AUTH-04 one refusal constant, both transports; guard before token on `download_backup.php` | `lib/session_auth.php:601-603,1096-1153`; `DownloadBackupScriptTest::guardRunsBeforeThePluginTokenCheck` |
| AUTH-05 token mechanism unchanged; exactly two gated minting entry points; `start`/`begin`/`chunk` not guarded | `git diff` of `lib/csrf_token.php`; `CsrfMintingPointsTest` (subset + equality) |
| AUTH-06 genuine admin accepted, polls stable, expiry still denies | `GuardDecisionTest::testGenuineAdministratorSessionIsAccepted` |
| RACC-01 include graph = `config.php` only (static **and** runtime, with the core stripped) | `RecoveryIncludeGraphTest`; container probe |
| RACC-02 generation shape, 0600, persistence, restart, HTTP-fetch disclosure, no per-request regeneration, CLI loud error | `RecoverySecretTest`; probe (`-rw-------`) |
| RACC-03 core-free resolution; gate answers before `config.php` (sentinel absent on all three failures) | `RecoverySecretTest::testTheGateComparesAgainstThePersistedValueBeforeAnyConfigLoad`; probe |
| RACC-04 404, one path, identical bodies, no cookie/session/config/marker, header subset, no `X-Powered-By` | `RecoveryGateTest::testARequestWithoutTheSecretIsIndistinguishableFromAMissingPath`; probe |
| RACC-05 marker written only after the secret; bound to the granting value; rotation retires it; nothing outside the console accepts it | `RecoveryMarkerTest` (9 cases) |
| RACC-06 CLI read/rotate, web inertness, one log line, no value to the web | `RecoverySecretTest` CLI cases |
| RACC-07 mint order, no mint before both gates, session survival, session binding, marker≠token, stepped drive with the token everywhere, no `action=start`, console token grants nothing on the admin surface | `RecoveryMintOrderTest` (12 cases); `RecoveryRestoreDriveTest`; `RestoreDriveRetryTest` (node) |
| RACC-08 name from `config.php` with no core class; mismatch asks for credentials without denying | `RecoverySessionBootstrapTest` |
| REC-01 authoritative, first, single refusal, own namespace, session-bound | `RecoveryHandlerTest`, `RecoveryLoginTokenTest` |
| REC-02 zero attempts on rejection; exactly one counter set per real failure | `recovery_policy_precheck`/`should_count`; `RecoveryHandlerTest`, `RecoveryFallbackLimiterTest` |
| REC-03 native ban read with the core's semantics; never writes | `RecoveryBanListTest` (parity + no-write + fixture) |
| REC-04 both modes; 6/600 and 20/900; atomic reservation; **one budget per account across case and accent spellings** | `RecoveryFallbackLimiterTest::everySpellingOfOneAccountSharesOneBudget`, `::theDeclaredBudgetIsTheCoreBudget` (no skip) |
| REC-05 one verification, dummy bcrypt constant, one body and status | `recovery_policy_verify_credentials`; `RecoveryCredentialVerdictTest` (45 cases) |
| REC-06 rotation before write; keys written; the session passes the plugin guard with no token | `RecoveryRotationTest` |
| REC-07 the full legacy comparison set, modern first, hash_equals, uniform failure, drift tripwire **ran** | `recovery_legacy_hash.php`; `RecoveryLegacyHashTest` (38 cases) |
| REC-08 existing-session re-check retained, marker alone renders the form | `recovery.php:180-197`; `RecoveryMarkerTest` |
| CSRF-01 token is not an identity, in either direction | `RecoveryMintOrderTest` (both directions measured over HTTP) |
| BA-01 / BA-02 | guard ordering + 401 measurement; `RestoreSteppedUiTest` |
| PU-16 eight-name allowlist, both source branches, effective root, containment, symlinks, idempotence, unremovable reported, only on success, drift test with an always-runnable half | `CoreUpdaterPruneTest` (14 cases, 0 skipped) |
| Static gate | `php -l` sweep: 0 failures |
| Baseline | 641 / 2382 / 2 skipped; root suite 12 failures, all `OidcProvider` |

### OPERATOR-OWED — **do not record these as verified**

| # | Probe | Closes | Why it cannot be asserted here |
|---|---|---|---|
| **O.1** | **`REC-08`'s demoted-administrator half**: authenticate an administrator, clear `fs_users.admin`, reopen the console → the "administrator required" path | REC-08 (second half) | Needs a live `fs_users`; the suite is DB-free |
| **O.2** | **The console render → browser cookie → `begin` leg**: open the console with the secret, log in, click restore, observe `begin` then `chunk` accepted with the *minted* token | RACC-07 (first scenario) | The authenticated render needs a database; the suite seeds the plugin session instead (`theSteppedActionsAcceptTheTokenTheConsoleMinted`) |
| **O.3** | **A real core update** on a disposable installation with `git` available (clone branch) **and** without it (download branch), then `ls` the installation root for the eight names and `ls plugins/*/openspec` | PU-16 (end-to-end) | Needs network/`git` and a throwaway install; the suite stops at the staging directory |
| **O.4** | **`LoginThrottle` shared mode** under concurrency: N parallel submissions against a real cache | REC-04 (shared-mode ceiling) | Explicitly out of scope: the core API is check-then-act and closing it needs a core `reserve()`. Recorded, not claimed |
| **O.5** | **VULN-0001 live PoC retest**: five rapid guesses from one address, then a sixth, then a banned address | VULN-0001 (whole) | The suite asserts the mechanism (ban read, shared budget, uniform body); the live end-to-end attack run is an operator step |
| **O.6** | **Cross-installation secret isolation** after the C-1 fix: two vhosts under one parent, each with its own secret, each rotating independently | RACC-02 (once fixed) | Needs two real installations; the suite can only observe the functions, and today it observes the collision |
| **O.7** | **The two-resolver divergence** (W-4) resolved against a real flat uploaded ZIP on a live installation | PU-16 (flat-upload shape) | The suite builds the ZIP and calls `extractZipSafe()`, but never runs the full `update_core()` for that shape (the `VERSION` gate rejects it) |

**This report records none of O.1–O.7 as verified.** Everything in the two CRITICAL findings was
measured directly, not inferred.

Also still open, by explicit scope decision, and **not to be reported as closed**:

- **`LoginThrottle`'s shared mode is still check-then-act.** Closing it needs a core `reserve()`.
  The fallback limiter *is* atomic; only the shared mode has the window. Out of scope.
- **The `sha1`/`md5` legacy verifier is a second copy by mandate.** The drift tripwire only fires
  where both repositories are present; a released plugin against a changed core is unprotected until
  the plugin is re-released.
- **The disabled-administrator gap** (`fs_users.enabled` is not checked by the console) is an
  accepted, documented decision in `plugin-recovery-login`; no test asserts either direction.
- **The `recovery.php` nick echo** is now escaped (the proposal's "unescaped nick" non-goal is moot),
  but the `$message` echo (**S-4**) is not.
- **`recovery_secret_resolve()` is not consumed by any access decision**; it exists and is tested,
  but the gate uses a different function. Dead-ish API that invites the C-2 confusion.

---

## Merge recommendation for the record

`plugin-auth-guard`, `plugin-recovery-login`, `plugin-csrf-token`, `plugin-backup-access` and
`plugin-updates` may be merged now (the last with its language/heading decision). `plugin-recovery-access`
must **not** be merged until **C-1** and **C-2** are resolved and **W-1**/**W-2** are amended. No
delta carries an unredacted credential.

**The change may not be archived as it stands.** The blocking set is exactly: C-1, C-2 (fix or amend
+ a test that fails without the fix), and the two scenario corrections W-1 and W-2. Everything else is
record hygiene — the four unchecked `tasks.md` PR-body obligations (`tasks.md:431, 555, 1139, 1636`)
should stay unchecked rather than be ticked at archive time, since no PR body exists in this phase.
