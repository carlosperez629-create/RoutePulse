<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST paquetes/cambiar_estado.php (A, D): cambio manual permitido (solo cancelar, con motivo). */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'paquete_id'   => 'requerido|int|min:1',
        'estado_nuevo' => 'requerido|enum:creado,ingresado_hub,en_clasificacion,asignado_ruta,en_transito,entregado,incidencia,cancelado,devuelto',
        'motivo'       => 'opcional|str|max:255',
    ]);
    $paquete = paquete_cambiar_estado_manual((int) $d['paquete_id'], $d['estado_nuevo'], $d['motivo'], (int) $usuario['id']);
    return paquete_para_api($paquete);
});
