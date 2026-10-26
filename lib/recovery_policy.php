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
 * The recovery console's credential policy (REC-02, REC-03, REC-04, REC-05).
 *
 * Decide here, act in the console
 * -------------------------------
 * This file answers questions. It never reads the request, never opens the
 * database, never writes the ban bucket and never touches a session. The
 * console collects the facts (was the form token valid? is this address
 * banned? is this nick throttled? which row, if any?) and asks
 * {@see recovery_policy_evaluate()} what to do. That split is what makes every
 * requirement in this slice falsifiable in a suite that has no database: each
 * answer is a function over injected facts, and "the credential was evaluated"
 * is a call counter on an injected verdict instead of a timing measurement.
 *
 * The order is the requirement
 * ----------------------------
 * REC-02 and REC-03 are ordering requirements wearing the costume of counting
 * rules, and REC-04's budget is a concurrency requirement wearing the costume of
 * a threshold. {@see recovery_policy_evaluate()} therefore encodes the whole
 * order in one place - form token, submission, ban bucket, budget reservation,
 * credential - and short-circuits: a closed gate returns before the verdict is
 * called, so no later step of the same request can reach credential evaluation.
 * The console still owns the *reads* (W7 wires them); what this file guarantees
 * is that a fact which closed a gate can never be followed by an evaluation.
 *
 * Why the budget is reserved and not merely counted
 * -------------------------------------------------
 * A counter read now and incremented later is not a limit. N simultaneous
 * submissions for one nick all read a count below the threshold, all reach
 * `password_verify()`, and the ceiling becomes a function of how many requests
 * an attacker can open - which is precisely the damaged-core state, where this
 * limiter is the only defence. {@see recovery_policy_fallback_reserve()} makes
 * the verdict and the increment one `flock()`ed decision, and
 * {@see recovery_policy_evaluate()} calls it *before* the credential and
 * releases it after a success. The reservation is the only thing in this file
 * that writes, and it writes because a check separated from its act is the
 * defect it exists to remove.
 *
 * The shared mode cannot be fixed here: `LoginThrottle` publishes
 * `isThrottled()` and `recordFailure()` as two calls, and closing that race is a
 * core change this slice is forbidden to make. So the console passes a
 * reservation for the fallback bucket only, and the asymmetry is stated rather
 * than hidden.
 *
 * Dependency contract (RACC-01)
 * -----------------------------
 * Plugin-local and procedural, like `recovery_secret.php` and
 * `recovery_session.php`: no class, no `require`, no Composer symbol, and not
 * one file under `base/`, `src/`, `controller/` or `model/`. The core throttle
 * is detected with `class_exists(..., false)` - autoload **disabled** - so the
 * console can use it when the panel already loaded it in the same process and
 * can never pull it in when the core is damaged. That is the whole difference
 * between REC-04's two modes.
 *
 * One bucket, named once
 * ----------------------
 * {@see recovery_policy_should_count()} and
 * {@see recovery_policy_count_bucket()} are the only places that decide whether
 * a submission counts and where. The pipeline consumes them instead of
 * re-deriving the rule, so "exactly one failure in exactly one counter set,
 * never both" (REC-02) cannot drift from the phase vocabulary.
 */

/** A submission that reached credential evaluation and was accepted. */
const RECOVERY_POLICY_OUTCOME_OK = 'ok';

/** The single failure outcome of REC-05: no reason, no cause, no variant. */
const RECOVERY_POLICY_OUTCOME_DENY = 'deny';

/** The form token was missing, empty or wrong (REC-01, REC-02). */
const RECOVERY_POLICY_PHASE_TOKEN_REJECTED = 'token_rejected';

/**
 * The submission carried no nick or no password (LTI-03, REC-02).
 *
 * A partially filled form is a browser or a script repeating a request, not a
 * guess, and charging it would let an empty POST lock an administrator out. The
 * phase exists so that "we never reached the budget" is a decision this module
 * makes, not a property of the console remembering to validate first.
 */
const RECOVERY_POLICY_PHASE_INPUT_REJECTED = 'input_rejected';

/** The client address is banned in the bucket the primary login writes (REC-03). */
const RECOVERY_POLICY_PHASE_BANNED = 'banned';

/** The nick is over budget for this address or for the account (REC-04). */
const RECOVERY_POLICY_PHASE_THROTTLED = 'throttled';

/** The credential was evaluated and refused (REC-05). */
const RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED = 'credential_failed';

/** The credential was evaluated and accepted (REC-05, REC-06). */
const RECOVERY_POLICY_PHASE_CREDENTIAL_OK = 'credential_ok';

/** No counter may move: the gate closed before evaluation. */
const RECOVERY_POLICY_BUCKET_NONE = 'none';

/** The core throttle the panel login also uses (REC-04, shared mode). */
const RECOVERY_POLICY_BUCKET_SHARED = 'shared';

/** The plugin-local counter (REC-04, damaged-core mode). */
const RECOVERY_POLICY_BUCKET_FALLBACK = 'fallback';

