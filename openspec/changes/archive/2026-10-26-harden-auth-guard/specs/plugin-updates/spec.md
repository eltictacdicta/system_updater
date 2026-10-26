# Delta for plugin-updates (existing capability — new requirement; no existing text altered)

## Provenance

- **Source**: product-owner requirement delivered **after** `proposal.md` was approved and after
  the other five deltas of this change were written (2026-10-26). It is unrelated to the two
  findings (`VULN-0001`, `VULN-0004`): it is not an authentication defect and it does not touch
  `recovery.php`, the guard, the console or the token model.
- **Change class**: additive to an existing capability. `PU-01`…`PU-15` of
  `plugins/system_updater/openspec/specs/plugin-updates/spec.md` are untouched and are not
  restated here; this delta adds `PU-16` and nothing else. No `## MODIFIED Requirements` or
  `## REMOVED Requirements` section exists because no canonical requirement text changes.
- **Superseded statement in `proposal.md`**: "Relationship to this plugin's canonical specs"
  (`:355`) says `plugin-updates` "contains no authentication requirement; unaffected". The first
  half remains true — this capability still carries no authentication requirement. The second half
  is superseded: the capability is affected by a later product-owner decision on a different
  subject. That sentence must be read as scoped to the two authentication findings.
- **No contradiction with `proposal.md`'s out-of-scope list**: that list rules out *deployment-side
  alternatives* ("reverse-proxy deny rules", "loopback/CLI-only invocation"). This requirement
  requires **no operator to touch a server**. It reads the core's `nginx-sample.conf` only as the
  authoritative *statement of the set*, so the two enforcement points cannot drift; it never
  requires the edge rules to be deployed.
- **Grounding in the shipped code** (verified in this working tree on 2026-10-26):
  - `lib/core_updater.php:102` — `$extractPath = $this->rootPath . '/tmp/core_update'`.
  - `lib/core_updater.php:222` — `prepareCoreSource()` returns either `$clonePath`
    (`:241`, `cloneGitRepository()` at `:396`) or the ZIP extraction directory
    (`downloadCoreZipSource()` at `:354`, which is hard-wired to
    `https://github.com/eltictacdicta/fs-framework/archive/refs/heads/master.zip` and its
    siblings at `:357-360` — the whole branch tree).
  - `lib/core_updater.php:563` — `extractZipSafe()`, the function that owns the prune; it validates
    entry names against `../`, `..\\`, a leading `/` and a Windows drive prefix (`:582`), then calls
    `$zip->extractTo($destination)` at `:589` and returns its result at `:591`.
  - `lib/core_updater.php:146` — `copyDirectorySelective($sourceDir, $this->rootPath, $excludeFiles)`
    writes the extracted tree into the installation root.
  - `lib/core_updater.php:667` — `coreRootCopyExcludes()` excludes `config.php`, `.htaccess`,
    `plugins`, `backups`, `tmp`, `apk`, `imgs`, `.ddev` and **none** of the planning directories, so
    all eight are copied into the installation today.
  - Verified present in the core repository root and therefore written by every update:
    `openspec/` (180 files), `docs/` (6), `tools/` (1), `extras/` (1), `odd/` (2), `dev-tools/` (2),
    `managed_context/` (1), `test_suite_analysis/` (1).
  - `nginx-sample.conf:50-61` — the core sample denies exactly those eight as one class, with the
    comment "Planning, documentation and analysis trees… hold no runtime asset", and explicitly
    keeps `themes/` out of the deny list because it holds assets the browser fetches.
  - `lib/core_updater.php:285-324` — `cleanupRootDirectories()` runs *before* the copy and already
    deletes `docs/` and `extras/` from the root, then the copy restores them from the source. This
    is why the defect is not self-limiting, and it is why the six other names persist indefinitely
    (see "Recorded consequences").
- **Why this capability**: `PU-01`…`PU-15` govern *plugin* updates. No canonical requirement states
  anything about what a *core* update writes into the installation, so this is the first one.

## ADDED Requirements

### Requirement: PU-16 — A core update MUST NOT write the core's planning tree into the installation

After a successful core update extraction, the updater MUST remove the non-runtime directories it
just wrote, from an **explicit allowlist**. The allowlist MUST be exactly:

`openspec/`, `docs/`, `tools/`, `extras/`, `odd/`, `dev-tools/`, `managed_context/`,
`test_suite_analysis/`

