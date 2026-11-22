<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET hubs/listar.php (A, D, G). */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, ['activo' => 'opcional|bool']);
    return hub_listar(!empty($d['activo']) ? true : null);
});
