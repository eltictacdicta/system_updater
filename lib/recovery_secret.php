<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Per-installation secret of the emergency recovery console (RACC-02, RACC-03,
 * RACC-06).
 *
 * Dependency contract (RACC-01): this file is plugin-local and procedural on
 * purpose. It must stay loadable when `vendor/`, `base/` and `src/` are gone,
 * so it declares no class, requires no autoloader and touches no core file. It
 * also never reads the request: a console secret must not be reachable from a
 * query parameter.
 *
 * Where the secret lives, and why that survives a damaged core
 * ------------------------------------------------------------
 * The value is persisted as plain data on disk, in one of two locations, in
 * this order:
 *
 *   1. `dirname(FS_FOLDER) . '/<per-installation directory>/system_updater_recovery_secret.php'`;
 *   2. `FS_FOLDER . '/tmp/system_updater_recovery_secret.php'`.
 *
 * Candidate 1 is a sibling of the installation, so it is normally outside the
 * document root and survives anything that happens *inside* it: a broken core,
 * a half-applied update, a restore that overwrote `config.php`, a `vendor/`
 * directory that no longer autoloads. Candidate 2 is the last resort for a
 * shared host that does not let the PHP user write beside the installation; it
 * sits inside the webroot, so the file is written in a shape whose direct HTTP
 * request discloses nothing (see {@see recovery_secret_payload()}: the bytes
 * begin with a PHP guard that answers 404 and exits before the value line is
 * reached, and the value is only ever parsed as text, never included).
 *
 * Candidate 1's *directory* is derived from the installation, not from the
 * parent: see {@see recovery_secret_install_directory()}. A fixed file name
 * beside the installation made two installations that share a parent
 * (`public_html` next to `staging`, or any two vhosts under `/var/www`) resolve
 * one file and therefore share one secret — reachability of one installation's
 * console from the other's operator secret, and a `--rotate-secret` that
 * silently retired the neighbour's URL. The file name stays fixed because it is
 * the part an operator greps for; the digest lives one level up.
 *
 * Both locations are *outside* the database, *outside* the framework session
 * and *outside* `config.php`, which is the point: the console exists for the
 * state where the application cannot boot, so a secret stored by the
 * application would be unavailable exactly when it is needed.
 *
 * Both candidates are derived from the installation root. When the caller
 * passes none and `FS_FOLDER` is not defined, the root is inferred from this
 * file's own depth and then *verified* against the tree — see
 * {@see recovery_secret_detect_install_root()}. A tree that does not verify
 * yields no candidate at all, because a guessed candidate is one whose position
 * relative to the webroot cannot be stated.
 *
 * If both locations are lost (the file was deleted, or the installation was
 * moved to a host where neither is writable), the secret is regenerated on the
 * next request and disclosed once through the PHP error log. An operator who
 * still remembers the old value is not locked out either: `SU_RECOVERY_SECRET`
 * in `config.php` outranks the persisted value, and the CLI escapes of RACC-06
 * read and rotate it without any core code. A lost file is therefore a
 * recoverable event, never a lockout.
 *
 * Resolution order (RACC-03), and the one thing that must not be misread
 * ---------------------------------------------------------------------
 * {@see recovery_secret_resolve()} answers "what is the secret's value": the
 * operator pin when the constant is already defined, otherwise the persisted
 * value. It never loads `config.php`, so branch 2 is reachable with a damaged
 * or absent `config.php` and loads no class.
 *
 * The console's gate does not ask that question. It asks "does this candidate
 * match", and the order there is the opposite one, for a documented reason:
 * compare against the persisted value first (core-free, always available), and
 * only when no persisted value exists load `config.php` through
 * {@see recovery_secret_operator_pin()} and compare against the pin. That is
 * what keeps a wrong secret away from `config.php` in every state that has a
 * persisted secret to protect.
 */

