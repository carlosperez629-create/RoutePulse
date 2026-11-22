<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** POST rutas/recalcular.php (A, D): reenrutamiento; devuelve la ruta y cambios[]. */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1', 'motivo' => 'requerido|str|max:255']);
    $evitar = isset($in['evitar']) && is_array($in['evitar']) ? $in['evitar'] : [];
    return ruta_recalcular((int) $d['ruta_id'], $d['motivo'], (int) $usuario['id'], ['evitar' => $evitar]);
});
