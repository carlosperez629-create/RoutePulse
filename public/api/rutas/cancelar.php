<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/cancelar.php (A, D): solo borrador o planificada. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'motivo' => 'opcional|str|max:255']);
    return ruta_cancelar((int) $d['ruta_id'], $d['motivo'], (int) $usuario['id']);
});
