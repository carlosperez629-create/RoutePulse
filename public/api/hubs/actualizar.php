<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST hubs/actualizar.php (A): no se desactiva un HUB con paquetes sin cerrar (CONFLICTO). */
api_run('POST', ['admin'], function (array $in, ?array $usuario) {
    $d = validar($in, ['hub_id' => 'requerido|int|min:1']);
    return hub_actualizar((int) $d['hub_id'], $in, (int) $usuario['id']);
});