/**
 * Whether the core throttle is usable in this process.
 *
 * `false` as the second argument is the requirement, not a detail: with the
 * autoloader allowed, `class_exists()` would *load* `LoginThrottle` and the
 * three core files it drags in, which is the dependency RACC-01 removes from
 * the console. So "shared mode" means exactly "this process already loaded it",
 * which is the state a healthy framework produces and a damaged one cannot.
 */
function recovery_policy_shared_throttle_available(): bool
{
    return class_exists('FSFramework\\Security\\LoginThrottle', false);
}

/**
 * REC-02: does this phase record a failed attempt?
 *
 * Only a submission that reached credential evaluation and failed. Everything
 * else - a rejected token, a partially filled form, a banned address, a
 * throttled nick, a success - is explicitly `false`, and the list is exhaustive
 * over the phase vocabulary, so a new phase cannot inherit a count by accident.
 */
function recovery_policy_should_count(string $phase): bool
{
    return $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED;
}

/**
 * Which counter a phase acts on.
 *
 * `none` for every phase that closed before evaluation, and otherwise exactly
 * one of `shared` / `fallback`, chosen by whether the core throttle exists in
 * this process. Never both - recording a failure twice would halve the budget
 * an attacker needs (REC-02).
 *
 * A success names a bucket too, and cannot name `none`: REC-04 requires a
 * successful login to clear the budget it was measured against, and a name the
 * caller has to derive for itself is a rule with two spellings. Recording is
 * gated separately, by {@see recovery_policy_should_count()}, so "which
 * mechanism" and "does this count" stay independent answers.
 */
function recovery_policy_count_bucket(string $phase, bool $sharedThrottleAvailable): string
{
    if (!in_array($phase, [RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED, RECOVERY_POLICY_PHASE_CREDENTIAL_OK], true)) {
        return RECOVERY_POLICY_BUCKET_NONE;
    }

    return $sharedThrottleAvailable ? RECOVERY_POLICY_BUCKET_SHARED : RECOVERY_POLICY_BUCKET_FALLBACK;
}

/**
 * Ban threshold of the bucket the primary login writes.
 *
 * Mirrors `fs_ip_filter::MAX_ATTEMPTS`, and the comparison is `>`, not `>=`:
 * six recorded attempts ban, five do not. The drift test reads the core's own
 * constant when the core is present; the plugin needs its own copy because in
 * the damaged-core state there is no class to ask (RACC-01).
 */
const RECOVERY_POLICY_BAN_MAX_ATTEMPTS = 5;

/**
 * Ban window, mirroring `fs_ip_filter::BAN_SECONDS`.
 *
 * The parser never needs it - each line carries its own expiry, and
 * `set_attempt()` is the only writer of that bucket (REC-03 forbids a fourth
 * one) - but a reader that keeps the threshold and forgets the window is
 * answering half the core's policy, so both are declared and both are
 * drift-tested.
 */
const RECOVERY_POLICY_BAN_SECONDS = 600;

/**
 * Failed attempts allowed for one nick from one address inside the window.
 * Mirrors `LoginThrottle::MAX_ATTEMPTS`, which throttles at `>=`.
 */
const RECOVERY_POLICY_MAX_ATTEMPTS = 6;

/** Window of the nick+address budget. Mirrors `LoginThrottle::THROTTLE_WINDOW`. */
const RECOVERY_POLICY_THROTTLE_WINDOW = 600;

/**
 * Failed attempts allowed for one account regardless of address.
 * Mirrors `LoginThrottle::ACCOUNT_MAX_ATTEMPTS`.
 */
const RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS = 20;

/** Window of the account budget. Mirrors `LoginThrottle::ACCOUNT_WINDOW`. */
const RECOVERY_POLICY_ACCOUNT_WINDOW = 900;

/**
 * The single failure body of REC-05.
 *
 * One string for every cause: a wrong password, an unknown nick, an account
 * without administrator rights, a missing form token, a banned address and a
 * throttled nick all render this. Any second spelling would turn the console
 * into an oracle that says which half of the guess was right.
 */
const RECOVERY_POLICY_FAILURE_MESSAGE = 'No se pudo iniciar sesión con esas credenciales.';

/**
 * Appended to a throttled refusal **only** when the core throttle is absent.
 *
 * REC-04's damaged-core scenario asks the message to state that the console is
 * running its local limiter, because that is a deployment fact the operator has
 * to act on. It is deliberately not part of the default state: there the
 * refusal has to be the uniform failure of REC-05, or the console would tell an
 * attacker whether the throttle that stopped them was the panel's.
 */
const RECOVERY_POLICY_LOCAL_LIMITER_NOTE = 'La consola está aplicando su limitador local porque el limitador del núcleo no está disponible.';

/**
 * A bcrypt hash that belongs to no account.
 *
 * REC-05 requires the unknown-nick path to perform the same work as the
 * wrong-password path, so it must verify against *something* — and that
 * something is a constant, because computing a hash per request would move a
 * cost into the failure path and make the two cases distinguishable again.
 *
 * The plaintext was random bytes that were never stored, so no submission can
 * ever match it: it grants nothing and it is not a backdoor. Its cost is 10,
 * the default a real row carries, so the work is comparable. A deployment that
 * hashes at a higher cost makes this path *faster* than a real row, which is the
 * safe direction (it cannot make a missing user look slower).
 */