The removal MUST be driven by that literal list — **never a wildcard, never a pattern, and never
"anything not on a keep-list"**. A rule expressed as "delete what is not runtime" would delete the
next directory the core adds before anyone decided it was disposable; the allowlist is the only
form that fails closed.

The eight names MUST be declared in exactly one place in the plugin and consumed by the removal, so
the list and the code cannot drift from each other.

#### The prune belongs to `core_updater::extractZipSafe()`

The prune MUST run inside `core_updater::extractZipSafe()` (`lib/core_updater.php:563`),
immediately after `$zip->extractTo($destination)` returns `true` (`:589`) and before the method
returns. It MUST be a single shared helper so that the second source branch is covered by the same
list and the same rules: `core_updater::prepareCoreSource()` (`:222`) also returns a full tree when
`cloneGitRepository()` (`:396`) succeeds, and a git clone of `master` carries the same eight
directories. Both source branches MUST be pruned before `copyDirectorySelective()` (`:146`) writes
anything into the installation. A prune on the ZIP branch only would leave the property false on
the branch the updater prefers when `git` is available.

**The base directory is the one that gets copied, not the outer destination.** A branch archive
extracts into its own top-level directory (`fs-framework-master/`), one level below the
`$destination` passed to `extractZipSafe()`; `findFirstDirectory()` (`:428`) exists precisely to
find it, and it is what `prepareCoreSource()` returns and what the copy reads. Pruning only the
outer `$destination` would find none of the eight, report success and change nothing — a vacuous
implementation that satisfies a careless test and leaves the tree in place. The required property is
stated over the directory handed to `copyDirectorySelective()`: after the updater prepares a source,
**that** directory contains none of the eight. An implementation MUST therefore resolve the effective
source root and prune there, and MUST cover both branches of the ZIP shape (a branch archive with its
own top-level directory, and an uploaded ZIP whose root is already the core tree).

#### The prune MUST NOT touch runtime or user data

The allowlist is closed. Nothing outside it MUST be removed. In particular the prune MUST NOT
touch `vendor/`, `themes/`, `view/`, `plugins/`, `src/`, `base/`, `model/`, `controller/`,
`images/`, `imgs/`, `tmp/`, `backups/`, `apk/`, `translations/`, `config/`, `config.php`,
`.git/`, `.htaccess`, or any directory that holds a backup created by this plugin.

A plugin's own `openspec/` lives at `plugins/<plugin>/openspec/` and is therefore **not at risk**:
it sits under `plugins/`, which is a single path segment that is never on the allowlist, and the
removal never recurses into a directory it was not told to remove. Plugin SDD artifacts — including
this change's own `plugins/system_updater/openspec/` — MUST survive every core update. The same
applies to a `docs/` or `tools/` directory nested inside a plugin or a theme: only the **top-level**
directory named on the allowlist is a candidate.

#### Removal MUST be confined and MUST NOT follow symlinks out

Each removal MUST be confined to the staging source directory that `prepareCoreSource()` populated
(`<root>/tmp/core_update` and its single top-level child). The prune MUST NOT be invoked on
`$this->rootPath` (the installation) at any point.

For every name the helper MUST resolve the candidate and reject it unless it resolves **inside**
the staging directory: a path that resolves outside MUST be refused and reported, and nothing
outside MUST be removed. The removal MUST NOT follow a symlink out of the staging directory, and
MUST NOT delete through a symlink: a candidate that is a symlink is refused or unlinked as a link,
never traversed.

#### Idempotence, and an unremovable directory is reported — not fatal

A missing directory is **not** an error: the prune is idempotent, and running it twice on the same
tree MUST produce no new error, no new message and no change of outcome.

A directory that cannot be removed (permissions, a read-only mount, a file locked on the host) MUST
be reported **without failing the update**. The operator MUST be able to see it: the update result
MUST name the directory that survived, so "the tree is still there" is visible in the outcome rather
than inferred from the web server. A partial prune MUST leave the remaining names attempted, and
the update MUST complete with `success` unchanged by the prune outcome.

#### The prune runs only on a successful extraction

The prune MUST run only after a **successful** extraction (`extractTo()` returned `true`, or the
clone directory exists and is complete). It MUST NOT run on a failed, refused or partial extraction:
a failed extraction is already reported and cleaned up as a unit, and deleting from a half-written
tree turns one failure into a second, unreported one.

#### Two enforcement points, one decision — and a test that says so

The allowlist and the core's `nginx-sample.conf` deny block are **two enforcement points of one
decision**: the filesystem (never write it) and the edge (refuse to serve it). They MUST NOT drift.
A name added to one and not the other is a defect in this requirement, in either direction.

