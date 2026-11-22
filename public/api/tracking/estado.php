<?php
/**
 * GET tracking/estado.php?t={token} - Estado público de un paquete (sin sesión; se protege con el token
 * y el límite de consultas por IP). Solo devuelve los campos permitidos por M5.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

tracking_cabeceras_publicas();

api_run('GET', [], function (array $in, ?array $usuario) {
    rate_limit_check('track:ip:' . tracking_ip_cliente(), (int) config_get('rate_tracking_por_min', 60), 60);

    // El token no pasa por validar(): un token mal formado debe dar el mismo 404 que uno inexistente.
    $token = is_string($in['t'] ?? null) ? $in['t'] : '';
    return tracking_estado_publico($token);
});
