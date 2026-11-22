<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/reordenar.php (A, D): orden = lista de parada_id con TODAS las paradas no resueltas. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);
    if (!isset($in['orden']) || !is_array($in['orden'])) {
        error_validacion(['orden' => 'Envía la lista de paradas en el nuevo orden.']);
    }
    return ruta_reordenar((int) $d['ruta_id'], $in['orden'], (int) $usuario['id']);
});
