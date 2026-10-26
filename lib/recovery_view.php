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
 * The console's authenticated render (RACC-07).
 *
 * Why the render is a function and not inline markup
 * --------------------------------------------------
 * Two of RACC-07's properties are about the bytes the browser receives: "every
 * restore control points at a request carrying a valid `su_csrf_token`" and "no
 * control is rendered that is known to be refused". The console cannot be driven
 * to its authenticated page in this suite — that needs a database, and
 * `recovery.php` connects with `new mysqli()` — so the render is taken out of
 * the script's control flow and handed its inputs. The tests then assert on the
 * generated rows and on the generated driver, which is what runs.
 *
 * Nothing here reads the request, the session or the disk: this file is a
 * formatter. No core class, no autoloader (RACC-01).
 *
 * The driver
 * ----------
 * `recovery_view_restore_driver()` emits the stepped restore client: `begin`
 * first, then as many `chunk` requests as the server needs, each one short and
 * resumable because the production host kills a request after a few seconds.
 * The transport is `fetch` with `Accept: text/event-stream` so the SSE framing
 * the server still emits — including the `error` events from `csrf_guard.php`
 * and from the session bootstrap — keeps parsing.
 *
 * The token is a single constant in the emitted script and every request URL is
 * built by `restoreUrl()`, which appends it. There is exactly one builder and
 * one transport call on purpose: a second place that could build a URL is a
 * second place that can forget the token, which is precisely the defect this
 * replaced (`action=start` with no token, refused by `ensure_request_csrf()`).
 */

/**
 * The backup table body, one `<tr>` per group.
 *
 * `$restoreAvailable` is `false` when the render minted no token — the state
 * RACC-07 says must offer no restore control, because every request would be
 * refused. The listing itself still renders: the console's first job is to tell
 * the operator what backups exist.
 *
 * The markup is assembled from single-quoted fragments so the tags this file
 * emits can be read (and grepped) exactly as they appear in the response; a
 * double-quoted PHP string would write `data-type=\"database\"` into the source
 * and `data-type="database"` into the page, which is one too many spellings of
 * the same thing.
 *
 * @param array<int, array<string, mixed>> $backups as `list_backups_grouped()` returns them
 */
