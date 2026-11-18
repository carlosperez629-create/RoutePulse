<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST incidencias/crear.php (A, D): registra una incidencia manual (paquete en tránsito). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'paquete_id'  => 'requerido|int|min:1',
        'tipo'        => 'requerido|enum:' . implode(',', array_keys(INCIDENCIA_TIPOS)),
        'descripcion' => 'opcional|str|max:500',
    ]);
    $id = incidencia_crear((int) $d['paquete_id'], null, $d['tipo'], $d['descripcion'], null, (int) $usuario['id']);
    api_status(201);
    return ['incidencia_id' => $id];
});
