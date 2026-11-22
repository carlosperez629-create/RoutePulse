<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST paquetes/escanear.php (A, D): ingreso al HUB o clasificación por código de guía. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'codigo_guia' => 'requerido|str|max:60',
        'accion'      => 'requerido|enum:ingreso_hub,clasificar',
        'hub_id'      => 'opcional|int|min:1',
    ]);
    $p = paquete_escanear($d['codigo_guia'], $d['accion'], $d['hub_id'] !== null ? (int) $d['hub_id'] : null, (int) $usuario['id']);
    return paquete_para_api($p);
});