/**
 * Operator-facing file name of the persisted secret.
 *
 * It is part of the contract, not an implementation detail: an operator who
 * rescues an installation by hand has to know what to look for. The name is
 * stable across installations on purpose; the *directory* is what identifies
 * the installation ({@see recovery_secret_install_directory()}), so
 * `ls <parent>` shows one directory per installation, each holding a file with
 * this exact name.
 */
function recovery_secret_filename(): string
{
    return 'system_updater_recovery_secret.php';
}

/**
 * The per-installation directory that holds the outside-webroot candidate.
 *
 * RACC-02's uniqueness MUST is a statement about **installations**, not about
 * host directories, and the previous derivation keyed the candidate on
 * `dirname($root)` alone: two installations under one parent resolved the very
 * same file, shared one value, and each one's gate accepted the other's secret
 * (measured; `verify-report.md` C-1). The directory name is therefore a digest
 * of the installation root, so two installations cannot collide while both
 * candidates keep sitting beside their own installation.
 *
 * The digest is over the **resolved** root on purpose. `realpath()` folds the
 * spellings of one installation — a trailing slash, a redundant `..`, a
 * symlinked deployment path — onto one directory, so the same installation
 * cannot end up with two secrets depending on how it was reached. It is a
 * location, never an entropy source: the secret itself is still
 * `random_bytes(32)` ({@see recovery_secret_generate()}), and RACC-02 forbids
 * deriving the value from the path.
 *
 * Sixteen hex characters (64 bits) of `sha256` is the same order of magnitude a
 * handful of installations on one host could ever need; a collision would have
 * to be constructed, and it would still only reproduce the defect this
 * function exists to remove.
 */
function recovery_secret_install_directory(string $installRoot): string
{
    $resolved = realpath($installRoot);
    $identity = $resolved === false ? rtrim($installRoot, '/\\') : $resolved;

    return 'system_updater_recovery_' . substr(hash('sha256', $identity), 0, 16);
}

/**
 * The prefix of the single generation line written to the PHP error log.
 * Documented so an operator with log access but no shell can grep for it
 * (RACC-06).
 */
function recovery_secret_log_prefix(): string
{
    return '[system_updater] recovery console secret generated';
}

/**
 * Read the operator pin (`SU_RECOVERY_SECRET`) out of a `config.php`.
 *
 * This is the *lost-secret* fallback and nothing else: it is the only place in
 * the whole console's graph that loads `config.php` before the gate has
 * accepted a candidate, and the caller must reach it only when no persisted
 * value exists. Two properties keep that safe:
 *
 * - the include is error-suppressed, output-buffered and wrapped in a
 *   `Throwable` catch, so a `config.php` that does not parse yields "no pin"
 *   instead of a fatal page or a leaked parse error;
 * - it returns `null` rather than a value when the constant is absent or
 *   empty, so "the operator pinned the empty string" cannot become a
 *   one-character secret.
 *
 * The pin is deliberately not length-checked: RACC-06 lets an operator set a
 * value of their choosing. The library's own recommendation (64 hex, i.e.
 * `php -r 'echo bin2hex(random_bytes(32));'`) lives in the docblock instead of
 * a rejection, so a rescue is never blocked by a policy the operator cannot
 * see.
 */
function recovery_secret_operator_pin(string $configFile): ?string
{
    if (is_file($configFile) && is_readable($configFile)) {
        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $file): void {
                include $file;
            })($configFile);
        } catch (\Throwable $error) {
            // A damaged config.php must yield "no pin", never a fatal page.
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    if (!defined('SU_RECOVERY_SECRET')) {
        return null;
    }

    $pin = constant('SU_RECOVERY_SECRET');
    if (!is_string($pin) || trim($pin) === '') {
        return null;
    }

    return trim($pin);
}

