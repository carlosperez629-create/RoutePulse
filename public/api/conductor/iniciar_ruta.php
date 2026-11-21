<?php
/**
 * POST /api/conductor/iniciar_ruta.php  (rol: conductor)
 * Entrada: { "ruta_id": 1 }. Pasa la ruta de planificada a en curso (ruta_iniciar de M1).
 * Errores: SIN_PERMISO si no es suya; ESTADO_INVALIDO si no está planificada.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);

    return entrega_iniciar_ruta($d['ruta_id'], $conductorId);
});
