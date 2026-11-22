<?php
/** POST integraciones/paquetes_entrada.php — API para ERP. Cabecera X-API-Key. Body: {"paquetes":[{...}]} (máx 100) */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('POST', [], function (array $in, ?array $u): array {
    $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    $i = $key !== '' ? db_one('SELECT * FROM integraciones WHERE api_key_hash = ? AND activo = 1', 's', [hash('sha256', $key)]) : null;
    if (!$i) { lanzar_error('NO_AUTENTICADO', 'API key inválida.', 401); }
    $items = $in['paquetes'] ?? null;
    if (!is_array($items) || !$items || count($items) > 100) { error_validacion(['paquetes' => 'Envía entre 1 y 100 paquetes.']); }
    $res = [];
    foreach ($items as $k => $it) {
        try {
            $p = paquete_crear((array) $it, null, (int) $i['id']);
            $res[] = ['indice' => $k, 'ok' => true, 'id' => (int) $p['id'], 'codigo_guia' => $p['codigo_guia'], 'duplicado' => !empty($p['duplicado'])];
        } catch (AppError $e) {
            $res[] = ['indice' => $k, 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $res;
}, ['rate' => ['clave' => 'erp', 'max' => 60, 'segundos' => 60]]);
