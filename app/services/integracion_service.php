<?php
/** M7 - utilidades de integraciones: log, modo simulado, webhooks, API key. */

function integracion_log(string $integracion, string $accion, array $datos = []): void
{
    $dir = rp_ruta_storage('logs');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $linea = json_encode(['ts' => gmdate('c'), 'integracion' => $integracion, 'accion' => $accion, 'datos' => rp_ocultar_sensibles($datos)], JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/integraciones.log', $linea . "\n", FILE_APPEND | LOCK_EX);
}

function integracion_por_codigo(string $codigo): ?array
{
    return db_one('SELECT * FROM integraciones WHERE codigo = ?', 's', [$codigo]);
}

/** true si la integración está en modo simulado (o no existe). */
function integracion_simulada(string $codigo): bool
{
    $i = integracion_por_codigo($codigo);
    return !$i || (int) $i['modo_simulado'] === 1;
}

function integracion_http(string $metodo, string $url, ?array $json = null, array $cabeceras = [], int $timeout = 8): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: RoutePulse/1.0'], $cabeceras);
    if ($json !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $h]);
    $cuerpo = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'cuerpo' => $cuerpo === false ? null : $cuerpo, 'error' => $err ?: null];
}

/** Valida firma HMAC-SHA256 (base64 o hex). En dev+simulado se omite. */
function webhook_firma_valida(string $codigo, string $cuerpo, string $firma): bool
{
    if (rp_es_dev() && integracion_simulada($codigo)) { return true; }
    $secreto = (string) app_cfg('webhook_secretos.' . $codigo, '');
    if ($secreto === '' || $firma === '') { return false; }
    $raw = hash_hmac('sha256', $cuerpo, $secreto, true);
    return hash_equals(base64_encode($raw), $firma) || hash_equals(bin2hex($raw), strtolower($firma));
}

/** Convierte el JSON de la tienda a datos de paquete_crear. */
function webhook_normalizar(string $codigo, array $p): array
{
    if ($codigo === 'woocommerce') {
        $s = $p['shipping'] ?? [];
        $b = $p['billing'] ?? [];
        return [
            'externo' => (string) ($p['id'] ?? ''),
            'datos' => [
                'remitente_nombre' => 'Tienda WooCommerce',
                'destinatario_nombre' => trim(($s['first_name'] ?? $b['first_name'] ?? '') . ' ' . ($s['last_name'] ?? $b['last_name'] ?? '')) ?: 'Cliente',
                'destinatario_telefono' => $b['phone'] ?? ($s['phone'] ?? ''),
                'destinatario_email' => $b['email'] ?? null,
                'direccion_entrega' => trim(($s['address_1'] ?? '') . ' ' . ($s['address_2'] ?? '')),
                'ciudad' => $s['city'] ?? null,
                'codigo_postal' => $s['postcode'] ?? null,
            ],
        ];
    }
    // shopify / genérico
    $s = $p['shipping_address'] ?? [];
    return [
        'externo' => (string) ($p['id'] ?? ''),
        'datos' => [
            'remitente_nombre' => 'Tienda ' . $codigo,
            'destinatario_nombre' => trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: ($s['name'] ?? 'Cliente'),
            'destinatario_telefono' => $s['phone'] ?? ($p['phone'] ?? ($p['customer']['phone'] ?? '')),
            'destinatario_email' => $p['email'] ?? ($p['customer']['email'] ?? null),
            'direccion_entrega' => trim(($s['address1'] ?? '') . ' ' . ($s['address2'] ?? '')),
            'ciudad' => $s['city'] ?? null,
            'codigo_postal' => $s['zip'] ?? null,
            'lat' => $s['latitude'] ?? null,
            'lng' => $s['longitude'] ?? null,
        ],
    ];
}

/**
 * Procesa un evento de webhook ya guardado en webhook_eventos (id). Devuelve el estado final.
 */
