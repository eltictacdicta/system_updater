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

require_once __DIR__ . '/recovery_policy.php';
require_once __DIR__ . '/recovery_legacy_hash.php';
require_once __DIR__ . '/recovery_login_token.php';

/**
 * The console's credential handler: the wiring, and the order of its reads
 * (REC-01, REC-02, REC-03, REC-05, REC-06).
 *
 * Two halves of one contract
 * --------------------------
 * `lib/recovery_policy.php` decides *what a closed gate means* and in which
 * order the gates are consulted, over injected facts. It deliberately does not
 * read the request. This file is the other half: it performs the reads, in the
 * order the requirements state, and it is the only place that knows a
 * submission has a nick, an address and a session behind it.
 *
 * Why the order is code and not prose
 * -----------------------------------
 * "The ban gate is read before any credential is evaluated" is not provable by
 * observing the answer: a handler that looks the row up first and refuses
 * second returns the same uniform body. The difference is *what work happened*,
 * and the only honest way to assert it is to make every read an injected
 * callable and let the test count them. So this module owns no I/O at all — the
 * console hands it a lookup, a ban read, a reservation and the two accounting
 * writers — and a test can therefore watch a rejected token reach nothing but
 * the token verdict.
 *
 * The one thing it does not take as a seam is the form token. REC-01 makes that
 * verdict authoritative and first, so it is asked through the real
 * {@see recovery_login_token_validate()}; a fake there would let a test prove an
 * order that production does not have.
 *
 * The reservation is the policy's, not this file's
 * --------------------------------
 * A submission is charged exactly once, by whichever mechanism the process can
 * actually use: `LoginThrottle` when something already loaded it, the
 * plugin-local counter otherwise (REC-04). Both are expressed as seams, so the
 * shared bucket keeps the core's two calls and the fallback bucket keeps the
 * atomic reservation W5 built, and this file never branches on the *rule* — it
 * branches on which seam exists.
 *
 * Dependency contract (RACC-01)
 * -----------------------------
 * Procedural and plugin-local, like `recovery_policy.php`, `recovery_secret.php`
 * and `recovery_session.php`: no class, no namespace of its own, no Composer
 * symbol. The core throttle is detected with `class_exists(..., false)` —
 * autoload disabled — so the console can use it when the panel already loaded
 * it in the same process and can never pull it in when the core is damaged.
 */

/** A read the handler performed, in the order it happened (REC-01, REC-03). */
const RECOVERY_HANDLER_STEP_TOKEN = 'token';

/** The ban bucket was read (REC-03). */
const RECOVERY_HANDLER_STEP_BAN = 'ban';

/** The shared throttle was consulted (REC-04, shared mode). */
const RECOVERY_HANDLER_STEP_THROTTLE = 'throttle';

/** The budget was charged atomically (REC-04, fallback mode). */
const RECOVERY_HANDLER_STEP_RESERVE = 'reserve';

/** The account row was read. */
const RECOVERY_HANDLER_STEP_LOOKUP = 'lookup';

/** The credential was evaluated (REC-05, REC-06: at most once). */
const RECOVERY_HANDLER_STEP_VERIFY = 'verify';

/** A failed attempt was written into a counter (REC-02). */
const RECOVERY_HANDLER_STEP_RECORD = 'record';

/** A successful login released its budget (REC-04). */
const RECOVERY_HANDLER_STEP_CLEAR = 'clear';

/**
 * The client address the ban gate and the budget are keyed by.
 *
 * `REMOTE_ADDR` and nothing else. The core's `fs_ip_filter` and `LoginThrottle`
 * both read exactly this value, so honouring a forwarding header here would put
 * the console into a *different* bucket than the panel — the parallel-security-
 * state mistake RACC-01 forbids in the opposite direction. A request with no
 * usable address answers the empty string, which keys a bucket no real line
 * names: it never bans and it never shares a counter with a real client.
 *
 * @param array<string, mixed> $server
 */
function recovery_handler_client_ip(array $server): string
{
    $ip = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])
        ? trim($server['REMOTE_ADDR'])
        : '';

    return filter_var($ip, FILTER_VALIDATE_IP) === false ? '' : $ip;
}