const RECOVERY_POLICY_DUMMY_HASH = '$2y$10$IW7bu5q.eqUlBMIyx9gFo.QLtTpa5fMhmjRV0kmduPYQxvCpn1fOS';

/**
 * The installation root this library reasons about.
 *
 * `FS_FOLDER` when the installation defined it, otherwise the tree this file
 * sits in (`lib/` → `plugins/` → `<root>`), verified the same way
 * `recovery_session_root()` verifies it. An explicit root always wins, which is
 * what lets every test work on a throwaway install root instead of the real one.
 */
function recovery_policy_root(?string $installRoot = null): string
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

    return rtrim(dirname(__DIR__, 3), '/\\');
}

/**
 * The bucket the primary login reads and writes: `tmp/<FS_TMP_NAME>ip.log`.
 *
 * Same directory, same prefix and same file name as `fs_ip_filter`'s
 * constructor, because reading a different file would be exactly the parallel
 * bucket RACC-01 forbids. `FS_TMP_NAME` comes from `config.php`, so no core file
 * has to be loaded to build this path.
 */
function recovery_policy_ip_log_path(?string $installRoot = null): string
{
    $root = recovery_policy_root($installRoot);
    if ($root === '') {
        return '';
    }

    return $root . '/tmp/' . (defined('FS_TMP_NAME') ? (string) FS_TMP_NAME : '') . 'ip.log';
}

/**
 * Read the bucket as raw lines. Never writes, never creates.
 *
 * An absent, unreadable or directory path is an empty list, which the parser
 * turns into "not banned" - the same answer the core gives when its
 * `file_exists()` check fails.
 *
 * @return list<string>
 */
function recovery_policy_read_ban_lines(string $path): array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $lines = [];

    try {
        while (!feof($handle)) {
            $raw = fgets($handle);
            if ($raw === false) {
                break;
            }
            $lines[] = trim($raw);
        }
    } finally {
        fclose($handle);
    }

    return $lines;
}

/**
 * `fs_ip_filter::read_line()`, line for line.
 *
 *   - a line is considered only when it splits into **three** fields;
 *   - its expiry is `intval()`-cast and compared with `$now`; a non-numeric
 *     expiry becomes `0` and is dropped, exactly as the core drops it;
 *   - the count is cast with `(int)`, so a non-numeric count becomes `0` and
 *     bans nobody.
 *
 * The one deliberate difference is the clock: the core calls `time()` per line,
 * this takes one `$now` for the whole file. Within a single read the two only
 * differ on a line expiring mid-loop, which is not a behaviour worth
 * reproducing and not one an operator can observe.
 *
 * @param array<array-key, mixed> $lines
 *
 * @return list<array{ip: string, count: int, expire: int}>
 */
function recovery_policy_parse_ban_lines(array $lines, int $now): array
{
    $entries = [];

    foreach ($lines as $line) {
        if (!is_string($line)) {
            continue;
        }

        $fields = explode(';', trim($line));
        if (count($fields) !== 3) {
            continue;
        }

        $expire = (int) $fields[2];
        if ($expire <= $now) {
            continue;
        }

        $entries[] = [
            'ip' => $fields[0],
            'count' => (int) $fields[1],
            'expire' => $expire,
        ];
    }

    return $entries;
}

/**
 * `fs_ip_filter::is_banned()`, over lines instead of a file.
 *
 * The comparison keeps the core's loose `==` on the address and its strict `>`
 * on the count. A stricter (or laxer) rule here would be a second ban policy,
 * which is precisely the drift REC-03 accepts only under a drift test - and a
 * drifted *rule* is not something a fixture can catch, so the rule is copied
 * verbatim instead.
 *
 * @param array<array-key, mixed> $lines
 */
function recovery_policy_is_banned(array $lines, string $ip, int $now): bool
{
    foreach (recovery_policy_parse_ban_lines($lines, $now) as $entry) {
        if ($entry['ip'] == $ip && $entry['count'] > RECOVERY_POLICY_BAN_MAX_ATTEMPTS) {
            return true;
        }
    }

    return false;
}

/**
 * REC-03's whole gate as the console calls it: is this address banned?
 *
 * Reads the bucket the primary login writes and answers the core's verdict.
 * `$now` is injectable so the expiry cases are decided, not slept through.
 */
function recovery_policy_is_ip_banned(string $ip, ?string $installRoot = null, ?int $now = null): bool
{
    return recovery_policy_is_banned(
        recovery_policy_read_ban_lines(recovery_policy_ip_log_path($installRoot)),
        $ip,
        $now ?? time()
    );
}

/**
 * Counter key for a nick+address pair, and for an account.
 *
 * The derivation copies `LoginThrottle::cacheKey()` / `::accountCacheKey()`
 * (lower-cased nick, then `md5`) so the two modes count the same subjects, but
 * the keys are only ever read by this file: the shared mode never sees them and
 * the fallback mode never sees the cache.
 */
