<?php
/**
 * RoutePulse - M5 Portal del cliente: servicio de seguimiento por token.
 *
 * Funciones públicas (contrato de docs/00-base/07-servicios-internos.md, sección G):
 *   token_seguimiento_generar(), tracking_url(), tracking_estado_publico(), tracking_reprogramar().
 *
 * Seguridad: este servicio SOLO entrega los campos permitidos en docs/modulos/M5-portal-cliente.md
 * (sección 5). Nunca devuelve teléfonos, correos, coordenadas exactas del domicilio, nombres de
 * conductores ni placas. Las tablas se leen (SELECT); los cambios los hacen los servicios del dueño.
 */

const TRACKING_MENSAJE_INVALIDO = 'Este enlace de seguimiento no es válido o ya venció.';

const TRACKING_ESTADOS_TEXTO = [
    'creado' => 'Creado',
    'ingresado_hub' => 'Ingresado en HUB',
    'en_clasificacion' => 'En clasificación',
    'asignado_ruta' => 'Asignado a ruta',
    'en_transito' => 'En tránsito',
    'entregado' => 'Entregado',
    'incidencia' => 'Incidencia',
    'cancelado' => 'Cancelado',
    'devuelto' => 'Devuelto',
];

const TRACKING_MOTIVOS_TEXTO = [
    'direccion_no_encontrada' => 'Dirección no encontrada',
    'cliente_ausente' => 'Cliente ausente',
    'cobro_no_realizado' => 'Cobro no realizado',
    'rechazado_por_cliente' => 'Rechazado por el cliente',
    'paquete_danado' => 'Paquete dañado',
    'zona_insegura' => 'Zona insegura',
    'otro' => 'Otro',
];

/** Franjas de reprogramación: hora local de inicio y de fin. */
const TRACKING_FRANJAS = [
    'manana' => ['08:00:00', '12:00:00'],
    'tarde' => ['13:00:00', '18:00:00'],
    'cualquiera' => ['08:00:00', '18:00:00'],
];

/** Estados en los que el cliente ya no necesita refrescar la pantalla. */
const TRACKING_ESTADOS_TERMINALES = ['entregado', 'cancelado', 'devuelto'];

// ---------------------------------------------------------------------
// Funciones del contrato
// ---------------------------------------------------------------------

/**
 * Genera un token de seguimiento nuevo (32 caracteres hexadecimales).
 * No toca la base de datos: M3 lo guarda en paquetes.token_seguimiento al crear el paquete.
 */
function token_seguimiento_generar(): string
{
    return bin2hex(random_bytes(16));
}

/** URL absoluta del enlace de seguimiento: app.url + /t/ + token. */
function tracking_url(string $token): string
{
    return rtrim((string) app_cfg('app.url', ''), '/') . '/t/' . $token;
}

/**
 * Datos públicos del seguimiento de un paquete.
 *
 * @param string $token Token de 32 caracteres hexadecimales
 * @return array Solo los campos permitidos por M5 (ver sección 5 del documento del módulo)
 * @throws AppError NO_ENCONTRADO (404) si el token tiene formato inválido, no existe o venció
 */