/**
 * The credential decision for one submission, in the required order.
 *
 * Steps, and each one is also a step this function reports:
 *
 *   1. **token** — {@see recovery_login_token_validate()} against the session
 *      that minted it. Nothing else is read when it answers `false` (REC-01);
 *      the precheck also closes an empty submission here, because an empty form
 *      is a repeat and must not cost a ban read either;
 *   2. **ban** — read-only, from the file the primary login writes (REC-03).
 *      Skipped entirely when step 1 closed the request;
 *   3. **throttle** — shared mode only: `LoginThrottle`'s check. In fallback
 *      mode there is nothing to pre-read, because step 4 decides and charges
 *      under one lock;
 *   4. **reserve** — fallback mode only: the atomic charge (REC-04). A refusal
 *      ends the request here and the verdict is never reached;
 *   5. **lookup**, then **verify** — the row is read inside the verdict, so it
 *      can only happen after every gate passed, and
 *      {@see recovery_policy_verify_credentials()} is called **exactly once**
 *      with W6's bridge bound to that row's salt (REC-05, REC-07);
 *   6. **record** / **clear** — only when the policy says so. A submission the
 *      pipeline already charged is never counted twice.
 *
 * `$seams` (all optional, all plugin-local callables):
 *
 *   - `lookup(nick): array|null` — the account row;
 *   - `verifier(stored, plain, salt): bool` — defaults to
 *     {@see recovery_legacy_hash_verify()};
 *   - `is_banned(ip, now): bool` — defaults to "not banned" for a caller that
 *     deliberately wants the ban gate out of the way, which is only ever a test;
 *   - `reserve(nick, ip, now): array{reserved: bool, throttled: bool}` and
 *     `release(nick, ip): bool` — the fallback budget;
 *   - `record(bucket, nick, ip, now)` and `clear(bucket, nick, ip, now)` — the
 *     two accounting writes;
 *   - `shared_throttled(nick, ip): bool` — the core throttle's read;
 *   - `shared_available: bool` — `null` detects it with autoload disabled.
 *
 * @param array<string, mixed> $submission `token`, `nick`, `password`, `ip`, `now`
 * @param array<string, mixed> $seams
 *
 * @return array{
 *     outcome: string,
 *     phase: string,
 *     message: string,
 *     row: array<string, mixed>|null,
 *     identity: array<string, mixed>|null,
 *     steps: list<string>
 * }
 */
