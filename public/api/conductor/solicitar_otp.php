<?php
/**
 * POST /api/conductor/solicitar_otp.php  (rol: conductor)
 * Entrada: { "parada_id": 3 }. Envía el código de entrega al destinatario (requiere conexión).
 * Respuesta: { "canal": "sms", "expira_at": "ISO" } (en dev con mensajería simulada, también "otp_debug").
 * Límite: otp_max_por_hora solicitudes por paquete por hora (LIMITE).
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }
    $d = validar($in, ['parada_id' => 'requerido|int|min:1']);

    return entrega_solicitar_otp($d['parada_id'], $conductorId);
});
