<?php
/**
 * RoutePulse - Registro de eventos (storage/logs/app.log).
 *
 * log_app() NUNCA lanza excepciones: si no puede escribir, lo manda al error_log
 * de PHP y sigue. Los datos sensibles del contexto se enmascaran.
 */

/**
 * Enmascara recursivamente valores cuyas claves parecen sensibles
 * (contraseñas, tokens, claves de API, OTP, firmas en base64, cookies...).
 */
function rp_ocultar_sensibles(mixed $valor, int $profundidad = 0): mixed {
    if ($profundidad > 6) {
        return '…';
    }
    if (is_array($valor)) {
        $resultado = [];
        foreach ($valor as $clave => $elemento) {
            if (is_string($clave) && preg_match('/pass|clave|secret|token|authorization|api[_-]?key|otp|firma_base64|cookie/i', $clave) === 1) {
                $resultado[$clave] = '***';
            } else {
                $resultado[$clave] = rp_ocultar_sensibles($elemento, $profundidad + 1);
            }
        }
        return $resultado;
    }
    if (is_object($valor)) {
        return '[objeto]';
    }
    return $valor;
}

/**
 * @param string $nivel debug | info | warning | error  ('debug' solo se escribe en entorno dev)
 */
function log_app(string $nivel, string $mensaje, array $contexto = []): void {
    try {
        $nivel = strtolower($nivel);
        if (!in_array($nivel, ['debug', 'info', 'warning', 'error'], true)) {
            $nivel = 'info';
        }
        if ($nivel === 'debug' && !rp_es_dev()) {
            return;
        }

        $directorio = rp_ruta_storage('logs');
        if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            error_log('RoutePulse [' . $nivel . '] ' . $mensaje);
            return;
        }

        $linea = '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $nivel . ': ' . str_replace(["\r", "\n"], ' ', $mensaje);
        if ($contexto !== []) {
            $json = json_encode(
                rp_ocultar_sensibles($contexto),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($json !== false) {
                $linea .= ' ' . $json;
            }
        }
        if (strlen($linea) > 4000) {
            $linea = substr($linea, 0, 4000) . '…';
        }

        @file_put_contents($directorio . '/app.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('RoutePulse: log_app falló (' . $e->getMessage() . ') | ' . $mensaje);
    }
}
