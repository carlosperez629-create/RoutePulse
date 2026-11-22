<?php
/** POST integraciones/probar.php (admin): prueba rápida (geocodificar/matriz/notificar) para validar el hosting */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('POST', ['admin'], function (array $in, ?array $u): array {
    $d = validar($in, ['prueba' => 'requerido|enum:geocodificar,matriz,notificacion', 'direccion' => 'opcional|str|max:200', 'paquete_id' => 'opcional|int|min:1']);
    if ($d['prueba'] === 'geocodificar') {
        return ['resultado' => geocodificar($d['direccion'] ?? 'Calle 10 # 5-20', 'San Francisco')];
    }
    if ($d['prueba'] === 'matriz') {
        return ['resultado' => ruta_matriz([['lat' => 10.5571993, 'lng' => -71.6490662], ['lat' => 10.5974993, 'lng' => -71.6273662], ['lat' => 10.6474993, 'lng' => -71.6073662]])];
    }
    if (empty($d['paquete_id'])) { error_validacion(['paquete_id' => 'Indica un paquete.']); }
    $n = notificar((int) $d['paquete_id'], 'en_camino', [], 'prueba:' . $d['paquete_id'] . ':' . time());
    return ['notificaciones_creadas' => $n];
});
