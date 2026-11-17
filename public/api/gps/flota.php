<?php
/**
 * GET /api/gps/flota.php  (roles: admin, despachador, gerente)
 * Filtros: zona_id, estado_senal (en_linea | demorado | sin_senal).
 * Una entrada por ruta en curso. Formato: modulos/M2-rastreo-gps.md, sección 5.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $f = validar($in, [
        'zona_id'      => 'opcional|int|min:1',
        'estado_senal' => 'opcional|enum:en_linea,demorado,sin_senal',
    ]);

    return gps_flota($f['zona_id'] ?? null, $f['estado_senal'] ?? null);
});