/**
 * The last failure recorded by {@see recovery_secret_ensure()} or
 * {@see recovery_secret_rotate()}, or `''`.
 *
 * Doubles as the setter (`recovery_secret_last_error('...')`) and the reset
 * (`recovery_secret_last_error('')`). It exists so the operator-visible
 * surface — the CLI today, the gate's refusal page later — can say *why* there
 * is no secret instead of guessing. The message never contains a value: only
 * paths, the failure and the escape that still works.
 */
function recovery_secret_last_error(?string $message = null): string
{
    static $last = '';

    if ($message !== null) {
        $last = $message;
    }

    return $last;
}

/**
 * The diagnostic for RACC-02's loud-error state. Documented as one string so
 * the CLI and the gate cannot drift apart, and so it always names the two
 * things an operator needs: where it tried, and the escape that bypasses the
 * filesystem entirely.
 *
 * "Where it tried" is an empty list when the install root could not be
 * verified, and the message says so instead of printing a placeholder path: an
 * operator has to be able to tell "nothing is writable" apart from "this file
 * cannot tell where the installation is".
 */
function recovery_secret_no_location_message(?string $installRoot = null): string
{
    $candidates = recovery_secret_candidates($installRoot);

    if ($candidates === []) {
        return 'No usable location for the recovery console secret: the installation'
            . ' root could not be verified from ' . __DIR__ . ', so no candidate can'
            . ' be proven to sit outside the webroot.'
            . ' Set SU_RECOVERY_SECRET in config.php to bypass the filesystem entirely.';
    }

    return 'No writable location for the recovery console secret. Tried: '
        . implode(', ', $candidates)
        . '. Make one of those directories writable by the PHP user, or set'
        . ' SU_RECOVERY_SECRET in config.php.';
}

/**
 * The diagnostic for a write that could not be completed. Kept next to
 * {@see recovery_secret_no_location_message()} for the same reason: one
 * documented string, so the operator sees the path and the escape rather than
 * a generic excuse.
 */
function recovery_secret_write_failure_message(string $target): string
{
    return 'Could not write the recovery console secret at ' . $target
        . '. The PHP user must be able to create and replace that file,'
        . ' or set SU_RECOVERY_SECRET in config.php.';
}

/**
 * Resolve the installation root. Everything else in this file is a pure
 * function of it, which is what lets the suite run against a temp tree.
 *
 * `''` means "there is no root this file can vouch for", and every caller
 * turns it into the documented loud error rather than into a path. See
 * {@see recovery_secret_detect_install_root()}.
 */
function recovery_secret_install_root(?string $installRoot = null): string
{
    if ($installRoot !== null && $installRoot !== '') {
        return rtrim($installRoot, '/\\');
    }

    if (defined('FS_FOLDER')) {
        $folder = rtrim((string) FS_FOLDER, '/\\');
        if ($folder !== '') {
            return $folder;
        }
    }

    return recovery_secret_detect_install_root();
}

/**
 * The install root inferred from this file's own location, or `''`.
 *
 * This file lives at `<root>/plugins/<plugin>/lib`, so the installation root is
 * three levels up — two levels names `<root>/plugins`, and the sibling of
 * `<root>/plugins` is `<root>` itself, i.e. the webroot. Getting that depth
 * wrong does not fail loudly: it moves the "outside the webroot" candidate
 * *inside* it and quietly loses the RACC-02 guarantee. That is why the
 * arithmetic is never returned on its own. It is checked against the tree
 * first: the parent of this file's parent has to be named `plugins`, and
 * `<root>/plugins/<plugin>` has to resolve to the very directory this file sits
 * in. A deployment that does not answer both questions yields `''`, and the
 * caller reports "no usable location" instead of writing the secret somewhere
 * it cannot vouch for.
 */
function recovery_secret_detect_install_root(): string
{
    $pluginDirectory = dirname(__DIR__);
    $pluginsDirectory = dirname($pluginDirectory);

    if (basename($pluginsDirectory) !== 'plugins') {
        return '';
    }

    $candidate = dirname($pluginsDirectory);
    if ($candidate === '' || $candidate === '.') {
        return '';
    }

    $roundTrip = realpath($candidate . '/plugins/' . basename($pluginDirectory));
    if ($roundTrip === false || $roundTrip !== realpath($pluginDirectory)) {
        return '';
    }

    return rtrim($candidate, '/\\');
}

