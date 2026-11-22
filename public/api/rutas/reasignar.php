<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/reasignar.php (A, D): borrador o planificada; en_curso no se permite (ESTADO_INVALIDO). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'conductor_id' => 'opcional|int|min:1', 'vehiculo_id' => 'opcional|int|min:1']);
    if ($d['conductor_id'] === null && $d['vehiculo_id'] === null) {
        error_validacion(['conductor_id' => 'Indica un conductor o un vehículo.']);
    }
    return ruta_reasignar((int) $d['ruta_id'], $d['conductor_id'] !== null ? (int) $d['conductor_id'] : null, $d['vehiculo_id'] !== null ? (int) $d['vehiculo_id'] : null, (int) $usuario['id']);
});
