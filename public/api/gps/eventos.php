<?php
/**
 * GET /api/gps/eventos.php?ruta_id=  (roles: admin, despachador)
 * Eventos de geocerca de una ruta con datos de la parada y del paquete.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);

    return gps_eventos_listar($d['ruta_id']);
});
