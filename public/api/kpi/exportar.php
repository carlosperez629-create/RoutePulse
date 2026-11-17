<?php
/**
 * GET kpi/exportar.php?reporte=resumen|conductores|zonas|entregas - Descarga un CSV (roles: admin, gerente).
 * CSV en UTF-8 con BOM, separador coma, fechas en la zona horaria de la interfaz. Audita `kpi_exportar`.
 * Filtros: desde, hasta, zona_id?, conductor_id?, vehiculo_tipo?
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'reporte' => 'requerido|enum:resumen,conductores,zonas,entregas',
        'desde' => 'opcional|fecha',
        'hasta' => 'opcional|fecha',
        'zona_id' => 'opcional|int|min:1',
        'conductor_id' => 'opcional|int|min:1',
        'vehiculo_tipo' => 'opcional|enum:furgon,motocicleta,vehiculo_electrico,bicicleta',
    ]);
    [$desde, $hasta] = kpi_rango($d['desde'], $d['hasta']);
    $filtros = [
        'zona_id' => $d['zona_id'],
        'conductor_id' => $d['conductor_id'],
        'vehiculo_tipo' => $d['vehiculo_tipo'],
    ];

    [$cabecera, $filas] = kpi_exportar_tabla($d['reporte'], $desde, $hasta, $filtros);
    $csv = kpi_csv($cabecera, $filas);

    audit('kpi_exportar', 'kpi', null, [
        'reporte' => $d['reporte'],
        'desde' => $desde,
        'hasta' => $hasta,
        'filtros' => array_filter($filtros, fn ($v) => $v !== null),
        'filas' => count($filas),
    ]);

    // Respuesta de archivo: se corta aquí porque no es JSON.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routepulse_' . $d['reporte'] . '_' . $desde . '_' . $hasta . '.csv"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
});