function recovery_view_backup_rows(array $backups, bool $restoreAvailable): string
{
    if ($backups === []) {
        return "                        <tr>\n"
            . '                            <td colspan="3" class="text-center">'
            . 'No se encontraron copias de seguridad.</td>' . "\n"
            . "                        </tr>\n";
    }

    $rows = '';

    foreach ($backups as $group) {
        $name = htmlspecialchars((string) ($group['base_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $timestamp = (int) ($group['timestamp'] ?? 0);
        $complete = isset($group['complete']['name']) && is_string($group['complete']['name'])
            ? $group['complete']['name']
            : null;

        $actions = $complete === null
            ? '                                <span class="text-muted">'
                . 'Solo backup completo soportado en recuperación</span>' . "\n"
            : recovery_view_restore_controls($group, $complete, $restoreAvailable);

        $rows .= "                        <tr>\n";
        $rows .= "                            <td>\n";
        $rows .= '                                <strong>' . $name . '</strong><br>' . "\n";

        if ($complete !== null) {
            $rows .= '                                <span class="label label-primary">Completo</span>' . "\n";
        }

        $rows .= "                            </td>\n";
        $rows .= '                            <td>' . date('d/m/Y H:i', $timestamp) . "</td>\n";
        $rows .= "                            <td>\n";
        $rows .= $actions;
        $rows .= "                            </td>\n";
        $rows .= "                        </tr>\n";
    }

    return $rows;
}

/**
 * The action cell of one group.
 *
 * Both controls point at the driver, which is the only thing that builds a
 * request — and the only thing that carries the token. A group that cannot be
 * restored from the console (no complete archive) never reaches this function.
 *
 * @param array<string, mixed> $group
 */
function recovery_view_restore_controls(array $group, string $completeName, bool $restoreAvailable): string
{
    if (!$restoreAvailable) {
        return '                                <span class="text-muted">'
            . 'Restauración no disponible</span>' . "\n";
    }

    $controls = '                                <a href="#"' . "\n"
        . '                                    class="btn btn-danger btn-sm restore-btn"' . "\n"
        . '                                    data-file="'
        . htmlspecialchars($completeName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' . "\n"
        . '                                    data-type="complete">' . "\n"
        . '                                    <i class="fa fa-undo"></i> Restaurar Todo' . "\n"
        . "                                </a>\n";

    $databaseFile = isset($group['database_restore_file']) && is_string($group['database_restore_file'])
        ? $group['database_restore_file']
        : '';

    if (!empty($group['can_restore_database']) && $databaseFile !== '') {
        $controls .= '                                <a href="#"' . "\n"
            . '                                    class="btn btn-warning btn-sm restore-btn"' . "\n"
            . '                                    data-file="'
            . htmlspecialchars($databaseFile, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' . "\n"
            . '                                    data-type="database">' . "\n"
            . '                                    <i class="fa fa-database"></i> Restaurar BD' . "\n"
            . "                                </a>\n";
    }

    return $controls;
}

/**
 * The stepped restore driver, as the console emits it.
 *
 * `$csrfToken` is the value `recovery_csrf_mint_token()` returned for this
 * render. It is embedded with the `JSON_HEX_*` flags, so the value cannot close
 * the `<script>` element even if it ever stopped being 64 hex characters, and it
 * is never emitted anywhere else: the refusal path, the login render and the log
 * never see it (`RACC-07` point 6).
 */
function recovery_view_restore_driver(string $csrfToken): string
{
    $token = json_encode(
        $csrfToken,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $available = $csrfToken === '' ? 'false' : 'true';

    return <<<JS
    <script>
    (function() {
        // El token que acuñó este render (RACC-07). Sin él no se cablea ningún
        // control: un botón que ya sabemos que será rechazado es un botón muerto.
        var suCsrfToken = {$token};
        var restoreAvailable = {$available};
        var restoreEndpoint = 'process_restore.php';
        var restoreFile = '';
        var restoreType = 'complete';
        var restoreFinished = false;
        var restoreFailures = 0;
        var restoreSteps = 0;

        // Un único constructor de URL. Todo request pasa por aquí, así que el
        // token viaja en todos: `start`/`begin`/`chunk` lo exigen y
        // `ensure_request_csrf()` no se relaja para el console.
        function restoreUrl(action) {
            return restoreEndpoint + '?action=' + action
                + '&file=' + encodeURIComponent(restoreFile)
                + '&type=' + encodeURIComponent(restoreType)
                + '&su_csrf_token=' + encodeURIComponent(suCsrfToken);
        }

        function startRestore(file, type) {
            restoreFile = file;
            restoreType = type || 'complete';
            restoreFinished = false;
            restoreFailures = 0;
            restoreSteps = 0;

            updateProgress(0, 'Iniciando restauración...');
            document.getElementById('restoreDetails').innerHTML = '<small class="text-muted">Conectando al servidor...</small>';
            document.getElementById('restoreCompleteBtn').style.display = 'none';
            document.getElementById('restoreErrorBtn').style.display = 'none';
            document.getElementById('modalSpinner').classList.add('fa-spin');
            document.getElementById('statusSpinner').classList.add('fa-spin');

            jQuery('#restoreProgressModal').modal('show');

            // `begin` arma el estado en disco; a partir de ahí cada `chunk` es un
            // request corto y reanudable.
            runRestoreStep('begin');
        }

        function runRestoreStep(action) {
            if (restoreFinished) {
                return;
            }

            restoreSteps++;
            if (restoreSteps > 100000) {
                finishRestoreAsError('La restauración no termina de avanzar. Se detuvo por seguridad.');
                return;
            }

            fetch(restoreUrl(action), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'text/event-stream' }
            }).then(function(response) {
                if (!response.ok) {
                    var httpError = new Error('HTTP ' + response.status);
                    httpError.status = response.status;
                    throw httpError;
                }
                return response.text();
            }).then(function(body) {
                var events = parseRestoreSseEvents(body);
                var sawProgress = false;
                var doneData = null;
                var errorData = null;
                var busy = false;

                for (var i = 0; i < events.length; i++) {
                    var evt = events[i];

                    if (evt.event === 'progress') {
                        sawProgress = true;

                        if (evt.data.step === 'busy') {
                            busy = true;
                            continue;
                        }

                        updateProgress(evt.data.percent || 0, evt.data.message);
                        addLogEntry(evt.data.message);

                        if (evt.data.done) {
                            doneData = evt.data;
                        }
                    } else if (evt.event === 'error') {
                        errorData = evt.data;
                    }
                }

                // Otro proceso tiene el lock del estado: no es un fallo, es esperar.
                if (busy && !doneData && !errorData) {
                    window.setTimeout(function() {
                        runRestoreStep('chunk');
                    }, 2000);
                    return;
                }

                if (errorData) {
                    logRestoreDetail(errorData);
                    finishRestoreAsError(
                        errorData.message || 'La restauración falló.',
                        errorData.percent || 0
                    );
                    return;
                }

                if (doneData) {
                    // Terminar con errores SQL no es un éxito aunque el proceso haya acabado.
                    if ((doneData.errors_count || 0) > 0) {
                        logRestoreDetail(doneData);
                        finishRestoreAsError(doneData.message, 100);
                        return;
                    }
                    if ((doneData.warnings_count || 0) > 0) {
                        logRestoreDetail(doneData);
                    }
                    finishRestoreAsSuccess(doneData.warnings_count || 0);
                    return;
                }

                if (!sawProgress) {
                    throw new Error('respuesta sin progreso');
                }

                restoreFailures = 0;
                runRestoreStep('chunk');
            }).catch(function(error) {
                if (restoreFinished) {
                    return;
                }

                restoreFailures++;

                // Un corte no pierde trabajo: el checkpoint queda en disco, así que
                // se reintenta y se reanuda desde el último punto guardado.
                if (restoreFailures <= 5) {
                    addLogEntry('↻ Corte o error de red (' + error.message + '). Reintentando desde el último punto guardado...');
                    window.setTimeout(function() {
                        runRestoreStep('chunk');
                    }, 1500);
                    return;
                }

                finishRestoreAsError(
                    'La restauración se interrumpió por un error del servidor o de la red (' + error.message + '). '
                    + 'El punto de guardado queda en disco: vuelve a abrir la consola para comprobar el estado. '
                    + 'No se puede confirmar que la restauración haya terminado.'
                );
            });
        }

        // El servidor responde con framing SSE, así que se parsea el cuerpo completo.
        function parseRestoreSseEvents(body) {
            var events = [];
            var blocks = String(body).split(/\\r?\\n\\r?\\n/);

            for (var i = 0; i < blocks.length; i++) {
                var lines = blocks[i].split(/\\r?\\n/);
                var name = '';
                var data = '';

                for (var j = 0; j < lines.length; j++) {
                    if (lines[j].indexOf('event:') === 0) {
                        name = lines[j].substring(6).trim();
                    } else if (lines[j].indexOf('data:') === 0) {
                        data += lines[j].substring(5).trim();
                    }
                }

                if (!name || !data) {
                    continue;
                }

                try {
                    events.push({ event: name, data: JSON.parse(data) });
                } catch (err) {
                    // Bloque no parseable: se ignora y se sigue con el resto.
                }
            }

            return events;
        }

        // Vuelca el detalle de errores y avisos. Un contador sin los mensajes no
        // le sirve al operador para saber qué falló.
        function logRestoreDetail(payload) {
            var errors = payload.errors || [];
            var warnings = payload.warnings || [];

            for (var i = 0; i < errors.length; i++) {
                addLogEntry('   ✗ ' + errors[i]);
            }

            for (var j = 0; j < warnings.length; j++) {
                addLogEntry('   ⚠ ' + warnings[j]);
            }
        }

        function finishRestoreAsSuccess(warningCount) {
            restoreFinished = true;

            var message = '¡Restauración completada con éxito!';
            if (warningCount > 0) {
                message += ' (' + warningCount + ' aviso(s) en el registro)';
            }

            updateProgress(100, message, 'success');
            addLogEntry('✓ ' + message);
            document.getElementById('restoreCompleteBtn').style.display = 'inline-block';
            document.getElementById('modalSpinner').classList.remove('fa-spin');
            document.getElementById('statusSpinner').classList.remove('fa-spin');
        }

        function finishRestoreAsError(message, percent) {
            restoreFinished = true;
            updateProgress(percent || 0, message, 'danger');
            addLogEntry('✗ ERROR: ' + message);
            document.getElementById('restoreErrorBtn').style.display = 'inline-block';
            document.getElementById('modalSpinner').classList.remove('fa-spin');
            document.getElementById('statusSpinner').classList.remove('fa-spin');
        }

        function updateProgress(percent, message, type) {
            var progressBar = document.getElementById('restoreProgressBar');
            var statusMessage = document.getElementById('restoreStatusMessage');
            var statusText = document.getElementById('restoreStatusText');

            progressBar.style.width = percent + '%';
            progressBar.setAttribute('aria-valuenow', percent);
            progressBar.textContent = percent + '%';

            statusText.textContent = message;

            statusMessage.className = 'alert text-center';
            progressBar.className = 'progress-bar progress-bar-striped active progress-bar-danger';

            if (type === 'success') {
                statusMessage.classList.add('alert-success');
                progressBar.classList.remove('active');
                progressBar.classList.remove('progress-bar-danger');
                progressBar.classList.add('progress-bar-success');
            } else if (type === 'danger') {
                statusMessage.classList.add('alert-danger');
            } else {
                statusMessage.classList.add('alert-info');
            }
        }

        function addLogEntry(message) {
            var detailsDiv = document.getElementById('restoreDetails');
            var line = document.createElement('div');

            // `textContent`, no `innerHTML`: los mensajes los construye el
            // servidor y pueden incluir un nombre de fichero de backup. Un
            // nombre con marcado no debe poder pintar HTML dentro del console,
            // que es la pantalla que se abre cuando todo lo demás está roto.
            line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;

            detailsDiv.appendChild(line);
            detailsDiv.scrollTop = detailsDiv.scrollHeight;
        }

        jQuery(document).ready(function() {
            if (!restoreAvailable) {
                return;
            }

            jQuery('.restore-btn').on('click', function(e) {
                e.preventDefault();

                var file = jQuery(this).data('file');
                var type = jQuery(this).data('type') || 'complete';

                if (!file) {
                    alert('Error: No se pudo determinar el archivo de backup');
                    return;
                }

                var confirmMessage = '¡ADVERTENCIA! Esto sobrescribirá TODOS los archivos y la base de datos. ¿Estás seguro?';
                if (type === 'database') {
                    confirmMessage = '¿Restaurar SOLO la base de datos? Los archivos del sistema no se modificarán.';
                }

                if (confirm(confirmMessage)) {
                    startRestore(file, type);
                }
            });
        });
    })();
    </script>
JS;
}
