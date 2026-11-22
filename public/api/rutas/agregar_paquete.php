<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/agregar_paquete.php (A, D): posicion opcional (1 = primera parada abierta). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'paquete_id' => 'requerido|int|min:1', 'posicion' => 'opcional|int|min:1']);
    return ruta_agregar_paquete((int) $d['ruta_id'], (int) $d['paquete_id'], $d['posicion'] !== null ? (int) $d['posicion'] : null, (int) $usuario['id']);
});
