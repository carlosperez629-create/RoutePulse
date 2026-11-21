<?php
/**
 * POST /api/conductor/fallar.php  (rol: conductor, multipart)
 * Campos: uuid, parada_id, motivo_fallo, nota?, lat, lng, precision_m?, ocurrido_at,
 *         fuera_de_linea?, foto? (obligatoria si motivo_fallo = paquete_danado).
 * Respuesta 201: { entrega, siguiente_parada, ruta_completa, advertencias }
 *               (200 con duplicado:true si el uuid ya existía).
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['conductor'], function (array $in, ?array $usuario) {
    $conductorId = auth_conductor_id();
    if ($conductorId === null) {
        error_sin_permiso();
    }

    $d = validar($in, [
        'uuid'           => 'requerido|uuid',
        'parada_id'      => 'requerido|int|min:1',
        'motivo_fallo'   => 'requerido|enum:' . implode(',', array_keys(INCIDENCIA_TIPOS)),
        'nota'           => 'opcional|str|max:500',
        'lat'            => 'requerido|lat',
        'lng'            => 'requerido|lng',
        'precision_m'    => 'opcional|num|min:0|max:100000',
        'ocurrido_at'    => 'requerido|fecha_hora_ms',
        'fuera_de_linea' => 'opcional|bool',
    ]);

    if (strtotime($d['ocurrido_at'] . ' UTC') > time() + 120) {
        error_validacion(['ocurrido_at' => 'La hora del fallo no puede estar en el futuro.']);
    }

    $d['fuera_de_linea'] = $d['fuera_de_linea'] ?? false;
    $d['resultado'] = 'fallida';

    $resultado = entrega_registrar($d, $conductorId);
    if (empty($resultado['duplicado'])) {
        api_status(201);
    }
    return $resultado;
});
