<?php
/**
 * POST /api/gps/enviar.php  (rol: conductor)
 * Entrada JSON: { "ruta_id": 1, "puntos": [ {lat, lng, velocidad_kmh, precision_m, rumbo, bateria_pct, capturado_at}, ... ] }
 * Respuesta:    { "aceptados": n, "rechazados": [ {indice, motivo} ], "eventos": [ {tipo, parada_id} ] }
 * Reglas y detalle: modulos/M2-rastreo-gps.md, sección 5. La lógica vive en app/services/gps_service.php.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    // El conductor sale siempre de la sesión, nunca de un parámetro del cliente.
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }

    // 120 llamadas por minuto por conductor.
    rate_limit_check('gps:conductor:' . $conductorId, 120, 60);

    $d = validar($in, ['ruta_id' => 'requerido|int|min:1']);

    // validar() no tiene una regla para listas de objetos: se comprueba aquí; cada punto se valida en el servicio.
    $puntos = $in['puntos'] ?? null;
    if (!is_array($puntos) || $puntos === [] || !array_is_list($puntos)) {
        error_validacion(['puntos' => 'Envía una lista con al menos un punto.']);
    }
    if (count($puntos) > GPS_MAX_PUNTOS_POR_LLAMADA) {
        error_validacion(['puntos' => 'Máximo ' . GPS_MAX_PUNTOS_POR_LLAMADA . ' puntos por llamada.']);
    }

    return gps_registrar_puntos($d['ruta_id'], $conductorId, $puntos);
});
