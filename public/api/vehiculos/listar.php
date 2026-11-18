<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET vehiculos/listar.php (A, D): filtros activo, tipo, q. */
api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $r = vehiculo_listar($in);
    api_meta($r['meta']);
    return $r['filas'];
});
