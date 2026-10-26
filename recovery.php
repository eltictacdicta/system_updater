<?php
/**
 * Script de Recuperación de Emergencia - FacturaScripts
 *
 * Este script permite restaurar copias de seguridad cuando el sistema principal no carga.
 * Utiliza el backup_manager del plugin system_updater.
 *
 * Mantiene la sesión de usuario para seguridad o solicita login si no hay sesión.
 */

// Configuración básica
define('FS_FOLDER', dirname(dirname(__DIR__)));

// Secreto por instalación (RACC-01/02/03/06). Plugin-local a propósito: no
// carga ninguna clase del core, ni el autoloader, ni config.php. Debe poder
// resolver el secreto con el core dañado, que es justo el escenario para el
// que existe este script.
require_once __DIR__ . '/lib/recovery_secret.php';

// Sesión propia del console (RACC-05/08). También plugin-local y sin core: el
// nombre de sesión se resuelve por FS_SESSION_NAME (o se deriva del mismo seed
// que el core), y la marca de autorización vive en el namespace de este script.
require_once __DIR__ . '/lib/recovery_session.php';

// Política y handler de credenciales (REC-01…05). Plugin-local a propósito: la
// decisión, el puente de hashes legacy y el token del formulario son funciones
// sin clase, sin autoloader y sin un solo fichero del core (RACC-01).
require_once __DIR__ . '/lib/recovery_handler.php';

// Acuñación del token del plugin (RACC-07). El console es el SEGUNDO punto de
// acuñación autorizado, y sólo después de sus dos puertas. Este módulo llega a
// `system_updater_csrf_generate()` a través de `lib/csrf_token.php` y
// `lib/session_name.php`, los dos plugin-locales: el grafo del console sigue sin
// una sola clase del core (RACC-01).
require_once __DIR__ . '/lib/recovery_csrf.php';

// El render autenticado (RACC-07): la tabla de copias y el driver de
// restauración por pasos. Es un formateador puro —no lee request, sesión ni
// disco— y por eso las dos propiedades que RACC-07 exige del HTML emitido («todo
// control lleva el token» y «ningún control que ya sabemos rechazado») se pueden
// afirmar sobre su salida y no sobre la intención del template.
require_once __DIR__ . '/lib/recovery_view.php';

// Escapes de CLI (RACC-06): `--secret` lee el valor actual y `--rotate-secret`
// lo regenera. El guard de SAPI es lo único que separa estas ramas del mundo
// web: un request normal —incluido uno que lleve `?--secret`— no tiene ninguna
// representación aquí y sigue por el camino de siempre.
//
// Se ejecutan antes de que el console cargue config.php por su cuenta, y sin
// abrir base de datos ni cargar el core. Sí leen config.php para resolver el pin
// del operador, con la misma supresión que la puerta (RACC-03: el orden es uno
// solo para la puerta, la marca y el CLI), porque un `--secret` que ignorase el
// pin devolvería el valor persistido mientras la puerta responde al pin: le
// entregaría al operador una credencial que el gate rechaza (N-1 de la
// reverificación).
if (PHP_SAPI === 'cli') {
    $su_cli_command = recovery_secret_cli_command(
        isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : []
    );

    if ($su_cli_command !== null) {
        exit(recovery_secret_cli_run($su_cli_command, FS_FOLDER));
    }
}

// Puerta del secreto (RACC-04). El orden de credenciales es el de RACC-03,
// fijado por decisión del product owner tras la fase de verificación (C-2): el
// pin del operador (`SU_RECOVERY_SECRET`) gana siempre, y sólo cuando no está
// definido se compara el valor persistido de la instalación.
//
// La consecuencia es deliberada y se aceptó junto con la decisión: el pin vive
// en config.php, así que la puerta carga ese fichero en CADA evaluación,
// incluidas las negativas. Se hace de forma segura
// (`recovery_secret_operator_pin()`): include con supresión de errores, buffer
// de salida, `Throwable` capturado e idempotente, de modo que un config.php
// dañado produce «no hay pin» sin cuerpo, sin aviso y sin error de sintaxis, y
// la respuesta cae al valor persistido.
//
// Un único camino para los tres fallos — parámetro ausente, vacío y erróneo —
// porque `recovery_secret_gate()` compara con `hash_equals()` y no tiene rama
// temprana para "no vino el parámetro". La respuesta no arranca sesión, no
// toca la base de datos, no lee el directorio de copias y no emite cabecera
// alguna que delate que hay un script detrás.
//
// La segunda mitad es RACC-05: si el request no trae el secreto, todavía puede
// estar autorizado por la marca que este mismo console escribió en su sesión
// tras una comparación correcta anterior. Esa lectura es silenciosa (sin
// `Set-Cookie`, sin directivas de caché) y sólo abre una sesión cuyo fichero ya
// existe, así que la negativa sigue sin dejar estado.
$su_secret_candidate = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';
$su_secret_gate = recovery_secret_gate($su_secret_candidate, FS_FOLDER, FS_FOLDER . '/config.php');

