<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST incidencias/resolver.php (A, D): reprogramar, devolver o cancelar. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'incidencia_id'  => 'requerido|int|min:1',
        'accion'         => 'requerido|enum:reprogramar,devolver,cancelar',
        'fecha'          => 'opcional|fecha',
        'ventana_inicio' => 'opcional|fecha_hora',
        'ventana_fin'    => 'opcional|fecha_hora',
        'nota'           => 'opcional|str|max:255',
    ]);
    $ventana = ($d['ventana_inicio'] !== null || $d['ventana_fin'] !== null)
        ? ['inicio' => $d['ventana_inicio'], 'fin' => $d['ventana_fin']]
        : null;
    return incidencia_resolver((int) $d['incidencia_id'], $d['accion'], $d['fecha'], (int) $usuario['id'], $ventana);
});
