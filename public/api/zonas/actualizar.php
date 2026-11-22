<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST zonas/actualizar.php (A, D): al cambiar el polígono NO se recalculan las zonas de paquetes ya creados. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['zona_id' => 'requerido|int|min:1']);
    return zona_actualizar((int) $d['zona_id'], $in, (int) $usuario['id']);
});
