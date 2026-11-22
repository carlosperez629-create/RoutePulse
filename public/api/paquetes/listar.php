<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET paquetes/listar.php (A, D, G): lista paginada con filtros. */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $r = paquete_listar($in);
    api_meta($r['meta']);
    return $r['filas'];
});