/**
 * Candidate locations, in the documented order: outside the webroot first,
 * inside it only as a last resort.
 *
 * The outside-webroot candidate is one directory per installation
 * ({@see recovery_secret_install_directory()}), so two installations that share
 * a parent directory — the ordinary production-plus-staging layout — never
 * read or write the same file. The inside-webroot candidate needs no such
 * treatment: it is already keyed on `$root`.
 *
 * An unresolvable install root produces no candidates at all. That is a
 * deliberate empty list rather than a guess: every candidate is derived from
 * the root, so without one there is no path whose position relative to the
 * webroot can be stated, and inventing one is precisely the mistake RACC-02
 * forbids.
 *
 * @return string[]
 */
function recovery_secret_candidates(?string $installRoot = null): array
{
    $root = recovery_secret_install_root($installRoot);
    if ($root === '') {
        return [];
    }

    return [
        dirname($root) . '/' . recovery_secret_install_directory($root) . '/' . recovery_secret_filename(),
        $root . '/tmp/' . recovery_secret_filename(),
    ];
}

/**
 * RACC-02's shape: 32 crypto-random bytes rendered as 64 hex characters.
 */
function recovery_secret_is_valid(string $value): bool
{
    return strlen($value) === 64 && ctype_xdigit($value);
}

/**
 * RACC-02: the value itself. `random_bytes()` is the only source — never a
 * digest of the install path, the database name or a timestamp.
 */
