<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST vehiculos/crear.php (A, D). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $v = vehiculo_crear($in, (int) $usuario['id']);
    api_status(201);
    return $v;
});
