<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST conductores/crear.php (A, D): crea usuario (rol conductor) + conductor. Devuelve password_temporal una sola vez si no se envió password. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $c = conductor_crear($in, (int) $usuario['id']);
    api_status(201);
    return $c;
});
