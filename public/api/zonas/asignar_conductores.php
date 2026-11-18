<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST zonas/asignar_conductores.php (A, D): reemplaza la asignación actual. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['zona_id' => 'requerido|int|min:1']);
    $ids = isset($in['conductor_ids']) && is_array($in['conductor_ids']) ? $in['conductor_ids'] : [];
    return zona_asignar_conductores((int) $d['zona_id'], $ids, (int) $usuario['id']);
});
