<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST paquetes/actualizar.php (A, D): edita un paquete (solo creado, ingresado_hub o en_clasificacion). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['paquete_id' => 'requerido|int|min:1']);
    $paquete = paquete_actualizar((int) $d['paquete_id'], $in, (int) $usuario['id']);
    return paquete_para_api($paquete);
});
