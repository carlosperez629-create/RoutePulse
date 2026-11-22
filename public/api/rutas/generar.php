<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/generar.php (A, D): crea rutas en borrador. No cambia el estado de ningún paquete. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'fecha'       => 'opcional|fecha',
        'hub_id'      => 'requerido|int|min:1',
        'zona_id'     => 'opcional|int|min:1',
        'hora_inicio' => 'opcional|fecha_hora',
    ]);
    $opciones = [
        'fecha'         => $d['fecha'] ?? gmdate('Y-m-d'),
        'hub_id'        => (int) $d['hub_id'],
        'zona_id'       => $d['zona_id'],
        'paquete_ids'   => isset($in['paquete_ids']) && is_array($in['paquete_ids']) ? $in['paquete_ids'] : null,
        'conductor_ids' => isset($in['conductor_ids']) && is_array($in['conductor_ids']) ? $in['conductor_ids'] : null,
        'hora_inicio'   => $d['hora_inicio'],
        'usuario_id'    => (int) $usuario['id'],
    ];
    $r = ruta_generar($opciones);
    api_status(201);
    return $r;
});
