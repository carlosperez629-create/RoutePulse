<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET zonas/listar.php (A, D, G): incluye poligono (GeoJSON), codigos_postales[] y conductores[]. */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, ['activa' => 'opcional|bool']);
    return zona_listar(!empty($d['activa']) ? true : null);
});
