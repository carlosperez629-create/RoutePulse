<?php
/**
 * RoutePulse - Bootstrap. Se incluye con require_once al inicio de CADA endpoint y página.
 *
 *   Endpoints (public/api/<recurso>/<accion>.php):
 *       require_once __DIR__ . '/../../../app/core/bootstrap.php';
 *   Pantallas de public/:        require_once __DIR__ . '/../app/core/bootstrap.php';
 *   Pantallas de public/<mod>/:  require_once __DIR__ . '/../../app/core/bootstrap.php';
 *
 * Qué hace: zona horaria UTC y UTF-8, carga el núcleo y los servicios, configura
 * los errores de PHP según el entorno y envía las cabeceras de seguridad.
 * NO abre la sesión ni la base de datos: eso ocurre solo cuando hace falta
 * (api_run, auth_usuario, db()).
 *
 * Este archivo no declara funciones (para poder incluirse más de una vez sin error).
 */

if (defined('RP_BOOTSTRAP')) {
    return;
}
define('RP_BOOTSTRAP', true);
define('RP_RAIZ', dirname(__DIR__, 2));
define('RP_CLI', PHP_SAPI === 'cli');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Núcleo (solo definen funciones y clases, en este orden).
require_once __DIR__ . '/errores.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/entorno.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/textos.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/input.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

// Sin config/config.php no se puede continuar: se muestra un aviso claro.
try {
    app_cfg('app.entorno');
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "RoutePulse: falta config/config.php.\nCopia config/config.example.php a config/config.php y ajusta los valores.\n";
    exit(1);
}

rp_configurar_errores();
rp_instalar_manejadores();
rp_enviar_cabeceras_seguridad();

// Servicios de los módulos (cada archivo solo define funciones).
$rp_servicios = glob(RP_RAIZ . '/app/services/*.php');
if ($rp_servicios !== false) {
    sort($rp_servicios);
    foreach ($rp_servicios as $rp_archivo_servicio) {
        require_once $rp_archivo_servicio;
    }
}
unset($rp_servicios, $rp_archivo_servicio);
