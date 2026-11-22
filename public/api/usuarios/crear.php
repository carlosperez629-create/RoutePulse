<?php
/** POST usuarios/crear.php (admin). Si no se envía password se genera una temporal (password_temporal). */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['admin'], function (array $in, ?array $usuario): array {
    $temporal = null;
    $id = usuario_crear($in, $temporal);
    api_status(201);
    $u = usuario_obtener($id);
    $u['password_temporal'] = $temporal;   // se muestra una sola vez
    return $u;
});
