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
                                                <button type="button" id="btnCreateBackup" class="btn btn-success">
                                                    <i class="fa fa-plus"></i> Crear Copia de Seguridad
                                                </button>
                                                <button type="button" id="btnUploadBackup" class="btn btn-info"
                                                    data-toggle="collapse" data-target="#uploadBackupPanel">
                                                    <i class="fa fa-cloud-upload"></i> Subir Copia de Seguridad
                                                </button>

                                                <!-- Panel de subida colapsable -->
                                                <div class="collapse" id="uploadBackupPanel" style="margin-top: 15px;">
                                                    <div class="panel panel-info">
                                                        <div class="panel-heading">
                                                            <h4 class="panel-title">
                                                                <i class="fa fa-cloud-upload"></i> Subir Copia de
                                                                Seguridad
                                                            </h4>
                                                        </div>
                                                        <div class="panel-body">
                                                            <div class="alert alert-info">
                                                                <i class="fa fa-info-circle"></i>
                                                                <strong>Subida por fragmentos:</strong> Permite subir
                                                                archivos ZIP de backup de hasta 2GB.
                                                                Si la conexión se interrumpe, la subida se puede
                                                                reanudar.
                                                            </div>

                                                            <!-- Zona de Drop -->
                                                            <div id="backup-drop-zone" class="backup-drop-zone"
                                                                style="border: 2px dashed #3c8dbc; border-radius: 8px; padding: 40px; text-align: center; cursor: pointer; background: #f8f9fa; transition: all 0.3s;">
                                                                <i class="fa fa-cloud-upload"
                                                                    style="font-size: 48px; color: #3c8dbc;"></i>
                                                                <h4>Arrastra el archivo ZIP aquí</h4>
                                                                <p class="text-muted">o haz clic para seleccionar</p>
                                                                <button type="button" class="btn btn-primary"
                                                                    id="backup-browse-btn">
                                                                    <i class="fa fa-folder-open"></i> Seleccionar
                                                                    Archivo
                                                                </button>
                                                                <input type="file" id="backup-file-input" accept=".zip"
                                                                    style="display: none;">
                                                            </div>

                                                            <!-- Progreso de subida -->
                                                            <div id="backup-upload-progress"
                                                                style="display: none; margin-top: 15px;">
                                                                <div class="progress" style="height: 25px;">
                                                                    <div class="progress-bar progress-bar-info progress-bar-striped active"
                                                                        role="progressbar" style="width: 0%"
                                                                        id="backup-progress-bar">
                                                                        <span id="backup-progress-text">0%</span>
                                                                    </div>
                                                                </div>
                                                                <p id="backup-upload-status"
                                                                    class="text-center text-muted"></p>
                                                            </div>

                                                            <div class="row" style="margin-top: 15px;">
                                                                <div class="col-md-12">
                                                                    <ul class="list-inline text-muted">
                                                                        <li><i class="fa fa-check text-success"></i>
                                                                            Formato: <strong>.zip</strong></li>
                                                                        <li><i class="fa fa-check text-success"></i>
                                                                            Tamaño máximo: <strong>2 GB</strong></li>
                                                                        <li><i class="fa fa-check text-success"></i>
                                                                            Subida reanudable</li>
                                                                    </ul>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
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
                                                                <!-- Dropdown descargar -->
                                                                <div class="btn-group">
                                                                    <button type="button"
                                                                        class="btn btn-xs btn-success dropdown-toggle"
                                                                        data-toggle="dropdown" aria-haspopup="true"
                                                                        aria-expanded="false">
                                                                        <i class="fa fa-download"></i> Descargar <span
                                                                            class="caret"></span>
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php if (!empty($group['complete'])): ?>
                                                                            <li>
                                                                                <a
                                                                                    href="<?php echo $fsc->url(); ?>&action=download_backup&file=<?php echo urlencode($group['complete']['name']); ?>">
                                                                                    <i class="fa fa-file-zip-o"></i> Paquete
                                                                                    Completo
                                                                                    <small
                                                                                        class="text-muted">(<?php echo $group['complete']['size_formatted'] ?? ''; ?>)</small>
                                                                                </a>
                                                                            </li>
                                                                            <li role="separator" class="divider"></li>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($group['database'])): ?>
                                                                            <li>
                                                                                <a
                                                                                    href="<?php echo $fsc->url(); ?>&action=download_backup&file=<?php echo urlencode($group['database']['name']); ?>">
                                                                                    <i class="fa fa-database"></i> Base de Datos
                                                                                    <small
                                                                                        class="text-muted">(<?php echo $group['database']['size_formatted']; ?>)</small>
                                                                                </a>
                                                                            </li>
                                                                        <?php endif; ?>
                                                                        <?php if (!empty($group['files'])): ?>
                                                                            <li>
                                                                                <a
                                                                                    href="<?php echo $fsc->url(); ?>&action=download_backup&file=<?php echo urlencode($group['files']['name']); ?>">
                                                                                    <i class="fa fa-file-archive-o"></i> Archivos
                                                                                    <small
                                                                                        class="text-muted">(<?php echo $group['files']['size_formatted']; ?>)</small>
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
    <div class="modal fade" id="restoreProgressModal" tabindex="-1" role="dialog" data-backdrop="static"
        data-keyboard="false">
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
                        <div id="restoreProgressBar" class="progress-bar progress-bar-striped active" role="progressbar"
                            aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; min-width: 2em;">
                            0%
                        </div>
                    </div>

                    <div id="restoreStatusMessage" class="alert alert-info text-center" style="margin-top: 15px;">
                        <i class="fa fa-spinner fa-spin"></i>
                        <span id="restoreStatusText">Iniciando restauración...</span>
                    </div>

                    <div id="restoreDetails" class="well well-sm"
                        style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; background-color: #f5f5f5;">
                        <small class="text-muted">Esperando inicio...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="index.php?page=admin_updater" id="restoreCompleteBtn" class="btn btn-success"
                        style="display: none;">
                        <i class="fa fa-check"></i> Aceptar
                    </a>
                    <button type="button" id="restoreErrorBtn" class="btn btn-danger" data-dismiss="modal"
                        style="display: none;">
                        <i class="fa fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Progreso de Backup -->
    <div class="modal fade" id="backupProgressModal" tabindex="-1" role="dialog" data-backdrop="static"
        data-keyboard="false">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success" style="color: white;">
                    <h4 class="modal-title">
                        <i class="fa fa-database fa-spin"></i> Creando Copia de Seguridad
                    </h4>
                </div>
                <div class="modal-body">
                    <div class="text-center" style="margin-bottom: 20px;">
                        <i class="fa fa-archive" style="font-size: 48px; color: #00a65a;"></i>
                    </div>

                    <div class="progress" style="height: 30px;">
                        <div id="backupProgressBar"
                            class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar"
                            aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; min-width: 2em;">
                            0%
                        </div>
                    </div>

                    <div id="backupStatusMessage" class="alert alert-info text-center" style="margin-top: 15px;">
                        <i class="fa fa-spinner fa-spin"></i>
                        <span id="backupStatusText">Iniciando copia de seguridad...</span>
                    </div>

                    <div id="backupDetails" class="well well-sm"
                        style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; background-color: #f5f5f5;">
                        <small class="text-muted">Esperando inicio...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="index.php?page=admin_updater" id="backupCompleteBtn" class="btn btn-success"
                        style="display: none;">
                        <i class="fa fa-check"></i> Aceptar
                    </a>
                    <button type="button" id="backupErrorBtn" class="btn btn-danger" data-dismiss="modal"
                        style="display: none;">
                        <i class="fa fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
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

                restoreEventSource.addEventListener('start', function (e) {
                    var data = JSON.parse(e.data);
                    addLogEntry('Iniciando: ' + data.message);
                });

                restoreEventSource.addEventListener('init', function (e) {
                    var data = JSON.parse(e.data);
                    updateProgress(data.percent, data.message);
                    addLogEntry(data.message);
                });

                restoreEventSource.addEventListener('phase', function (e) {
                    var data = JSON.parse(e.data);
                    addLogEntry('=== Fase: ' + data.message + ' ===');
                });

                restoreEventSource.addEventListener('progress', function (e) {
                    var data = JSON.parse(e.data);
                    updateProgress(data.percent, data.message);
                    if (data.step && data.step.indexOf('_progress') === -1) {
                        addLogEntry(data.message);
                    }
                });

                restoreEventSource.addEventListener('complete', function (e) {
                    var data = JSON.parse(e.data);
                    updateProgress(100, data.message, 'success');
                    addLogEntry('✓ ' + data.message);
                    document.getElementById('restoreCompleteBtn').style.display = 'inline-block';
                    restoreEventSource.close();
                });

                restoreEventSource.addEventListener('error', function (e) {
                    var data = JSON.parse(e.data);
                    updateProgress(data.percent || 0, data.message, 'danger');
                    addLogEntry('✗ ERROR: ' + data.message);
                    document.getElementById('restoreErrorBtn').style.display = 'inline-block';
                    restoreEventSource.close();
                });

                restoreEventSource.onerror = function () {
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
                detailsDiv.innerHTML = restoreLog.map(function (entry) {
                    return '<div>' + entry + '</div>';
                }).join('');
                detailsDiv.scrollTop = detailsDiv.scrollHeight;
            }

            // Override restore link clicks
            document.addEventListener('DOMContentLoaded', function () {
                // Find all restore links and override them
                var restoreLinks = document.querySelectorAll('a[href*="action=restore_complete"]');
                restoreLinks.forEach(function (link) {
                    link.addEventListener('click', function (e) {
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
                dbRestoreLinks.forEach(function (link) {
                    link.addEventListener('click', function (e) {
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
                filesRestoreLinks.forEach(function (link) {
                    link.addEventListener('click', function (e) {
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

                // ========================================
                // BACKUP FUNCTIONALITY
                // ========================================
                var backupEventSource = null;
                var backupLog = [];

                function startBackup() {
                    // Reset UI
                    backupLog = [];
                    updateBackupProgress(0, 'Iniciando copia de seguridad...');
                    document.getElementById('backupDetails').innerHTML = '<small class="text-muted">Conectando al servidor...</small>';
                    document.getElementById('backupCompleteBtn').style.display = 'none';
                    document.getElementById('backupErrorBtn').style.display = 'none';

                    // Show modal
                    jQuery('#backupProgressModal').modal('show');

                    // Start SSE connection
                    var sseUrl = 'plugins/system_updater/process_backup.php?action=start';

                    backupEventSource = new EventSource(sseUrl);

                    backupEventSource.addEventListener('start', function (e) {
                        var data = JSON.parse(e.data);
                        addBackupLogEntry('Iniciando: ' + data.message);
                    });

                    backupEventSource.addEventListener('init', function (e) {
                        var data = JSON.parse(e.data);
                        updateBackupProgress(data.percent, data.message);
                        addBackupLogEntry(data.message);
                    });

                    backupEventSource.addEventListener('phase', function (e) {
                        var data = JSON.parse(e.data);
                        addBackupLogEntry('=== Fase: ' + data.message + ' ===');
                    });

                    backupEventSource.addEventListener('progress', function (e) {
                        var data = JSON.parse(e.data);
                        updateBackupProgress(data.percent, data.message);
                        if (data.step && data.step.indexOf('_progress') === -1 && data.step.indexOf('db_table') === -1) {
                            addBackupLogEntry(data.message);
                        }
                    });

                    backupEventSource.addEventListener('complete', function (e) {
                        var data = JSON.parse(e.data);
                        updateBackupProgress(100, data.message, 'success');
                        addBackupLogEntry('✓ ' + data.message);
                        if (data.backup_name) {
                            addBackupLogEntry('   Backup: ' + data.backup_name);
                        }
                        document.getElementById('backupCompleteBtn').style.display = 'inline-block';
                        backupEventSource.close();
                    });

                    backupEventSource.addEventListener('error', function (e) {
                        var data = JSON.parse(e.data);
                        updateBackupProgress(data.percent || 0, data.message, 'danger');
                        addBackupLogEntry('✗ ERROR: ' + data.message);
                        document.getElementById('backupErrorBtn').style.display = 'inline-block';
                        backupEventSource.close();
                    });

                    backupEventSource.onerror = function () {
                        updateBackupProgress(0, 'Error de conexión con el servidor', 'danger');
                        addBackupLogEntry('✗ Error de conexión');
                        document.getElementById('backupErrorBtn').style.display = 'inline-block';
                        backupEventSource.close();
                    };
                }

                function updateBackupProgress(percent, message, type) {
                    var progressBar = document.getElementById('backupProgressBar');
                    var statusMessage = document.getElementById('backupStatusMessage');
                    var statusText = document.getElementById('backupStatusText');

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
                        progressBar.classList.remove('progress-bar-success');
                        progressBar.classList.add('progress-bar-danger');
                    } else {
                        statusMessage.classList.add('alert-info');
                    }
                }

                function addBackupLogEntry(message) {
                    backupLog.push('[' + new Date().toLocaleTimeString() + '] ' + message);
                    var detailsDiv = document.getElementById('backupDetails');
                    detailsDiv.innerHTML = backupLog.map(function (entry) {
                        return '<div>' + entry + '</div>';
                    }).join('');
                    detailsDiv.scrollTop = detailsDiv.scrollHeight;
                }

                // Handle backup button click
                var backupBtn = document.getElementById('btnCreateBackup');
                if (backupBtn) {
                    backupBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (confirm('¿Crear una copia de seguridad completa (base de datos + archivos)?')) {
                            startBackup();
                        }
                    });
                }
            });
        })();
    </script>

    <!-- Resumable.js para subida por chunks -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/resumablejs@1.1.0/resumable.min.js"></script>

    <script>
        (function () {
            // ========================================
            // BACKUP UPLOAD FUNCTIONALITY
            // ========================================

            document.addEventListener('DOMContentLoaded', function () {
                // Verificar que Resumable esté disponible
                if (typeof Resumable === 'undefined') {
                    console.error('Resumable.js no está cargado');
                    var statusEl = document.getElementById('backup-upload-status');
                    if (statusEl) {
                        statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Error: Resumable.js no está disponible</span>';
                    }
                    return;
                }

                var dropZone = document.getElementById('backup-drop-zone');
                var browseButton = document.getElementById('backup-browse-btn');
                var progressContainer = document.getElementById('backup-upload-progress');
                var progressBar = document.getElementById('backup-progress-bar');
                var progressText = document.getElementById('backup-progress-text');
                var statusText = document.getElementById('backup-upload-status');

                if (!dropZone) return;

                // Configurar Resumable.js
                var r = new Resumable({
                    target: '<?php echo $fsc->url(); ?>',
                    chunkSize: 10 * 1024 * 1024, // 10MB por chunk
                    simultaneousUploads: 3,
                    testChunks: false,
                    throttleProgressCallbacks: 0.5,
                    fileType: ['zip'],
                    maxFiles: 1,
                    maxFileSize: 2 * 1024 * 1024 * 1024, // 2GB máximo
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!r.support) {
                    alert('Tu navegador no soporta subidas de archivos grandes. Por favor, actualiza tu navegador.');
                    return;
                }

                // Asignar el drag & drop
                r.assignDrop(dropZone);

                // Efectos visuales de drag & drop
                dropZone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    this.style.borderColor = '#00a65a';
                    this.style.background = '#e8f5e9';
                });

                dropZone.addEventListener('dragleave', function (e) {
                    this.style.borderColor = '#3c8dbc';
                    this.style.background = '#f8f9fa';
                });

                dropZone.addEventListener('drop', function (e) {
                    this.style.borderColor = '#3c8dbc';
                    this.style.background = '#f8f9fa';
                });

                // Click en la zona para abrir selector
                dropZone.addEventListener('click', function (e) {
                    if (e.target !== browseButton && e.target.tagName !== 'BUTTON') {
                        browseButton.click();
                    }
                });

                // Asignar el botón de browse
                if (browseButton) {
                    r.assignBrowse(browseButton);
                }

                // Evento: Archivo añadido
                r.on('fileAdded', function (file) {
                    // Validar extensión
                    var ext = file.fileName.split('.').pop().toLowerCase();
                    if (ext !== 'zip') {
                        alert('Solo se permiten archivos .zip de backup');
                        return false;
                    }

                    // Mostrar barra de progreso
                    if (progressContainer) progressContainer.style.display = 'block';
                    if (dropZone) dropZone.style.display = 'none';
                    if (progressBar) {
                        progressBar.style.width = '0%';
                        progressBar.classList.remove('progress-bar-success', 'progress-bar-danger');
                        progressBar.classList.add('progress-bar-info', 'active');
                    }
                    if (progressText) progressText.textContent = '0%';
                    if (statusText) statusText.textContent = 'Preparando subida de: ' + file.fileName;

                    // Iniciar la subida
                    r.upload();
                });

                // Evento: Progreso de subida
                r.on('fileProgress', function (file) {
                    var progress = Math.floor(file.progress() * 100);
                    if (progressBar) progressBar.style.width = progress + '%';
                    if (progressText) progressText.textContent = progress + '%';

                    if (progress < 100 && statusText) {
                        var fileSize = formatBytes(file.size);
                        var uploaded = formatBytes(file.size * file.progress());
                        statusText.textContent = 'Subiendo: ' + file.fileName + ' (' + uploaded + ' de ' + fileSize + ')';
                    }
                });

                // Evento: Subida exitosa
                r.on('fileSuccess', function (file, message) {
                    if (progressBar) {
                        progressBar.classList.remove('active', 'progress-bar-info');
                        progressBar.classList.add('progress-bar-success');
                    }
                    if (progressText) progressText.textContent = '100%';
                    if (statusText) {
                        statusText.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> ¡Archivo subido correctamente!</span>';
                    }

                    // Recargar la página después de 2 segundos
                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                });

                // Evento: Error en la subida
                r.on('fileError', function (file, message) {
                    if (progressBar) {
                        progressBar.classList.remove('active', 'progress-bar-info');
                        progressBar.classList.add('progress-bar-danger');
                    }

                    var errorMsg = 'Error desconocido';
                    try {
                        var response = JSON.parse(message);
                        errorMsg = response.message || message;
                    } catch (e) {
                        errorMsg = message;
                    }

                    if (statusText) {
                        statusText.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> <strong>Error:</strong> ' + errorMsg + '</span>';
                    }

                    // Mostrar opción de reintentar
                    setTimeout(function () {
                        if (confirm('¿Deseas reintentar la subida?')) {
                            r.upload();
                        } else {
                            // Restaurar zona de drop
                            if (progressContainer) progressContainer.style.display = 'none';
                            if (dropZone) dropZone.style.display = 'block';
                            r.files = [];
                        }
                    }, 2000);
                });

                // Función para formatear bytes
                function formatBytes(bytes) {
                    if (bytes >= 1073741824) {
                        return (bytes / 1073741824).toFixed(2) + ' GB';
                    } else if (bytes >= 1048576) {
                        return (bytes / 1048576).toFixed(2) + ' MB';
                    } else if (bytes >= 1024) {
                        return (bytes / 1024).toFixed(2) + ' KB';
                    } else {
                        return bytes + ' bytes';
                    }
                }
            });
        })();
    </script>

</body>

</html>