// La marca sólo se consulta cuando el secreto NO abrió la puerta: es la forma en
// que RACC-05 evita reenviar el secreto en cada request. Se nombra el resultado
// porque es la primera de las dos puertas de RACC-07 y el mint la necesita como
// hecho, no como condición anónima dentro de un `if`.
$su_console_authorized = $su_secret_gate['granted'];
if (!$su_console_authorized) {
    $su_console_authorized = recovery_console_authorized_by_marker(FS_FOLDER, FS_FOLDER . '/config.php');
}

if (!$su_console_authorized) {
    // El rechazo no puede añadir ni una cabecera que un fichero inexistente no
    // lleve ya: PHP anuncia `X-Powered-By` en toda respuesta de script y el 404
    // del servidor no lo trae, así que esa cabecera delataba por sí sola que
    // hay un script en esta ruta.
    header_remove('X-Powered-By');
    http_response_code(404);
    echo 'Not Found';
    exit;
}

// El secreto viaja en la URL, así que la página no puede filtrarlo por
// `Referer` a los CDN de terceros que carga (bootstrap, font-awesome,
// jquery). Sólo se anuncia en el camino aceptado: la negativa de arriba no
// lleva ninguna cabecera que la distinga de un archivo inexistente.
header('Referrer-Policy: no-referrer');

