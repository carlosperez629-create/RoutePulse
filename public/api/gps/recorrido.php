<?php
/**
 * GET /api/gps/recorrido.php?ruta_id=&desde=&hasta=&verificar=  (roles: admin, despachador, gerente)
 * Recorrido real frente al sugerido, con estadísticas, paradas, eventos e integridad (playback).
 * desde / hasta: fecha y hora ISO 8601 (opcionales). verificar=1 recorre la cadena de hashes.
 * Detalle: modulos/M2-rastreo-gps.md, sección 5.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'ruta_id' => 'requerido|int|min:1',
        'desde'   => 'opcional|fecha_hora',
        'hasta'   => 'opcional|fecha_hora',
    ]);
    $desde = $d['desde'] ?? null;
    $hasta = $d['hasta'] ?? null;
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        error_validacion(['hasta' => 'La fecha final debe ser posterior a la inicial.']);
    }

    $verificar = in_array((string) ($in['verificar'] ?? ''), ['1', 'true'], true);

    return gps_recorrido($d['ruta_id'], $desde, $hasta, $verificar);
});
