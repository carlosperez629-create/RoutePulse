<?php
/**
 * GET /api/gps/eta.php?ruta_id=  (roles: admin, despachador, gerente, conductor [solo su ruta])
 * Respuesta: [{ parada_id, orden, estado, eta (ISO), minutos, en_riesgo }]
 * en_riesgo = ETA posterior a la ventana_fin del paquete. Detalle: modulos/M2-rastreo-gps.md, sección 5.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador', 'gerente', 'conductor'], function (array $in, ?array $usuario) {
    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);

    // Un conductor solo consulta su propia ruta: su id sale de la sesión, nunca del cliente.
    $soloConductorId = (($usuario['rol'] ?? '') === 'conductor') ? auth_conductor_id() : null;
    if (($usuario['rol'] ?? '') === 'conductor' && $soloConductorId === null) {
        error_sin_permiso();
    }

    return gps_eta_listar($d['ruta_id'], $soloConductorId);
});
