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

require_once __DIR__ . '/csrf_token.php';

/**
 * The recovery console's `su_csrf_token` mint (RACC-07).
 *
 * Why the console mints at all
 * ----------------------------
 * The token's security property is provenance, and AUTH-05 states that
 * provenance as two authorized points, each behind its own honest gate:
 *
 *   1. the `#[AdminOnly]` `admin_updater` page, gated by the attribute plus an
 *      authenticated administrator session;
 *   2. this console, gated by the per-installation secret compared constant-time
 *      (`RACC-04`) *and* an authenticated administrator session in the console
 *      (`REC-06`).
 *
 * An emergency console that lists backups and cannot restore any of them has no
 * purpose, which is the product owner's reason for the second point (`RACC-07`).
 * Both gates have to be open, and no token exists before either one: the login
 * render, the secret refusal and the `config.php` boundary all return `null`.
 *
 * Why the order is observable
 * ---------------------------
 * "Minted after both gates" is a claim about what happened first, and a
 * returned token cannot prove it: a mint that ran before the comparison would
 * still return one. So the gate walk takes an optional recorder, the console
 * passes nothing, and the test injects one and asserts the recorded sequence.
 * Recording is free in production — the default recorder is a no-op closure.
 *
 * What this module is not
 * -----------------------
 * It is not a second token mechanism. Storage (`$_SESSION['system_updater']
 * ['csrf_tokens']` in `SU_SESS_*`), format (64 hex characters), the 4 h TTL and
 * the `hash_equals()` comparison are `lib/csrf_token.php`'s and are unchanged
 * (`RACC-07` point 4). This module decides *when* that function may be called.
 * Nor is it an identity: a minted token is a CSRF credential for
 * `start`/`begin`/`chunk` and for nothing else (`CSRF-01`).
 *
 * Dependency contract (RACC-01): procedural and plugin-local. `csrf_token.php`
 * reaches only `lib/session_name.php`, so the console's graph gains one more
 * plugin file and still no core class, no Composer autoload and no
 * `class_exists()`.
 */

/** The constant-time comparison of the console's secret already happened (RACC-04). */
const RECOVERY_CSRF_STEP_SECRET_COMPARED = 'secret_compared';

/** `config.php` is loaded: the console is past the refusal boundary (RACC-04). */
const RECOVERY_CSRF_STEP_CONFIG_LOADED = 'config_loaded';

/** An authenticated administrator session exists in the console (REC-06). */
const RECOVERY_CSRF_STEP_ADMIN_AUTHENTICATED = 'admin_authenticated';

/** The token was written into the plugin store (RACC-07). */
const RECOVERY_CSRF_STEP_TOKEN_MINTED = 'token_minted';

/**
 * Mint the console's token, or refuse.
 *
 * The facts are the two gates, stated in the order the request chain passed
 * them. Nothing is minted unless all of them are `true`: returning a token for
 * a request that failed the secret gate would make the refusal of `RACC-04`
 * distinguishable (the store would exist afterwards) and would hand the
 * plugin's CSRF credential to a visitor who never proved anything.
 *
 * `$recorder` is the seam the order is asserted with: it is called once per
 * step that *actually happened*, so a refused request records only the steps it
 * reached. It defaults to a no-op, which is what production passes.
 *
 * @param array{secret_granted?: bool, config_loaded?: bool, authenticated?: bool} $facts
 * @param callable(string): void|null $recorder
 *
 * @return string|null the minted value (64 hex characters) or `null` when a gate
 *                     was closed
 */
function recovery_csrf_mint_token(array $facts, ?callable $recorder = null): ?string
{
    $record = $recorder ?? static function (string $step): void {
    };

    // 1. RACC-04 / RACC-05. The comparison is constant-time and happens in
    //    `recovery_secret_gate()`; by the time this function is called its
    //    verdict is a fact, and it is the first one consulted.
    $record(RECOVERY_CSRF_STEP_SECRET_COMPARED);
    if (($facts['secret_granted'] ?? false) !== true) {
        return null;
    }

    // 2. `config.php` is the console's only hard stop and the boundary the
    //    refusal must not cross. A request that is past it may mint; one that is
    //    not cannot reach this function at all, and the fact is still checked so
    //    the order above is a walk and not a comment.
    $record(RECOVERY_CSRF_STEP_CONFIG_LOADED);
    if (($facts['config_loaded'] ?? false) !== true) {
        return null;
    }

    // 3. REC-06. The secret proves reachability, never a person: without an
    //    authenticated administrator row there is no second gate and no token.
    $record(RECOVERY_CSRF_STEP_ADMIN_AUTHENTICATED);
    if (($facts['authenticated'] ?? false) !== true) {
        return null;
    }

    // 4. Both gates open. This is `admin_updater`'s own mechanism, unchanged:
    //    same store, same format, same TTL, same comparison (RACC-07 point 4).
    //    The write switches to `SU_SESS` and closes it, so the console session is
    //    flushed to disk first and its identity keys and RACC-05 marker survive
    //    (point 5). Nothing of that identity is written into the plugin session.
    $token = system_updater_csrf_generate();

    $record(RECOVERY_CSRF_STEP_TOKEN_MINTED);

    return $token;
}