function recovery_policy_counter_key(string $nick, string $ip, bool $account): string
{
    $nick = strtolower($nick);

    return $account
        ? 'account_' . md5($nick)
        : 'pair_' . md5($nick . '|' . $ip);
}

/**
 * The local counter's file.
 *
 * One file per installation, inside the installation: `<root>/tmp/` is a
 * directory the installer owns, already exists (the framework creates it for
 * `ip.log`), and needs nothing from the core to be used. The alternative -
 * `sys_get_temp_dir()` - is world-writable, and with a name derived only from
 * `FS_TMP_NAME` and the install root it is predictable: another local user could
 * pre-create it `0666` and then delete the counts (disabling the limiter),
 * inflate them (locking the administrator out), or plant a symlink for the next
 * write to follow. A counter in a directory the attacker can write to is not a
 * counter. `FS_TMP_NAME` still prefixes the name, so a suite or a second
 * framework tree can namespace its own state, and the digest of the install root
 * still distinguishes two installations that share a document root.
 *
 * `$directory` exists for tests and for a deployment that keeps its writable
 * state elsewhere; `null` means "the installation's own tmp".
 */
function recovery_policy_fallback_path(?string $installRoot = null, ?string $directory = null): string
{
    $root = recovery_policy_root($installRoot);
    $directory = ($directory !== null && $directory !== '')
        ? rtrim($directory, '/\\')
        : $root . '/tmp';
    $prefix = defined('FS_TMP_NAME') ? (string) FS_TMP_NAME : '';

    return $directory . '/' . $prefix . 'system_updater_recovery_throttle_'
        . substr(sha1($root), 0, 12) . '.json';
}

/**
 * Read the counter file. Never creates it, never rewrites it.
 *
 * A missing, unreadable or malformed file is an empty counter, which throttles
 * nobody: the same direction the core takes when its cache is unavailable
 * (`LoginThrottle` is fail-open by design, and the spec's failure-mode inventory
 * keeps that direction here rather than inventing a stricter door).
 *
 * @return array<string, array{count: int, updated: int}>
 */
function recovery_policy_fallback_state(string $path): array
{
    if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
        return [];
    }

    return recovery_policy_fallback_decode((string) @file_get_contents($path));
}

/**
 * Decode and validate the counter file's contents.
 *
 * Shared by the read path and the writer, so a hand-edited file can only remove
 * counts: every entry has to be a string-keyed array of two numerics.
 *
 * @return array<string, array{count: int, updated: int}>
 */
function recovery_policy_fallback_decode(string|false $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $state = [];

    foreach ($decoded as $key => $entry) {
        if (!is_string($key) || $key === '' || !is_array($entry)) {
            continue;
        }

        if (!isset($entry['count'], $entry['updated'])
            || !is_numeric($entry['count'])
            || !is_numeric($entry['updated'])
        ) {
            continue;
        }

        $state[$key] = ['count' => (int) $entry['count'], 'updated' => (int) $entry['updated']];
    }

    return $state;
}

/**
 * How many attempts a single key still counts at `$now`.
 *
 * The window comes from the declared constant, never from the file: a tampered
 * or stale entry cannot widen its own budget. An entry stamped in the future
 * keeps counting (clock skew must not launder attempts).
 *
 * @param array<string, array{count: int, updated: int}> $state
 */
function recovery_policy_counter_entry(array $state, string $key, int $now, int $window): int
{
    $entry = $state[$key] ?? null;
    if (!is_array($entry)) {
        return 0;
    }

    $count = (int) ($entry['count'] ?? 0);
    if ($count <= 0) {
        return 0;
    }

    $updated = (int) ($entry['updated'] ?? 0);
    if ($now - $updated >= $window) {
        return 0;
    }

    return $count;
}

/**
 * REC-04's verdict, purely: how many attempts remain counted, and is it over.
 *
 * Two dimensions, because the nick+address counter alone allows guessing one
 * account by rotating addresses. Throttled when either dimension is at its
 * threshold - `>=`, matching `LoginThrottle::isThrottled()`.
 *
 * @param array<string, array{count: int, updated: int}> $state
 *
 * @return array{throttled: bool, attempts: int, account_attempts: int}
 */
function recovery_policy_counter_verdict(array $state, string $nick, string $ip, int $now): array
{
    $attempts = recovery_policy_counter_entry(
        $state,
        recovery_policy_counter_key($nick, $ip, false),
        $now,
        RECOVERY_POLICY_THROTTLE_WINDOW
    );
    $accountAttempts = recovery_policy_counter_entry(
        $state,
        recovery_policy_counter_key($nick, $ip, true),
        $now,
        RECOVERY_POLICY_ACCOUNT_WINDOW
    );

    return [
        'throttled' => $attempts >= RECOVERY_POLICY_MAX_ATTEMPTS
            || $accountAttempts >= RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS,
        'attempts' => $attempts,
        'account_attempts' => $accountAttempts,
    ];
}