function webhook_procesar(int $eventoId): string
{
    $ev = db_one('SELECT w.*, i.codigo FROM webhook_eventos w JOIN integraciones i ON i.id = w.integracion_id WHERE w.id = ?', 'i', [$eventoId]);
    if (!$ev) { return 'error'; }
    $p = json_decode((string) $ev['payload'], true);
    try {
        if (!is_array($p)) { throw new RuntimeException('JSON inválido'); }
        $tipo = (string) $ev['tipo'];
        if (str_contains($tipo, 'cancel')) {
            $pid = db_val('SELECT id FROM paquetes WHERE integracion_id = ? AND origen_externo_id = ?', 'is', [$ev['integracion_id'], preg_replace('/:cancel$/', '', (string) $ev['evento_externo_id'])]);
            if ($pid && function_exists('paquete_cambiar_estado')) {
                try { paquete_cambiar_estado((int) $pid, 'cancelado', null, 'Cancelado por webhook'); } catch (Throwable $e) { /* estado no cancelable */ }
            }
            db_exec("UPDATE webhook_eventos SET estado='procesado', paquete_id=?, procesado_at=UTC_TIMESTAMP(), error=NULL WHERE id=?", 'ii', [$pid ?: null, $eventoId]);
            return 'procesado';
        }
        $n = webhook_normalizar((string) $ev['codigo'], $p);
        $n['datos']['origen_externo_id'] = $n['externo'];
        $paq = paquete_crear($n['datos'], null, (int) $ev['integracion_id']);
        db_exec("UPDATE webhook_eventos SET estado='procesado', paquete_id=?, procesado_at=UTC_TIMESTAMP(), error=NULL, intentos=intentos+1 WHERE id=?", 'ii', [(int) $paq['id'], $eventoId]);
        integracion_log((string) $ev['codigo'], 'webhook_ok', ['evento' => $eventoId, 'paquete' => $paq['id']]);
        return 'procesado';
    } catch (Throwable $e) {
        db_exec("UPDATE webhook_eventos SET estado='error', error=?, intentos=intentos+1 WHERE id=?", 'si', [mb_substr($e->getMessage(), 0, 500), $eventoId]);
        integracion_log((string) $ev['codigo'], 'webhook_error', ['evento' => $eventoId, 'error' => $e->getMessage()]);
        return 'error';
    }
}

/** Handler común de /webhooks/<codigo>.php. */
function webhook_recibir(string $codigo): never
{
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
    $i = integracion_por_codigo($codigo);
    if (!$i || !(int) $i['activo']) { http_response_code(404); exit('{"ok":false,"error":"integracion inactiva"}'); }
    $cuerpo = entrada_cuerpo_crudo();
    $firma = (string) ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '');
    if (!webhook_firma_valida($codigo, $cuerpo, $firma)) {
        integracion_log($codigo, 'firma_invalida', []);
        http_response_code(401); exit('{"ok":false,"error":"firma invalida"}');
    }
    $p = json_decode($cuerpo, true);
    if (!is_array($p) || !isset($p['id'])) { http_response_code(400); exit('{"ok":false,"error":"payload invalido"}'); }
    $extId = (string) $p['id'];
    $tipo = (string) ($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? $p['topic'] ?? 'pedido');
    if (!empty($p['cancelled_at']) || ($p['status'] ?? '') === 'cancelled') { $tipo = 'cancelado'; }
    try {
        $id = db_insert('INSERT INTO webhook_eventos (integracion_id, evento_externo_id, tipo, payload, estado) VALUES (?,?,?,?,\'recibido\')', 'isss', [(int) $i['id'], $extId . ($tipo === 'cancelado' ? ':cancel' : ''), mb_substr($tipo, 0, 60), $cuerpo]);
    } catch (Throwable $e) {
        // clave única -> duplicado
        echo json_encode(['ok' => true, 'duplicado' => true]); exit;
    }
    $estado = webhook_procesar($id);
    echo json_encode(['ok' => true, 'evento_id' => $id, 'estado' => $estado]);
    exit;
}
