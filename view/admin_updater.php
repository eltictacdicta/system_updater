<?php
/**
 * Vista del Actualizador - Plugin system_updater
 * 
 * Esta vista usa PHP puro embebido para máxima compatibilidad
 * con versiones legacy del framework.
 * 
 * Variables disponibles desde el controlador:
 * - $fsc: instancia del controlador (admin_updater)
 * - $fsc->backups: lista de backups agrupados
 * - $fsc->updates: actualizaciones disponibles
 * - $fsc->updaterInfo: info del actualizador
 * - $fsc->successMessage, $fsc->errorMessage: mensajes
 */
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es" xml:lang="es">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Actualizador - FSFramework</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="view/css/bootstrap.min.css" />
    <!-- Font Awesome -->
    <link rel="stylesheet" href="view/css/font-awesome.min.css" />
    <!-- AdminLTE Theme -->
    <link rel="stylesheet" href="themes/AdminLTE/css/AdminLTE.min.css" />
    <link rel="stylesheet" href="themes/AdminLTE/css/skins/skin-blue.min.css" />
    <!-- jQuery y Bootstrap JS -->
    <script type="text/javascript" src="themes/AdminLTE/js/jQuery-2.1.4.min.js"></script>
    <script type="text/javascript" src="view/js/bootstrap.min.js"></script>
    <!-- AdminLTE JS -->
    <script type="text/javascript" src="themes/AdminLTE/js/adminlte.min.js"></script>
</head>

