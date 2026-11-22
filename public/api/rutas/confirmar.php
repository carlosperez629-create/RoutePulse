<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/confirmar.php (A, D): asigna conductor/vehículo si se envían y confirma (borrador → planificada). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'conductor_id' => 'opcional|int|min:1', 'vehiculo_id' => 'opcional|int|min:1']);
    return db_tx(function () use ($d, $usuario) {
        if ($d['conductor_id'] !== null || $d['vehiculo_id'] !== null) {
            ruta_reasignar((int) $d['ruta_id'], $d['conductor_id'] !== null ? (int) $d['conductor_id'] : null, $d['vehiculo_id'] !== null ? (int) $d['vehiculo_id'] : null, (int) $usuario['id']);
        }
        return ruta_confirmar((int) $d['ruta_id'], (int) $usuario['id']);
    });
});