function recovery_secret_generate(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * The persisted bytes.
 *
 * The file has to be safe to request directly, because the last-resort
 * candidate lives inside the webroot: it is a PHP file whose first statement
 * answers 404 and exits, so an executed request emits nothing at all. The value
 * itself sits on a comment line that only ever gets *parsed as text* by this
 * library — the file is never `include`d, so its bytes can never execute as
 * code either.
 */
function recovery_secret_payload(string $value): string
{
    return "<?php\n"
        . "// system_updater per-installation recovery secret (RACC-02).\n"
        . "// Owner-only file. A direct HTTP request must disclose nothing, so the\n"
        . "// guard below answers 404 and exits before the value line is reached.\n"
        . "// The console reads this file as text; it is never included.\n"
        . "http_response_code(404);\n"
        . "exit;\n"
        . '# secret: ' . $value . "\n";
}

/**
 * Extract the value from the persisted bytes, or `null` when the file does not
 * hold one (truncated, replaced by a human, or not ours at all).
 */
function recovery_secret_parse(string $bytes): ?string
{
    if (preg_match('/^# secret: ([0-9a-f]{64})$/m', $bytes, $matches) !== 1) {
        return null;
    }

    return recovery_secret_is_valid($matches[1]) ? $matches[1] : null;
}

/**
 * The candidate that already holds a valid secret, or `null`.
 */
function recovery_secret_existing_path(?string $installRoot = null): ?string
{
    foreach (recovery_secret_candidates($installRoot) as $candidate) {
        if (!is_file($candidate) || !is_readable($candidate)) {
            continue;
        }

        if (recovery_secret_parse((string) @file_get_contents($candidate)) !== null) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The first candidate whose directory can be written, or `''`.
 *
 * A missing directory is created (a last-resort candidate needs `tmp/`); a
 * directory that exists but refuses the write is skipped, never forced.
 */
function recovery_secret_writable_path(?string $installRoot = null): string
{
    foreach (recovery_secret_candidates($installRoot) as $candidate) {
        $directory = dirname($candidate);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            continue;
        }

        if (is_writable($directory)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * Where the secret is (or would be) persisted. `''` means "nowhere", which is
 * the loud-error state RACC-02 forbids resolving by generating a throwaway
 * value per request.
 */
function recovery_secret_path(?string $installRoot = null): string
{
    return recovery_secret_existing_path($installRoot) ?? recovery_secret_writable_path($installRoot);
}

/**
 * The persisted value, or `null`. Reads no `config.php` and loads no class.
 */
function recovery_secret_read(?string $installRoot = null): ?string
{
    $path = recovery_secret_existing_path($installRoot);
    if ($path === null) {
        return null;
    }

    return recovery_secret_parse((string) @file_get_contents($path));
}

/**
 * Persist a value with owner-only permissions.
 *
 * The write goes to a sibling temp file and is renamed into place, so a crash
 * cannot leave a half-written secret that resolves to garbage and a reader
 * never observes a partial file at the real path.
 *
 * `0600` on the result is not enough on its own. `file_put_contents()` creates
 * the file with the process umask, and the `chmod()` that follows lands only
 * after the bytes are already on disk: without narrowing the umask first there
 * is a window in which another local user can read the secret, and a process
 * that dies inside that window leaves it readable for good. The umask is
 * therefore set to `0077` around the write and restored in a `finally`, so
 * neither a failure nor a throw leaves the process with a permissive mask.
 *
 * `file_put_contents()` also reports a short write as `false` while keeping the
 * bytes it accepted, so the byte count is verified against the payload, and the
 * temp file is removed on **every** failure path — short write, failed write,
 * failed rename, or a throw. Only a successful rename publishes the value.
 * No fallback value is ever invented here: a failed write returns `false`.
 */
function recovery_secret_store(string $value, ?string $target = null): bool
{
    if (!recovery_secret_is_valid($value)) {
        return false;
    }

    $path = $target ?? recovery_secret_path();
    if ($path === '') {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }

    $payload = recovery_secret_payload($value);
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $previousUmask = umask(0077);

    try {
        $written = @file_put_contents($temp, $payload);
        if ($written === false || $written !== strlen($payload)) {
            return false;
        }

        @chmod($temp, 0600);

        if (!@rename($temp, $path)) {
            return false;
        }
    } catch (\Throwable $error) {
        return false;
    } finally {
        // Unconditional on purpose: after a successful rename the temp file no
        // longer exists and this is a no-op, and on every failure path above it
        // is the cleanup. One place, so a failure branch added later cannot
        // forget it and leave a partial secret on disk.
        @unlink($temp);

        umask($previousUmask);
    }

    @chmod($path, 0600);

    return true;
}

/**
 * RACC-06 escapes 1 and 4: the CLI surface.
 *
 * The parser is pure and intentionally strict. It accepts exactly the two
 * documented tokens in the first argument position, so a web request that
 * carries `--secret=...` as a query parameter is not merely refused later — it
 * has no representation here at all. The SAPI guard in the console is the
 * other half of that.
 *
 * @param string[] $argv
 */
function recovery_secret_cli_command(array $argv): ?string
{
    $flag = $argv[1] ?? '';

    return match ($flag) {
        '--secret' => 'read',
        '--rotate-secret' => 'rotate',
        default => null,
    };
}

/**
 * Run a CLI escape and print its result.
 *
 * Output contract, documented for scripts: the first line is the secret and
 * nothing else, so `php recovery.php --secret` is shell-composable; any
 * advisory text follows on lines that begin with `# `. A failure prints no
 * secret at all and exits non-zero.
 */
function recovery_secret_cli_run(string $command, ?string $installRoot = null): int
{
    $stdout = recovery_secret_cli_stream('STDOUT');
    $stderr = recovery_secret_cli_stream('STDERR');

    $value = $command === 'rotate'
        ? recovery_secret_rotate($installRoot)
        : recovery_secret_ensure($installRoot);

    if ($value === null) {
        $detail = recovery_secret_last_error();
        fwrite($stderr, 'system_updater: cannot read or persist the recovery console secret.'
            . ($detail !== '' ? ' ' . $detail : '')
            . PHP_EOL);

        return 1;
    }

    fwrite($stdout, $value . PHP_EOL);

    if ($command === 'rotate') {
        fwrite($stdout, '# note: SU_RECOVERY_SECRET in config.php, when defined, overrides this value.'
            . PHP_EOL);
    }

    return 0;
}

/**
 * A standard stream, without assuming the CLI constants exist.
 *
 * @return resource
 */
function recovery_secret_cli_stream(string $constant)
{
    if (defined($constant)) {
        return constant($constant);
    }

    return fopen('php://' . strtolower($constant), 'wb');
}

/**
 * RACC-06 escape 2: regenerate, persist and print a new value, retiring the
 * previous one in the same write.
 */
function recovery_secret_rotate(?string $installRoot = null): ?string
{
    $target = recovery_secret_existing_path($installRoot) ?? recovery_secret_writable_path($installRoot);
    if ($target === '') {
        recovery_secret_last_error(recovery_secret_no_location_message($installRoot));

        return null;
    }

    $value = recovery_secret_generate();
    if (!recovery_secret_store($value, $target)) {
        recovery_secret_last_error(recovery_secret_write_failure_message($target));

        return null;
    }

    recovery_secret_log_generation($value);

    return $value;
}

/**
 * The one and only place the value is written to a sink.
 *
 * RACC-06 requires the first generation to be recoverable from the PHP error
 * log by an operator with no shell, so the line carries the *whole* value —
 * a masked line would not be an escape. Every other path is silent: reads,
 * resolutions, refusals, and a failed write log nothing, and no candidate
 * value is ever logged. One line per generation event (initial generation and
 * each rotation), which keeps the last line of the log equal to the current
 * secret.
 */
function recovery_secret_log_generation(string $value): void
{
    error_log(recovery_secret_log_prefix() . ': ' . $value
        . ' (read it again with: php plugins/system_updater/recovery.php --secret)');
}

/**
 * What *is* the secret (RACC-03): the operator pin when the constant is
 * already defined, otherwise the persisted value.
 *
 * This function never loads `config.php`. That is deliberate and load-bearing:
 * branch 2 must answer with a damaged or absent `config.php`, so the pin can
 * only win when the caller already loaded the file — and the only caller
 * allowed to do that is the console's lost-secret fallback
 * ({@see recovery_secret_operator_pin()}).
 */
function recovery_secret_resolve(?string $installRoot = null): ?string
{
    $pin = defined('SU_RECOVERY_SECRET') ? constant('SU_RECOVERY_SECRET') : null;
    if (is_string($pin) && trim($pin) !== '') {
        return trim($pin);
    }

    return recovery_secret_read($installRoot);
}

/**
 * First use: return the secret, generating and persisting it once when the
 * installation has none.
 *
 * A secret is only handed back after it has been persisted. When no writable
 * location exists this returns `null` and nothing else — an in-memory value
 * would look like success while differing on every request, which is the
 * failure RACC-02 calls out explicitly.
 */
function recovery_secret_ensure(?string $installRoot = null): ?string
{
    $existing = recovery_secret_resolve($installRoot);
    if ($existing !== null) {
        return $existing;
    }

    $target = recovery_secret_writable_path($installRoot);
    if ($target === '') {
        recovery_secret_last_error(recovery_secret_no_location_message($installRoot));

        return null;
    }

    $value = recovery_secret_generate();
    if (!recovery_secret_store($value, $target)) {
        recovery_secret_last_error(recovery_secret_write_failure_message($target));

        return null;
    }

    recovery_secret_log_generation($value);

    return $value;
}

/**
 * The value the gate answers against, given the pin this request can already
 * see.
 *
 * One definition of the *order* — the persisted value first, the operator pin
 * only when nothing is persisted — so the gate and the marker's fingerprint
 * check cannot disagree about which credential is current. They differ only in
 * how they learn the pin: the gate may load `config.php` when nothing is
 * persisted (and there is therefore nothing to protect, see
 * {@see recovery_secret_gate()}), while a marker read only trusts what the
 * request has already defined.
 *
 * `null` means "no credential this request can verify", which both callers turn
 * into a refusal rather than into a match.
 *
 * @return array{value: string, source: 'persisted'|'operator_pin'}|null
 */
function recovery_secret_gate_value(?string $installRoot = null): ?array
{
    $persisted = recovery_secret_read($installRoot);
    if ($persisted !== null) {
        return ['value' => $persisted, 'source' => 'persisted'];
    }

    $pin = defined('SU_RECOVERY_SECRET') ? constant('SU_RECOVERY_SECRET') : null;
    if (is_string($pin) && trim($pin) !== '') {
        return ['value' => trim($pin), 'source' => 'operator_pin'];
    }

    return null;
}

/**
 * RACC-04's gate: does this request carry the installation's secret?
 *
 * This is the question the console asks **before** `config.php` is loaded, and
 * it is not the same question as {@see recovery_secret_resolve()}. That one
 * answers "what is the value" and puts the operator pin first; this one answers
 * "does the candidate match" and puts the persisted value first, because the
 * persisted value is core-free and always reachable. Keeping those two orders
 * apart is what lets a wrong secret be refused in every state that has a
 * persisted secret to protect, including when `config.php` is damaged
 * (RACC-01's "a wrong secret never reaches config.php").
 *
 * The order itself lives in {@see recovery_secret_gate_value()} so the marker's
 * fingerprint check consumes the very same answer instead of re-deriving it.
 *
 * The three failure inputs are one code path on purpose: there is no branch for
 * "the parameter is absent" and none for "it is empty". An absent parameter
 * arrives as `''`, `''` runs through the same `hash_equals()` as a 64-hex wrong
 * value, and `hash_equals()` is constant-time over equal-length strings — with a
 * fixed 64-hex secret the length check it performs first carries no information
 * about the value.
 *
 * `source` names the branch that decided, for the suite's benefit: it is never
 * emitted, logged or otherwise exposed, because the branch a request took is
 * exactly the kind of difference RACC-04 forbids an observer from seeing.
 *
 * The lost-secret state is the one documented exception to "no `config.php` on
 * the failing path" (`tasks.md`, "Delta conflict resolved at apply time"): with
 * no persisted value there is nothing left to protect, the include is
 * error-suppressed and `Throwable`-caught, and no body or header changes. That
 * state also generates the secret on first use (RACC-02), which is the only way
 * a fresh installation ever becomes reachable.
 *
 * The include happens only when the pin is not already visible: a request that
 * already loaded `config.php` (the marker read does) would otherwise include it
 * a second time, and `config.php` is free to declare more than constants.
 *
 * @return array{granted: bool, source: 'persisted'|'operator_pin'|'unavailable'}
 */
function recovery_secret_gate(string $candidate, ?string $installRoot = null, ?string $configFile = null): array
{
    $grant = recovery_secret_gate_value($installRoot);

    if ($grant === null) {
        $root = recovery_secret_install_root($installRoot);
        $pinFile = $configFile ?? ($root === '' ? null : $root . '/config.php');
        $pin = $pinFile === null ? null : recovery_secret_operator_pin($pinFile);

        if ($pin !== null) {
            $grant = ['value' => $pin, 'source' => 'operator_pin'];
        } else {
            // First use: nothing has been persisted yet, so nothing can be
            // known about the candidate. Generate, persist and disclose once in
            // the log (RACC-06), then answer the refusal like any other request.
            $persisted = recovery_secret_ensure($installRoot);
            if ($persisted === null) {
                return ['granted' => false, 'source' => 'unavailable'];
            }

            $grant = ['value' => $persisted, 'source' => 'persisted'];
        }
    }

    return [
        'granted' => hash_equals($grant['value'], $candidate),
        'source' => $grant['source'],
    ];
}