<body class="hold-transition skin-blue layout-top-nav">
    <div class="wrapper">
        <div class="content-wrapper" style="margin-left: 0;">
            <section class="content-header">
                <h1>
                    <i class="fa fa-upload"></i> Actualizador de FSFramework
                    <small>v
                        <?php echo htmlspecialchars($fsc->updaterInfo['version'] ?? '1.0'); ?>
                    </small>
                </h1>
            </section>
            <section class="content">
                <div class="row">
                    <div class="col-sm-12">
                        <a href="index.php?page=admin_home" class="btn btn-sm btn-default">
                            <i class="fa fa-arrow-left"></i>
                            <span class="hidden-xs">&nbsp;Panel de control</span>
                        </a>
                        <a href="index.php?page=admin_plugin_store" class="btn btn-sm btn-primary">
                            <i class="fa fa-shopping-cart"></i>
                            <span class="hidden-xs">&nbsp;Tienda de Plugins</span>
                        </a>
                        <br /><br />

                        <?php if ($fsc->successMessage): ?>
                        <div class="alert alert-success alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <i class="fa fa-check-circle"></i>
                            <?php echo htmlspecialchars($fsc->successMessage); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($fsc->errorMessage): ?>
                        <div class="alert alert-danger alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <i class="fa fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($fsc->errorMessage); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-12">
                        <div class="box box-primary">
                            <div class="box-header with-border">
                                <h3 class="box-title">Actualizaciones, Opciones y Copias de Seguridad</h3>
                            </div>
                            <div class="box-body">
                                <ul class="nav nav-tabs" role="tablist">
                                    <li role="presentation" class="active">
                                        <a href="#actualizaciones" role="tab" data-toggle="tab">
                                            <i class="fa fa-download"></i> Actualizaciones
                                        </a>
                                    </li>
                                    <li role="presentation">
                                        <a href="#backups" role="tab" data-toggle="tab">
                                            <i class="fa fa-database"></i> Copias de Seguridad
                                        </a>
                                    </li>
                                    <li role="presentation">
                                        <a href="#info" role="tab" data-toggle="tab">
                                            <i class="fa fa-info-circle"></i> Información
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content" style="padding-top: 15px;">
                                    <!-- TAB ACTUALIZACIONES -->
                                    <div role="tabpanel" class="tab-pane active" id="actualizaciones">
                                        <?php if ($fsc->updates['core']): ?>
                                        <div class="callout callout-warning">
                                            <h4><i class="fa fa-arrow-up"></i> Actualización del Núcleo Disponible</h4>
                                            <p>
                                                <strong>Versión actual:</strong>
                                                <?php echo $fsc->plugin_manager->version; ?> |
                                                <strong>Nueva versión:</strong>
                                                <?php echo htmlspecialchars($fsc->updates['core_new_version']); ?>
                                            </p>
                                            <a href="<?php echo $fsc->url(); ?>&action=update_core"
                                                class="btn btn-warning"
                                                onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Actualizando...'; this.disabled=true; return confirm('¿Actualizar el núcleo? Se creará una copia de seguridad automáticamente.');">
                                                <i class="fa fa-upload"></i> Actualizar Núcleo
                                            </a>
                                        </div>
                                        <?php else: ?>
                                        <div class="alert alert-success">
                                            <i class="fa fa-check-circle"></i> El núcleo está actualizado (v
                                            <?php echo $fsc->plugin_manager->version; ?>)
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($fsc->updates['plugins'])): ?>
                                        <h4><i class="fa fa-puzzle-piece"></i> Plugins con Actualizaciones</h4>
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Plugin</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Versión Actual</th>
                                                    <th class="text-center">Nueva Versión</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($fsc->updates['plugins'] as $plugin): ?>
                                                <tr>
                                                    <td><strong>
                                                            <?php echo htmlspecialchars($plugin['name']); ?>
                                                        </strong></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($plugin['description'] ?? ''); ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php echo htmlspecialchars($plugin['current_version']); ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="label label-info">
                                                            <?php echo htmlspecialchars($plugin['new_version']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="<?php echo $fsc->url(); ?>&action=update_plugin&plugin=<?php echo urlencode($plugin['name']); ?>"
                                                            class="btn btn-xs btn-primary"
                                                            onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i>'; return confirm('¿Actualizar <?php echo htmlspecialchars($plugin['name']); ?>?');">
                                                            <i class="fa fa-upload"></i> Actualizar
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="fa fa-info-circle"></i> No hay plugins con actualizaciones
                                            pendientes.
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- TAB BACKUPS -->
                                    <div role="tabpanel" class="tab-pane" id="backups">
                                        <div class="row">
                                            <div class="col-md-12">
                                                <a href="<?php echo $fsc->url(); ?>&action=create_backup"
                                                    class="btn btn-success"
                                                    onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Creando...'; this.disabled=true;">
                                                    <i class="fa fa-plus"></i> Crear Copia de Seguridad
                                                </a>
                                                <hr>
                                            </div>
                                        </div>

                                        <h4><i class="fa fa-list"></i> Copias Disponibles</h4>
                                        <?php if (empty($fsc->backups)): ?>
                                        <div class="alert alert-info">No hay copias de seguridad disponibles.</div>
                                        <?php else: ?>
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Copia</th>
                                                    <th>Base de Datos</th>
                                                    <th>Archivos</th>
                                                    <th>Fecha</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($fsc->backups as $group): ?>
                                                <tr>
                                                    <td>
                                                        <i class="fa fa-archive"></i>
                                                        <strong>
                                                            <?php echo htmlspecialchars($group['base_name']); ?>
                                                        </strong>
                                                        <?php if (!empty($group['complete'])): ?>
                                                        <br><small class="text-muted"><i class="fa fa-file-zip-o"></i>
                                                            Paquete completo</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($group['database'])): ?>
                                                        <span class="label label-info"><i class="fa fa-database"></i>
                                                            database</span>
                                                        <br><small>
                                                            <?php echo $group['database']['size_formatted']; ?>
                                                        </small>
                                                        <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($group['files'])): ?>
                                                        <span class="label label-success"><i
                                                                class="fa fa-file-archive-o"></i> files</span>
                                                        <br><small>
                                                            <?php echo $group['files']['size_formatted']; ?>
                                                        </small>
                                                        <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $group['date']; ?>
                                                    </td>
                                                    <td>
                                                        <!-- Dropdown restaurar -->
                                                        <div class="btn-group">
                                                            <button type="button"
                                                                class="btn btn-xs btn-primary dropdown-toggle"
                                                                data-toggle="dropdown" aria-haspopup="true"
                                                                aria-expanded="false">
                                                                <i class="fa fa-undo"></i> Restaurar <span
                                                                    class="caret"></span>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <?php if (!empty($group['complete'])): ?>
                                                                <li>
                                                                    <a href="<?php echo $fsc->url(); ?>&action=restore_complete&file=<?php echo urlencode($group['complete']['name']); ?>"
                                                                        onclick="return confirm('¿Restaurar TODO? Esta acción sobrescribirá los datos actuales.');">
                                                                        <i class="fa fa-refresh"></i> Restaurar Todo
                                                                    </a>
                                                                </li>
                                                                <li role="separator" class="divider"></li>
                                                                <?php endif; ?>
                                                                <?php if (!empty($group['database'])): ?>
                                                                <li>
                                                                    <a href="<?php echo $fsc->url(); ?>&action=restore_database&file=<?php echo urlencode($group['database']['name']); ?>"
                                                                        onclick="return confirm('¿Restaurar solo la BASE DE DATOS?');">
                                                                        <i class="fa fa-database"></i> Solo Base de
                                                                        Datos
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                                <?php if (!empty($group['files'])): ?>
                                                                <li>
                                                                    <a href="<?php echo $fsc->url(); ?>&action=restore_files&file=<?php echo urlencode($group['files']['name']); ?>"
                                                                        onclick="return confirm('¿Restaurar solo los ARCHIVOS?');">
                                                                        <i class="fa fa-file-archive-o"></i> Solo
                                                                        Archivos
                                                                    </a>
                                                                </li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        </div>
                                                        <!-- Eliminar -->
                                                        <a href="<?php echo $fsc->url(); ?>&action=delete_backup_group&base_name=<?php echo urlencode($group['base_name']); ?>"
                                                            class="btn btn-xs btn-danger"
                                                            onclick="return confirm('¿Eliminar todas las copias de este grupo?');">
                                                            <i class="fa fa-trash"></i> Eliminar
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php endif; ?>

                                        <p class="text-muted">
                                            <i class="fa fa-info-circle"></i>
                                            Las copias se almacenan en:
                                            <code><?php echo htmlspecialchars($fsc->getBackupPath()); ?></code><br>
                                            Se mantienen automáticamente las últimas 5 copias.
                                        </p>
                                    </div>

                                    <!-- TAB INFO -->
                                    <div role="tabpanel" class="tab-pane" id="info">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="box box-info">
                                                    <div class="box-header with-border">
                                                        <h4 class="box-title"><i class="fa fa-cogs"></i> Información del
                                                            Sistema</h4>
                                                    </div>
                                                    <div class="box-body">
                                                        <table class="table table-striped">
                                                            <tr>
                                                                <th>Versión FSFramework:</th>
                                                                <td><span class="label label-primary">
                                                                        <?php echo $fsc->plugin_manager->version; ?>
                                                                    </span></td>
                                                            </tr>
                                                            <tr>
                                                                <th>Versión Plugin Updater:</th>
                                                                <td>
                                                                    <?php echo htmlspecialchars($fsc->updaterInfo['version'] ?? '1.0'); ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <th>PHP:</th>
                                                                <td>
                                                                    <?php echo PHP_VERSION; ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <th>Directorio Backups:</th>
                                                                <td><code><?php echo htmlspecialchars($fsc->getBackupPath()); ?></code>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="box box-warning">
                                                    <div class="box-header with-border">
                                                        <h4 class="box-title"><i class="fa fa-link"></i> Enlaces Útiles
                                                        </h4>
                                                    </div>
                                                    <div class="box-body">
                                                        <ul class="list-unstyled">
                                                            <li><a href="https://github.com/eltictacdicta/fs-framework"
                                                                    target="_blank">
                                                                    <i class="fa fa-github"></i> Repositorio en GitHub
                                                                </a></li>
                                                            <li><a href="index.php?page=admin_plugin_store">
                                                                    <i class="fa fa-shopping-cart"></i> Tienda de
                                                                    Plugins
                                                                </a></li>
                                                            <li><a href="index.php?page=admin_home">
                                                                    <i class="fa fa-home"></i> Panel de Control
                                                                </a></li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