function recovery_handler_authenticate(array $submission, array $seams = []): array
{
    $token = isset($submission['token']) && is_string($submission['token']) ? $submission['token'] : '';
    $nick = isset($submission['nick']) && is_string($submission['nick']) ? $submission['nick'] : '';
    $plain = isset($submission['password']) && is_string($submission['password']) ? $submission['password'] : '';
    $ip = isset($submission['ip']) && is_string($submission['ip']) ? $submission['ip'] : '';
    $now = isset($submission['now']) && is_int($submission['now']) ? $submission['now'] : time();
    $shared = isset($seams['shared_available']) && is_bool($seams['shared_available'])
        ? $seams['shared_available']
        : recovery_policy_shared_throttle_available();

    $steps = [];

    $facts = [
        'token_valid' => false,
        'ip_banned' => false,
        'throttled' => false,
        'nick' => $nick,
        'ip' => $ip,
        'now' => $now,
        'plain' => $plain,
        'row' => null,
    ];

    // 1. REC-01. The verdict is asked once, first, and by the real validator.
    $steps[] = RECOVERY_HANDLER_STEP_TOKEN;
    $facts['token_valid'] = recovery_login_token_validate($token);

    // The same precheck the pipeline runs, executed here purely to learn whether
    // any further read is allowed at all. It is a pure function over the facts,
    // so asking twice costs nothing and decides nothing twice.
    $phase = recovery_policy_precheck($facts);

    // 2. REC-03. Only reached when the token and the submission both passed.
    if ($phase === null) {
        $steps[] = RECOVERY_HANDLER_STEP_BAN;
        $facts['ip_banned'] = isset($seams['is_banned']) && is_callable($seams['is_banned'])
            ? (bool) ($seams['is_banned'])($ip, $now)
            : false;
        $phase = recovery_policy_precheck($facts);
    }

    $reserve = null;
    $release = null;

    if ($phase === null) {
        if ($shared) {
            // 3. Shared mode: the core throttle's own check, kept as a read
            //    because its API is check-then-act and this slice may not add a
            //    reserve() to a core class.
            $steps[] = RECOVERY_HANDLER_STEP_THROTTLE;
            $facts['throttled'] = isset($seams['shared_throttled']) && is_callable($seams['shared_throttled'])
                ? (bool) ($seams['shared_throttled'])($nick, $ip)
                : false;
        } elseif (isset($seams['reserve']) && is_callable($seams['reserve'])) {
            // 4. Fallback mode: the reservation is the budget, and the policy
            //    charges it under one lock before the verdict. Wrapped so the
            //    charge appears in the step list as the read it is.
            $reserve = static function (string $n, string $i, int $t) use ($seams, &$steps): array {
                $steps[] = RECOVERY_HANDLER_STEP_RESERVE;
                $budget = ($seams['reserve'])($n, $i, $t);

                return is_array($budget) ? $budget : ['reserved' => false, 'throttled' => false];
            };

            $release = isset($seams['release']) && is_callable($seams['release'])
                ? $seams['release']
                : null;
        }
    }

    $verifier = isset($seams['verifier']) && is_callable($seams['verifier'])
        ? $seams['verifier']
        : 'recovery_legacy_hash_verify';

    $row = null;

    $verdict = static function (?array $existing, string $given) use (
        $seams,
        $verifier,
        $nick,
        &$row,
        &$steps
    ): string {
        $row = $existing ?? (isset($seams['lookup']) && is_callable($seams['lookup'])
            ? ($seams['lookup'])($nick)
            : null);
        $row = is_array($row) ? $row : null;
        $steps[] = RECOVERY_HANDLER_STEP_LOOKUP;
        $steps[] = RECOVERY_HANDLER_STEP_VERIFY;

        $salt = isset($row['salt']) && is_string($row['salt']) ? $row['salt'] : null;

        return recovery_policy_verify_credentials(
            $row,
            $given,
            static fn (string $stored, string $plain): bool => $verifier($stored, $plain, $salt) === true
        );
    };

    $result = recovery_policy_evaluate($facts, $verdict, $shared, $reserve, $release);

    // 6. Accounting, and only where the policy asked for it. A submission the
    //    pipeline charged is already counted; a refusal before the budget is not
    //    counted at all.
    if ($result['record_failure'] === true && isset($seams['record']) && is_callable($seams['record'])) {
        $steps[] = RECOVERY_HANDLER_STEP_RECORD;
        ($seams['record'])($result['bucket'], $nick, $ip, $now);
    }

    // The success-clear. A reserved submission was already released by the
    // pipeline, so this only ever fires for the shared bucket, whose clear is a
    // call this file owes and the policy cannot make.
    if ($result['clear'] === true
        && $result['reserved'] !== true
        && isset($seams['clear'])
        && is_callable($seams['clear'])
    ) {
        $steps[] = RECOVERY_HANDLER_STEP_CLEAR;
        ($seams['clear'])($result['bucket'], $nick, $ip, $now);
    }

    $identity = null;

    if ($result['outcome'] === RECOVERY_POLICY_OUTCOME_OK) {
        // The row's nick is authoritative: the operator may have typed a
        // different case, and the session must carry the name the guard and the
        // re-check will look up. `user_id` keeps its historical meaning here —
        // this console has always written the nick there — because the
        // revalidation branch binds it to `fs_users.nick`. REC-06 asks for both
        // keys; it does not ask the numeric id.
        $canonical = isset($row['nick']) && is_string($row['nick']) && $row['nick'] !== ''
            ? $row['nick']
            : $nick;

        $identity = [
            'user_nick' => $canonical,
            'user_id' => $canonical,
            'login_time' => $now,
            'last_activity' => $now,
        ];
    }

    return [
        'outcome' => $result['outcome'],
        'phase' => $result['phase'],
        'message' => recovery_policy_failure_message($result['phase'], $shared),
        'row' => $row,
        'identity' => $identity,
        'steps' => $steps,
    ];
}

/**
 * Write the authenticated identity into the console's session (REC-06).
 *
 * The order is the requirement: the identifier is rotated **before** the first
 * identity key is written, so the identifier the attacker may have observed
 * before the login can never be the one that carries the session. `$regenerate`
 * exists as a seam for exactly one reason — it is called *before* the write, and
 * a caller-supplied callable can observe that the identity keys were still
 * absent at that moment. A self-reported step list could lie about it; the
 * session this function leaves behind could not, but it also could not tell the
 * order apart from "written, then rotated".
 *
 * `$regenerate` defaults to `session_regenerate_id(true)`, which deletes the old
 * session file. A `false` answer is not fatal: the keys are written anyway,
 * because refusing here would turn a rotation failure into a login failure on
 * the one screen an operator uses when nothing else works.
 *
 * @param array<string, mixed> $identity as returned by
 *                                      {@see recovery_handler_authenticate()}
 * @param callable(): mixed|null $regenerate
 *
 * @return array{steps: list<string>, session: array<string, mixed>}
 */
function recovery_handler_write_identity(array $identity, ?callable $regenerate = null): array
{
    $steps = [];

    $steps[] = 'regenerate';
    if ($regenerate !== null) {
        $regenerate();
    } else {
        session_regenerate_id(true);
    }

    $steps[] = 'write';

    $written = [];

    foreach (['user_nick', 'user_id', 'login_time', 'last_activity'] as $key) {
        if (array_key_exists($key, $identity)) {
            $_SESSION[$key] = $identity[$key];
            $written[$key] = $identity[$key];
        }
    }

    return ['steps' => $steps, 'session' => $written];
}

