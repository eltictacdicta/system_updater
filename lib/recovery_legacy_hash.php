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
 * The console's own legacy hash verifier (REC-07).
 *
 * Why a second copy of the algorithm exists
 * -----------------------------------------
 * The emergency console must boot when the framework does not (A1): no
 * autoloader, no class under `base/`, `src/`, `controller/` or `model/`, no
 * kernel. The previous implementation reached the core through the Composer
 * autoloader, which is exactly the dependency that is being removed, so the
 * comparison has to live here. A1 overrides the *mechanism*, not the outcome:
 * an administrator whose row still holds a pre-bcrypt digest must still be able
 * to log in, because this console is the one screen that exists for that
 * administrator.
 *
 * The comparison set is the core's, copied on purpose
 * ---------------------------------------------------
 * `src/Security/PasswordHasherService.php` (`verifyLegacyHash()`) is the source
 * of truth, and `plugins/legacy_support/LegacyCompatibility.php` delegates to
 * it. This file reproduces it in the same order:
 *
 *   1. `password_verify($plain, $stored)` — the modern path, first;
 *   2. `sha1($salt . $plain)` — only when the row supplies a non-null salt;
 *   3. `sha1($plain)`;
 *   4. `sha1(mb_strtolower($plain, 'UTF8'))` — FacturaScripts 2017 also stored
 *      the digest of the folded password;
 *   5. `md5($plain)`.
 *
 * Every comparison in steps 2-5 is `hash_equals()`, so no comparison leaks the
 * position of the first differing byte.
 *
 * The `salt` argument is `null` for every row read from the current schema:
 * `model/table/fs_users.xml` has no `salt` column, so steps 2 is unreachable
 * unless a legacy schema that does have the column is still running. It is
 * reproduced anyway, because a row written by such a schema must keep working
 * and because dropping it would make a salted digest fall through to the
 * unsalted comparisons.
 *
 * The drift risk, stated rather than hidden
 * -----------------------------------------
 * Two copies of one algorithm now exist by mandate. They must be changed
 * together, and the enforcement is a test rather than a comment:
 * `tests/RecoveryLegacyHashTest.php` (`theComparisonSetMatchesTheCoreAlgorithm`)
 * runs the same matrix through this module and through
 * `verifyLegacyHash()` whenever the core is loadable, so a core change to the
 * legacy algorithm turns that test red instead of silently locking a legacy
 * administrator out of the console. When the plugin is checked out standalone
 * there is no core copy to compare against, and the test reports the class it
 * could not find instead of passing quietly. The residual risk is therefore
 * "a core change is made and the plugin is not re-released": the test only
 * fires in a checkout that carries both, which is why the same sentence is in
 * `plugin-recovery-login`'s failure-mode inventory.
 *
 * Failing is uniform, never generous
 * ----------------------------------
 * The verifier answers a boolean and nothing else, so the only bypass it could
 * offer is `true`. A stored value that matches nothing — prose, a truncated
 * digest, a format this function does not know — answers `false`, and the
 * console renders the single failure body of REC-05. There is no
 * "unknown format, accept it" branch and there must never be one.
 *
 * An empty password never verifies, even when the row holds `sha1('')`: that
 * digest is valid, so a row whose password was emptied would otherwise
 * authenticate every empty submission, and an empty submission is free to
 * produce from another site. The console's policy layer already refuses an
 * empty submission before evaluation (REC-02); this is the same rule at the
 * last line of defence.
 *
 * Dependency contract (RACC-01)
 * -----------------------------
 * Procedural, like `recovery_secret.php` and `recovery_session.php`: no class,
 * no namespace, no import, no include, no Composer symbol, no core symbol with
 * autoload enabled. The only functions it calls are PHP built-ins, so the file
 * is loadable with nothing else defined and nothing else installed.
 */

/**
 * Whether the stored value is a format `password_verify()` owns.
 *
 * The distinction is not cosmetic: the legacy comparisons must not be attempted
 * against a modern hash (REC-07). It is a cost rule rather than a correctness
 * one — a 60+ character modern hash can never equal a 40-character SHA-1 or a
 * 32-character MD5 digest — but the wasted work is what the requirement
 * forbids, so the branch is explicit.
 *
 * `password_get_info()` is the authority for the set, with one measured
 * addition. On PHP 8.3.33 it names bcrypt `$2y$` and the argon2 family, and it
 * answers `unknown` for the blowfish spellings `$2a$`, `$2b$` and `$2x$` — yet
 * `password_verify()` accepts those three, because they are the same hash. A
 * detector built on `password_get_info()` alone would therefore hand a modern
 * hash to the legacy comparisons. The prefix test below closes that gap.
 *
 * Detection is a gate, never the decision: {@see recovery_legacy_hash_verify()}
 * asks `password_verify()` first and consults this function afterwards, so a
 * format PHP accepts but this function has not learned yet is verified rather
 * than denied. The reverse order would be a lockout, which is the one failure
 * mode REC-07 exists to prevent.
 */
function recovery_legacy_hash_is_modern(string $stored): bool
{
    if ($stored === '' || $stored[0] !== '$') {
        return false;
    }

    if (password_get_info($stored)['algoName'] !== 'unknown') {
        return true;
    }

    return preg_match('/^\$2[abxy]\$/', $stored) === 1;
}

/**
 * Verify a plain password against a stored hash, modern or legacy.
 *
 * The eight-line contract of REC-07, in one place: see this file's header for
 * the comparison set, its order, the salt rule and the drift tripwire.
 *
 * `$salt` is the row's `salt` column when one exists and `null` otherwise. It
 * changes nothing on the modern path, and on the legacy path it only adds the
 * first comparison; supplying it does not disable the unsalted variants, which
 * matches the core.
 *
 * A `false` answer means "this password does not authenticate this row" and
 * carries no cause. The caller renders one uniform failure (REC-05) and writes
 * no identity when it receives it.
 */
function recovery_legacy_hash_verify(string $stored, string $plain, ?string $salt = null): bool
{
    if ($stored === '' || $plain === '') {
        return false;
    }

    if (password_verify($plain, $stored)) {
        return true;
    }

    if (recovery_legacy_hash_is_modern($stored)) {
        return false;
    }

    if ($salt !== null && hash_equals($stored, sha1($salt . $plain))) {
        return true;
    }

    return hash_equals($stored, sha1($plain))
        || hash_equals($stored, sha1(mb_strtolower($plain, 'UTF8')))
        || hash_equals($stored, md5($plain));
}
