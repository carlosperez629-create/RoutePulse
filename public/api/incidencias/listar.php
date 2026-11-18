<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET incidencias/listar.php (A, D, G). */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $r = incidencia_listar($in);
    api_meta($r['meta']);
    return $r['filas'];
});
