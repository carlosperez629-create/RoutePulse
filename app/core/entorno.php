<?php
/**
 * RoutePulse - Entorno: URLs, rutas, IP del cliente, manejo de errores de PHP
 * y cabeceras de seguridad.
 */

function rp_entorno(): string {
    return app_cfg('app.entorno', 'prod') === 'dev' ? 'dev' : 'prod';
}

function rp_es_dev(): bool {
    return rp_entorno() === 'dev';
}

function rp_es_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (app_cfg('app.proxy_confiable', false) === true
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

/**
 * IP del cliente. Solo se confía en X-Forwarded-For si app.proxy_confiable = true.
 */
function rp_ip_cliente(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (app_cfg('app.proxy_confiable', false) === true && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $primera = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
            $ip = $primera;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

/**
 * URL absoluta a partir de app.url. Ej.: rp_url('login.php') → http://localhost/routepulse/public/login.php
 */
function rp_url(string $ruta = ''): string {
    $base = rtrim((string) app_cfg('app.url', ''), '/');
    return $ruta === '' ? $base : $base . '/' . ltrim($ruta, '/');
}

/**
 * Ruta absoluta dentro de storage/ (fuera de public/). Ej.: rp_ruta_storage('logs').
 */
function rp_ruta_storage(string $sub = ''): string {
    $base = rtrim((string) app_cfg('storage.ruta', RP_RAIZ . '/storage'), '/\\');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/\\');
}

/**
 * Cuerpo crudo de la petición (para verificar firmas de webhooks). Se lee una vez.
 */
function entrada_cuerpo_crudo(): string {
    static $crudo = null;
    if ($crudo === null) {
        $leido = file_get_contents('php://input');
        $crudo = $leido === false ? '' : $leido;
    }
    return $crudo;
}

function rp_configurar_errores(): void {
    error_reporting(E_ALL);
    ini_set('display_errors', rp_es_dev() ? '1' : '0');
    ini_set('display_startup_errors', rp_es_dev() ? '1' : '0');
    ini_set('log_errors', '1');
    $directorio = rp_ruta_storage('logs');
    if (is_dir($directorio) && is_writable($directorio)) {
        ini_set('error_log', $directorio . '/php_errors.log');
    }
}

/**
 * Responde un error 500 genérico para excepciones no capturadas.
 */
function rp_responder_error_fatal(Throwable $e): void {
    $http = $e instanceof AppError ? $e->http : 500;
    $codigo = $e instanceof AppError ? $e->codigo : 'ERROR_INTERNO';
    $mensaje = $e instanceof AppError
        ? $e->getMessage()
        : 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.';

    if (RP_CLI) {
        fwrite(STDERR, 'RoutePulse: ' . $mensaje . PHP_EOL);
        return;
    }
    if (!headers_sent()) {
        http_response_code($http);
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $esApi = str_contains($uri, '/api/') || str_contains($uri, '/webhooks/');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($esApi) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            ['ok' => false, 'error' => ['codigo' => $codigo, 'mensaje' => $mensaje]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } else {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Error · RoutePulse</title></head>'
            . '<body style="font-family:sans-serif;padding:2rem"><h1>Ocurrió un problema</h1><p>'
            . htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p></body></html>';
    }
}

function rp_instalar_manejadores(): void {
    set_error_handler(function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
        if ((error_reporting() & $nivel) === 0) {
            return false;
        }
        log_app('warning', 'PHP (' . $nivel . '): ' . $mensaje, ['archivo' => $archivo, 'linea' => $linea]);
        // En dev se deja que PHP también lo muestre; en prod se silencia.
        return !rp_es_dev();
    });

    set_exception_handler(function (Throwable $e): void {
        log_app('error', 'Excepción no capturada: ' . $e->getMessage(), [
            'archivo' => $e->getFile(),
            'linea'   => $e->getLine(),
            'traza'   => substr($e->getTraceAsString(), 0, 2000),
        ]);
        rp_responder_error_fatal($e);
        exit(1);
    });

    register_shutdown_function(function (): void {
        $ultimo = error_get_last();
        if ($ultimo !== null && in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_app('error', 'Error fatal de PHP: ' . $ultimo['message'], [
                'archivo' => $ultimo['file'],
                'linea'   => $ultimo['line'],
            ]);
        }
    });
}

/**
 * Cabeceras de seguridad (docs/modulos/M8, sección 6.4). El portal de seguimiento
 * puede sobrescribir Referrer-Policy con header('Referrer-Policy: no-referrer').
 */
function rp_enviar_cabeceras_seguridad(): void {
    if (RP_CLI || headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'");
    if (rp_es_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
