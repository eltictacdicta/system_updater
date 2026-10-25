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
 *   1. `dirname(FS_FOLDER) . '/system_updater_recovery_secret.php'`;
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
 * Both locations are *outside* the database, *outside* the framework session
 * and *outside* `config.php`, which is the point: the console exists for the
 * state where the application cannot boot, so a secret stored by the
 * application would be unavailable exactly when it is needed.
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

// The functions of this library are declared below; see the docblock above for
// the storage decision and the resolution order they implement.
