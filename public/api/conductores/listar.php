<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET conductores/listar.php (A, D): filtros estado, zona_id, disponibles_para_fecha. */
api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $r = conductor_listar($in);
    api_meta($r['meta']);
    return $r['filas'];
});
