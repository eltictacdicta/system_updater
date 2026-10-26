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