This drift MUST be caught by a test, not by review. The test MUST assert that the plugin's
allowlist equals the set of top-level directory names the core sample denies in its
"Planning, documentation and analysis trees" block — the eight names above, and no others. The
comparison MUST NOT absorb the sample's other denies (`/vendor/`, `/tmp/`, `/backups/`, `/.git/`)
or its file-level denies: those are the runtime and operator paths this requirement explicitly must
not touch, and treating them as members of the set would silently widen the allowlist.

The core sample lives at `../../nginx-sample.conf` relative to the plugin directory
(`plugins/system_updater` → the core repository root), which is `dirname(__DIR__, 3)` from a file
under `plugins/system_updater/tests/`. The test MAY read that file. Because this plugin is also
delivered as a standalone repository where the core sample does not exist, the test MUST degrade
gracefully when it is absent — and it MUST say so rather than pass vacuously:

- the **always-runnable** assertion MUST still execute: it pins the allowlist to the eight literal
  names this requirement states, so a silent removal or addition is RED with or without the core
  repository present;
- the **drift** assertion MUST be skipped **visibly**, with a message that names the resolved path
  it looked for and states that the two-list comparison did **not** run. A green suite with an
  unremarked skip is the vacuous pass this clause exists to forbid.

#### Not a substitute for the edge, and not the same decision as `coreRootCopyExcludes()`

This requirement does not require any operator to deploy the nginx sample, configure a reverse
proxy, or change a server. It removes the need for that decision to be the *only* one.

Adding the eight names to `coreRootCopyExcludes()` (`:667`) MAY be done as defence in depth, but it
MUST NOT be the only mechanism: the copy exclusion alone still leaves the full tree inside the web
root, under `tmp/core_update`, for the duration of the update, because that directory is only
deleted after the copy at `:158-160`.

#### Scenario: A fresh update removes all eight and leaves the runtime directories intact

- GIVEN a staging directory containing the eight planning directories plus `vendor/`, `themes/`,
  `view/`, `plugins/`, `src/`, `base/`, `model/`, `controller/`, `images/`, `imgs/`, `tmp/`,
  `backups/`, `config.php` and `.htaccess` — in both shapes: directly at the root (an uploaded ZIP)
  and one level down under `<repo>-<branch>/` (a branch archive)
- WHEN a core update extracts successfully
- THEN none of the eight planning directories exists in the directory the update copies from
  afterwards
- AND every runtime and user-data entry listed above is still present and unmodified
- AND the resulting installation root contains none of the eight

#### Scenario: A second update is a no-op

- GIVEN a staging directory from which the eight directories were already removed
- WHEN the prune runs again
- THEN it removes nothing
- AND it records no error and no message
- AND the update outcome is identical to the first run

#### Scenario: An absent directory does not fail the update

- GIVEN a source tree that never contained one of the eight names
- WHEN the prune runs
- THEN the missing name is not an error
- AND the update completes with `success` true

#### Scenario: An unremovable directory is reported and the update still completes

- GIVEN one of the eight directories cannot be removed
- WHEN the update finishes
- THEN the update result names that directory as not removed
- AND the update still completes with `success` true
- AND the surviving directory is the only one the result names

#### Scenario: A path that resolves outside the staging directory is refused

- GIVEN a candidate inside the staging directory that resolves outside it (a symlink pointing out
  of the tree, or a resolved path that does not share the staging prefix)
- WHEN the prune evaluates it
- THEN it is refused and reported
- AND nothing outside the staging directory is removed
- AND the symlink is not traversed

#### Scenario: Plugin SDD artifacts survive

- GIVEN an installation with `plugins/system_updater/openspec/` and another plugin's
  `plugins/<name>/openspec/`
- WHEN a core update completes
- THEN both directories and their files are still present

#### Scenario: A failed extraction is never pruned

- GIVEN an extraction that returns `false` (a refused entry name, an unreadable archive, a missing
  `ZipArchive`)
- WHEN the update aborts
- THEN the prune did not run
- AND no planning directory was removed from the partial tree
- AND the reported failure is the extraction failure, unchanged

#### Scenario: The git-clone branch is pruned too, and the installation root never is

- GIVEN a source prepared by `prepareCoreSource()` through `cloneGitRepository()`
- WHEN the update proceeds to the copy
- THEN the eight directories are removed from the clone directory
- AND the prune was never invoked on the installation root
- AND a pre-existing `openspec/` in the installation root was neither created nor deleted by the
  prune