function tracking_estado_publico(string $token): array
{
    $p = tracking_paquete_por_token($token);
    $estado = (string) $p['estado'];
    $paqueteId = (int) $p['id'];
    $enTransito = $estado === 'en_transito';

    // Parada abierta del paquete en una ruta en curso (solo importa mientras va en tránsito).
    $parada = null;
    $paradasAntes = null;
    $eta = null;
    if ($enTransito) {
        $parada = db_one(
            "SELECT pa.id, pa.ruta_id, pa.orden, pa.lat, pa.lng, pa.eta, r.conductor_id
             FROM paradas pa
             JOIN rutas r ON r.id = pa.ruta_id
             WHERE pa.paquete_id = ? AND r.estado = 'en_curso'
               AND pa.estado IN ('pendiente','en_camino','llegado')
             ORDER BY pa.id DESC
             LIMIT 1",
            'i',
            [$paqueteId]
        );
        if ($parada !== null) {
            $paradasAntes = (int) db_val(
                "SELECT COUNT(*) FROM paradas
                 WHERE ruta_id = ? AND orden < ? AND estado IN ('pendiente','en_camino','llegado')",
                'ii',
                [(int) $parada['ruta_id'], (int) $parada['orden']]
            );
            $eta = tracking_leer_eta((int) $parada['id'], $parada['eta']);
        }
    }

    // Regla del mapa: en tránsito, a lo sumo N paradas antes y posición reciente.
    $vehiculo = null;
    $destino = null;
    $mostrarMapa = false;
    if ($parada !== null && $parada['conductor_id'] !== null
        && $paradasAntes <= (int) config_get('portal_paradas_en_vivo', 3)) {
        $pos = tracking_leer_posicion((int) $parada['conductor_id']);
        if ($pos !== null && $pos['antiguedad_s'] < (int) config_get('gps_sin_senal_s', 300)) {
            $mostrarMapa = true;
            $vehiculo = [
                'lat' => $pos['lat'],
                'lng' => $pos['lng'],
                'antiguedad_s' => $pos['antiguedad_s'],
            ];
            // El destino se redondea a 3 decimales (~110 m) para no revelar el domicilio exacto.
            $destino = ['lat' => round((float) $parada['lat'], 3), 'lng' => round((float) $parada['lng'], 3)];
        }
    }

    // Historial público: solo estado, texto y fecha (sin usuarios, motivos ni coordenadas).
    $historial = [];
    $filas = db_all(
        'SELECT estado_nuevo, created_at FROM historial_estados WHERE paquete_id = ? ORDER BY created_at, id',
        'i',
        [$paqueteId]
    );
    foreach ($filas as $h) {
        $historial[] = [
            'estado' => (string) $h['estado_nuevo'],
            'estado_texto' => tracking_estado_texto((string) $h['estado_nuevo']),
            'fecha' => fecha_a_iso((string) $h['created_at']),
        ];
    }

    $incidencia = null;
    if ($estado === 'incidencia') {
        $tipo = db_val(
            "SELECT tipo FROM incidencias WHERE paquete_id = ? ORDER BY (estado = 'abierta') DESC, id DESC LIMIT 1",
            'i',
            [$paqueteId]
        );
        $incidencia = ['motivo_texto' => TRACKING_MOTIVOS_TEXTO[(string) $tipo] ?? TRACKING_MOTIVOS_TEXTO['otro']];
    }

    $entrega = null;
    if ($estado === 'entregado') {
        $e = db_one(
            "SELECT ocurrido_at, receptor_nombre FROM entregas
             WHERE paquete_id = ? AND resultado = 'entregado' ORDER BY id DESC LIMIT 1",
            'i',
            [$paqueteId]
        );
        $fecha = $e['ocurrido_at'] ?? null;
        if ($fecha === null) {
            // Respaldo: la fecha del cambio de estado a «entregado».
            $fecha = db_val(
                "SELECT created_at FROM historial_estados
                 WHERE paquete_id = ? AND estado_nuevo = 'entregado' ORDER BY id DESC LIMIT 1",
                'i',
                [$paqueteId]
            );
        }
        $entrega = [
            'fecha' => $fecha !== null ? fecha_a_iso(tracking_sin_fracciones((string) $fecha)) : null,
            'recibio' => isset($e['receptor_nombre']) && $e['receptor_nombre'] !== ''
                ? tracking_nombre_abreviado((string) $e['receptor_nombre']) : null,
        ];
    }

    return [
        'codigo_guia' => (string) $p['codigo_guia'],
        'estado' => $estado,
        'estado_texto' => tracking_estado_texto($estado),
        'destinatario' => tracking_nombre_abreviado((string) $p['destinatario_nombre']),
        'direccion_resumen' => tracking_direccion_resumen((string) $p['direccion_entrega']),
        'eta' => $eta !== null ? fecha_a_iso($eta) : null,
        'paradas_antes' => $paradasAntes,
        'mostrar_mapa' => $mostrarMapa,
        'vehiculo' => $vehiculo,
        'destino' => $destino,
        'historial' => $historial,
        'puede_reprogramar' => $estado === 'incidencia' && (int) $p['intentos'] < (int) $p['max_intentos'],
        'incidencia' => $incidencia,
        'entrega' => $entrega,
        'intervalo_refresco_s' => (int) config_get('portal_refresh_s', 10),
    ];
}

