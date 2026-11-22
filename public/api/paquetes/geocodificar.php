<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/**
 * GET paquetes/geocodificar.php?direccion=&ciudad=&codigo_postal= (A, D)
 * Apoyo del botón "Ubicar en mapa" de paquete_nuevo.php. No guarda nada.
 * (Endpoint agregado por M3: pendiente de registrar en docs/00-base/05-contrato-api.md.)
 */
api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, [
        'direccion'     => 'requerido|str|max:255',
        'ciudad'        => 'opcional|str|max:80',
        'codigo_postal' => 'opcional|str|max:12',
    ]);
    return paquete_ubicar($d['direccion'], $d['ciudad'], $d['codigo_postal']);
});
