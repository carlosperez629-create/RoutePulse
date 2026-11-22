<?php
/**
 * POST /api/conductor/llegada.php  (rol: conductor)
 * Entrada: { "parada_id": 3, "lat": 4.6351, "lng": -74.0779, "ocurrido_at": "2026-11-13T15:04:05Z" }
 * Marca la parada en camino como llegada (llegada_real = ocurrido_at). Idempotente si ya está llegada.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }
    $d = validar($in, [
        'parada_id'   => 'requerido|int|min:1',
        'lat'         => 'opcional|lat',
        'lng'         => 'opcional|lng',
        'ocurrido_at' => 'requerido|fecha_hora',   // validar() la convierte a 'Y-m-d H:i:s' UTC
    ]);

    // Un evento no puede haber ocurrido en el futuro (margen de 2 minutos por diferencias de reloj).
    if (strtotime($d['ocurrido_at'] . ' UTC') > time() + 120) {
        error_validacion(['ocurrido_at' => 'La fecha del evento no puede estar en el futuro.']);
    }

    return entrega_marcar_llegada($d['parada_id'], $conductorId, $d['ocurrido_at']);
});