/**
 * Is the nick throttled in the core's shared budget? False when the console
 * cannot see that budget at all.
 *
 * `recovery_policy_shared_throttle_available()` is the only gate: it answers
 * with autoload disabled, so this never pulls a core class into a process that
 * did not already have one (RACC-01). When the class is absent the answer is
 * `false` — not throttled — because the fallback counter is the bound in that
 * state and answering `true` would lock every operator out of a damaged
 * installation.
 */
function recovery_handler_shared_throttled(string $nick, string $ip): bool
{
    if (!recovery_policy_shared_throttle_available()) {
        return false;
    }

    return \FSFramework\Security\LoginThrottle::isThrottled($nick) === true;
}

/**
 * Charge one failed attempt to the core's shared budget, when it is visible.
 */
function recovery_handler_shared_record(string $nick, string $ip): void
{
    if (!recovery_policy_shared_throttle_available()) {
        return;
    }

    \FSFramework\Security\LoginThrottle::recordFailure($nick);
}

/**
 * Release the core's shared budget for this nick, when it is visible.
 */
function recovery_handler_shared_clear(string $nick, string $ip): void
{
    if (!recovery_policy_shared_throttle_available()) {
        return;
    }

    \FSFramework\Security\LoginThrottle::clear($nick);
}

/**
 * The production seam set for one console request.
 *
 * Everything the handler needs that touches the outside world is decided here,
 * once, so `recovery.php` stays a renderer:
 *
 *   - the row lookup is `SELECT *` on purpose: the current schema has no `salt`
 *     column and a legacy one does, and `*` reads it when it exists instead of
 *     probing the schema or naming a column some installations do not have. One
 *     row, four columns of interest;
 *   - the ban read is the policy's own reader over `tmp/<FS_TMP_NAME>ip.log`
 *     (REC-03). It never writes;
 *   - the budget is the fallback counter when the core throttle is invisible —
 *     the state a damaged core always produces — and the core's two calls when
 *     it is not.
 *
 * `$db` is the console's live `mysqli`. A `null` here means the caller already
 * decided the database boundary was not reached; the lookup is then absent
 * rather than present-and-lying, and the verdict treats the missing row as "no
 * such account" and verifies against the constant dummy, so even that degenerate
 * state costs what a real guess costs.
 *
 * @param object|null $db
 * @return array<string, mixed>
 */
function recovery_handler_seams($db = null, ?string $installRoot = null): array
{
    $root = $installRoot ?? (defined('FS_FOLDER') ? (string) FS_FOLDER : '');
    $shared = recovery_policy_shared_throttle_available();
    $counterPath = recovery_policy_fallback_path($root);

    $seams = [
        'is_banned' => static fn (string $ip, int $now): bool => recovery_policy_is_ip_banned($ip, $root, $now),
        'shared_available' => $shared,
    ];

    if (is_object($db) && method_exists($db, 'prepare')) {
        $seams['lookup'] = static function (string $nick) use ($db): ?array {
            $statement = $db->prepare('SELECT * FROM fs_users WHERE nick = ?');
            if (!is_object($statement)) {
                return null;
            }

            $statement->bind_param('s', $nick);
            $statement->execute();
            $result = $statement->get_result();
            $row = is_object($result) ? $result->fetch_assoc() : null;
            $statement->close();

            return is_array($row) ? $row : null;
        };
    }

    if ($shared) {
        $seams['shared_throttled'] = 'recovery_handler_shared_throttled';
        $seams['record'] = static function (string $bucket, string $nick, string $ip, int $now): void {
            recovery_handler_shared_record($nick, $ip);
        };
        $seams['clear'] = static function (string $bucket, string $nick, string $ip, int $now): void {
            recovery_handler_shared_clear($nick, $ip);
        };

        return $seams;
    }

    $seams['reserve'] = static fn (string $nick, string $ip, int $now): array
        => recovery_policy_fallback_reserve($counterPath, $nick, $ip, $now);
    $seams['release'] = static fn (string $nick, string $ip): bool
        => recovery_policy_fallback_clear($counterPath, $nick, $ip);
    $seams['record'] = static function (string $bucket, string $nick, string $ip, int $now) use ($counterPath): void {
        recovery_policy_fallback_record($counterPath, $nick, $ip, $now);
    };
    $seams['clear'] = static function (string $bucket, string $nick, string $ip, int $now) use ($counterPath): void {
        recovery_policy_fallback_clear($counterPath, $nick, $ip);
    };

    return $seams;
}
