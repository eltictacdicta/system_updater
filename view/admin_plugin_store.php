<?php
/**
 * Vista Tienda de Plugins - Plugin system_updater
 * 
 * Esta vista usa PHP puro embebido para máxima compatibilidad
 * con versiones legacy del framework.
 * 
 * Variables disponibles desde el controlador:
 * - $fsc: instancia del controlador (admin_plugin_store)
 * - $fsc->publicPlugins: plugins públicos
 * - $fsc->privatePlugins: plugins privados
 * - $fsc->privateConfig: configuración de plugins privados
 * - $fsc->privateEnabled: si plugins privados están habilitados
 */
$activeTab = filter_input(INPUT_GET, 'tab') ?: 'public';
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es" xml:lang="es">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Tienda de Plugins - FSFramework</title>
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
                    <i class="fa fa-shopping-cart"></i> Tienda de Plugins
                    <small>Descarga y gestiona plugins</small>
                </h1>
            </section>
            <section class="content">
                <div class="row">
                    <div class="col-sm-12">
                        <a href="index.php?page=admin_home" class="btn btn-sm btn-default">
                            <i class="fa fa-arrow-left"></i>
                            <span class="hidden-xs">&nbsp;Panel de control</span>
                        </a>
                        <a href="index.php?page=admin_updater" class="btn btn-sm btn-primary">
                            <i class="fa fa-upload"></i>
                            <span class="hidden-xs">&nbsp;Actualizador</span>
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
                            <div class="box-body">
                                <ul class="nav nav-tabs" role="tablist">
                                    <li role="presentation"
                                        class="<?php echo $activeTab === 'public' ? 'active' : ''; ?>">
                                        <a href="#public" role="tab" data-toggle="tab">
                                            <i class="fa fa-globe"></i> Plugins Públicos
                                            <span class="badge">
                                                <?php echo count($fsc->publicPlugins); ?>
                                            </span>
                                        </a>
                                    </li>
                                    <li role="presentation"
                                        class="<?php echo $activeTab === 'private' ? 'active' : ''; ?>">
                                        <a href="#private" role="tab" data-toggle="tab">
                                            <i class="fa fa-lock"></i> Plugins Privados
                                            <?php if ($fsc->privateEnabled): ?>
                                            <span class="badge">
                                                <?php echo count($fsc->privatePlugins); ?>
                                            </span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content" style="padding-top: 15px;">
                                    <!-- TAB PLUGINS PÚBLICOS -->
                                    <div role="tabpanel"
                                        class="tab-pane <?php echo $activeTab === 'public' ? 'active' : ''; ?>"
                                        id="public">
                                        <div class="row" style="margin-bottom: 10px;">
                                            <div class="col-md-12 text-right">
                                                <a href="<?php echo $fsc->url(); ?>&action=refresh"
                                                    class="btn btn-default btn-sm">
                                                    <i class="fa fa-refresh"></i> Actualizar Lista
                                                </a>
                                            </div>
                                        </div>

                                        <?php if (empty($fsc->publicPlugins)): ?>
                                        <div class="alert alert-info">
                                            <i class="fa fa-info-circle"></i> No se pudieron cargar los plugins
                                            públicos.
                                            Verifica tu conexión a internet.
                                        </div>
                                        <?php else: ?>
                                        <div class="row">
                                            <?php foreach ($fsc->publicPlugins as $plugin): ?>
                                            <div class="col-md-4 col-sm-6">
                                                <div class="box box-widget widget-user-2">
                                                    <div
                                                        class="widget-user-header bg-<?php echo $fsc->isInstalled($plugin['nombre']) ? 'green' : 'aqua'; ?>">
                                                        <h3 class="widget-user-username">
                                                            <?php echo htmlspecialchars($plugin['nombre']); ?>
                                                        </h3>
                                                        <h5 class="widget-user-desc">
                                                            <?php if (($plugin['tipo'] ?? 'gratis') === 'gratis'): ?>
                                                            <span class="label label-success">Gratis</span>
                                                            <?php else: ?>
                                                            <span class="label label-warning">
                                                                <?php echo htmlspecialchars($plugin['tipo']); ?>
                                                            </span>
                                                            <?php endif; ?>
                                                            v
                                                            <?php echo htmlspecialchars($plugin['version'] ?? '?'); ?>
                                                        </h5>
                                                    </div>
                                                    <div class="box-footer">
                                                        <p>
                                                            <?php echo htmlspecialchars(substr($plugin['descripcion'] ?? 'Sin descripción', 0, 100)); ?>
                                                            <?php echo strlen($plugin['descripcion'] ?? '') > 100 ? '...' : ''; ?>
                                                        </p>
                                                        <p class="text-muted">
                                                            <small>
                                                                <i class="fa fa-user"></i>
                                                                <?php echo htmlspecialchars($plugin['creador'] ?? 'Desconocido'); ?>
                                                                <?php if (isset($plugin['descargas'])): ?>
                                                                | <i class="fa fa-download"></i>
                                                                <?php echo $plugin['descargas']; ?> descargas
                                                                <?php endif; ?>
                                                            </small>
                                                        </p>
                                                        <div class="btn-group btn-group-justified">
                                                            <?php if (isset($plugin['link'])): ?>
                                                            <a href="<?php echo htmlspecialchars($plugin['link']); ?>"
                                                                target="_blank" class="btn btn-sm btn-default">
                                                                <i class="fa fa-github"></i> Ver
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if ($fsc->isInstalled($plugin['nombre'])): ?>
                                                            <?php if ($fsc->isActive($plugin['nombre'])): ?>
                                                            <span class="btn btn-sm btn-success disabled">
                                                                <i class="fa fa-check"></i> Activo
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="btn btn-sm btn-warning disabled">
                                                                <i class="fa fa-pause"></i> Instalado
                                                            </span>
                                                            <?php endif; ?>
                                                            <?php else: ?>
                                                            <a href="<?php echo $fsc->url(); ?>&action=download&plugin_id=<?php echo $plugin['id']; ?>"
                                                                class="btn btn-sm btn-primary"
                                                                onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i>'; return confirm('¿Descargar e instalar <?php echo htmlspecialchars($plugin['nombre']); ?>?');">
                                                                <i class="fa fa-download"></i> Instalar
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- TAB PLUGINS PRIVADOS -->
                                    <div role="tabpanel"
                                        class="tab-pane <?php echo $activeTab === 'private' ? 'active' : ''; ?>"
                                        id="private">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="box box-warning">
                                                    <div class="box-header with-border">
                                                        <h4 class="box-title"><i class="fa fa-cogs"></i> Configuración
                                                        </h4>
                                                    </div>
                                                    <form method="post" action="<?php echo $fsc->url(); ?>&tab=private">
                                                        <input type="hidden" name="action" value="save_private_config">
                                                        <div class="box-body">
                                                            <div class="form-group">
                                                                <label>Token de GitHub</label>
                                                                <input type="password" name="github_token"
                                                                    class="form-control"
                                                                    value="<?php echo htmlspecialchars($fsc->privateConfig['github_token'] ?? ''); ?>"
                                                                    placeholder="ghp_xxxxxxxxxxxx">
                                                                <p class="help-block">
                                                                    <a href="https://github.com/settings/tokens"
                                                                        target="_blank">
                                                                        Crear token en GitHub
                                                                    </a> (necesita permiso <code>repo</code>)
                                                                </p>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>URL del JSON de Plugins</label>
                                                                <input type="url" name="private_plugins_url"
                                                                    class="form-control"
                                                                    value="<?php echo htmlspecialchars($fsc->privateConfig['private_plugins_url'] ?? ''); ?>"
                                                                    placeholder="https://raw.githubusercontent.com/...">
                                                                <p class="help-block">URL del archivo JSON con la lista
                                                                    de plugins privados</p>
                                                            </div>
                                                        </div>
                                                        <div class="box-footer">
                                                            <button type="submit" class="btn btn-primary">
                                                                <i class="fa fa-save"></i> Guardar
                                                            </button>
                                                            <?php if ($fsc->privateEnabled): ?>
                                                            <a href="<?php echo $fsc->url(); ?>&action=test_private_connection&tab=private"
                                                                class="btn btn-default">
                                                                <i class="fa fa-plug"></i> Probar Conexión
                                                            </a>
                                                            <a href="<?php echo $fsc->url(); ?>&action=delete_private_config&tab=private"
                                                                class="btn btn-danger"
                                                                onclick="return confirm('¿Eliminar configuración de plugins privados?');">
                                                                <i class="fa fa-trash"></i> Eliminar
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <?php if (!$fsc->privateEnabled): ?>
                                                <div class="callout callout-info">
                                                    <h4><i class="fa fa-info-circle"></i> Plugins Privados</h4>
                                                    <p>
                                                        Configura un token de GitHub y la URL de tu repositorio privado
                                                        para descargar plugins que no están disponibles públicamente.
                                                    </p>
                                                </div>
                                                <?php else: ?>
                                                <div class="callout callout-success">
                                                    <h4><i class="fa fa-check-circle"></i> Conexión Configurada</h4>
                                                    <p>
                                                        Tienes <strong>
                                                            <?php echo count($fsc->privatePlugins); ?>
                                                        </strong> plugins privados disponibles.
                                                    </p>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($fsc->privateEnabled && !empty($fsc->privatePlugins)): ?>
                                        <hr>
                                        <h4><i class="fa fa-lock"></i> Plugins Privados Disponibles</h4>
                                        <div class="row">
                                            <?php foreach ($fsc->privatePlugins as $plugin): ?>
                                            <div class="col-md-4 col-sm-6">
                                                <div class="box box-widget widget-user-2">
                                                    <div
                                                        class="widget-user-header bg-<?php echo $fsc->isInstalled($plugin['nombre']) ? 'green' : 'purple'; ?>">
                                                        <h3 class="widget-user-username">
                                                            <i class="fa fa-lock"></i>
                                                            <?php echo htmlspecialchars($plugin['nombre']); ?>
                                                        </h3>
                                                        <h5 class="widget-user-desc">
                                                            <span class="label label-default">Privado</span>
                                                            v
                                                            <?php echo htmlspecialchars($plugin['version'] ?? '?'); ?>
                                                        </h5>
                                                    </div>
                                                    <div class="box-footer">
                                                        <p>
                                                            <?php echo htmlspecialchars(substr($plugin['descripcion'] ?? 'Sin descripción', 0, 100)); ?>
                                                            <?php echo strlen($plugin['descripcion'] ?? '') > 100 ? '...' : ''; ?>
                                                        </p>
                                                        <div class="btn-group btn-group-justified">
                                                            <?php if (isset($plugin['link'])): ?>
                                                            <a href="<?php echo htmlspecialchars($plugin['link']); ?>"
                                                                target="_blank" class="btn btn-sm btn-default">
                                                                <i class="fa fa-github"></i> Ver
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if ($fsc->isInstalled($plugin['nombre'])): ?>
                                                            <?php if ($fsc->isActive($plugin['nombre'])): ?>
                                                            <span class="btn btn-sm btn-success disabled">
                                                                <i class="fa fa-check"></i> Activo
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="btn btn-sm btn-warning disabled">
                                                                <i class="fa fa-pause"></i> Instalado
                                                            </span>
                                                            <?php endif; ?>
                                                            <?php else: ?>
                                                            <a href="<?php echo $fsc->url(); ?>&action=download_private&plugin_id=<?php echo $plugin['id']; ?>&tab=private"
                                                                class="btn btn-sm btn-primary"
                                                                onclick="this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i>'; return confirm('¿Descargar e instalar <?php echo htmlspecialchars($plugin['nombre']); ?>?');">
                                                                <i class="fa fa-download"></i> Instalar
                                                            </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>

</html>