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
 * rules. {@see recovery_policy_evaluate()} therefore encodes the whole order in
 * one place - form token, then ban bucket, then throttle, then credential - and
 * short-circuits: a closed gate returns before the verdict is called, so no
 * later step of the same request can reach credential evaluation. The console
 * still owns the *reads* (W7 wires them); what this file guarantees is that a
 * fact which closed a gate can never be followed by an evaluation.
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
 * else - a rejected token, a banned address, a throttled nick, a success - is
 * explicitly `false`, and the list is exhaustive over the phase vocabulary, so
 * a new phase cannot inherit a count by accident.
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
 * One file per installation: the suffix is a digest of the install root, because
 * `sys_get_temp_dir()` is shared by every installation on the host and two
 * installations sharing a budget would let one operator's failures lock the
 * other out. `FS_TMP_NAME` prefixes it for the same reason the framework uses it
 * - so a suite or a second framework tree can namespace its own state.
 *
 * `$directory` exists for tests and for a deployment that keeps its temp state
 * somewhere else; `null` means the system temp directory.
 */
function recovery_policy_fallback_path(?string $installRoot = null, ?string $directory = null): string
{
    $root = recovery_policy_root($installRoot);
    $directory = ($directory !== null && $directory !== '')
        ? rtrim($directory, '/\\')
        : rtrim(sys_get_temp_dir(), '/');
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
    if ($path === '' || !is_file($path) || !is_readable($path)) {
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
 * @return array{throttled: bool, attempts: int, account_attempts: int}
 */
function recovery_policy_fallback_count(string $path, string $nick, string $ip, int $now): array
{
    return recovery_policy_counter_verdict(recovery_policy_fallback_state($path), $nick, $ip, $now);
}

/**
 * Record one failed attempt in both dimensions.
 *
 * Both, and atomically: an attempt that counted for the address but not for the
 * account would let the account budget be evaded by rotating addresses, which is
 * the case the account dimension exists for.
 */
function recovery_policy_fallback_record(string $path, string $nick, string $ip, int $now): bool
{
    return recovery_policy_fallback_mutate($path, static function (array $state) use ($nick, $ip, $now): array {
        $dimensions = [
            recovery_policy_counter_key($nick, $ip, false) => RECOVERY_POLICY_THROTTLE_WINDOW,
            recovery_policy_counter_key($nick, $ip, true) => RECOVERY_POLICY_ACCOUNT_WINDOW,
        ];

        foreach ($dimensions as $key => $window) {
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
 * The one writer of the counter file: `flock()`ed read-modify-write, pruned.
 *
 * Pruning on every write is what keeps the file bounded without a cron job: an
 * entry past its window carries no information, and leaving it would let a busy
 * host grow this file until the disk answered for it. A `null` clock means the
 * write is not about expiry (a clear) and prunes nothing, so an injected clock
 * never has to be invented for it.
 *
 * A failure (no directory, no permission, a lock that cannot be taken) returns
 * `false` and leaves the previous content alone. The throttle then fails open
 * for that submission - the direction the spec's failure-mode inventory records
 * for the shared throttle too, and strictly better than the shipped console,
 * which counts nothing at all.
 *
 * @param callable(array<string, array{count: int, updated: int}>): array<string, array{count: int, updated: int}> $mutator
 */
function recovery_policy_fallback_mutate(string $path, callable $mutator, ?int $now): bool
{
    if ($path === '') {
        return false;
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        $state = recovery_policy_fallback_decode(stream_get_contents($handle));
        if ($now !== null) {
            $state = recovery_policy_fallback_prune($state, $now);
        }

        $state = $mutator($state);
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return false;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return false;
        }

        $written = fwrite($handle, $encoded . "\n");
        fflush($handle);

        return $written !== false;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
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
 * The console's whole credential decision, in the order the requirements state.
 *
 * Gates, first to last:
 *
 *   1. `token_valid` - REC-01's verdict is authoritative and comes first, so a
 *      cross-site POST can neither evaluate a credential nor burn a budget;
 *   2. `ip_banned` - REC-03's read of the bucket the primary login writes;
 *   3. `throttled` - REC-04's consult of the budget;
 *   4. `$verdict` - the credential itself, called **exactly once** and only when
 *      all three gates passed.
 *
 * The facts are the console's job (W7); this function is the authority for what
 * a closed gate means. A missing `token_valid` key is a rejected token: the
 * console must pass `true` explicitly, never by omission.
 *
 * `$verdict` is `fn (?array $row, string $plain): string`. W7 passes
 * {@see recovery_policy_verify_credentials()} bound with its verifier, which is
 * where REC-05's "exactly one `password_verify()`" is measured; the seam here is
 * what makes REC-02's "no evaluation at all" measurable without a database.
 *
 * @param array{
 *     token_valid?: bool,
 *     ip_banned?: bool,
 *     throttled?: bool,
 *     row?: array<string, mixed>|null,
 *     plain?: string
 * } $facts
 * @param callable(?array<string, mixed>, string): string $verdict
 * @param bool|null $sharedThrottleAvailable `null` detects it for real
 *
 * @return array{
 *     outcome: 'ok'|'deny',
 *     phase: string,
 *     bucket: 'none'|'shared'|'fallback',
 *     record_failure: bool,
 *     clear: bool
 * }
 */
function recovery_policy_evaluate(array $facts, callable $verdict, ?bool $sharedThrottleAvailable = null): array
{
    $shared = $sharedThrottleAvailable ?? recovery_policy_shared_throttle_available();
    $phase = RECOVERY_POLICY_PHASE_TOKEN_REJECTED;

    if (($facts['token_valid'] ?? false) === true) {
        if (($facts['ip_banned'] ?? false) === true) {
            $phase = RECOVERY_POLICY_PHASE_BANNED;
        } elseif (($facts['throttled'] ?? false) === true) {
            $phase = RECOVERY_POLICY_PHASE_THROTTLED;
        } else {
            $row = $facts['row'] ?? null;
            $verdictOutcome = $verdict(is_array($row) ? $row : null, (string) ($facts['plain'] ?? ''));

            $phase = $verdictOutcome === RECOVERY_POLICY_OUTCOME_OK
                ? RECOVERY_POLICY_PHASE_CREDENTIAL_OK
                : RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED;
        }
    }

    return [
        'outcome' => $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK
            ? RECOVERY_POLICY_OUTCOME_OK
            : RECOVERY_POLICY_OUTCOME_DENY,
        'phase' => $phase,
        'bucket' => recovery_policy_count_bucket($phase, $shared),
        'record_failure' => recovery_policy_should_count($phase),
        'clear' => $phase === RECOVERY_POLICY_PHASE_CREDENTIAL_OK,
    ];
}