/**
 * Consult the local budget for one submission.
 *
 * Read-only: a consult must not create the file, because a request that was
 * refused before evaluation (REC-02) has to leave the filesystem untouched.
 *
 * **A consult is not a limit.** Reading here and incrementing in
 * {@see recovery_policy_fallback_record()} is two `flock()`s, and N submissions
 * that read in between them all pass. Anything that gates a credential must use
 * {@see recovery_policy_fallback_reserve()}, which decides and increments under
 * one lock. This function remains for what it honestly is: an observation, and
 * the read the console uses to render "how many attempts are left".
 *
 * @return array{throttled: bool, attempts: int, account_attempts: int}
 */
function recovery_policy_fallback_count(string $path, string $nick, string $ip, int $now): array
{
    return recovery_policy_counter_verdict(recovery_policy_fallback_state($path), $nick, $ip, $now);
}

/**
 * REC-04's budget as one indivisible decision.
 *
 * Under a single `flock()`: read the counter, prune it, ask
 * {@see recovery_policy_counter_verdict()} for the verdict and - only when the
 * verdict admits the submission - increment both dimensions before releasing the
 * lock. Nothing can be interleaved between the question and the answer, which is
 * what makes the ceiling a property of the file rather than of the attacker's
 * connection pool.
 *
 * `throttled` answers "was **this** submission refused", not "is the budget now
 * closed": the sixth reservation is admitted and reports six, and the seventh is
 * the one that is refused. `attempts` / `account_attempts` are the counts after
 * an admission and the counts as found on a refusal.
 *
 * A counter that cannot be opened or locked answers `reserved` and `throttled`
 * both `false`: the submission proceeds and the console still owes a count that
 * will also fail. That is the fail-open direction `count()` takes when it cannot
 * read, the direction `LoginThrottle` takes when its cache is unavailable, and
 * the direction this console must take rather than locking every operator out of
 * a damaged install.
 *
 * @return array{reserved: bool, throttled: bool, attempts: int, account_attempts: int}
 */
function recovery_policy_fallback_reserve(string $path, string $nick, string $ip, int $now): array
{
    $result = recovery_policy_fallback_transact(
        $path,
        $now,
        static function (array $state) use ($nick, $ip, $now): array {
            $verdict = recovery_policy_counter_verdict($state, $nick, $ip, $now);
            if ($verdict['throttled']) {
                return [$state, false];
            }

            foreach (recovery_policy_counter_dimensions($nick, $ip) as $key => $window) {
                $state[$key] = [
                    'count' => recovery_policy_counter_entry($state, $key, $now, $window) + 1,
                    'updated' => $now,
                ];
            }

            return [$state, true];
        }
    );

    if ($result === null) {
        return ['reserved' => false, 'throttled' => false, 'attempts' => 0, 'account_attempts' => 0];
    }

    $verdict = recovery_policy_counter_verdict($result['state'], $nick, $ip, $now);

    return [
        'reserved' => $result['written'],
        'throttled' => !$result['written'],
        'attempts' => $verdict['attempts'],
        'account_attempts' => $verdict['account_attempts'],
    ];
}

/**
 * The two dimensions REC-04 counts, and the window each one is measured over.
 *
 * Declared once so the reservation and the plain increment cannot disagree about
 * which dimensions exist - an attempt that counted for the address but not for
 * the account would let the account budget be evaded by rotating addresses,
 * which is the case that dimension exists for.
 *
 * @return array<string, int> counter key => window in seconds
 */
function recovery_policy_counter_dimensions(string $nick, string $ip): array
{
    return [
        recovery_policy_counter_key($nick, $ip, false) => RECOVERY_POLICY_THROTTLE_WINDOW,
        recovery_policy_counter_key($nick, $ip, true) => RECOVERY_POLICY_ACCOUNT_WINDOW,
    ];
}

/**
 * Record one failed attempt in both dimensions.
 *
 * Both, and atomically: an attempt that counted for the address but not for the
 * account would let the account budget be evaded by rotating addresses, which is
 * the case the account dimension exists for.
 *
 * Unconditional, unlike {@see recovery_policy_fallback_reserve()}: this is the
 * write that follows a decision already taken. A caller that has not taken one
 * must reserve instead.
 */
function recovery_policy_fallback_record(string $path, string $nick, string $ip, int $now): bool
{
    return recovery_policy_fallback_mutate($path, static function (array $state) use ($nick, $ip, $now): array {
        foreach (recovery_policy_counter_dimensions($nick, $ip) as $key => $window) {
            $state[$key] = [
                'count' => recovery_policy_counter_entry($state, $key, $now, $window) + 1,
                'updated' => $now,
            ];
        }

        return $state;
    }, $now);
}

/**
 * REC-04's "success clears the budget": drop both dimensions for this subject.
 *
 * This is the release of a reservation too. {@see recovery_policy_evaluate()}
 * calls the `$release` seam only when it charged the budget for the submission
 * and the credential was accepted, so a legitimate login can never leave its own
 * attempt behind. Clearing is deliberately stronger than undoing the increment:
 * a zeroed budget is what REC-04 asks for, and subtracting one would let a
 * failed-then-succeeded pattern keep someone else's count alive.
 */
function recovery_policy_fallback_clear(string $path, string $nick, string $ip): bool
{
    return recovery_policy_fallback_mutate($path, static function (array $state) use ($nick, $ip): array {
        unset(
            $state[recovery_policy_counter_key($nick, $ip, false)],
            $state[recovery_policy_counter_key($nick, $ip, true)]
        );

        return $state;
    }, null);
}

