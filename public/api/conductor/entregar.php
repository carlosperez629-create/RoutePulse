<?php
/**
 * POST /api/conductor/entregar.php  (rol: conductor, multipart)
 * Campos: uuid, parada_id, receptor_nombre, receptor_documento?, otp?, completa?, nota?,
 *         lat, lng, precision_m?, ocurrido_at, fuera_de_linea?, firma (archivo) o firma_base64, foto (archivo).
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
        'uuid'               => 'requerido|uuid',
        'parada_id'          => 'requerido|int|min:1',
        'receptor_nombre'    => 'requerido|str|max:100',
        'receptor_documento' => 'opcional|str|max:30',
        'otp'                => 'opcional|str|max:10',
        'completa'           => 'opcional|bool',
        'nota'               => 'opcional|str|max:500',
        'lat'                => 'requerido|lat',
        'lng'                => 'requerido|lng',
        'precision_m'        => 'opcional|num|min:0|max:100000',
        'ocurrido_at'        => 'requerido|fecha_hora_ms',
        'fuera_de_linea'     => 'opcional|bool',
    ]);

    if ($d['otp'] !== null && !ctype_digit($d['otp'])) {
        error_validacion(['otp' => 'El código debe contener solo dígitos.']);
    }
    if (strtotime($d['ocurrido_at'] . ' UTC') > time() + 120) {
        error_validacion(['ocurrido_at' => 'La hora de la entrega no puede estar en el futuro.']);
    }

    $d['completa'] = $d['completa'] ?? true;
    $d['fuera_de_linea'] = $d['fuera_de_linea'] ?? false;
    $d['resultado'] = 'entregado';
    $d['firma_base64'] = isset($in['firma_base64']) && is_string($in['firma_base64']) ? $in['firma_base64'] : null;

    $resultado = entrega_registrar($d, $conductorId);
    if (empty($resultado['duplicado'])) {
        api_status(201);
    }
    return $resultado;
});
