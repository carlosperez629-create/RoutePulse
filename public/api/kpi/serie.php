<?php
/**
 * GET kpi/serie.php - Serie diaria de KPIs: una fila por cada día del rango (roles: admin, gerente).
 * Filtros: desde, hasta, zona_id?, conductor_id?, vehiculo_tipo?
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'desde' => 'opcional|fecha',
        'hasta' => 'opcional|fecha',
        'zona_id' => 'opcional|int|min:1',
        'conductor_id' => 'opcional|int|min:1',
        'vehiculo_tipo' => 'opcional|enum:furgon,motocicleta,vehiculo_electrico,bicicleta',
    ]);
    [$desde, $hasta] = kpi_rango($d['desde'], $d['hasta']);
    return kpi_serie($desde, $hasta, [
        'zona_id' => $d['zona_id'],
        'conductor_id' => $d['conductor_id'],
        'vehiculo_tipo' => $d['vehiculo_tipo'],
    ]);
});
