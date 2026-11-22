<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET rutas/listar.php (A, D, G): filtros fecha, desde, hasta, estado, conductor_id, zona_id, hub_id. */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $r = ruta_listar($in);
    api_meta($r['meta']);
    return $r['filas'];
});
