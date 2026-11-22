<?php
/**
 * POST tracking/reprogramar.php - El cliente reprograma una entrega fallida (sin sesión; token + límite por IP).
 * Cuerpo: { "t": "{token}", "fecha": "AAAA-MM-DD", "franja": "manana|tarde|cualquiera", "nota": "..." }
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

tracking_cabeceras_publicas();

api_run('POST', [], function (array $in, ?array $usuario) {
    $ip = tracking_ip_cliente();
    rate_limit_check('track:ip:' . $ip, (int) config_get('rate_tracking_por_min', 60), 60);
    rate_limit_check('reprog:ip:' . $ip, 5, 3600);   // 5 reprogramaciones por hora por IP

    $d = validar($in, [
        'fecha' => 'requerido|fecha',
        'franja' => 'requerido|enum:manana,tarde,cualquiera',
        'nota' => 'opcional|str|max:300',
    ]);
    $token = is_string($in['t'] ?? null) ? $in['t'] : '';
    return tracking_reprogramar($token, $d);
});
