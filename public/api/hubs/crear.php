<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST hubs/crear.php (A). */
api_run('POST', ['admin'], function (array $in, ?array $usuario) {
    $hub = hub_crear($in, (int) $usuario['id']);
    api_status(201);
    return $hub;
});
