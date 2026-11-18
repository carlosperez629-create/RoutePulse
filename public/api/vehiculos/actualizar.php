<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST vehiculos/actualizar.php (A, D): no se desactiva con ruta planificada o en curso (CONFLICTO). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['vehiculo_id' => 'requerido|int|min:1']);
    return vehiculo_actualizar((int) $d['vehiculo_id'], $in, (int) $usuario['id']);
});
