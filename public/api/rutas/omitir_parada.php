<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/omitir_parada.php (A, D): solo en_curso; incidencia «otro» sin sumar intento. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'parada_id' => 'requerido|int|min:1', 'motivo' => 'opcional|str|max:255']);
    return ruta_omitir_parada((int) $d['ruta_id'], (int) $d['parada_id'], $d['motivo'], (int) $usuario['id']);
});
