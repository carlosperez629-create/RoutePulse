<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET zonas/resolver.php?lat=&lng=&codigo_postal= (A, D): {zona_id, nombre} o null. */
api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['lat' => 'requerido|lat', 'lng' => 'requerido|lng', 'codigo_postal' => 'opcional|str|max:12']);
    $zid = zona_resolver((float) $d['lat'], (float) $d['lng'], $d['codigo_postal']);
    if ($zid === null) {
        return null;
    }
    return ['zona_id' => $zid, 'nombre' => db_val('SELECT nombre FROM zonas WHERE id = ?', 'i', [$zid])];
});
