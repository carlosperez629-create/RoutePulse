<?php
/**
 * POST /api/conductor/finalizar_ruta.php  (rol: conductor)
 * Entrada: { "ruta_id": 1 }. Exige que no queden paradas pendiente, en_camino ni llegado
 * (si quedan: ESTADO_INVALIDO con la lista). Llama ruta_finalizar de M1.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);

    return entrega_finalizar_ruta($d['ruta_id'], $conductorId, $usuario['id'] ?? null);
});
