<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST conductores/actualizar.php (A, D): licencia, vehiculo_id (puede ser null), estado (disponible/inactivo). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['conductor_id' => 'requerido|int|min:1']);
    return conductor_actualizar((int) $d['conductor_id'], $in, (int) $usuario['id']);
});