<!-- Modal de Progreso de Restauración -->
<div class="modal fade" id="restoreProgressModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h4 class="modal-title">
                    <i class="fa fa-refresh fa-spin"></i> Restaurando Copia de Seguridad
                </h4>
            </div>
            <div class="modal-body">
                <div class="text-center" style="margin-bottom: 20px;">
                    <i class="fa fa-database" style="font-size: 48px; color: #3c8dbc;"></i>
                </div>

                <div class="progress" style="height: 30px;">
                    <div id="restoreProgressBar" class="progress-bar progress-bar-striped active"
                        role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                        style="width: 0%; min-width: 2em;">
                        0%
                    </div>
                </div>

                <div id="restoreStatusMessage" class="alert alert-info text-center" style="margin-top: 15px;">
                    <i class="fa fa-spinner fa-spin"></i>
                    <span id="restoreStatusText">Iniciando restauración...</span>
                </div>

                <div id="restoreDetails" class="well well-sm" style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; background-color: #f5f5f5;">
                    <small class="text-muted">Esperando inicio...</small>
                </div>
            </div>
            <div class="modal-footer">
                <a href="index.php?page=admin_updater" id="restoreCompleteBtn" class="btn btn-success" style="display: none;">
                    <i class="fa fa-check"></i> Aceptar
                </a>
                <button type="button" id="restoreErrorBtn" class="btn btn-danger" data-dismiss="modal" style="display: none;">
                    <i class="fa fa-times"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var restoreEventSource = null;
    var restoreLog = [];

    function startRestore(file, type) {
        // Reset UI
        restoreLog = [];
        updateProgress(0, 'Iniciando restauración...');
        document.getElementById('restoreDetails').innerHTML = '<small class="text-muted">Conectando al servidor...</small>';
        document.getElementById('restoreCompleteBtn').style.display = 'none';
        document.getElementById('restoreErrorBtn').style.display = 'none';

        // Show modal
        jQuery('#restoreProgressModal').modal('show');

        // Start SSE connection
        var sseUrl = 'plugins/system_updater/process_restore.php?action=start&file=' + encodeURIComponent(file) + '&type=' + encodeURIComponent(type);

        restoreEventSource = new EventSource(sseUrl);

        restoreEventSource.addEventListener('start', function(e) {
            var data = JSON.parse(e.data);
            addLogEntry('Iniciando: ' + data.message);
        });

        restoreEventSource.addEventListener('init', function(e) {
            var data = JSON.parse(e.data);
            updateProgress(data.percent, data.message);
            addLogEntry(data.message);
        });

        restoreEventSource.addEventListener('phase', function(e) {
            var data = JSON.parse(e.data);
            addLogEntry('=== Fase: ' + data.message + ' ===');
        });

        restoreEventSource.addEventListener('progress', function(e) {
            var data = JSON.parse(e.data);
            updateProgress(data.percent, data.message);
            if (data.step && data.step.indexOf('_progress') === -1) {
                addLogEntry(data.message);
            }
        });

        restoreEventSource.addEventListener('complete', function(e) {
            var data = JSON.parse(e.data);
            updateProgress(100, data.message, 'success');
            addLogEntry('✓ ' + data.message);
            document.getElementById('restoreCompleteBtn').style.display = 'inline-block';
            restoreEventSource.close();
        });

        restoreEventSource.addEventListener('error', function(e) {
            var data = JSON.parse(e.data);
            updateProgress(data.percent || 0, data.message, 'danger');
            addLogEntry('✗ ERROR: ' + data.message);
            document.getElementById('restoreErrorBtn').style.display = 'inline-block';
            restoreEventSource.close();
        });

        restoreEventSource.onerror = function() {
            updateProgress(0, 'Error de conexión con el servidor', 'danger');
            addLogEntry('✗ Error de conexión');
            document.getElementById('restoreErrorBtn').style.display = 'inline-block';
            restoreEventSource.close();
        };
    }

    function updateProgress(percent, message, type) {
        var progressBar = document.getElementById('restoreProgressBar');
        var statusMessage = document.getElementById('restoreStatusMessage');
        var statusText = document.getElementById('restoreStatusText');

        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', percent);
        progressBar.textContent = percent + '%';

        statusText.textContent = message;

        // Update alert type
        statusMessage.className = 'alert text-center';
        if (type === 'success') {
            statusMessage.classList.add('alert-success');
            progressBar.classList.remove('active');
        } else if (type === 'danger') {
            statusMessage.classList.add('alert-danger');
            progressBar.classList.add('progress-bar-danger');
        } else {
            statusMessage.classList.add('alert-info');
        }
    }

    function addLogEntry(message) {
        restoreLog.push('[' + new Date().toLocaleTimeString() + '] ' + message);
        var detailsDiv = document.getElementById('restoreDetails');
        detailsDiv.innerHTML = restoreLog.map(function(entry) {
            return '<div>' + entry + '</div>';
        }).join('');
        detailsDiv.scrollTop = detailsDiv.scrollHeight;
    }

    // Override restore link clicks
    document.addEventListener('DOMContentLoaded', function() {
        // Find all restore links and override them
        var restoreLinks = document.querySelectorAll('a[href*="action=restore_complete"]');
        restoreLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                // Parse URL to get file parameter
                var url = new URL(link.href, window.location.href);
                var file = url.searchParams.get('file');

                if (!file) {
                    // Try to extract from the href directly
                    var match = link.href.match(/file=([^&]+)/);
                    if (match) {
                        file = decodeURIComponent(match[1]);
                    }
                }

                if (file) {
                    if (confirm('¿Restaurar TODO? Esta acción sobrescribirá los datos actuales. ¿Estás seguro?')) {
                        startRestore(file, 'complete');
                    }
                } else {
                    alert('Error: No se pudo determinar el archivo de backup');
                }
            });
        });

        // Handle restore database links
        var dbRestoreLinks = document.querySelectorAll('a[href*="action=restore_database"]');
        dbRestoreLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                var url = new URL(link.href, window.location.href);
                var file = url.searchParams.get('file');

                if (!file) {
                    var match = link.href.match(/file=([^&]+)/);
                    if (match) {
                        file = decodeURIComponent(match[1]);
                    }
                }

                if (file) {
                    if (confirm('¿Restaurar solo la BASE DE DATOS?')) {
                        startRestore(file, 'database');
                    }
                }
            });
        });

        // Handle restore files links
        var filesRestoreLinks = document.querySelectorAll('a[href*="action=restore_files"]');
        filesRestoreLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                var url = new URL(link.href, window.location.href);
                var file = url.searchParams.get('file');

                if (!file) {
                    var match = link.href.match(/file=([^&]+)/);
                    if (match) {
                        file = decodeURIComponent(match[1]);
                    }
                }

                if (file) {
                    if (confirm('¿Restaurar solo los ARCHIVOS?')) {
                        startRestore(file, 'files');
                    }
                }
            });
        });
    });
})();
</script>

</body>

</html>