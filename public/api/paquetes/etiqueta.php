<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET paquetes/etiqueta.php?ids=1,2,3 (A, D): datos para imprimir las guías (máx. 100). */
api_run('GET', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ids' => 'requerido|lista_int']);
    return paquete_etiquetas($d['ids']);
});
