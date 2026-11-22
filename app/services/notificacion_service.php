<?php
/** M7 - notificaciones con fallback de canal, deduplicación, modo simulado y cola. */

function notificacion_render(string $texto, array $v): string
{
    $map = [];
    foreach ($v as $k => $val) { $map['{' . $k . '}'] = (string) $val; }
    return strtr($texto, $map);
}

function notificacion_destino(array $p, string $canal): ?string
{
    if ($canal === 'email') { return $p['destinatario_email'] ?: null; }
    return $p['destinatario_telefono'] ?: null;
}

/**
 * Crea y envía (primer intento) una notificación. Nunca lanza. Devuelve filas creadas (0 si duplicada/sin canal).
 */
function notificar(int $paqueteId, string $tipo, array $vars = [], ?string $claveDedup = null): int
{
    try {
        $p = db_one('SELECT * FROM paquetes WHERE id = ?', 'i', [$paqueteId]);
        if (!$p) { return 0; }
        $claveDedup = $claveDedup ?? "$paqueteId:$tipo";
        if (db_val('SELECT COUNT(*) FROM notificaciones WHERE clave_dedup = ?', 's', [$claveDedup])) { return 0; }
        $vars += [
            'nombre' => $p['destinatario_nombre'], 'guia' => $p['codigo_guia'],
            'link' => function_exists('paquete_enlace_seguimiento') ? paquete_enlace_seguimiento((string) $p['token_seguimiento']) : '',
            'fecha' => gmdate('Y-m-d H:i'),
        ];
        $canales = config_get('canales_orden', ['whatsapp', 'sms', 'email']);
        return notificacion_crear_en_canal($p, $tipo, $vars, $claveDedup, is_array($canales) ? array_values($canales) : []);
    } catch (Throwable $e) {
        log_app('error', 'notificar falló: ' . $e->getMessage(), ['paquete' => $paqueteId, 'tipo' => $tipo]);
        return 0;
    }
}

/** Busca el primer canal con destino y plantilla; crea la fila y envía. */
function notificacion_crear_en_canal(array $p, string $tipo, array $vars, string $clave, array $canales): int
{
    foreach ($canales as $canal) {
        $dest = notificacion_destino($p, (string) $canal);
        $pl = $dest ? db_one('SELECT * FROM plantillas_notificacion WHERE tipo = ? AND canal = ? AND activa = 1', 'ss', [$tipo, $canal]) : null;
        if (!$pl) { continue; }
        $asunto = $pl['asunto'] ? notificacion_render($pl['asunto'], $vars) : null;
        $msg = notificacion_render($pl['cuerpo'], $vars);
        $id = db_insert(
            'INSERT INTO notificaciones (paquete_id, tipo, canal, destino, asunto, mensaje, clave_dedup, estado, intentos, proximo_intento_at, created_at) VALUES (?,?,?,?,?,?,?,\'pendiente\',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            'issssss', [(int) $p['id'], $tipo, $canal, $dest, $asunto, $msg, $clave]
        );
        notificacion_enviar($id);
        return 1;
    }
    return 0;
}

/** Intenta enviar una notificación; actualiza estado/reintentos. Devuelve el estado. */
function notificacion_enviar(int $id): string
{
    $n = db_one('SELECT * FROM notificaciones WHERE id = ?', 'i', [$id]);
    if (!$n || $n['estado'] !== 'pendiente') { return $n['estado'] ?? 'cancelado'; }
    $prov = $n['canal'] === 'email' ? 'smtp' : 'twilio';
    try {
        if (integracion_simulada($prov)) {
            $mid = 'SIM-' . uuid_v4();
            $dir = rp_ruta_storage('logs');
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            @file_put_contents($dir . '/notificaciones_simuladas.log',
                gmdate('c') . " [{$n['canal']}] -> {$n['destino']}: " . ($n['asunto'] ? "[{$n['asunto']}] " : '') . $n['mensaje'] . "\n", FILE_APPEND | LOCK_EX);
        } else {
            $mid = $n['canal'] === 'email' ? notificacion_enviar_smtp($n) : notificacion_enviar_twilio($n);
        }
        db_exec("UPDATE notificaciones SET estado='enviado', proveedor_mensaje_id=?, enviado_at=UTC_TIMESTAMP(), intentos=intentos+1, error=NULL WHERE id=?", 'si', [$mid, $id]);
        return 'enviado';
    } catch (Throwable $e) {
        $intentos = (int) $n['intentos'] + 1;
        $max = (int) config_get('notif_max_reintentos', 3);
        $back = config_get('notif_backoff_min', [1, 5, 15]);
        if ($intentos >= $max) {
            db_exec("UPDATE notificaciones SET estado='fallido', intentos=?, error=? WHERE id=?", 'isi', [$intentos, mb_substr($e->getMessage(), 0, 400), $id]);
            return 'fallido';
        }
        $min = (int) (is_array($back) ? ($back[$intentos - 1] ?? end($back)) : 5);
        db_exec('UPDATE notificaciones SET intentos=?, error=?, proximo_intento_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id=?', 'isii', [$intentos, mb_substr($e->getMessage(), 0, 400), $min, $id]);
        return 'pendiente';
    }
}