/**
 * Reprograma una entrega fallida a pedido del cliente (operación única).
 *
 * @param string $token Token de seguimiento
 * @param array  $datos `fecha` (AAAA-MM-DD), `franja` (manana|tarde|cualquiera), `nota` (opcional, no se guarda)
 * @return array `['estado' => 'ingresado_hub', 'fecha_programada' => 'AAAA-MM-DD']`
 * @throws AppError NO_ENCONTRADO (token), VALIDACION (fecha o franja), ESTADO_INVALIDO (no está en incidencia
 *                  o ya no quedan intentos)
 */
function tracking_reprogramar(string $token, array $datos): array
{
    $p = tracking_paquete_por_token($token);

    $fecha = (string) ($datos['fecha'] ?? '');
    $franja = (string) ($datos['franja'] ?? '');
    $errores = [];
    if (!isset(TRACKING_FRANJAS[$franja])) {
        $errores['franja'] = 'Elige una franja horaria válida.';
    }
    $diasMax = (int) config_get('reprogramar_dias_max', 7);
    $tz = tracking_zona_ui();
    $hoy = new DateTimeImmutable('today', $tz);
    $minimo = $hoy->modify('+1 day');
    $maximo = $hoy->modify('+' . $diasMax . ' days');
    $fechaOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1
        && checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4));
    if (!$fechaOk) {
        $errores['fecha'] = 'Fecha inválida.';
    } else {
        $elegida = new DateTimeImmutable($fecha . ' 00:00:00', $tz);
        if ($elegida < $minimo || $elegida > $maximo) {
            $errores['fecha'] = 'Elige una fecha desde mañana hasta dentro de ' . $diasMax . ' días.';
        }
    }
    if ($errores) {
        error_validacion($errores);
    }

    if ($p['estado'] !== 'incidencia' || (int) $p['intentos'] >= (int) $p['max_intentos']) {
        error_estado('Este envío ya no se puede reprogramar. Comunícate con la empresa.');
    }
    $incidenciaId = db_val(
        "SELECT id FROM incidencias WHERE paquete_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1",
        'i',
        [(int) $p['id']]
    );
    if ($incidenciaId === null) {
        error_estado('Este envío ya no se puede reprogramar. Comunícate con la empresa.');
    }

    // Ventana horaria en hora local → UTC para ventana_inicio / ventana_fin.
    $utc = new DateTimeZone('UTC');
    [$horaIni, $horaFin] = TRACKING_FRANJAS[$franja];
    $ventana = [
        'inicio' => (new DateTimeImmutable($fecha . ' ' . $horaIni, $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
        'fin' => (new DateTimeImmutable($fecha . ' ' . $horaFin, $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];

    // usuario_id = null: la acción la hace el cliente desde el portal.
    incidencia_resolver((int) $incidenciaId, 'reprogramar', $fecha, null, $ventana);

    $nuevo = db_one('SELECT estado, fecha_programada FROM paquetes WHERE id = ?', 'i', [(int) $p['id']]);
    return [
        'estado' => (string) ($nuevo['estado'] ?? 'ingresado_hub'),
        'fecha_programada' => $nuevo['fecha_programada'] ?? $fecha,
    ];
}

// ---------------------------------------------------------------------
// Auxiliares del portal
// ---------------------------------------------------------------------

/** ¿Tiene el formato de un token de seguimiento (32 caracteres hexadecimales en minúscula)? */
function tracking_token_valido(string $token): bool
{
    return preg_match('/^[0-9a-f]{32}$/', $token) === 1;
}

/**
 * Busca el paquete por token. Lanza el MISMO 404 si el formato es inválido, el token no existe o
 * ya venció, para no revelar si el token existió alguna vez.
 */
function tracking_paquete_por_token(string $token): array
{
    $p = null;
    if (tracking_token_valido($token)) {
        $p = db_one(
            'SELECT id, codigo_guia, estado, destinatario_nombre, direccion_entrega,
                    intentos, max_intentos, token_expira_at
             FROM paquetes WHERE token_seguimiento = ?',
            's',
            [$token]
        );
    }
    if ($p === null || ($p['token_expira_at'] !== null && (string) $p['token_expira_at'] <= fecha_utc_ahora())) {
        lanzar_error('NO_ENCONTRADO', TRACKING_MENSAJE_INVALIDO, 404);
    }
    return $p;
}

function tracking_estado_texto(string $estado): string
{
    return TRACKING_ESTADOS_TEXTO[$estado] ?? $estado;
}

/** «Gloria Sanz» → «Gloria S.»; un solo nombre queda igual. */
function tracking_nombre_abreviado(string $nombre): string
{
    $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($partes) === 0) {
        return '';
    }
    if (count($partes) === 1) {
        return $partes[0];
    }
    return $partes[0] . ' ' . mb_strtoupper(mb_substr($partes[1], 0, 1)) . '.';
}

/** Primeros 15 caracteres de la dirección más «…» (la dirección completa no se publica). */
function tracking_direccion_resumen(string $direccion): string
{
    $direccion = trim($direccion);
    if (mb_strlen($direccion) <= 15) {
        return $direccion;
    }
    return rtrim(mb_substr($direccion, 0, 15)) . '…';
}

function tracking_zona_ui(): DateTimeZone
{
    try {
        return new DateTimeZone((string) config_get('zona_horaria_ui', 'UTC'));
    } catch (Throwable $e) {
        return new DateTimeZone('UTC');
    }
}

/** Quita los milisegundos de una fecha UTC (`2026-11-13 15:04:05.123` → `2026-11-13 15:04:05`). */
function tracking_sin_fracciones(string $fechaUtc): string
{
    return substr($fechaUtc, 0, 19);
}

/** ETA de la parada: usa el servicio de M2 si ya está publicado; si no, lee paradas.eta. */
function tracking_leer_eta(int $paradaId, ?string $etaTabla): ?string
{
    if (function_exists('eta_de_parada')) {
        $eta = eta_de_parada($paradaId);
        return $eta !== null ? tracking_sin_fracciones((string) $eta) : null;
    }
    return $etaTabla !== null ? tracking_sin_fracciones((string) $etaTabla) : null;
}

/**
 * Última posición del conductor: usa el servicio de M2 si ya está publicado; si no, lee ultima_posicion.
 *
 * @return array{lat:float,lng:float,antiguedad_s:int}|null
 */
function tracking_leer_posicion(int $conductorId): ?array
{
    if (function_exists('gps_ultima_posicion')) {
        $pos = gps_ultima_posicion($conductorId);
        if ($pos === null) {
            return null;
        }
        return [
            'lat' => (float) $pos['lat'],
            'lng' => (float) $pos['lng'],
            'antiguedad_s' => max(0, (int) $pos['antiguedad_s']),
        ];
    }
    $f = db_one('SELECT lat, lng, capturado_at FROM ultima_posicion WHERE conductor_id = ?', 'i', [$conductorId]);
    if ($f === null) {
        return null;
    }
    $capturado = new DateTimeImmutable(tracking_sin_fracciones((string) $f['capturado_at']), new DateTimeZone('UTC'));
    return [
        'lat' => (float) $f['lat'],
        'lng' => (float) $f['lng'],
        'antiguedad_s' => max(0, time() - $capturado->getTimestamp()),
    ];
}

/** Cabeceras de todas las respuestas públicas del portal. */
function tracking_cabeceras_publicas(): void
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
}

/** IP del cliente para el límite de velocidad (solo confía en X-Forwarded-For si app.proxy_confiable). */
function tracking_ip_cliente(): string
{
    if (app_cfg('app.proxy_confiable', false) === true && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $primera = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
            return $primera;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
