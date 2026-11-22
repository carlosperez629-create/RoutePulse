<?php
/**
 * GET kpi/mapa_calor.php?tipo=entregas|incidencias - Puntos [[lat, lng, peso], ...] (roles: admin, gerente).
 * Filtros: desde, hasta, zona_id?, conductor_id?, vehiculo_tipo?
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'tipo' => 'opcional|enum:entregas,incidencias',
        'desde' => 'opcional|fecha',
        'hasta' => 'opcional|fecha',
        'zona_id' => 'opcional|int|min:1',
        'conductor_id' => 'opcional|int|min:1',
        'vehiculo_tipo' => 'opcional|enum:furgon,motocicleta,vehiculo_electrico,bicicleta',
    ]);
    [$desde, $hasta] = kpi_rango($d['desde'], $d['hasta']);
    return kpi_mapa_calor($desde, $hasta, [
        'zona_id' => $d['zona_id'],
        'conductor_id' => $d['conductor_id'],
        'vehiculo_tipo' => $d['vehiculo_tipo'],
    ], $d['tipo'] ?? 'entregas');
});
