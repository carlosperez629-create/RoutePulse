<?php
/**
 * M3. Trazabilidad de envíos y control de HUBs (Juan).
 *
 * Servicio de paquetes, historial, incidencias y HUBs.
 * Contrato: docs/00-base/07-servicios-internos.md (sección C) y docs/modulos/M3-trazabilidad-hubs.md.
 *
 * Regla de oro: el estado de un paquete SOLO cambia con paquete_cambiar_estado().
 */

const PAQUETE_ESTADOS = ['creado', 'ingresado_hub', 'en_clasificacion', 'asignado_ruta', 'en_transito', 'entregado', 'incidencia', 'cancelado', 'devuelto'];
const PAQUETE_ESTADOS_TERMINALES = ['entregado', 'cancelado', 'devuelto'];
const PAQUETE_ESTADOS_EDITABLES = ['creado', 'ingresado_hub', 'en_clasificacion'];

/** Transiciones permitidas (docs/00-base/04-estados-y-reglas.md, sección 1). */
const PAQUETE_TRANSICIONES = [
    'creado'           => ['ingresado_hub', 'cancelado'],
    'ingresado_hub'    => ['en_clasificacion', 'cancelado'],
    'en_clasificacion' => ['asignado_ruta', 'cancelado'],
    'asignado_ruta'    => ['en_transito', 'en_clasificacion', 'cancelado'],
    'en_transito'      => ['entregado', 'incidencia'],
    'incidencia'       => ['ingresado_hub', 'devuelto', 'cancelado'],
    'entregado'        => [],
    'cancelado'        => [],
    'devuelto'         => [],
];

/** Estados desde los que el despachador puede cancelar a mano. */
const PAQUETE_CANCELABLES_MANUAL = ['creado', 'ingresado_hub', 'en_clasificacion', 'asignado_ruta'];

const PAQUETE_ESTADO_TEXTO = [
    'creado'           => 'Creado',
    'ingresado_hub'    => 'Ingresado en HUB',
    'en_clasificacion' => 'En clasificación',
    'asignado_ruta'    => 'Asignado a ruta',
    'en_transito'      => 'En tránsito',
    'entregado'        => 'Entregado',
    'incidencia'       => 'Incidencia',
    'cancelado'        => 'Cancelado',
    'devuelto'         => 'Devuelto',
];

const INCIDENCIA_TIPOS = [
    'direccion_no_encontrada' => 'Dirección no encontrada',
    'cliente_ausente'         => 'Cliente ausente',
    'cobro_no_realizado'      => 'Cobro no realizado',
    'rechazado_por_cliente'   => 'Rechazado por el cliente',
    'paquete_danado'          => 'Paquete dañado',
    'zona_insegura'           => 'Zona insegura',
    'otro'                    => 'Otro',
];

/** Columnas de paquetes que nunca se devuelven por la API (OTP de M4). */
const PAQUETE_CAMPOS_PRIVADOS = ['otp_hash', 'otp_expira_at', 'otp_intentos', 'otp_solicitudes', 'otp_ventana_at'];

/* ====================================================================
 * Utilidades
 * ==================================================================== */

/** Texto en pantalla de un estado de paquete. */
function paquete_texto_estado(string $estado): string
{
    return PAQUETE_ESTADO_TEXTO[$estado] ?? $estado;
}

if (!function_exists('fechas_fila_iso')) {
    /**
     * Convierte a ISO 8601 UTC los campos de fecha indicados de una fila (o lista de filas).
     * Los servicios devuelven 'Y-m-d H:i:s' UTC; la API entrega ISO con "Z".
     */
    function fechas_fila_iso(array $fila, array $campos): array
    {
        foreach ($campos as $c) {
            if (array_key_exists($c, $fila) && $fila[$c] !== null && $fila[$c] !== '') {
                $fila[$c] = fecha_a_iso((string) $fila[$c]);
            }
        }
        return $fila;
    }
}

/** Quita las columnas privadas (otp_*) y deja el paquete listo para la API. */
function paquete_para_api(array $p): array
{
    foreach (PAQUETE_CAMPOS_PRIVADOS as $c) {
        unset($p[$c]);
    }
    if (isset($p['token_seguimiento'])) {
        $p['enlace_seguimiento'] = paquete_enlace_seguimiento((string) $p['token_seguimiento']);
    }
    $p = fechas_fila_iso($p, ['ventana_inicio', 'ventana_fin', 'token_expira_at', 'created_at', 'updated_at']);
    foreach (['lat', 'lng', 'peso_kg', 'volumen_m3', 'cobro_contra_entrega'] as $c) {
        if (array_key_exists($c, $p) && $p[$c] !== null) {
            $p[$c] = (float) $p[$c];
        }
    }
    return $p;
}

/** URL absoluta del enlace de seguimiento (usa M5 si ya existe). */
function paquete_enlace_seguimiento(string $token): string
{
    if (function_exists('tracking_url')) {
        return tracking_url($token);
    }
    return rtrim((string) app_cfg('app.url', ''), '/') . '/t/' . $token;
}