// Cargar configuración. Es el ÚNICO fichero que este script carga fuera de su
// propio directorio (RACC-01): todo lo demás — secreto, sesión, política de
// credenciales, puente de hashes legacy, token del formulario y el backup
// manager — es plugin-local y no necesita ni una clase del core.
//
// No hay autoloader de Composer, y no es un olvido: cargarlo era la dependencia
// que impedía a este script funcionar con el core dañado, y el puente de hashes
// legacy que lo sustituye es el del propio plugin (REC-07). Tampoco se carga
// `lib/session_auth.php`, que arrastra `base/config2.php` y el kernel: el nombre
// de sesión lo resuelve `lib/recovery_session.php` sin ninguna clase del core
// (RACC-08), y la identidad la escribe `lib/recovery_handler.php`.
//
// RACC-01: que falte config.php es la ÚNICA parada dura del console, y su
// respuesta es deliberadamente distinta de la negativa: HTTP 500 y un cuerpo de
// texto plano que nombra el fichero y el directorio donde se esperaba. El
// operador tiene que poder ver qué falta — para eso existe esta respuesta — y un
// 200 en una parada dura es invisible para un uptime check y para el log de
// accesos. No se puede confundir con el 404 de RACC-04: ese se decide antes, en
// la puerta, y sin el secreto esta rama no se alcanza.
//
// La ruta se escribe literal en el `require_once` a propósito: el grafo de
// includes de RACC-01 se enumera estáticamente, y una ruta en variable lo
// dejaría sin resolver justo en el único fichero externo permitido.
//
// Cuando la puerta ya lo incluyó en esta petición —que es el caso normal desde
// C-2, porque el pin vive aquí— este `require_once` es un no-op: PHP no ejecuta
// dos veces el mismo fichero. El lector de la marca (`lib/recovery_session.php`)
// aplica la misma regla, así que solo hay una ejecución por petición.
if (!is_file(FS_FOLDER . '/config.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    die('Error: no se encuentra el archivo config.php en el directorio ' . FS_FOLDER . ".\n");
}

require_once FS_FOLDER . '/config.php';

// Sesión. El nombre se resuelve DESPUÉS de config.php, que es donde vive
// FS_SESSION_NAME — salvo en la negativa, donde la lectura de la marca lo hace
// de forma defensiva.
recovery_session_configure(recovery_session_name(FS_FOLDER));

session_start();

// Marca de autorización (RACC-05): se escribe SÓLO después de la comparación
// constante del secreto, nunca tras llegar aquí por la propia marca. A partir de
// aquí el secreto ya no hace falta: la sesión lo lleva.
//
// La marca guarda además la huella del valor que la autorizó, y se compara con
// el valor contra el que la puerta respondería ahora — el pin del operador
// cuando está definido, si no el valor persistido (C-2: el pin gana). Rotar el
// secreto retira así las marcas que emitió el valor antiguo: quien obtuvo el
// secreto filtrado y ya había abierto una sesión pierde el acceso (RACC-06).
if ($su_secret_gate['granted']) {
    $su_grant = recovery_secret_gate_value(FS_FOLDER);
    recovery_console_mark_authorized($su_grant === null ? null : $su_grant['value']);
}

// Cargar Backup Manager
if (file_exists(__DIR__ . '/lib/backup_manager.php')) {
    require_once __DIR__ . '/lib/backup_manager.php';
} else {
    die("Error: No se encuentra el plugin system_updater.");
}

// Conexión a base de datos para autenticación.
//
// Un fallo de conexión ya no es una parada (RACC-01: config.php es el único
// "hard stop"): la pantalla de login y la lista de copias se pintan sin
// credenciales, y este script existe justo para el estado en el que el resto de
// la instalación no funciona. Las credenciales se evalúan sólo cuando hay base
// de datos; sin ella el formulario vuelve a mostrarse, que es la respuesta
// honesta —no hay forma de verificar a nadie— y no una página de error.
$db = null;

try {
    $db = new mysqli(FS_DB_HOST, FS_DB_USER, FS_DB_PASS, FS_DB_NAME, FS_DB_PORT);
    if ($db->connect_error) {
        throw new Exception("Error de conexión: " . $db->connect_error);
    }
} catch (Throwable) {
    // El motivo no se guarda ni se imprime: lo que el operador necesita saber
    // es que no hay base de datos, y el detalle del motor incluye host y
    // usuario. La consola ya está detrás del secreto; no hace falta más.
    $db = null;
}

// Verificar autenticación
$user = null;
$error = null;

// REC-08: una sesión ya autenticada se revalida contra la fila, exactamente como
// antes. La marca del console (RACC-05) no participa: prueba que se llegó hasta
// aquí, no quién es el operador. Se lee `user_nick` porque es la clave de
// identidad que escribió el login (REC-06) y la que resuelve el guard del plugin;
// una sesión que sólo lleve `user_id` no es una sesión autenticada.
if ($db !== null && !$user && isset($_SESSION['user_nick']) && is_string($_SESSION['user_nick'])) {
    $sessionNick = trim($_SESSION['user_nick']);

    if ($sessionNick !== '') {
        $stmt = $db->prepare("SELECT nick, admin FROM fs_users WHERE nick = ?");
        $stmt->bind_param("s", $sessionNick);
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
}

// Procesar login si se envió el formulario.
//
// Todo el envío pasa por `recovery_handler_authenticate()` (REC-01…05): el
// veredicto del token es lo primero que se consulta, después la lista de IPs
// bloqueadas, después el presupuesto de intentos y sólo entonces se lee la fila
// y se compara la contraseña — una vez, con el puente de hashes legacy del
// plugin, que es lo que permite entrar a un administrador con SHA1 o MD5 justo
// en el script que existe para cuando no se puede entrar (REC-07). El handler
// tampoco escribe identidad: la escribe este script, y sólo si el veredicto es
// `ok`.
//
// El fallo con base de datos caída sigue siendo su propio mensaje: no es un
// motivo de credencial y no distingue entre cuentas. Pero se decide DESPUÉS del
// veredicto del token (REC-01): sin token válido no se llega ni a plantearse la
// conexión, y el cuerpo es la negativa uniforme.
$submitted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST !== [];
$suToken = '';
$suRefusal = null;

if ($submitted) {
    $suToken = isset($_POST[RECOVERY_LOGIN_TOKEN_FIELD]) && is_string($_POST[RECOVERY_LOGIN_TOKEN_FIELD])
        ? $_POST[RECOVERY_LOGIN_TOKEN_FIELD]
        : '';
    $suRefusal = recovery_handler_token_refusal($suToken);
}

if ($submitted && $suRefusal !== null) {
    $error = $suRefusal;
} elseif ($submitted && $db === null) {
    $error = 'No se puede conectar a la base de datos.';
}

if ($submitted && $db !== null && !$user && $suRefusal === null) {
    $su_result = recovery_handler_authenticate(
        [
            'token' => $suToken,
            'nick' => isset($_POST['nick']) && is_string($_POST['nick']) ? trim($_POST['nick']) : '',
            'password' => isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '',
            'ip' => recovery_handler_client_ip($_SERVER),
        ],
        recovery_handler_seams($db)
    );

    if ($su_result['outcome'] === 'ok' && is_array($su_result['identity'])) {
        recovery_handler_write_identity($su_result['identity']);
        $user = $su_result['row'];
    } else {
        $error = $su_result['message'];
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
                            <?php if ($error !== null && $error !== ''): ?>
                                <div class="alert alert-danger">
                                    <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                            <form method="post">
                                <?php
                                // REC-01: el token del formulario es de este
                                // console, no el del plugin. `csrf_field()` del
                                // plugin generaría un `su_csrf_token`, que es
                                // justo lo que RACC-07 prohíbe antes de las dos
                                // puertas.
                                echo recovery_login_token_field();
                                ?>
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

// Acuñación del token del plugin (RACC-07). Este punto es deliberadamente el
// único: está DESPUÉS del `exit` del render de login —así que la segunda puerta
// ya está cerrada cuando el mint no debe ocurrir— y después de la comparación
// constante del secreto o de la marca que la sustituye (RACC-04/RACC-05). El
// valor no se imprime en ningún otro sitio: ni en la negativa, que termina
// mucho antes, ni en una línea de log.
//
// `config_loaded` es un hecho en esta línea: sin config.php el script muere
// arriba, y `authenticated` se pasa desde `$user` en vez de darlo por supuesto,
// para que el mint vuelva a comprobar la puerta que el `if` anterior acaba de
// dejar pasar.
$su_csrf_token = recovery_csrf_mint_token([
    'secret_granted' => $su_console_authorized,
    'config_loaded' => true,
    'authenticated' => is_array($user),
]);

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
                        <?php echo htmlspecialchars((string) $user['nick'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
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
                        <?php
                        // RACC-07: sin token no se pinta ningún control de
                        // restauración, porque el servidor rechazaría cada
                        // request. La lista se pinta igual.
                        echo recovery_view_backup_rows($backups, (string) $su_csrf_token !== '');
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de Progreso de Restauración -->
    <div class="modal fade" id="restoreProgressModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger">
                    <h4 class="modal-title">
                        <i class="fa fa-refresh fa-spin" id="modalSpinner"></i> Restaurando Sistema
                    </h4>
                </div>
                <div class="modal-body">
                    <div class="text-center" style="margin-bottom: 20px;">
                        <i class="fa fa-ambulance" style="font-size: 48px; color: #d9534f;"></i>
                    </div>

                    <div class="progress" style="height: 30px;">
                        <div id="restoreProgressBar" class="progress-bar progress-bar-striped active progress-bar-danger"
                            role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                            style="width: 0%; min-width: 2em;">
                            0%
                        </div>
                    </div>

                    <div id="restoreStatusMessage" class="alert alert-info text-center" style="margin-top: 15px;">
                        <i class="fa fa-spinner fa-spin" id="statusSpinner"></i>
                        <span id="restoreStatusText">Iniciando restauración...</span>
                    </div>

                    <div id="restoreDetails" class="well well-sm" style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; background-color: #f5f5f5;">
                        <small class="text-muted">Esperando inicio...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="recovery.php" id="restoreCompleteBtn" class="btn btn-success" style="display: none;">
                        <i class="fa fa-check"></i> Finalizar
                    </a>
                    <button type="button" id="restoreErrorBtn" class="btn btn-danger" data-dismiss="modal" style="display: none;">
                        <i class="fa fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>

    <?php
    // RACC-07: el driver por pasos (`begin` + N x `chunk`), con el token en cada
    // request. Sustituye a `new EventSource('process_restore.php?action=start…')`,
    // que no llevaba token —el servidor lo rechazaba— y usaba el camino de un
    // solo request, que en el host de producción no puede terminar.
    echo recovery_view_restore_driver((string) $su_csrf_token);
    ?>
</body>

</html>
