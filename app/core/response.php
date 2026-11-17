<?php
/**
 * RoutePulse - Respuestas JSON y ejecución de endpoints (api_run).
 *
 * Formato del contrato (docs/00-base/05-contrato-api.md):
 *   éxito: {"ok":true,"data":...,"meta":{...}}
 *   error: {"ok":false,"error":{"codigo","mensaje","campos"}}
 */

/**
 * Envía el JSON y termina la ejecución. Descarta cualquier salida accidental
 * previa (avisos de PHP, espacios) para no corromper el JSON.
 */
function rp_enviar_json(array $payload, int $http): never {
    while (ob_get_level() > 0) {
        $sobrante = ob_get_clean();
        if ($sobrante !== false && $sobrante !== '') {
            log_app('warning', 'Salida inesperada antes de la respuesta JSON', ['salida' => substr($sobrante, 0, 500)]);
        }
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        log_app('error', 'No se pudo codificar la respuesta JSON: ' . json_last_error_msg());
        $http = 500;
        $json = '{"ok":false,"error":{"codigo":"ERROR_INTERNO","mensaje":"Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador."}}';
    }

    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $json;
    exit;
}

function json_ok(mixed $data = null, array $meta = [], int $http = 200): never {
    $payload = ['ok' => true, 'data' => $data];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    rp_enviar_json($payload, $http);
}

function json_error(string $codigo, string $mensaje, int $http, array $campos = []): never {
    $error = ['codigo' => $codigo, 'mensaje' => $mensaje];
    if ($campos !== []) {
        $error['campos'] = $campos;
    }
    rp_enviar_json(['ok' => false, 'error' => $error], $http);
}

/**
 * Agrega "meta" (paginación) a la respuesta de éxito. Llamar antes de retornar.
 */
function api_meta(array $meta): void {
    $GLOBALS['rp_api_meta'] = $meta;
}

/**
 * Cambia el HTTP de éxito (por ejemplo 201 al crear).
 */
function api_status(int $http): void {
    $GLOBALS['rp_api_http'] = $http;
}

/**
 * Ejecuta un endpoint. En este orden: método → sesión → límite de peticiones →
 * autenticación y rol → CSRF → lectura de entrada → manejador → respuesta.
 *
 * @param string   $metodo    'GET' o 'POST'
 * @param array    $roles     [] = público; ['*'] = cualquier usuario con sesión; o lista de roles
 * @param callable $manejador function (array $in, ?array $usuario) { ... return $datos; }
 * @param array    $opciones  (opcional)
 *     'sesion' => bool   iniciar sesión (por defecto: true si el endpoint no es público)
 *     'csrf'   => bool   exigir X-CSRF-Token (por defecto: true en POST con sesión)
 *     'rate'   => ['clave' => 'track', 'max' => 60, 'segundos' => 60]   límite por IP
 *     'permitir_cambio_pendiente' => bool   permitir usuarios que deben cambiar su contraseña
 */
function api_run(string $metodo, array $roles, callable $manejador, array $opciones = []): never {
    $metodo = strtoupper($metodo);
    $GLOBALS['rp_api_meta'] = [];
    $GLOBALS['rp_api_http'] = 200;
    $GLOBALS['rp_permitir_cambio_pendiente'] = !empty($opciones['permitir_cambio_pendiente']);

    ob_start();
    try {
        $recibido = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($recibido !== $metodo) {
            if (!headers_sent()) {
                header('Allow: ' . $metodo);
            }
            lanzar_error('METODO_NO_PERMITIDO', 'Método no permitido para este recurso.', 405);
        }

        $publico = ($roles === []);
        $usaSesion = array_key_exists('sesion', $opciones) ? (bool) $opciones['sesion'] : !$publico;
        if ($usaSesion) {
            sesion_iniciar();
        }

        if (isset($opciones['rate']) && is_array($opciones['rate'])) {
            $rate = $opciones['rate'];
            rate_limit_check(
                (string) ($rate['clave'] ?? 'api') . ':ip:' . rp_ip_cliente(),
                (int) ($rate['max'] ?? 60),
                (int) ($rate['segundos'] ?? 60)
            );
        }

        $usuario = null;
        if (!$publico) {
            $usuario = auth_requerir($roles);
        } elseif ($usaSesion) {
            $usuario = auth_usuario();
        }

        $exigeCsrf = array_key_exists('csrf', $opciones) ? (bool) $opciones['csrf'] : ($metodo === 'POST' && $usaSesion);
        if ($exigeCsrf) {
            csrf_validar();
        }

        $entrada = rp_leer_entrada($metodo);
        $datos = $manejador($entrada, $usuario);

        json_ok($datos, $GLOBALS['rp_api_meta'], $GLOBALS['rp_api_http']);
    } catch (AppError $e) {
        if ($e->reintentar_despues !== null && !headers_sent()) {
            header('Retry-After: ' . $e->reintentar_despues);
        }
        if ($e->http >= 500) {
            log_app('error', 'AppError ' . $e->codigo . ': ' . $e->getMessage());
        }
        json_error($e->codigo, $e->getMessage(), $e->http, $e->campos);
    } catch (Throwable $e) {
        log_app('error', 'Error no controlado en el endpoint: ' . $e->getMessage(), [
            'tipo'    => get_class($e),
            'archivo' => $e->getFile(),
            'linea'   => $e->getLine(),
            'uri'     => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'traza'   => substr($e->getTraceAsString(), 0, 2000),
        ]);
        json_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
    }
}
