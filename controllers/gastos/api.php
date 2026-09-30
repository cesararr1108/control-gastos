<?php
/**
 * Punto único de entrada de la API de control de gastos.
 *
 *   POST controllers/gastos/api.php   recurso=solicitudes&accion=listar&...
 *   GET  controllers/gastos/api.php?recurso=archivos&accion=ver&id=12   (PDF)
 *
 * Respuesta JSON: {ok:true, data:...} | {ok:false, mensaje:'...', sesion:false?}
 * Los errores de validación (GasError) se muestran tal cual; cualquier otro error
 * se registra en el log de PHP y el usuario solo ve un mensaje genérico.
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');

$base = dirname(dirname(dirname(__FILE__)));
spl_autoload_register(function ($clase) use ($base) {
    foreach (array('/models/gastos/', '/controllers/gastos/') as $dir) {
        $f = $base . $dir . $clase . '.php';
        if (strpos($clase, 'Gas') === 0 && is_file($f)) {
            require_once $f;
            return;
        }
    }
});

// Diagnóstico de instalación: avisa qué carpeta/archivo falta (Linux distingue mayúsculas).
foreach (array('models/gastos/GasSesion.php', 'models/gastos/GasDb.php', 'models/gastos/GasMotor.php', 'controllers/gastos/GasSolicitudesController.php') as $req) {
    if (!is_file($base . '/' . $req)) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => false, 'mensaje' => 'Instalación incompleta: falta ' . $base . '/' . $req
            . '. Copia al servidor las carpetas models/gastos y controllers/gastos completas.'));
        exit;
    }
}

/**
 * Whitelist: 'recurso.accion' => array(clase, método, método HTTP, solo admin).
 * Lo que no esté aquí no se puede invocar.
 */
$RUTAS = array(
    'catalogo.opciones'    => array('GasCatalogoController', 'opciones', 'POST', false),
    'catalogo.terceros'    => array('GasCatalogoController', 'terceros', 'POST', false),
    'catalogo.usuarios'    => array('GasCatalogoController', 'usuarios', 'POST', true),
    'solicitudes.variantes' => array('GasSolicitudesController', 'variantes', 'POST', false),
    'solicitudes.crear'    => array('GasSolicitudesController', 'crear', 'POST', false),
    'solicitudes.listar'   => array('GasSolicitudesController', 'listar', 'POST', false),
    'solicitudes.obtener'  => array('GasSolicitudesController', 'obtener', 'POST', false),
    'solicitudes.ejecutar' => array('GasSolicitudesController', 'ejecutar', 'POST', false),
    'solicitudes.cancelar' => array('GasSolicitudesController', 'cancelar', 'POST', false),
    'solicitudes.contador' => array('GasSolicitudesController', 'contador', 'POST', false),
    'flujos.listar'        => array('GasFlujosController', 'listar', 'POST', true),
    'flujos.obtener'       => array('GasFlujosController', 'obtener', 'POST', true),
    'flujos.guardar'       => array('GasFlujosController', 'guardar', 'POST', true),
    'flujos.estado'        => array('GasFlujosController', 'estado', 'POST', true),
    'archivos.ver'         => array('GasArchivosController', 'ver', 'GET', false),
);

/** ¿El usuario de la sesión es administrador? (solo ellos ven el detalle técnico de un error). */
function gasEsAdminSesion()
{
    if (session_id() === '') {
        @session_start();
    }
    $rol = isset($_SESSION['ses_RolesId']) ? (int) $_SESSION['ses_RolesId'] : -1;
    return class_exists('GasConfig') && in_array($rol, array_map('intval', GasConfig::$ROLES_ADMIN), true);
}

// Un error fatal de PHP (clase o función inexistente, memoria…) no debe dejar una respuesta vacía.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        error_log('[gastos] FATAL ' . $e['message'] . ' en ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=UTF-8');
        }
        $msg = 'Ocurrió un error inesperado. Intenta de nuevo o contacta a soporte.';
        if (gasEsAdminSesion()) {
            $msg .= ' [Detalle para administradores: ' . $e['message'] . ' en ' . basename($e['file']) . ':' . $e['line'] . ']';
        }
        echo json_encode(array('ok' => false, 'mensaje' => $msg));
    }
});

function gasResponder($arr, $codigo = 200)
{
    if ($codigo !== 200) {
        header('HTTP/1.1 ' . $codigo);
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($arr);
    exit;
}

try {
    $recurso = isset($_REQUEST['recurso']) ? preg_replace('/[^a-z]/', '', $_REQUEST['recurso']) : '';
    $accion  = isset($_REQUEST['accion']) ? preg_replace('/[^a-zA-Z]/', '', $_REQUEST['accion']) : '';
    $clave   = $recurso . '.' . $accion;
    if (!isset($RUTAS[$clave])) {
        gasResponder(array('ok' => false, 'mensaje' => 'Operación no encontrada.'), 404);
    }
    list($clase, $metodo, $http, $soloAdmin) = $RUTAS[$clave];

    if ($_SERVER['REQUEST_METHOD'] !== $http) {
        gasResponder(array('ok' => false, 'mensaje' => 'Método no permitido.'), 405);
    }
    // Defensa CSRF: una petición entre sitios no puede poner este header sin permiso CORS.
    if ($http === 'POST' && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')) {
        gasResponder(array('ok' => false, 'mensaje' => 'Petición no válida.'), 400);
    }

    $u = GasSesion::actual();
    if ($soloAdmin && !GasSesion::esAdmin($u)) {
        gasResponder(array('ok' => false, 'mensaje' => 'No tienes permiso para esta operación.'), 403);
    }

    $in = $http === 'POST' ? $_POST : $_GET;
    $data = call_user_func(array($clase, $metodo), $u, $in, $_FILES);
    if ($clase === 'GasArchivosController') {
        exit; // el PDF ya se envió
    }
    gasResponder(array('ok' => true, 'data' => $data));
} catch (GasSesionException $e) {
    gasResponder(array('ok' => false, 'sesion' => false, 'mensaje' => $e->getMessage()), 401);
} catch (GasError $e) {
    // Descarga fallida: mostrar texto simple en vez de JSON.
    if (isset($clase) && $clase === 'GasArchivosController') {
        header('HTTP/1.1 403 Forbidden');
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
    gasResponder(array('ok' => false, 'mensaje' => $e->getMessage()));
} catch (Exception $e) {
    error_log('[gastos] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $msg = 'Ocurrió un error inesperado. Intenta de nuevo o contacta a soporte.';
    if (gasEsAdminSesion()) {
        $msg .= ' [Detalle para administradores: ' . $e->getMessage() . ']';
    }
    gasResponder(array('ok' => false, 'mensaje' => $msg), 500);
}