#### Scenario: The allowlist and the core sample cannot drift

- GIVEN the plugin's declared allowlist
- WHEN the test enumerates the top-level directory names denied in the core sample's planning block
- THEN the two sets are identical and contain the eight names above
- AND when the core sample is absent, the eight-name assertion still runs and the comparison is
  reported as skipped with the resolved path it looked for

## Recorded consequences (named, not hidden)

| # | Consequence | Why it is accepted / what it means |
|---|---|---|
| 1 | `docs/` and `extras/` disappear from an installation on the **first** update after this change, the other six only if they were never written | `cleanupRootDirectories()` (`:285-324`) already deletes `docs/` and `extras/` from the root before the copy and the copy restores them today; once the source no longer carries them, the restore cannot happen |
| 2 | Installations that already received the tree keep the six remaining directories until an operator removes them | This requirement deletes nothing it did not just write: the prune is confined to the staging directory and is explicitly forbidden from running against the installation root. Those paths stay covered by the edge deny (`nginx-sample.conf`), which is the second enforcement point |
| 3 | A developer working on a **git checkout** who runs the updater loses these directories from the working tree | The updater already deletes `docs/`, `extras/`, `vendor/` and more from a checkout (`cleanupRootDirectories()`); the update path is not the developer path. A `git checkout -- .` restores them, and no runtime behaviour depends on them |
| 4 | `tools/phpstan-plugins.php` and `dev-tools/` are no longer present in an updated installation | They are core-owned development tooling driven by `composer phpstan` / `composer phpstan:plugins`, which are developer commands, not runtime. The PHPStan gate is unaffected because it runs in a clone, not in an updated installation |
| 5 | Plugin ZIP installs (`plugin_downloader::extractZipSafe()` at `:1441`, `updater_manager::extractZipSafe()` at `:839`) are **not** touched | They extract into `plugins/`, so a plugin's own `openspec/` lands under `plugins/<name>/` — the directory this requirement protects. Widening the prune to them would delete plugin SDD artifacts |
| 6 | A third-party or hand-rolled source tree (an operator-uploaded ZIP) is pruned by the same list | The upload path (`prepareCoreSource()`, `:231-238`) goes through the same `extractZipSafe()`. A vendor ZIP that legitimately ships a `docs/` directory loses it; that is the same decision, applied to every source |

## Traceability

| Requirement | Capability | Source | Enforcement point |
|---|---|---|---|
| PU-16 | plugin-updates | product-owner decision 2026-10-26, not from `proposal.md`; grounded in `lib/core_updater.php:102/146/222/354/563/589/667` and `nginx-sample.conf:50-61` | filesystem (never write) + edge (`nginx-sample.conf`), one decision, two points, drift caught by a test |

Success criteria this requirement contributes to the change's gate:

- After a core update, the installation root contains none of `openspec/`, `docs/`, `tools/`,
  `extras/`, `odd/`, `dev-tools/`, `managed_context/`, `test_suite_analysis/`.
- `plugins/system_updater/openspec/` (and every other plugin's `openspec/`) is untouched by an
  update.
- The plugin's allowlist and the core sample's planning deny block are proven equal by a test that
  reports its skip when the core sample is unavailable, and never passes vacuously.

## Testing notes — this suite has no database and no network

- The prune helper is asserted directly over a **temp staging tree** built in `setUp()` and removed
  in `tearDown()` (`tests/CoreUpdaterPruneTest.php`, same shape and same reflection seam as
  `tests/CoreUpdaterTest.php`, which already reaches private members this way).
- The extraction half is asserted end-to-end without the network: a synthetic ZIP built in the test
  with `ZipArchive` (available in this container: PHP 8.3.33, `class_exists('ZipArchive') === true`)
  holding the eight directories plus runtime entries, extracted through the production
  `extractZipSafe()`. No test may call `downloadCoreZipSource()`, `cloneGitRepository()` or
  `update_core()`, which reach GitHub or a real installation.
- The unremovable case must produce the state it asserts rather than assume it: `chmod 0555` does
  not restrict a privileged host, so the case observes the precondition and skips **visibly** with a
  recorded reason when the host cannot produce an unwritable directory (the `RecoverySecretTest`
  precedent).
- The symlink case is skipped visibly where the host cannot create a symlink, for the same reason.
- The drift test reads `dirname(__DIR__, 3) . '/nginx-sample.conf'`, never a hard-coded absolute
  path, and never writes to it.