/** Convierte una fecha AAAA-MM-DD de la zona de la interfaz al inicio/fin de ese día en UTC. */
function paquete_fecha_ui_a_utc(string $fecha, bool $finDelDia = false): string
{
    $tz = new DateTimeZone((string) config_get('zona_horaria_ui', 'UTC'));
    $dt = new DateTime($fecha . ($finDelDia ? ' 23:59:59' : ' 00:00:00'), $tz);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** Convierte "a,b,c" (o un arreglo) en una lista filtrada por los valores permitidos. */
function paquete_lista_csv(mixed $valor, array $permitidos): array
{
    if ($valor === null || $valor === '') {
        return [];
    }
    $lista = is_array($valor) ? $valor : explode(',', (string) $valor);
    $lista = array_map(static fn($v) => trim((string) $v), $lista);
    return array_values(array_unique(array_filter($lista, static fn($v) => in_array($v, $permitidos, true))));
}

/**
 * Si el error es una clave duplicada (1062) devuelve el mensaje original de MySQL
 * (db_convertir_error del núcleo lo envuelve en un AppError CONFLICTO), si no null.
 */
function paquete_error_duplicado(Throwable $e): ?string
{
    $previo = $e instanceof AppError ? $e->getPrevious() : $e;
    if ($previo instanceof mysqli_sql_exception && (int) $previo->getCode() === 1062) {
        return $previo->getMessage();
    }
    return null;
}

/** Escapa % _ \ para usarlos dentro de un LIKE. */
function paquete_like(string $texto): string
{
    return '%' . strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/** Acepta el código impreso, el texto GS1 "(91)RP..." o el valor leído "91RP..." y devuelve la guía normal. */
function paquete_normalizar_guia(string $codigo): string
{
    $c = strtoupper(trim($codigo));
    $c = preg_replace('/[^A-Z0-9\-]/', '', $c) ?? '';
    if (preg_match('/^(?:91)?RP(\d{6})([A-Z2-9]{6})$/', $c, $m)) {
        return 'RP-' . $m[1] . '-' . $m[2];
    }
    return $c;
}

/** Genera un código de guía RP-AAMMDD-XXXXXX (la unicidad se asegura al insertar). */
function paquete_generar_guia(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $sufijo = '';
    for ($i = 0; $i < 6; $i++) {
        $sufijo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return 'RP-' . gmdate('ymd') . '-' . $sufijo;
}

/** Token de seguimiento: usa el de M5 si existe; si no, el mismo formato (32 hex). */
function paquete_generar_token(): string
{
    if (function_exists('token_seguimiento_generar')) {
        return token_seguimiento_generar();
    }
    return bin2hex(random_bytes(16));
}

/**
 * Bandera interna: cuando M1 omite una parada, la incidencia NO suma intento
 * y NO debe disparar la notificación "fallida".
 */
function paquete_notificar_fallida_suprimida(?bool $valor = null): bool
{
    static $suprimida = false;
    if ($valor !== null) {
        $suprimida = $valor;
    }
    return $suprimida;
}

/* ====================================================================
 * Consultas básicas
 * ==================================================================== */

function paquete_obtener(int $id): ?array
{
    return db_one('SELECT * FROM paquetes WHERE id = ?', 'i', [$id]);
}

function paquete_obtener_por_guia(string $guia): ?array
{
    return db_one('SELECT * FROM paquetes WHERE codigo_guia = ?', 's', [paquete_normalizar_guia($guia)]);
}

function hub_obtener(int $id): ?array
{
    return db_one('SELECT * FROM hubs WHERE id = ?', 'i', [$id]);
}

/* ====================================================================
 * Validación de datos de un paquete
 * ==================================================================== */

/** Reglas de validar() para crear (todo lo obligatorio) o editar (todo opcional). */
function paquete_reglas(bool $creando): array
{
    $req = $creando ? 'requerido' : 'opcional';
    return [
        'remitente_nombre'      => $req . '|str|max:100',
        'remitente_telefono'    => 'opcional|telefono',
        'destinatario_nombre'   => $req . '|str|max:100',
        'destinatario_telefono' => $req . '|telefono',
        'destinatario_email'    => 'opcional|email|max:150',
        'direccion_entrega'     => $req . '|str|max:255',
        'referencia'            => 'opcional|str|max:255',
        'ciudad'                => 'opcional|str|max:80',
        'codigo_postal'         => 'opcional|str|max:12',
        'lat'                   => 'opcional|lat',
        'lng'                   => 'opcional|lng',
        'peso_kg'               => 'opcional|num|min:0.01|max:1000',
        'volumen_m3'            => 'opcional|num|min:0.0001|max:50',
        'cobro_contra_entrega'  => 'opcional|num|min:0',
        'moneda'                => 'opcional|str|min:3|max:3',
        'ventana_inicio'        => 'opcional|fecha_hora',
        'ventana_fin'           => 'opcional|fecha_hora',
        'prioridad'             => 'opcional|enum:normal,urgente',
        'hub_id'                => 'opcional|int|min:1',
        'notas'                 => 'opcional|str|max:500',
    ];
}

/** Limpia teléfonos (quita espacios, guiones y paréntesis) antes de validar. */
function paquete_limpiar_telefonos(array $datos): array
{
    foreach (['remitente_telefono', 'destinatario_telefono'] as $c) {
        if (isset($datos[$c]) && is_string($datos[$c])) {
            $datos[$c] = preg_replace('/[\s\-\(\)\.]/', '', $datos[$c]);
            if ($datos[$c] === '') {
                $datos[$c] = null;
            }
        }
    }
    // Cadenas vacías = dato ausente
    foreach ($datos as $k => $v) {
        if ($v === '') {
            $datos[$k] = null;
        }
    }
    return $datos;
}

/** Validaciones cruzadas: coordenadas en pareja, ventana horaria, HUB existente. */
function paquete_validar_cruzado(array $d, bool $validarFuturo): void
{
    $errores = [];
    if (($d['lat'] === null) !== ($d['lng'] === null)) {
        $errores['lat'] = 'Envía latitud y longitud juntas, o ninguna.';
    }
    if ($d['ventana_fin'] !== null) {
        if ($d['ventana_inicio'] !== null && $d['ventana_fin'] <= $d['ventana_inicio']) {
            $errores['ventana_fin'] = 'Debe ser posterior al inicio de la ventana.';
        } elseif ($validarFuturo && $d['ventana_fin'] <= fecha_utc_ahora()) {
            $errores['ventana_fin'] = 'Debe ser una fecha y hora futura.';
        }
    }
    if ($d['hub_id'] !== null && !hub_obtener((int) $d['hub_id'])) {
        $errores['hub_id'] = 'El HUB no existe.';
    }
    if ($errores) {
        error_validacion($errores);
    }
}

/** Intenta geocodificar sin romper si M7 aún no está o falla. Retorna ['lat','lng'] o null. */
function paquete_intentar_geocodificar(string $direccion, ?string $ciudad): ?array
{
    if (!function_exists('geocodificar')) {
        return null;
    }
    try {
        $g = geocodificar($direccion, $ciudad);
        if (is_array($g) && isset($g['lat'], $g['lng'])) {
            return ['lat' => (float) $g['lat'], 'lng' => (float) $g['lng']];
        }
    } catch (Throwable $e) {
        log_app('warning', 'geocodificar falló: ' . $e->getMessage());
    }
    return null;
}

/** Intenta resolver la zona sin romper si M1 aún no está o falla. */
function paquete_intentar_zona(?float $lat, ?float $lng, ?string $cp): ?int
{
    if ($lat === null || $lng === null || !function_exists('zona_resolver')) {
        return null;
    }
    try {
        $z = zona_resolver($lat, $lng, $cp);
        return $z !== null ? (int) $z : null;
    } catch (Throwable $e) {
        log_app('warning', 'zona_resolver falló: ' . $e->getMessage());
        return null;
    }
}

/* ====================================================================
 * Crear / editar
 * ==================================================================== */

/**
 * Crea un paquete (guía, geocodificación, zona, token e historial "creado").
 * Idempotente por (integracion_id, origen_externo_id).
 *
 * @param array    $datos         Campos de paquetes/crear.php (+ origen_externo_id opcional).
 * @param int|null $usuarioId     Quien lo crea (null = sistema/webhook).
 * @param int|null $integracionId Integración de origen.
 * @return array Paquete + 'advertencias' (sin_coordenadas, sin_zona) [+ 'duplicado' => true].
 * @throws AppError VALIDACION, ERROR_INTERNO
 */
function paquete_crear(array $datos, ?int $usuarioId, ?int $integracionId = null): array
{
    $datos = paquete_limpiar_telefonos($datos);
    $d = validar($datos, paquete_reglas(true) + ['origen_externo_id' => 'opcional|str|max:80']);
    paquete_validar_cruzado($d, true);

    // Idempotencia
    if ($integracionId !== null && $d['origen_externo_id'] !== null) {
        $existente = db_one(
            'SELECT * FROM paquetes WHERE integracion_id = ? AND origen_externo_id = ?',
            'is',
            [$integracionId, $d['origen_externo_id']]
        );
        if ($existente) {
            $existente['advertencias'] = [];
            $existente['duplicado'] = true;
            return $existente;
        }
    }

    $advertencias = [];
    $geocodificado = 0;
    $lat = $d['lat'] !== null ? (float) $d['lat'] : null;
    $lng = $d['lng'] !== null ? (float) $d['lng'] : null;

    if ($lat === null) {
        $g = paquete_intentar_geocodificar((string) $d['direccion_entrega'], $d['ciudad']);
        if ($g) {
            $lat = $g['lat'];
            $lng = $g['lng'];
            $geocodificado = 1;
        } else {
            $advertencias[] = 'sin_coordenadas';
        }
    }

    $zonaId = paquete_intentar_zona($lat, $lng, $d['codigo_postal']);
    if ($lat !== null && $zonaId === null) {
        $advertencias[] = 'sin_zona';
    }

    $peso = $d['peso_kg'] !== null ? (float) $d['peso_kg'] : 0.5;
    $volumen = $d['volumen_m3'] !== null ? (float) $d['volumen_m3'] : 0.01;
    $cobro = $d['cobro_contra_entrega'] !== null ? (float) $d['cobro_contra_entrega'] : 0.0;
    $moneda = strtoupper($d['moneda'] ?? (string) config_get('moneda_defecto', 'USD'));
    $prioridad = $d['prioridad'] ?? 'normal';
    $maxIntentos = (int) config_get('max_intentos_entrega', 3);

    $paqueteId = 0;
    for ($intento = 1; $intento <= 5; $intento++) {
        $guia = paquete_generar_guia();
        $token = paquete_generar_token();
        try {
            $paqueteId = db_tx(function () use (
                $guia, $token, $integracionId, $d, $lat, $lng, $geocodificado, $zonaId,
                $peso, $volumen, $cobro, $moneda, $prioridad, $maxIntentos, $usuarioId
            ) {
                $id = db_insert(
                    'INSERT INTO paquetes (codigo_guia, integracion_id, origen_externo_id, remitente_nombre, remitente_telefono,
                        destinatario_nombre, destinatario_telefono, destinatario_email, direccion_entrega, referencia, ciudad,
                        codigo_postal, lat, lng, geocodificado, zona_id, hub_id, peso_kg, volumen_m3, cobro_contra_entrega, moneda,
                        ventana_inicio, ventana_fin, prioridad, max_intentos, notas, token_seguimiento, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    // guía s, integración i, externo s, 9 textos (remitente..CP) s, lat d, lng d, geocodificado i,
                    // zona i, hub i, peso d, volumen d, cobro d, moneda s, ventanas ss, prioridad s, max_intentos i,
                    // notas s, token s, creador i
                    'sissssssssssddiiidddssssissi',
                    [
                        $guia, $integracionId, $d['origen_externo_id'], $d['remitente_nombre'], $d['remitente_telefono'],
                        $d['destinatario_nombre'], $d['destinatario_telefono'], $d['destinatario_email'], $d['direccion_entrega'],
                        $d['referencia'], $d['ciudad'], $d['codigo_postal'], $lat, $lng, $geocodificado, $zonaId, $d['hub_id'],
                        $peso, $volumen, $cobro, $moneda, $d['ventana_inicio'], $d['ventana_fin'], $prioridad, $maxIntentos,
                        $d['notas'], $token, $usuarioId,
                    ]
                );
                db_insert(
                    'INSERT INTO historial_estados (paquete_id, estado_anterior, estado_nuevo, usuario_id, motivo) VALUES (?, NULL, ?, ?, NULL)',
                    'isi',
                    [$id, 'creado', $usuarioId]
                );
                return $id;
            });
            break;
        } catch (AppError $ex) {
            $detalle = paquete_error_duplicado($ex);
            if ($detalle === null) {
                throw $ex;
            }
            // Duplicado por origen externo (carrera entre dos webhooks): devolver el existente.
            if (stripos($detalle, 'uq_paquetes_externo') !== false && $integracionId !== null) {
                $existente = db_one(
                    'SELECT * FROM paquetes WHERE integracion_id = ? AND origen_externo_id = ?',
                    'is',
                    [$integracionId, $d['origen_externo_id']]
                );
                if ($existente) {
                    $existente['advertencias'] = [];
                    $existente['duplicado'] = true;
                    return $existente;
                }
            }
            if ($intento === 5) {
                log_app('error', 'No se pudo generar una guía única: ' . $detalle);
                lanzar_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
            }
        }
    }

    audit('paquete_crear', 'paquete', $paqueteId, ['codigo_guia' => $guia, 'advertencias' => $advertencias]);

    $paquete = paquete_obtener($paqueteId);
    $paquete['advertencias'] = $advertencias;
    return $paquete;
}

/**
 * Edita un paquete. Solo en estados creado, ingresado_hub o en_clasificacion.
 * Re-geocodifica si cambió la dirección sin enviar coordenadas; recalcula la zona si cambian
 * las coordenadas o el código postal.
 *
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, VALIDACION
 */
function paquete_actualizar(int $paqueteId, array $datos, ?int $usuarioId): array
{
    $datos = paquete_limpiar_telefonos($datos);
    $d = validar($datos, paquete_reglas(false));
    paquete_validar_cruzado($d, true);

    $p = paquete_obtener($paqueteId);
    if (!$p) {
        error_no_encontrado('El paquete');
    }
    if (!in_array($p['estado'], PAQUETE_ESTADOS_EDITABLES, true)) {
        error_estado('El paquete está «' . paquete_texto_estado($p['estado']) . '» y ya no se puede editar. Quítalo de la ruta o cancélalo.');
    }

    $nuevo = $p;
    $anulables = ['remitente_telefono', 'destinatario_email', 'referencia', 'ciudad', 'codigo_postal', 'ventana_inicio', 'ventana_fin', 'notas', 'hub_id'];
    $simples = ['remitente_nombre', 'remitente_telefono', 'destinatario_nombre', 'destinatario_telefono', 'destinatario_email',
        'direccion_entrega', 'referencia', 'ciudad', 'codigo_postal', 'peso_kg', 'volumen_m3', 'cobro_contra_entrega',
        'ventana_inicio', 'ventana_fin', 'prioridad', 'hub_id', 'notas'];
    foreach ($simples as $c) {
        if (array_key_exists($c, $datos) && ($d[$c] !== null || in_array($c, $anulables, true))) {
            $nuevo[$c] = $d[$c];
        }
    }
    if (array_key_exists('moneda', $datos) && $d['moneda'] !== null) {
        $nuevo['moneda'] = strtoupper($d['moneda']);
    }

    $advertencias = [];
    $coordsEnviadas = $d['lat'] !== null && $d['lng'] !== null;
    $direccionCambio = $nuevo['direccion_entrega'] !== $p['direccion_entrega'] || $nuevo['ciudad'] !== $p['ciudad'];

    if ($coordsEnviadas) {
        $nuevo['lat'] = (float) $d['lat'];
        $nuevo['lng'] = (float) $d['lng'];
        $nuevo['geocodificado'] = 0;
    } elseif ($direccionCambio) {
        $g = paquete_intentar_geocodificar((string) $nuevo['direccion_entrega'], $nuevo['ciudad']);
        if ($g) {
            $nuevo['lat'] = $g['lat'];
            $nuevo['lng'] = $g['lng'];
            $nuevo['geocodificado'] = 1;
        } else {
            $nuevo['lat'] = null;
            $nuevo['lng'] = null;
            $nuevo['geocodificado'] = 0;
        }
    }
    if ($nuevo['lat'] === null) {
        $advertencias[] = 'sin_coordenadas';
    }

    $coordsCambiaron = (string) $nuevo['lat'] !== (string) $p['lat'] || (string) $nuevo['lng'] !== (string) $p['lng'];
    if ($coordsCambiaron || $nuevo['codigo_postal'] !== $p['codigo_postal']) {
        $nuevo['zona_id'] = paquete_intentar_zona(
            $nuevo['lat'] !== null ? (float) $nuevo['lat'] : null,
            $nuevo['lng'] !== null ? (float) $nuevo['lng'] : null,
            $nuevo['codigo_postal']
        );
    }
    if ($nuevo['lat'] !== null && $nuevo['zona_id'] === null) {
        $advertencias[] = 'sin_zona';
    }

    db_exec(
        'UPDATE paquetes SET remitente_nombre = ?, remitente_telefono = ?, destinatario_nombre = ?, destinatario_telefono = ?,
            destinatario_email = ?, direccion_entrega = ?, referencia = ?, ciudad = ?, codigo_postal = ?, lat = ?, lng = ?,
            geocodificado = ?, zona_id = ?, hub_id = ?, peso_kg = ?, volumen_m3 = ?, cobro_contra_entrega = ?, moneda = ?,
            ventana_inicio = ?, ventana_fin = ?, prioridad = ?, notas = ?
         WHERE id = ?',
        'sssssssssddiiidddsssssi',
        [
            $nuevo['remitente_nombre'], $nuevo['remitente_telefono'], $nuevo['destinatario_nombre'], $nuevo['destinatario_telefono'],
            $nuevo['destinatario_email'], $nuevo['direccion_entrega'], $nuevo['referencia'], $nuevo['ciudad'], $nuevo['codigo_postal'],
            $nuevo['lat'], $nuevo['lng'], (int) $nuevo['geocodificado'], $nuevo['zona_id'], $nuevo['hub_id'],
            $nuevo['peso_kg'], $nuevo['volumen_m3'], $nuevo['cobro_contra_entrega'], $nuevo['moneda'],
            $nuevo['ventana_inicio'], $nuevo['ventana_fin'], $nuevo['prioridad'], $nuevo['notas'], $paqueteId,
        ]
    );

    $antes = [];
    $despues = [];
    foreach (array_merge($simples, ['lat', 'lng', 'zona_id', 'moneda']) as $c) {
        if ((string) ($p[$c] ?? '') !== (string) ($nuevo[$c] ?? '')) {
            $antes[$c] = $p[$c] ?? null;
            $despues[$c] = $nuevo[$c] ?? null;
        }
    }
    audit('paquete_actualizar', 'paquete', $paqueteId, ['antes' => $antes, 'despues' => $despues]);

    $resultado = paquete_obtener($paqueteId);
    $resultado['advertencias'] = $advertencias;
    return $resultado;
}

/* ====================================================================
 * Cambio de estado (EL CORAZÓN DEL SISTEMA)
 * ==================================================================== */

/**
 * Única forma de cambiar el estado de un paquete.
 *
 * Valida la transición, actualiza, escribe el historial inmutable, fija la vigencia del token en
 * estados terminales y audita (todo en db_tx). Después del commit dispara las notificaciones y,
 * si pasa a «cancelado», saca la parada de su ruta. Un fallo de esas llamadas no revierte el cambio.
 * Cambiar al mismo estado es idempotente: no duplica historial ni notificaciones.
 *
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, CONFLICTO, VALIDACION
 */
function paquete_cambiar_estado(int $paqueteId, string $estadoNuevo, ?int $usuarioId, ?string $motivo = null, ?float $lat = null, ?float $lng = null): array
{
    if (!in_array($estadoNuevo, PAQUETE_ESTADOS, true)) {
        error_validacion(['estado_nuevo' => 'Estado desconocido.']);
    }

    $resultado = db_tx(function () use ($paqueteId, $estadoNuevo, $usuarioId, $motivo, $lat, $lng) {
        $p = db_one('SELECT * FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);
        if (!$p) {
            error_no_encontrado('El paquete');
        }
        $actual = $p['estado'];

        if ($actual === $estadoNuevo) {
            return ['cambio' => false, 'paquete' => $p, 'desde' => $actual];
        }

        if (!in_array($estadoNuevo, PAQUETE_TRANSICIONES[$actual] ?? [], true)) {
            error_estado('No se puede pasar de «' . paquete_texto_estado($actual) . '» a «' . paquete_texto_estado($estadoNuevo) . '».');
        }
        if ($actual === 'incidencia' && $estadoNuevo === 'ingresado_hub' && (int) $p['intentos'] >= (int) $p['max_intentos']) {
            error_conflicto('Se alcanzó el máximo de intentos de entrega.');
        }

        db_exec('UPDATE paquetes SET estado = ? WHERE id = ?', 'si', [$estadoNuevo, $paqueteId]);
        db_insert(
            'INSERT INTO historial_estados (paquete_id, estado_anterior, estado_nuevo, usuario_id, motivo, lat, lng) VALUES (?,?,?,?,?,?,?)',
            'issisdd',
            [$paqueteId, $actual, $estadoNuevo, $usuarioId, $motivo !== null ? mb_substr($motivo, 0, 255) : null, $lat, $lng]
        );
        if (in_array($estadoNuevo, PAQUETE_ESTADOS_TERMINALES, true)) {
            db_exec(
                'UPDATE paquetes SET token_expira_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY) WHERE id = ?',
                'ii',
                [(int) config_get('token_seguimiento_dias', 30), $paqueteId]
            );
        }
        audit('paquete_cambiar_estado', 'paquete', $paqueteId, ['desde' => $actual, 'hacia' => $estadoNuevo, 'motivo' => $motivo]);

        return ['cambio' => true, 'paquete' => paquete_obtener($paqueteId), 'desde' => $actual];
    });

    if ($resultado['cambio']) {
        paquete_despues_del_cambio($resultado['paquete'], $resultado['desde'], $estadoNuevo, $usuarioId, $motivo);
    }
    return $resultado['paquete'];
}

/** Efectos posteriores al commit (notificaciones y parada). Nunca lanzan error al llamador. */
function paquete_despues_del_cambio(array $p, string $desde, string $hacia, ?int $usuarioId, ?string $motivo): void
{
    $id = (int) $p['id'];
    $vars = [
        'nombre' => $p['destinatario_nombre'],
        'guia'   => $p['codigo_guia'],
        'link'   => paquete_enlace_seguimiento((string) $p['token_seguimiento']),
        'motivo' => $motivo,
    ];

    try {
        if (function_exists('notificar')) {
            if ($hacia === 'en_transito') {
                notificar($id, 'en_camino', $vars, $id . ':en_camino:' . (int) $p['intentos']);
            } elseif ($hacia === 'entregado') {
                notificar($id, 'entregado', $vars, $id . ':entregado');
            } elseif ($hacia === 'incidencia' && !paquete_notificar_fallida_suprimida()) {
                notificar($id, 'fallida', $vars, $id . ':fallida:' . (int) $p['intentos']);
            }
        }
    } catch (Throwable $e) {
        log_app('error', 'Notificación falló para paquete ' . $id . ': ' . $e->getMessage());
    }

    if ($hacia === 'cancelado') {
        try {
            if (function_exists('parada_cancelar_por_paquete')) {
                parada_cancelar_por_paquete($id, $usuarioId);
            }
        } catch (Throwable $e) {
            log_app('error', 'parada_cancelar_por_paquete falló para paquete ' . $id . ': ' . $e->getMessage());
        }
    }
}

/**
 * Cambio manual desde la pantalla: solo se permite cancelar (con motivo) desde los estados previos a la calle.
 *
 * @throws AppError ESTADO_INVALIDO, VALIDACION, NO_ENCONTRADO
 */
function paquete_cambiar_estado_manual(int $paqueteId, string $estadoNuevo, ?string $motivo, ?int $usuarioId): array
{
    $p = paquete_obtener($paqueteId);
    if (!$p) {
        error_no_encontrado('El paquete');
    }
    if ($p['estado'] === $estadoNuevo) {
        return $p;
    }
    if ($estadoNuevo !== 'cancelado' || !in_array($p['estado'], PAQUETE_CANCELABLES_MANUAL, true)) {
        error_estado('No se puede pasar de «' . paquete_texto_estado($p['estado']) . '» a «' . paquete_texto_estado($estadoNuevo) . '» manualmente.');
    }
    if ($motivo === null || trim($motivo) === '') {
        error_validacion(['motivo' => 'El motivo es obligatorio al cancelar.']);
    }
    return paquete_cambiar_estado($paqueteId, 'cancelado', $usuarioId, trim($motivo));
}

/* ====================================================================
 * Escaneo en el HUB
 * ==================================================================== */

/**
 * Ingreso al HUB (creado → ingresado_hub) o clasificación (ingresado_hub → en_clasificacion) por código de guía.
 *
 * @return array Paquete + 'advertencias'
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, VALIDACION
 */
function paquete_escanear(string $codigoGuia, string $accion, ?int $hubId, ?int $usuarioId): array
{
    $p = paquete_obtener_por_guia($codigoGuia);
    if (!$p) {
        error_no_encontrado('La guía «' . mb_substr(trim($codigoGuia), 0, 40) . '»');
    }
    $advertencias = [];

    if ($accion === 'ingreso_hub') {
        if ($hubId === null) {
            error_validacion(['hub_id' => 'Selecciona el HUB de ingreso.']);
        }
        $hub = hub_obtener($hubId);
        if (!$hub || !(int) $hub['activo']) {
            error_validacion(['hub_id' => 'El HUB no existe o está inactivo.']);
        }
        if ($p['estado'] !== 'creado') {
            error_estado('La guía ' . $p['codigo_guia'] . ' está «' . paquete_texto_estado($p['estado']) . '»; para el ingreso debe estar «Creado».');
        }
        $actualizado = db_tx(function () use ($p, $hubId, $usuarioId) {
            db_exec('UPDATE paquetes SET hub_id = ? WHERE id = ?', 'ii', [$hubId, (int) $p['id']]);
            return paquete_cambiar_estado((int) $p['id'], 'ingresado_hub', $usuarioId, 'Ingreso al HUB por escaneo');
        });
    } elseif ($accion === 'clasificar') {
        if ($p['estado'] !== 'ingresado_hub') {
            error_estado('La guía ' . $p['codigo_guia'] . ' está «' . paquete_texto_estado($p['estado']) . '»; para clasificar debe estar «Ingresado en HUB».');
        }
        $actualizado = paquete_cambiar_estado((int) $p['id'], 'en_clasificacion', $usuarioId, 'Clasificado por escaneo');
        if ($actualizado['lat'] === null) {
            $advertencias[] = 'sin_coordenadas';
        }
    } else {
        error_validacion(['accion' => 'Acción de escaneo desconocida.']);
    }

    $actualizado['advertencias'] = $advertencias;
    return $actualizado;
}

/* ====================================================================
 * Listados y detalle
 * ==================================================================== */

/**
 * Lista paginada de paquetes con filtros. Todo parametrizado; el orden sale de una lista blanca.
 *
 * @return array ['filas' => [...], 'meta' => ['page','por_pagina','total','paginas']]
 */
function paquete_listar(array $in): array
{
    $f = validar($in, [
        'hub_id'                 => 'opcional|int|min:1',
        'zona_id'                => 'opcional|int|min:1',
        'prioridad'              => 'opcional|enum:normal,urgente',
        'desde'                  => 'opcional|fecha',
        'hasta'                  => 'opcional|fecha',
        'q'                      => 'opcional|str|max:100',
        'con_incidencia_abierta' => 'opcional|bool',
        'orden'                  => 'opcional|str|max:30',
    ]);
    $estados = paquete_lista_csv($in['estado'] ?? null, PAQUETE_ESTADOS);
    [$page, $porPagina, $offset] = paginacion($in);

    $where = [];
    $tipos = '';
    $params = [];
    if ($estados) {
        $where[] = 'p.estado IN (' . db_in($estados) . ')';
        $tipos .= str_repeat('s', count($estados));
        $params = array_merge($params, $estados);
    }
    if ($f['hub_id'] !== null) {
        $where[] = 'p.hub_id = ?';
        $tipos .= 'i';
        $params[] = (int) $f['hub_id'];
    }
    if ($f['zona_id'] !== null) {
        $where[] = 'p.zona_id = ?';
        $tipos .= 'i';
        $params[] = (int) $f['zona_id'];
    }
    if ($f['prioridad'] !== null) {
        $where[] = 'p.prioridad = ?';
        $tipos .= 's';
        $params[] = $f['prioridad'];
    }
    if ($f['desde'] !== null) {
        $where[] = 'p.created_at >= ?';
        $tipos .= 's';
        $params[] = paquete_fecha_ui_a_utc($f['desde']);
    }
    if ($f['hasta'] !== null) {
        $where[] = 'p.created_at <= ?';
        $tipos .= 's';
        $params[] = paquete_fecha_ui_a_utc($f['hasta'], true);
    }
    if ($f['q'] !== null && trim($f['q']) !== '') {
        $like = paquete_like(trim($f['q']));
        $where[] = '(p.codigo_guia LIKE ? OR p.destinatario_nombre LIKE ? OR p.destinatario_telefono LIKE ? OR p.direccion_entrega LIKE ? OR p.origen_externo_id LIKE ?)';
        $tipos .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($f['con_incidencia_abierta'])) {
        $where[] = "EXISTS (SELECT 1 FROM incidencias i WHERE i.paquete_id = p.id AND i.estado = 'abierta')";
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $orden = 'p.created_at DESC';
    if ($f['orden'] !== null && preg_match('/^(created_at|estado|ventana_fin|prioridad)_(asc|desc)$/', $f['orden'], $m)) {
        $orden = 'p.' . $m[1] . ' ' . strtoupper($m[2]);
    }

    $total = (int) db_val('SELECT COUNT(*) FROM paquetes p ' . $sqlWhere, $tipos, $params);
    $filas = db_all(
        'SELECT p.id, p.codigo_guia, p.destinatario_nombre, p.destinatario_telefono, p.direccion_entrega, p.ciudad, p.lat, p.lng,
                p.zona_id, z.nombre AS zona_nombre, p.hub_id, h.nombre AS hub_nombre, p.prioridad, p.estado, p.intentos,
                p.ventana_inicio, p.ventana_fin, p.peso_kg, p.cobro_contra_entrega, p.moneda, p.token_seguimiento AS token,
                p.created_at,
                EXISTS (SELECT 1 FROM incidencias i WHERE i.paquete_id = p.id AND i.estado = \'abierta\') AS incidencia_abierta
         FROM paquetes p
         LEFT JOIN zonas z ON z.id = p.zona_id
         LEFT JOIN hubs h ON h.id = p.hub_id ' . $sqlWhere . '
         ORDER BY ' . $orden . ', p.id DESC
         LIMIT ? OFFSET ?',
        $tipos . 'ii',
        array_merge($params, [$porPagina, $offset])
    );
    foreach ($filas as &$fila) {
        $fila['incidencia_abierta'] = (bool) $fila['incidencia_abierta'];
        $fila = fechas_fila_iso($fila, ['ventana_inicio', 'ventana_fin', 'created_at']);
    }
    unset($fila);

    return ['filas' => $filas, 'meta' => ['page' => $page, 'por_pagina' => $porPagina, 'total' => $total, 'paginas' => (int) ceil($total / max(1, $porPagina))]];
}

/** Historial cronológico (cadena de custodia) con el nombre de quien hizo el cambio. */
function paquete_historial(int $paqueteId): array
{
    $filas = db_all(
        'SELECT h.id, h.estado_anterior, h.estado_nuevo, h.usuario_id, COALESCE(u.nombre, \'Sistema\') AS usuario_nombre,
                h.motivo, h.lat, h.lng, h.created_at
         FROM historial_estados h
         LEFT JOIN usuarios u ON u.id = h.usuario_id
         WHERE h.paquete_id = ?
         ORDER BY h.created_at ASC, h.id ASC',
        'i',
        [$paqueteId]
    );
    foreach ($filas as &$f) {
        $f = fechas_fila_iso($f, ['created_at']);
    }
    unset($f);
    return $filas;
}

/** Paquete completo + historial + incidencias + parada actual + entregas + enlace de seguimiento. */
function paquete_ver_completo(?int $id, ?string $guia): array
{
    $p = $id !== null ? paquete_obtener($id) : ($guia !== null ? paquete_obtener_por_guia($guia) : null);
    if (!$p) {
        error_no_encontrado('El paquete');
    }
    $pid = (int) $p['id'];

    $p['zona_nombre'] = $p['zona_id'] ? db_val('SELECT nombre FROM zonas WHERE id = ?', 'i', [(int) $p['zona_id']]) : null;
    $p['hub_nombre'] = $p['hub_id'] ? db_val('SELECT nombre FROM hubs WHERE id = ?', 'i', [(int) $p['hub_id']]) : null;

    $incidencias = db_all(
        'SELECT i.id, i.tipo, i.descripcion, i.estado, i.reprogramada_para, i.created_at, i.resuelta_at, i.parada_id
         FROM incidencias i WHERE i.paquete_id = ? ORDER BY i.created_at DESC, i.id DESC',
        'i',
        [$pid]
    );
    foreach ($incidencias as &$i) {
        $i['tipo_texto'] = INCIDENCIA_TIPOS[$i['tipo']] ?? $i['tipo'];
        $i = fechas_fila_iso($i, ['created_at', 'resuelta_at']);
    }
    unset($i);

    $parada = db_one(
        'SELECT r.id AS ruta_id, r.codigo AS ruta_codigo, pa.id AS parada_id, pa.orden, pa.estado, pa.eta
         FROM paradas pa JOIN rutas r ON r.id = pa.ruta_id
         WHERE pa.paquete_id = ? ORDER BY pa.id DESC LIMIT 1',
        'i',
        [$pid]
    );
    if ($parada) {
        $parada = fechas_fila_iso($parada, ['eta']);
    }

    $entregas = db_all(
        'SELECT id, resultado, motivo_fallo, receptor_nombre, ocurrido_at FROM entregas WHERE paquete_id = ? ORDER BY ocurrido_at ASC',
        'i',
        [$pid]
    );
    foreach ($entregas as &$e) {
        $e = fechas_fila_iso($e, ['ocurrido_at']);
    }
    unset($e);

    $p['historial'] = paquete_historial($pid);
    $p['incidencias'] = $incidencias;
    $p['parada_actual'] = $parada;
    $p['entregas'] = $entregas;
    return paquete_para_api($p);
}

/**
 * Datos para imprimir las guías (el dibujo del QR y del código de barras lo hace el navegador).
 *
 * @param int[] $ids Máximo 100.
 */
function paquete_etiquetas(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$ids) {
        error_validacion(['ids' => 'Indica al menos un paquete.']);
    }
    if (count($ids) > 100) {
        error_validacion(['ids' => 'Máximo 100 etiquetas por impresión.']);
    }
    $filas = db_all(
        'SELECT p.id, p.codigo_guia, p.destinatario_nombre, p.destinatario_telefono, p.direccion_entrega, p.referencia, p.ciudad,
                p.codigo_postal, z.nombre AS zona_nombre, p.prioridad, p.peso_kg, p.cobro_contra_entrega, p.moneda, p.created_at
         FROM paquetes p LEFT JOIN zonas z ON z.id = p.zona_id
         WHERE p.id IN (' . db_in($ids) . ') ORDER BY p.id',
        str_repeat('i', count($ids)),
        $ids
    );
    $salida = [];
    foreach ($filas as $f) {
        $valor = str_replace('-', '', $f['codigo_guia']);
        $f['qr_contenido'] = $f['codigo_guia'];
        $f['gs1_128'] = ['ai' => '91', 'valor' => $valor, 'texto_legible' => '(91)' . $valor];
        $f['peso_kg'] = (float) $f['peso_kg'];
        $f['cobro_contra_entrega'] = (float) $f['cobro_contra_entrega'];
        $salida[] = fechas_fila_iso($f, ['created_at']);
    }
    return $salida;
}

/* ====================================================================
 * Paquetes para M1
 * ==================================================================== */

/**
 * Paquetes candidatos a una ruta: en_clasificacion, con coordenadas, fecha_programada nula o <= fecha,
 * del HUB indicado y sin ruta activa (borrador, planificada, en_curso).
 *
 * @param string     $fecha      AAAA-MM-DD
 * @param int[]|null $paqueteIds Si se envía, limita a esos paquetes.
 */
function paquetes_para_ruta(int $hubId, ?int $zonaId, string $fecha, ?array $paqueteIds = null): array
{
    $sql = "SELECT p.id, p.codigo_guia, p.lat, p.lng, p.peso_kg, p.volumen_m3, p.prioridad, p.ventana_inicio, p.ventana_fin,
                   p.zona_id, p.destinatario_nombre, p.direccion_entrega
            FROM paquetes p
            WHERE p.estado = 'en_clasificacion' AND p.hub_id = ? AND p.lat IS NOT NULL AND p.lng IS NOT NULL
              AND (p.fecha_programada IS NULL OR p.fecha_programada <= ?)
              AND NOT EXISTS (SELECT 1 FROM paradas pa JOIN rutas r ON r.id = pa.ruta_id
                              WHERE pa.paquete_id = p.id AND r.estado IN ('borrador','planificada','en_curso'))";
    $tipos = 'is';
    $params = [$hubId, $fecha];
    if ($zonaId !== null) {
        $sql .= ' AND p.zona_id = ?';
        $tipos .= 'i';
        $params[] = $zonaId;
    }
    if ($paqueteIds !== null) {
        $paqueteIds = array_values(array_unique(array_map('intval', $paqueteIds)));
        if (!$paqueteIds) {
            return [];
        }
        $sql .= ' AND p.id IN (' . db_in($paqueteIds) . ')';
        $tipos .= str_repeat('i', count($paqueteIds));
        $params = array_merge($params, $paqueteIds);
    }
    $sql .= ' ORDER BY p.id';
    $filas = db_all($sql, $tipos, $params);
    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['lat'] = (float) $f['lat'];
        $f['lng'] = (float) $f['lng'];
        $f['peso_kg'] = (float) $f['peso_kg'];
        $f['volumen_m3'] = (float) $f['volumen_m3'];
        $f['zona_id'] = $f['zona_id'] !== null ? (int) $f['zona_id'] : null;
    }
    unset($f);
    return $filas;
}

/* ====================================================================
 * Incidencias
 * ==================================================================== */

/**
 * Registra una incidencia y pasa el paquete en_transito → incidencia.
 * Con $cuentaIntento = false (parada omitida por despacho) no suma intento ni dispara la notificación "fallida".
 *
 * @return int incidencia_id
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, VALIDACION
 */
function incidencia_crear(int $paqueteId, ?int $paradaId, string $tipo, ?string $descripcion, ?int $fotoArchivoId, ?int $usuarioId, bool $cuentaIntento = true): int
{
    if (!isset(INCIDENCIA_TIPOS[$tipo])) {
        error_validacion(['tipo' => 'Tipo de incidencia no válido.']);
    }

    $suprimia = paquete_notificar_fallida_suprimida();
    if (!$cuentaIntento) {
        paquete_notificar_fallida_suprimida(true);
    }
    try {
        $incidenciaId = db_tx(function () use ($paqueteId, $paradaId, $tipo, $descripcion, $fotoArchivoId, $usuarioId, $cuentaIntento) {
            $p = db_one('SELECT id, estado FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);
            if (!$p) {
                error_no_encontrado('El paquete');
            }
            if ($p['estado'] !== 'en_transito') {
                error_estado('Solo se pueden registrar incidencias de paquetes «En tránsito».');
            }
            $id = db_insert(
                'INSERT INTO incidencias (paquete_id, parada_id, tipo, descripcion, foto_archivo_id, creada_por) VALUES (?,?,?,?,?,?)',
                'iissii',
                [$paqueteId, $paradaId, $tipo, $descripcion !== null ? mb_substr($descripcion, 0, 500) : null, $fotoArchivoId, $usuarioId]
            );
            if ($cuentaIntento) {
                db_exec('UPDATE paquetes SET intentos = intentos + 1 WHERE id = ?', 'i', [$paqueteId]);
            }
            paquete_cambiar_estado($paqueteId, 'incidencia', $usuarioId, INCIDENCIA_TIPOS[$tipo]);
            audit('incidencia_crear', 'incidencia', $id, ['paquete_id' => $paqueteId, 'tipo' => $tipo, 'cuenta_intento' => $cuentaIntento]);
            return $id;
        });
    } finally {
        paquete_notificar_fallida_suprimida($suprimia);
    }
    return $incidenciaId;
}

/**
 * Resuelve una incidencia abierta: reprogramar, devolver o cancelar.
 *
 * @param string|null $fecha   AAAA-MM-DD (obligatoria al reprogramar; hoy o futura).
 * @param array|null  $ventana ['inicio' => 'Y-m-d H:i:s', 'fin' => 'Y-m-d H:i:s'] en UTC.
 * @param int|null    $usuarioId null cuando la resuelve el cliente desde el portal.
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, CONFLICTO, VALIDACION
 */
function incidencia_resolver(int $incidenciaId, string $accion, ?string $fecha, ?int $usuarioId, ?array $ventana = null): array
{
    if (!in_array($accion, ['reprogramar', 'devolver', 'cancelar'], true)) {
        error_validacion(['accion' => 'Acción no válida.']);
    }

    $incidencia = db_tx(function () use ($incidenciaId, $accion, $fecha, $usuarioId, $ventana) {
        $inc = db_one('SELECT * FROM incidencias WHERE id = ? FOR UPDATE', 'i', [$incidenciaId]);
        if (!$inc) {
            error_no_encontrado('La incidencia');
        }
        if ($inc['estado'] !== 'abierta') {
            error_estado('La incidencia ya fue resuelta.');
        }
        $paqueteId = (int) $inc['paquete_id'];
        $p = db_one('SELECT * FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);

        $estadoIncidencia = 'cancelada';
        $reprogramadaPara = null;

        if ($accion === 'reprogramar') {
            if ((int) $p['intentos'] >= (int) $p['max_intentos']) {
                error_conflicto('Se alcanzó el máximo de intentos');
            }
            if ($fecha === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                error_validacion(['fecha' => 'Indica la nueva fecha de entrega.']);
            }
            if ($fecha < gmdate('Y-m-d')) {
                error_validacion(['fecha' => 'La fecha debe ser hoy o posterior.']);
            }
            $vInicio = $ventana['inicio'] ?? null;
            $vFin = $ventana['fin'] ?? null;
            if ($vInicio !== null && $vFin !== null && $vFin <= $vInicio) {
                error_validacion(['ventana_fin' => 'Debe ser posterior al inicio de la ventana.']);
            }
            paquete_cambiar_estado($paqueteId, 'ingresado_hub', $usuarioId, 'Reprogramado para ' . $fecha);
            db_exec('UPDATE paquetes SET fecha_programada = ? WHERE id = ?', 'si', [$fecha, $paqueteId]);
            if ($vInicio !== null || $vFin !== null) {
                db_exec('UPDATE paquetes SET ventana_inicio = ?, ventana_fin = ? WHERE id = ?', 'ssi', [$vInicio, $vFin, $paqueteId]);
            }
            $estadoIncidencia = 'reprogramada';
            $reprogramadaPara = $fecha;
        } elseif ($accion === 'devolver') {
            paquete_cambiar_estado($paqueteId, 'devuelto', $usuarioId, 'Devuelto al remitente');
            $estadoIncidencia = 'devuelta';
        } else {
            paquete_cambiar_estado($paqueteId, 'cancelado', $usuarioId, 'Cancelado desde la incidencia');
        }

        db_exec(
            'UPDATE incidencias SET estado = ?, reprogramada_para = ?, resuelta_por = ?, resuelta_at = UTC_TIMESTAMP() WHERE id = ?',
            'ssii',
            [$estadoIncidencia, $reprogramadaPara, $usuarioId, $incidenciaId]
        );
        audit('incidencia_resolver', 'incidencia', $incidenciaId, ['accion' => $accion, 'paquete_id' => $paqueteId, 'fecha' => $reprogramadaPara]);
        return db_one('SELECT * FROM incidencias WHERE id = ?', 'i', [$incidenciaId]);
    });

    $incidencia['tipo_texto'] = INCIDENCIA_TIPOS[$incidencia['tipo']] ?? $incidencia['tipo'];
    return fechas_fila_iso($incidencia, ['created_at', 'resuelta_at']);
}

/** Lista paginada de incidencias. @return array ['filas' => [...], 'meta' => [...]] */
function incidencia_listar(array $in): array
{
    $f = validar($in, [
        'estado'     => 'opcional|enum:abierta,reprogramada,devuelta,cancelada',
        'tipo'       => 'opcional|enum:' . implode(',', array_keys(INCIDENCIA_TIPOS)),
        'desde'      => 'opcional|fecha',
        'hasta'      => 'opcional|fecha',
        'paquete_id' => 'opcional|int|min:1',
    ]);
    [$page, $porPagina, $offset] = paginacion($in);

    $where = [];
    $tipos = '';
    $params = [];
    foreach (['estado' => 'i.estado', 'tipo' => 'i.tipo'] as $campo => $col) {
        if ($f[$campo] !== null) {
            $where[] = $col . ' = ?';
            $tipos .= 's';
            $params[] = $f[$campo];
        }
    }
    if ($f['paquete_id'] !== null) {
        $where[] = 'i.paquete_id = ?';
        $tipos .= 'i';
        $params[] = (int) $f['paquete_id'];
    }
    if ($f['desde'] !== null) {
        $where[] = 'i.created_at >= ?';
        $tipos .= 's';
        $params[] = paquete_fecha_ui_a_utc($f['desde']);
    }
    if ($f['hasta'] !== null) {
        $where[] = 'i.created_at <= ?';
        $tipos .= 's';
        $params[] = paquete_fecha_ui_a_utc($f['hasta'], true);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = (int) db_val('SELECT COUNT(*) FROM incidencias i ' . $sqlWhere, $tipos, $params);
    $filas = db_all(
        'SELECT i.id, i.paquete_id, p.codigo_guia, p.destinatario_nombre, p.direccion_entrega, p.intentos, p.max_intentos,
                p.estado AS estado_paquete, i.parada_id, i.tipo, i.descripcion, i.estado, i.reprogramada_para, i.created_at, i.resuelta_at
         FROM incidencias i JOIN paquetes p ON p.id = i.paquete_id ' . $sqlWhere . '
         ORDER BY i.created_at DESC, i.id DESC LIMIT ? OFFSET ?',
        $tipos . 'ii',
        array_merge($params, [$porPagina, $offset])
    );
    foreach ($filas as &$fila) {
        $fila['tipo_texto'] = INCIDENCIA_TIPOS[$fila['tipo']] ?? $fila['tipo'];
        $fila = fechas_fila_iso($fila, ['created_at', 'resuelta_at']);
    }
    unset($fila);

    return ['filas' => $filas, 'meta' => ['page' => $page, 'por_pagina' => $porPagina, 'total' => $total, 'paginas' => (int) ceil($total / max(1, $porPagina))]];
}

/* ====================================================================
 * HUBs
 * ==================================================================== */

/** Lista de HUBs (con cantidad de paquetes en estados no terminales). */
function hub_listar(?bool $soloActivos = null): array
{
    $sql = "SELECT h.id, h.nombre, h.direccion, h.lat, h.lng, h.activo, h.created_at,
                   (SELECT COUNT(*) FROM paquetes p WHERE p.hub_id = h.id AND p.estado NOT IN ('entregado','cancelado','devuelto')) AS paquetes_activos
            FROM hubs h";
    $params = [];
    if ($soloActivos === true) {
        $sql .= ' WHERE h.activo = 1';
    }
    $sql .= ' ORDER BY h.id';
    $filas = db_all($sql, '', $params);
    foreach ($filas as &$f) {
        $f['lat'] = (float) $f['lat'];
        $f['lng'] = (float) $f['lng'];
        $f['activo'] = (bool) $f['activo'];
        $f['paquetes_activos'] = (int) $f['paquetes_activos'];
        $f = fechas_fila_iso($f, ['created_at']);
    }
    unset($f);
    return $filas;
}

function hub_crear(array $datos, ?int $usuarioId): array
{
    $d = validar($datos, [
        'nombre'    => 'requerido|str|max:100',
        'direccion' => 'opcional|str|max:255',
        'lat'       => 'requerido|lat',
        'lng'       => 'requerido|lng',
        'activo'    => 'opcional|bool',
    ]);
    $id = db_insert(
        'INSERT INTO hubs (nombre, direccion, lat, lng, activo) VALUES (?,?,?,?,?)',
        'ssddi',
        [$d['nombre'], $d['direccion'], (float) $d['lat'], (float) $d['lng'], $d['activo'] === null ? 1 : (int) (bool) $d['activo']]
    );
    audit('hub_crear', 'hub', $id, ['nombre' => $d['nombre']]);
    return hub_obtener($id);
}

function hub_actualizar(int $hubId, array $datos, ?int $usuarioId): array
{
    $d = validar($datos, [
        'nombre'    => 'opcional|str|max:100',
        'direccion' => 'opcional|str|max:255',
        'lat'       => 'opcional|lat',
        'lng'       => 'opcional|lng',
        'activo'    => 'opcional|bool',
    ]);
    $hub = hub_obtener($hubId);
    if (!$hub) {
        error_no_encontrado('El HUB');
    }
    $nuevo = $hub;
    foreach (['nombre', 'lat', 'lng'] as $c) {
        if ($d[$c] !== null) {
            $nuevo[$c] = $d[$c];
        }
    }
    if (array_key_exists('direccion', $datos)) {
        $nuevo['direccion'] = $d['direccion'];
    }
    if ($d['activo'] !== null) {
        $nuevo['activo'] = (int) (bool) $d['activo'];
        if ((int) $hub['activo'] === 1 && $nuevo['activo'] === 0) {
            $abiertos = (int) db_val(
                "SELECT COUNT(*) FROM paquetes WHERE hub_id = ? AND estado NOT IN ('entregado','cancelado','devuelto')",
                'i',
                [$hubId]
            );
            if ($abiertos > 0) {
                error_conflicto('No se puede desactivar el HUB: tiene ' . $abiertos . ' paquete(s) sin cerrar.');
            }
        }
    }
    db_exec(
        'UPDATE hubs SET nombre = ?, direccion = ?, lat = ?, lng = ?, activo = ? WHERE id = ?',
        'ssddii',
        [$nuevo['nombre'], $nuevo['direccion'], (float) $nuevo['lat'], (float) $nuevo['lng'], (int) $nuevo['activo'], $hubId]
    );
    audit('hub_actualizar', 'hub', $hubId, ['antes' => $hub, 'despues' => $nuevo]);
    return hub_obtener($hubId);
}

/* ====================================================================
 * Utilidad para el formulario "Ubicar en mapa"
 * ==================================================================== */

/**
 * Geocodifica una dirección y resuelve su zona SIN crear nada (la usa paquete_nuevo.php).
 *
 * @return array ['lat','lng','geocodificado' => bool,'zona_id','zona_nombre']  (lat/lng null si no se pudo ubicar)
 */
function paquete_ubicar(string $direccion, ?string $ciudad, ?string $codigoPostal): array
{
    $g = paquete_intentar_geocodificar($direccion, $ciudad);
    $zonaId = $g ? paquete_intentar_zona($g['lat'], $g['lng'], $codigoPostal) : null;
    return [
        'lat'           => $g['lat'] ?? null,
        'lng'           => $g['lng'] ?? null,
        'geocodificado' => $g !== null,
        'zona_id'       => $zonaId,
        'zona_nombre'   => $zonaId !== null ? db_val('SELECT nombre FROM zonas WHERE id = ?', 'i', [$zonaId]) : null,
    ];
}
