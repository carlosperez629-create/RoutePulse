<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET rutas/ver.php?ruta_id= (A, D, G, C): el conductor solo ve su propia ruta (SIN_PERMISO si no). */
api_run('GET', ['admin', 'despachador', 'gerente', 'conductor'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);
    $ruta = ruta_obtener((int) $d['ruta_id']);
    if (!$ruta) {
        error_no_encontrado('La ruta');
    }
    if ($usuario['rol'] === 'conductor') {
        $conductorId = auth_conductor_id();
        if ($conductorId === null || (int) $ruta['conductor_id'] !== $conductorId) {
            error_sin_permiso();
        }
    }
    return $ruta;
});
