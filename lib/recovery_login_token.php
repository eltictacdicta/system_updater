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
 * The recovery console's login form token (REC-01).
 *
 * Why the console needs a token of its own
 * ----------------------------------------
 * The form carries a credential the operator types, so a cross-site POST that
 * reaches the handler is a password-guessing attempt by proxy: it would spend
 * the operator's budget and, on a lucky guess, hand an attacker a session. The
 * handler therefore refuses a submission whose token is missing, empty or wrong
 * before it reads the ban bucket, consults a throttle, looks up an account or
 * compares a password (REC-01, REC-02).
 *
 * A1 forbids `CsrfManager`, so the token cannot come from the framework. It also
 * cannot come from the plugin's own token helper, and that exclusion is the
 * whole point of this file:
 *
 * - the plugin store — `system_updater` → `csrf_tokens` in the plugin session —
 *   is what `start`, `begin` and `chunk` accept. A value written there is a
 *   plugin credential. Minting one on the login render would hand it to an
 *   unauthenticated visitor, whose only effect would be to let that visitor
 *   drive a restore without an identity;
 * - the framework store (`_csrf`, `_sf2_attributes`) belongs to the panel and is
 *   neither readable nor writable without core code;
 * - so the token lives in the namespace the console already owns
 *   (`recovery_console`, the same one RACC-05's marker uses), in a slot of its
 *   own.
 *
 * The console mints no plugin token before both of its gates have passed
 * (RACC-07): a form token is not that token, and this file cannot produce one —
 * it writes one key, in one namespace, and nothing else.
 *
 * Binding, lifetime, comparison
 * -----------------------------
 * The value never leaves the session that minted it; it is read back from the
 * store on submission and compared with `hash_equals()`, so a token harvested
 * from another browser cannot validate (REC-01). It is reusable within its
 * lifetime, exactly like the plugin token: re-rendering the form must not
 * invalidate a page the operator already has open, and single use would need a
 * second store to remember which values were spent.
 *
 * The lifetime is 4 hours, aligned with the plugin token's. The alignment is
 * declared here instead of imported: loading the plugin token module opens the
 * plugin session, which is the wrong store and the wrong side effect for a
 * screen that may not have authenticated anybody yet. The two constants are held
 * together by a drift test in `tests/RecoveryLoginTokenTest.php`, which reads the
 * plugin's definition as text. The plugin's value is overridable from
 * `config.php`; this one is not, because the form token has its own lifecycle
 * and no operator needs to lengthen it.
 *
 * A token is minted only into an open session, and the console opens its session
 * in the gate that runs before any render (RACC-05). Minting without a session
 * would write into an array that is discarded at the end of the request, so it
 * refuses loudly instead in the only way it can: an empty token, which the
 * caller must treat as "the console cannot protect this form" rather than
 * rendering a form that can only fail.
 *
 * Dependency contract (RACC-01)
 * -----------------------------
 * Procedural and standalone, like `recovery_secret.php`, `recovery_session.php`
 * and `recovery_policy.php`: no class, no namespace, no import, no include, no
 * Composer symbol. It never opens, closes or renames a session — the console
 * owns that — and it reads no request superglobal. Only `$_SESSION` is touched,
 * which is the session the caller already opened.
 */

/** The console's namespace: the same one RACC-05's marker is written into. */
const RECOVERY_LOGIN_TOKEN_NAMESPACE = 'recovery_console';

/** The slot this form token occupies inside that namespace. */
const RECOVERY_LOGIN_TOKEN_SLOT = 'login_token';

/** The form field the console's login form must render. */
const RECOVERY_LOGIN_TOKEN_FIELD = 'recovery_login_token';

/** Four hours, aligned with the plugin token's lifetime. */
const RECOVERY_LOGIN_TOKEN_TTL = 4 * 3600;

/**
 * Mint a fresh form token into the open console session.
 *
 * Answers the empty string when no session is open, which is the only honest
 * answer: a token that cannot be read back at submission time is worse than a
 * visible refusal.
 *
 * The namespace is created when absent and preserved when present, because the
 * RACC-05 authorization marker lives beside this slot and losing it would log an
 * operator out of a console they had already opened.
 */
function recovery_login_token_mint(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $token = bin2hex(random_bytes(32));

    if (!isset($_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE])
        || !is_array($_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE])) {
        $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE] = [];
    }

    $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT] = [
        'value' => $token,
        'created_at' => time(),
    ];

    return $token;
}

/**
 * The stored token, or `null` when there is none to render.
 *
 * Expiry is answered as "nothing stored" rather than as an error, so a render
 * after four hours mints a fresh value instead of emitting a dead form.
 */
function recovery_login_token_read(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $entry = $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT] ?? null;

    if (!is_array($entry) || !isset($entry['value'], $entry['created_at'])) {
        return null;
    }

    if (!is_string($entry['value']) || $entry['value'] === '') {
        return null;
    }

    if (time() - (int) $entry['created_at'] > RECOVERY_LOGIN_TOKEN_TTL) {
        return null;
    }

    return $entry['value'];
}

/**
 * Validate a submitted token against the session that minted it.
 *
 * Missing, empty, wrong and expired are the same answer — `false` — so the
 * handler cannot turn the validator's return value into a reason. The console
 * still renders one generic CSRF refusal for all four (REC-01, REC-05).
 *
 * An expired entry is removed on the way out: the form the operator abandoned is
 * not a store to keep, and the next render starts clean.
 */
function recovery_login_token_validate(string $submitted): bool
{
    if ($submitted === '' || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $entry = $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT] ?? null;

    if (!is_array($entry) || !isset($entry['value'], $entry['created_at'])) {
        return false;
    }

    $stored = is_string($entry['value']) ? $entry['value'] : '';

    if ($stored === '') {
        return false;
    }

    if (time() - (int) $entry['created_at'] > RECOVERY_LOGIN_TOKEN_TTL) {
        unset($_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT]);

        return false;
    }

    return hash_equals($stored, $submitted);
}

/**
 * The hidden input the login form renders.
 *
 * Mints on demand so the render cannot forget to, and reuses an existing value
 * so two open tabs do not invalidate each other. Answers the empty string when
 * there is no session to mint into — the caller's cue to refuse rather than to
 * paint a form nobody can submit.
 *
 * The value is escaped even though it is 64 hex characters: the shape of this
 * helper is what a later edit will copy, and "it cannot contain markup" is a
 * property of today's generator, not of the function's contract.
 */
function recovery_login_token_field(): string
{
    $token = recovery_login_token_read() ?? recovery_login_token_mint();

    if ($token === '') {
        return '';
    }

    return sprintf(
        '<input type="hidden" name="%s" value="%s">',
        RECOVERY_LOGIN_TOKEN_FIELD,
        htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8')
    );
}