/**
 * Open the counter file without following a link and without trusting its mode.
 *
 * Two properties, and the second is why this is not just `fopen()`:
 *
 *   1. **The descriptor must be the regular file the path names.** A symlink is
 *      refused before the open, and the pair `fstat()` / `lstat()` closes the
 *      window between that check and the descriptor actually existing - which is
 *      what `O_NOFOLLOW` would do and PHP exposes no flag for. Following a
 *      planted link would let whoever created it choose where the write lands.
 *   2. **The file must not be readable by anyone else.** A counter holds one
 *      installation's attempt counts, and a pre-created `0666` file is the other
 *      half of the shared-directory attack: the counts could be read to time a
 *      guess, or left world-writable to be edited between requests. The mode is
 *      narrowed on every open that finds it loose, so the file heals instead of
 *      being trusted.
 *
 * @return resource|false
 */
function recovery_policy_fallback_open(string $path)
{
    if ($path === '' || is_link($path)) {
        return false;
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    $stat = fstat($handle);
    $named = @lstat($path);

    $regular = is_array($stat) && (($stat['mode'] & 0170000) === 0100000);
    $sameFile = is_array($stat) && is_array($named)
        && $named['dev'] === $stat['dev']
        && $named['ino'] === $stat['ino'];

    if (!$regular || !$sameFile) {
        fclose($handle);

        return false;
    }

    if (($stat['mode'] & 0077) !== 0) {
        // Heal a loose mode rather than refuse to count: the counters inside are
        // still this installation's, and refusing would disable the limiter for
        // exactly the deployment that needs it.
        @chmod($path, 0600);
    }

    return $handle;
}

/**
 * The one place that opens the counter file, takes its lock and rewrites it.
 *
 * Pruning on every write is what keeps the file bounded without a cron job: an
 * entry past its window carries no information, and leaving it would let a busy
 * host grow this file until the disk answered for it. A `null` clock means the
 * write is not about expiry (a clear) and prunes nothing, so an injected clock
 * never has to be invented for it.
 *
 * The decision belongs to `$transact`, which runs **inside** the lock: it
 * receives the pruned state and answers `[state, writeIt]`. That shape is what
 * lets a refusal be a refusal without a write, and it is why the reservation and
 * the plain increment share one implementation instead of two locks that could
 * be interleaved.
 *
 * A failure (no directory, no permission, a link, a lock that cannot be taken,
 * an encoding error) returns `null` and leaves the previous content alone.
 *
 * @param callable(array<string, array{count: int, updated: int}>): array{0: array<string, array{count: int, updated: int}>, 1: bool} $transact
 *
 * @return array{state: array<string, array{count: int, updated: int}>, written: bool}|null
 */
function recovery_policy_fallback_transact(string $path, ?int $now, callable $transact): ?array
{
    $handle = recovery_policy_fallback_open($path);
    if ($handle === false) {
        return null;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return null;
        }

        $state = recovery_policy_fallback_decode(stream_get_contents($handle));
        if ($now !== null) {
            $state = recovery_policy_fallback_prune($state, $now);
        }

        [$state, $write] = $transact($state);

        if (!$write) {
            return ['state' => $state, 'written' => false];
        }

        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return null;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return null;
        }

        $written = fwrite($handle, $encoded . "\n");
        fflush($handle);

        if ($written === false) {
            return null;
        }

        return ['state' => $state, 'written' => true];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * {@see recovery_policy_fallback_transact()} for the callers that always write.
 *
 * @param callable(array<string, array{count: int, updated: int}>): array<string, array{count: int, updated: int}> $mutator
 */
function recovery_policy_fallback_mutate(string $path, callable $mutator, ?int $now): bool
{
    $result = recovery_policy_fallback_transact(
        $path,
        $now,
        static fn (array $state): array => [$mutator($state), true]
    );

    return $result !== null && $result['written'];
}

/**
 * Drop every entry that no longer counts.
 *
 * @param array<string, array{count: int, updated: int}> $state
 *
 * @return array<string, array{count: int, updated: int}>
 */
function recovery_policy_fallback_prune(array $state, int $now): array
{
    $pruned = [];

    foreach ($state as $key => $entry) {
        $window = str_starts_with($key, 'account_')
            ? RECOVERY_POLICY_ACCOUNT_WINDOW
            : RECOVERY_POLICY_THROTTLE_WINDOW;

        if (recovery_policy_counter_entry($state, $key, $now, $window) > 0) {
            $pruned[$key] = $entry;
        }
    }

    return $pruned;
}

/**
 * Whether a `fs_users.admin` value means "administrator".
 *
 * The flag arrives in whatever shape the reader's driver produces: `1` / `0`
 * from MySQL, `'1'` / `'0'` through some paths, `true` / `false` from a PHP
 * array literal, `'t'` / `'f'` from PostgreSQL. A strict `=== true` would deny
 * every PostgreSQL administrator - the same class of regression AUTH-06 was
 * about - so the accepted shapes are listed once, here, and everything else
 * denies.
 *
 * @param array<string, mixed> $row
 */
function recovery_policy_row_is_admin(array $row): bool
{
    $flag = $row['admin'] ?? null;

    if (is_bool($flag)) {
        return $flag;
    }

    if (is_int($flag)) {
        return $flag === 1;
    }

    if (is_string($flag)) {
        return in_array(strtolower(trim($flag)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    return false;
}

/**
 * REC-05's verdict: one outcome token, one verification, no reason.
 *
 * The order inside is the requirement:
 *
 *   1. a row with no stored password is treated as "no such account", so it
 *      verifies against {@see RECOVERY_POLICY_DUMMY_HASH} and costs the same as
 *      an unknown nick;
 *   2. the verifier is called **exactly once**, whoever the caller is and
 *      whatever the answer - the seam W7 binds with `password_verify()` for
 *      modern hashes and with W6's bridge for legacy ones;
 *   3. only then is the administrator flag read, which is why a correct password
 *      on a non-administrator account is indistinguishable from a wrong one.
 *
 * A verifier must answer `true`; anything else is a denial, so a bridge that
 * returns a truthy value by accident cannot authenticate.
 *
 * A legacy digest reaches the verifier unchanged: substituting the dummy for a
 * hash the modern path does not recognize would lock out exactly the
 * administrator REC-07 exists for.
 *
 * @param array<string, mixed>|null $row
 * @param callable(string, string): bool $verifier `(storedHash, plain)`
 */
function recovery_policy_verify_credentials(?array $row, string $plain, callable $verifier): string
{
    $stored = is_array($row) ? ($row['password'] ?? null) : null;
    $hasStoredCredential = is_string($stored) && $stored !== '';

    $verified = ($verifier)(
        $hasStoredCredential ? $stored : RECOVERY_POLICY_DUMMY_HASH,
        $plain
    ) === true;

    if (!$hasStoredCredential || !$verified) {
        return RECOVERY_POLICY_OUTCOME_DENY;
    }

    return recovery_policy_row_is_admin($row) ? RECOVERY_POLICY_OUTCOME_OK : RECOVERY_POLICY_OUTCOME_DENY;
}

/**
 * The body a phase renders when it refuses.
 *
 * One message for every refusal, with the single documented exception of a
 * throttled submission while the local limiter is in force. `null` for the mode
 * detects it, exactly like {@see recovery_policy_evaluate()}.
 */
function recovery_policy_failure_message(string $phase, ?bool $sharedThrottleAvailable = null): string
{
    if ($phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK) {
        return '';
    }

    if ($phase === RECOVERY_POLICY_PHASE_THROTTLED) {
        $shared = $sharedThrottleAvailable ?? recovery_policy_shared_throttle_available();

        if (!$shared) {
            return RECOVERY_POLICY_FAILURE_MESSAGE . ' ' . RECOVERY_POLICY_LOCAL_LIMITER_NOTE;
        }
    }

    return RECOVERY_POLICY_FAILURE_MESSAGE;
}

/**
 * Whether a submission carries something to evaluate at all (LTI-03, REC-02).
 *
 * Two sources, and the second is why a caller cannot lose the rule by
 * forgetting: `input_valid` lets the console add its own criteria, and an empty
 * nick or an empty password closes the gate on its own, because an empty form is
 * a repeat, never a guess.
 *
 * @param array<string, mixed> $facts
 */
function recovery_policy_input_is_valid(array $facts): bool
{
    if (($facts['input_valid'] ?? true) !== true) {
        return false;
    }

    $nick = $facts['nick'] ?? null;
    $plain = $facts['plain'] ?? null;

    return is_string($nick) && $nick !== '' && is_string($plain) && $plain !== '';
}

/**
 * The gates that close before any budget is consulted, in their order.
 *
 *   1. REC-01's token verdict - authoritative and first, so a cross-site POST
 *      can neither evaluate a credential nor burn a budget;
 *   2. the submission itself - an empty form counts nothing (LTI-03);
 *   3. REC-03's ban read.
 *
 * `null` means "proceed". Everything before a reservation is here, so the
 * reservation cannot happen after a gate that has already closed, and every
 * phase this function can return is one that {@see recovery_policy_should_count()}
 * refuses by name.
 *
 * @param array<string, mixed> $facts
 */
function recovery_policy_precheck(array $facts): ?string
{
    if (($facts['token_valid'] ?? false) !== true) {
        return RECOVERY_POLICY_PHASE_TOKEN_REJECTED;
    }

    if (!recovery_policy_input_is_valid($facts)) {
        return RECOVERY_POLICY_PHASE_INPUT_REJECTED;
    }

    if (($facts['ip_banned'] ?? false) === true) {
        return RECOVERY_POLICY_PHASE_BANNED;
    }

    return null;
}

/**
 * The console's whole credential decision, in the order the requirements state.
 *
 * Steps, first to last, every one of them a boundary between two kinds of work:
 *
 *   1. **token verdict** - {@see recovery_policy_precheck()} (REC-01). A
 *      rejected token records nothing and evaluates nothing (REC-02);
 *   2. **submission** - the same ladder, so an empty nick or password never
 *      reaches the budget (LTI-03);
 *   3. **ban check** - REC-03's read of the bucket the primary login writes,
 *      still read-only and still before any counter moves;
 *   4. **reservation** - when the console supplies a `$reserve`, this is where
 *      the budget is charged, atomically, *before* the credential: the decision
 *      and the increment are one `flock()` (REC-04). A refusal answers
 *      `throttled` and the verdict is never reached. When no `$reserve` is
 *      supplied - the shared mode, whose core API is check-then-act - the
 *      `throttled` fact is honoured instead, exactly as before;
 *   5. **credential** - the verdict, called exactly once (REC-05);
 *   6. **release** - a success calls `$release` when a reservation stands, so a
 *      legitimate login leaves no charge behind (REC-04's success-clear).
 *
 * The facts are the console's job (W7); this function is the authority for what
 * a closed gate means and for the order in which the gates are consulted. A
 * missing `token_valid` key is a rejected token: the console must pass `true`
 * explicitly, never by omission.
 *
 * `$verdict` is `fn (?array $row, string $plain): string`. W7 passes
 * {@see recovery_policy_verify_credentials()} bound with its verifier, which is
 * where REC-05's "exactly one `password_verify()`" is measured; the seam here is
 * what makes REC-02's "no evaluation at all" measurable without a database.
 *
 * `$reserve` is `fn (string $nick, string $ip, int $now): array` answering the
 * shape of {@see recovery_policy_fallback_reserve()}, and `$release` is
 * `fn (string $nick, string $ip): bool` bound to
 * {@see recovery_policy_fallback_clear()}. Both are optional and both are only
 * ever called after every precheck gate passed.
 *
 * Returned flags:
 *
 *   - `record_failure` - the console still owes a count. `false` once the
 *     pipeline charged it itself, so a reservation is never counted twice;
 *   - `clear` - REC-04's success-clear of the bucket the submission was measured
 *     against. When `reserved` is true the `$release` seam has already applied
 *     it for the fallback bucket, so a second clear is a harmless no-op there;
 *     the shared bucket still needs `LoginThrottle::clear()`;
 *   - `reserved` - this submission was charged atomically by the pipeline.
 *
 * @param array{
 *     token_valid?: bool,
 *     input_valid?: bool,
 *     ip_banned?: bool,
 *     throttled?: bool,
 *     nick?: string,
 *     ip?: string,
 *     now?: int,
 *     row?: array<string, mixed>|null,
 *     plain?: string
 * } $facts
 * @param callable(?array<string, mixed>, string): string $verdict
 * @param bool|null $sharedThrottleAvailable `null` detects it for real
 * @param callable(string, string, int): array{reserved: bool, throttled: bool}|null $reserve
 * @param callable(string, string): bool|null $release
 *
 * @return array{
 *     outcome: 'ok'|'deny',
 *     phase: string,
 *     bucket: 'none'|'shared'|'fallback',
 *     record_failure: bool,
 *     clear: bool,
 *     reserved: bool
 * }
 */
function recovery_policy_evaluate(
    array $facts,
    callable $verdict,
    ?bool $sharedThrottleAvailable = null,
    ?callable $reserve = null,
    ?callable $release = null
): array {
    $shared = $sharedThrottleAvailable ?? recovery_policy_shared_throttle_available();
    $nick = is_string($facts['nick'] ?? null) ? $facts['nick'] : '';
    $ip = is_string($facts['ip'] ?? null) ? $facts['ip'] : '';
    $now = is_int($facts['now'] ?? null) ? $facts['now'] : time();
    $reserved = false;

    $phase = recovery_policy_precheck($facts) ?? '';

    if ($phase === '') {
        if ($reserve !== null) {
            // The reservation is the budget: a stale `throttled` read must not be
            // able to skip it, and a fresh one must not be able to double it.
            $budget = $reserve($nick, $ip, $now);
            $reserved = ($budget['reserved'] ?? false) === true;

            if (($budget['throttled'] ?? false) === true) {
                $phase = RECOVERY_POLICY_PHASE_THROTTLED;
            }
        } elseif (($facts['throttled'] ?? false) === true) {
            $phase = RECOVERY_POLICY_PHASE_THROTTLED;
        }
    }

    if ($phase === '') {
        $row = $facts['row'] ?? null;
        $verdictOutcome = $verdict(is_array($row) ? $row : null, (string) ($facts['plain'] ?? ''));

        $phase = $verdictOutcome === RECOVERY_POLICY_OUTCOME_OK
            ? RECOVERY_POLICY_PHASE_CREDENTIAL_OK
            : RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED;

        if ($reserved && $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK && $release !== null) {
            $release($nick, $ip);
        }
    }

    return [
        'outcome' => $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK
            ? RECOVERY_POLICY_OUTCOME_OK
            : RECOVERY_POLICY_OUTCOME_DENY,
        'phase' => $phase,
        'bucket' => recovery_policy_count_bucket($phase, $shared),
        'record_failure' => recovery_policy_should_count($phase) && !$reserved,
        'clear' => $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK,
        'reserved' => $reserved,
    ];
}
