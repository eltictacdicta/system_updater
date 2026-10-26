<?php
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
 * The plugin session name, on its own.
 *
 * Why this is a file and not a function inside `session_auth.php`
 * ---------------------------------------------------------------
 * `session_auth.php` is the guard library of the standalone process scripts and
 * it carries the framework bootstrap with it: `system_updater_ensure_fs_path()`
 * requires `base/config2.php` and `system_updater_bootstrap_framework()` requires
 * `vendor/autoload.php`. The recovery console must load none of that (`RACC-01`),
 * which is why `lib/csrf_token.php` could not be part of its graph while this
 * function lived there — and `RACC-07` needs exactly that module, because the
 * console mints `su_csrf_token` with `system_updater_csrf_generate()`.
 *
 * So the *name* is factored out from the *guard*: a pure derivation with no
 * include and no class, loaded by both. `session_auth.php` loads it, so every
 * process script keeps resolving the same name it always did, and the console
 * can load `csrf_token.php` without pulling the kernel in.
 *
 * The derivation itself is unchanged and must stay unchanged: the process
 * scripts read the store this function names, and a different name here would be
 * a token minted where nothing looks for it.
 */

/**
 * The plugin's dedicated session name.
 *
 * `SU_SESS_*` with a prefix of the installation path, so two installations on
 * one host do not share a store, and so the value can never collide with the
 * application's own `FSSESS_*`.
 */
function system_updater_resolve_session_name(): string
{
    // El plugin usa su propia sesión independiente del framework
    // para evitar conflictos entre las sesiones del framework.
    if (defined('SYSTEM_UPDATER_SESSION_NAME') && trim((string) SYSTEM_UPDATER_SESSION_NAME) !== '') {
        return trim((string) SYSTEM_UPDATER_SESSION_NAME);
    }

    $seed = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(dirname(__DIR__)));
    $seed = str_replace('\\', '/', $seed);

    return 'SU_SESS_' . substr(sha1($seed . '_system_updater'), 0, 12);
}