function notificacion_enviar_twilio(array $n): string
{
    $c = app_cfg('twilio', []);
    if (empty($c['sid']) || empty($c['token'])) { throw new RuntimeException('Twilio sin credenciales'); }
    $wa = $n['canal'] === 'whatsapp';
    $from = $wa ? 'whatsapp:' . $c['from_whatsapp'] : $c['from_sms'];
    $to = ($wa ? 'whatsapp:' : '') . $n['destino'];
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $c['sid'] . '/Messages.json');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => $c['sid'] . ':' . $c['token'], CURLOPT_POSTFIELDS => http_build_query(['From' => $from, 'To' => $to, 'Body' => $n['mensaje']])]);
    $r = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string) $r, true);
    if ($http >= 300 || empty($j['sid'])) { throw new RuntimeException('Twilio HTTP ' . $http . ': ' . ($j['message'] ?? '')); }
    return (string) $j['sid'];
}

function notificacion_enviar_smtp(array $n): string
{
    $c = app_cfg('smtp', []);
    if (empty($c['host'])) { throw new RuntimeException('SMTP sin configurar'); }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) { throw new RuntimeException('PHPMailer no instalado (composer require phpmailer/phpmailer)'); }
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP(); $m->Host = $c['host']; $m->Port = (int) $c['puerto']; $m->SMTPAuth = true;
    $m->Username = $c['usuario']; $m->Password = $c['clave']; $m->SMTPSecure = 'tls'; $m->CharSet = 'UTF-8';
    $m->setFrom($c['desde'] ?: $c['usuario'], 'RoutePulse');
    $m->addAddress($n['destino']); $m->Subject = (string) ($n['asunto'] ?: 'RoutePulse'); $m->Body = $n['mensaje'];
    $m->send();
    return 'SMTP-' . uuid_v4();
}

/**
 * Cola: reintenta pendientes vencidas; si una falló definitivamente, crea fila en el siguiente canal (':fb<n>').
 * @return array{procesadas:int,enviadas:int,fallback:int}
 */
function notificacion_procesar_cola(int $limite = 50): array
{
    $r = ['procesadas' => 0, 'enviadas' => 0, 'fallback' => 0];
    $filas = db_all("SELECT id FROM notificaciones WHERE estado='pendiente' AND proximo_intento_at <= UTC_TIMESTAMP() ORDER BY id LIMIT ?", 'i', [$limite]);
    foreach ($filas as $f) {
        $r['procesadas']++;
        $e = notificacion_enviar((int) $f['id']);
        if ($e === 'enviado') { $r['enviadas']++; }
    }
    // fallback para fallidas sin hijo
    $fall = db_all("SELECT * FROM notificaciones WHERE estado='fallido' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) ORDER BY id LIMIT ?", 'i', [$limite]);
    foreach ($fall as $n) {
        $base = $n['clave_dedup'];
        $nivel = 0;
        if (preg_match('/^(.*):fb(\d+)$/', $base, $m)) { $base = $m[1]; $nivel = (int) $m[2]; }
        $canales = config_get('canales_orden', ['whatsapp', 'sms', 'email']);
        $pos = array_search($n['canal'], (array) $canales, true);
        if ($pos === false) { continue; }
        if (db_val('SELECT COUNT(*) FROM notificaciones WHERE clave_dedup = ?', 's', [$base . ':fb' . ($nivel + 1)])) { continue; }
        $resto = array_slice((array) $canales, $pos + 1);
        $p = db_one('SELECT * FROM paquetes WHERE id = ?', 'i', [(int) $n['paquete_id']]);
        if (!$p || !$resto) { continue; }
        $vars = ['nombre' => $p['destinatario_nombre'], 'guia' => $p['codigo_guia'], 'link' => function_exists('paquete_enlace_seguimiento') ? paquete_enlace_seguimiento((string) $p['token_seguimiento']) : '', 'fecha' => gmdate('Y-m-d H:i'), 'eta_min' => '', 'otp' => '', 'motivo' => ''];
        if (notificacion_crear_en_canal($p, $n['tipo'], $vars, $base . ':fb' . ($nivel + 1), $resto)) { $r['fallback']++; }
    }
    return $r;
}
