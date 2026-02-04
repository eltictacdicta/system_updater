<?php
/**
 * Script de Recuperación de Emergencia - FacturaScripts
 * 
 * Este script permite restaurar copias de seguridad cuando el sistema principal no carga.
 * Utiliza el backup_manager del plugin system_updater.
 * 
 * Mantiene la sesión de usuario para seguridad o solicita login si no hay sesión.
 */

// Iniciar sesión
session_name('fsSess');
session_start();

// Configuración básica
define('FS_FOLDER', dirname(dirname(__DIR__)));

// Cargar configuración
if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
} else {
    die("Error: No se encuentra el archivo config.php.");
}

// Cargar Backup Manager
if (file_exists(__DIR__ . '/lib/backup_manager.php')) {
    require_once __DIR__ . '/lib/backup_manager.php';
} else {
    die("Error: No se encuentra el plugin system_updater.");
}

// Conexión a base de datos para autenticación
try {
    $db = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, FS_DB_PORT);
    if ($db->connect_error) {
        throw new Exception("Error de conexión: " . $db->connect_error);
    }
} catch (Exception $e) {
    die("Error crítico: No se puede conectar a la base de datos. " . $e->getMessage());
}

// Verificar autenticación
$user = null;
if (isset($_SESSION['user_id'])) {
    // Verificar que el usuario sigue existiendo y es admin
    $stmt = $db->prepare("SELECT nick, admin FROM fs_users WHERE nick = ?");
    $stmt->bind_param("s", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['admin']) {
            $user = $row;
        } else {
            die("Acceso denegado: Se requieren privilegios de administrador.");
        }
    }
}

// Procesar login si se envió el formulario
if (!$user && isset($_POST['nick']) && isset($_POST['password'])) {
    $nick = $db->real_escape_string($_POST['nick']);
    $password = $_POST['password'];

    $stmt = $db->prepare("SELECT nick, password, admin FROM fs_users WHERE nick = ?");
    $stmt->bind_param("s", $nick);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Verificar contraseña (md5 o sha1 según versión antigua, o password_verify para nuevas)
        // FSFramework legacy usa sha1 o md5 directo a veces, o funciones propias.
        // Asumimos sha1 que es lo común en versiones 2017
        if (sha1($password) === $row['password'] || md5($password) === $row['password'] || password_verify($password, $row['password'])) {
            if ($row['admin']) {
                $_SESSION['user_id'] = $row['nick'];
                $user = $row;
            } else {
                $error = "El usuario no es administrador.";
            }
        } else {
            $error = "Contraseña incorrecta.";
        }
    } else {
        $error = "Usuario no encontrado.";
    }
}

// Si no está autenticado, mostrar login
if (!$user) {
    ?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Recuperación - Login</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
        <style>
            body {
                padding-top: 40px;
                background-color: #eee;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <div class="row">
                <div class="col-md-4 col-md-offset-4">
                    <div class="panel panel-danger">
                        <div class="panel-heading">
                            <h3 class="panel-title">Modo Recuperación</h3>
                        </div>
                        <div class="panel-body">
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger">
                                    <?php echo $error; ?>
                                </div>
                            <?php endif; ?>
                            <form method="post">
                                <div class="form-group">
                                    <label>Usuario</label>
                                    <input type="text" name="nick" class="form-control" required autofocus>
                                </div>
                                <div class="form-group">
                                    <label>Contraseña</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-danger btn-block">Iniciar Sesión</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// Instanciar Backup Manager
$backupManager = new backup_manager(FS_FOLDER);
$message = '';
$messageType = 'info';

// Procesar Acciones
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'restore_complete' && isset($_GET['file'])) {
        $result = $backupManager->restore_complete($_GET['file']);
        if ($result['success']) {
            $message = "Sistema restaurado correctamente.";
            $messageType = "success";
        } else {
            $message = "Error al restaurar: " . implode(", ", $backupManager->get_errors());
            $messageType = "danger";
        }
    }
}

// Listar backups
$backups = $backupManager->list_backups_grouped();
?>
<!DOCTYPE html>
<html>

<head>
    <title>Modo Recuperación - FacturaScripts</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <nav class="navbar navbar-inverse">
        <div class="container">
            <div class="navbar-header">
                <a class="navbar-brand" href="#">FS Recuperación</a>
            </div>
            <ul class="nav navbar-nav navbar-right">
                <li><a href="../../index.php">Ir al Sitio</a></li>
                <li><a href="#"><i class="fa fa-user"></i>
                        <?php echo $user['nick']; ?>
                    </a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="alert alert-warning">
            <strong><i class="fa fa-exclamation-triangle"></i> MODO RECUPERACIÓN</strong>
            <p>Estás utilizando el script de emergencia independiente. Úsalo solo si no puedes acceder al panel de
                administración normal.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="panel panel-primary">
            <div class="panel-heading">
                <h3 class="panel-title">Copias de Seguridad Disponibles</h3>
            </div>
            <div class="panel-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $group): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?php echo $group['base_name']; ?>
                                    </strong><br>
                                    <?php if ($group['complete']): ?><span class="label label-primary">Completo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y H:i', $group['timestamp']); ?>
                                </td>
                                <td>
                                    <?php if ($group['complete']): ?>
                                        <a href="?action=restore_complete&file=<?php echo $group['complete']['name']; ?>"
                                            class="btn btn-danger btn-sm"
                                            onclick="return confirm('¡ADVERTENCIA! Esto sobrescribirá TODOS los archivos y la base de datos. ¿Estás seguro?');">
                                            <i class="fa fa-undo"></i> Restaurar Todo
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Solo backup completo soportado en recuperación</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($backups)): ?>
                            <tr>
                                <td colspan="3" class="text-center">No se encontraron copias de seguridad.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>