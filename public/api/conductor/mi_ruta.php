<?php
/**
 * GET /api/conductor/mi_ruta.php  (rol: conductor)
 * Hoja de ruta del conductor de la sesión: ruta, vehículo, config, paradas con paquete y enlaces de navegación.
 * Devuelve data = null si no tiene ruta. Detalle: modulos/M4-app-conductor.md, sección 5.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();   // de la sesión, nunca del cliente
    if ($conductorId === null) {
        error_sin_permiso();
    }

    return entrega_mi_ruta($conductorId);
});
