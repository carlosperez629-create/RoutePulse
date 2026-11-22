<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET paquetes/historial.php?paquete_id= (A, D, G): cadena de custodia. */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, ['paquete_id' => 'requerido|int|min:1']);
    if (!paquete_obtener((int) $d['paquete_id'])) {
        error_no_encontrado('El paquete');
    }
    return paquete_historial((int) $d['paquete_id']);
});
