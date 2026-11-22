<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/quitar_paquete.php (A, D): solo borrador o planificada. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'paquete_id' => 'requerido|int|min:1']);
    ruta_quitar_paquete((int) $d['ruta_id'], (int) $d['paquete_id'], (int) $usuario['id']);
    return ruta_obtener((int) $d['ruta_id']);
});
