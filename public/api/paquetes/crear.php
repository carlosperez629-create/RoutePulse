<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST paquetes/crear.php (A, D): crea un paquete. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $paquete = paquete_crear($in, (int) $usuario['id']);
    api_status(201);
    return paquete_para_api($paquete);
});
