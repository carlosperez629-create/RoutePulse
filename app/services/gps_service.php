<?php
/**
 * gps_service.php - M2 Rastreo GPS y telemetría.
 * Contrato: 00-base/07-servicios-internos.md, sección E.
 *
 * Estado de las funciones:
 *   gps_registrar_puntos      COMPLETA (T3)
 *   gps_ultima_posicion       básica pero real (la usan M1 y M5; se refina en T6)
 *   eta_de_parada             real (lee paradas.eta)
 *   geocerca_evaluar          COMPLETA (T5)
 *   eta_recalcular_ruta       COMPLETA (T5)
 *   gps_distancia_recorrida_km COMPLETA (T6)
 *
 * Formatos que fija este archivo (los reutiliza T6 al verificar la cadena de hashes):
 *   - capturado_at se guarda y se hashea como 'Y-m-d H:i:s.v' (UTC, milisegundos), igual que la columna DATETIME(3).
 *   - lat y lng se guardan y se hashean como texto con 7 decimales ('4.6331000'), igual que DECIMAL(10,7).
 */

const GPS_HASH_CERO = '0000000000000000000000000000000000000000000000000000000000000000';
const GPS_MAX_PUNTOS_POR_LLAMADA = 200;
const GPS_FUTURO_MAX_S = 60;
const GPS_PASADO_MAX_S = 86400;   // 24 h

/**
 * Hash de integridad de un punto: sha256(hash_anterior | ruta_id | lat | lng | capturado_at).
 *
 * @param string $hashAnterior Hash del punto anterior de la ruta (GPS_HASH_CERO para el primero).
 * @param string $lat          Latitud con 7 decimales, como queda en la BD.
 * @param string $lng          Longitud con 7 decimales, como queda en la BD.
 * @param string $capturadoAt  'Y-m-d H:i:s.v' en UTC.
 */
function gps_hash_punto(string $hashAnterior, int $rutaId, string $lat, string $lng, string $capturadoAt): string
{
    return hash('sha256', $hashAnterior . '|' . $rutaId . '|' . $lat . '|' . $lng . '|' . $capturadoAt);
}

/**
 * Valida UN punto del lote. No usa validar() porque ese rechaza el lote completo y
 * aquí cada punto se acepta o rechaza por separado; además validar('fecha_hora') pierde los milisegundos.
 *
 * @return array{0: ?array, 1: ?string} [punto normalizado, null] o [null, motivo del rechazo]
 */
function gps_validar_punto($p, float $ahora): array
{
    if (!is_array($p)) {
        return [null, 'el punto debe ser un objeto'];
    }

    $lat = $p['lat'] ?? null;
    if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
        return [null, 'lat fuera de rango'];
    }
    $lng = $p['lng'] ?? null;
    if (!is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
        return [null, 'lng fuera de rango'];
    }

    // Campos opcionales: si vienen deben ser válidos; si faltan o son null se guardan como NULL.
    $opcionales = [
        'velocidad_kmh' => [0, 500, 1],
        'precision_m'   => [0, 99999, 1],
        'rumbo'         => [0, 360, 0],
        'bateria_pct'   => [0, 100, 0],
    ];
    $salida = [];
    foreach ($opcionales as $campo => [$min, $max, $decimales]) {
        $valor = $p[$campo] ?? null;
        if ($valor === null || $valor === '') {
            $salida[$campo] = null;
            continue;
        }
        if (!is_numeric($valor) || (float) $valor < $min || (float) $valor > $max) {
            return [null, $campo . ' fuera de rango'];
        }
        $salida[$campo] = round((float) $valor, $decimales);
    }
    if ($salida['rumbo'] !== null && $salida['rumbo'] >= 360) {
        $salida['rumbo'] = 0.0;   // 360° es el mismo rumbo que 0°
    }

    $texto = $p['capturado_at'] ?? null;
    if (!is_string($texto) || $texto === '' || strlen($texto) > 40) {
        return [null, 'capturado_at inválido'];
    }
    try {
        $fecha = new DateTimeImmutable($texto);
    } catch (Throwable $e) {
        return [null, 'capturado_at inválido'];
    }
    $fecha = $fecha->setTimezone(new DateTimeZone('UTC'));
    $marca = (float) $fecha->format('U.u');
    if ($marca > $ahora + GPS_FUTURO_MAX_S || $marca < $ahora - GPS_PASADO_MAX_S) {
        return [null, 'capturado_at fuera de rango'];
    }

    return [[
        'lat'           => number_format((float) $lat, 7, '.', ''),
        'lng'           => number_format((float) $lng, 7, '.', ''),
        'velocidad_kmh' => $salida['velocidad_kmh'],
        'precision_m'   => $salida['precision_m'],
        'rumbo'         => $salida['rumbo'] === null ? null : (int) $salida['rumbo'],
        'bateria_pct'   => $salida['bateria_pct'] === null ? null : (int) $salida['bateria_pct'],
        'capturado_at'  => $fecha->format('Y-m-d H:i:s.v'),
        'marca'         => $marca,
    ], null];
}

/**
 * Registra un lote de puntos GPS de una ruta en curso.
 *
 * - Valida cada punto por separado; los inválidos se informan en 'rechazados' y no frenan a los demás.
 * - Ignora duplicados (misma ruta y mismo capturado_at), sin error y sin contarlos como aceptados.
 * - Inserta ordenado por capturado_at con la cadena de hashes (fila de la ruta bloqueada con FOR UPDATE).
 * - Puntos con precisión peor que gps_precision_max_m se guardan, pero no mueven ultima_posicion,
 *   ni la geocerca, ni el ETA.
 * - Después de confirmar la transacción recalcula el ETA y evalúa la geocerca con el punto más reciente.
 *
 * @param int   $rutaId      Ruta a la que pertenecen los puntos.
 * @param int   $conductorId Siempre el de la sesión (auth_conductor_id()), nunca del cliente.
 * @param array $puntos      Lista de puntos {lat,lng,velocidad_kmh,precision_m,rumbo,bateria_pct,capturado_at}.
 * @return array{aceptados:int, rechazados:array, eventos:array}
 * @throws AppError NO_ENCONTRADO, SIN_PERMISO, ESTADO_INVALIDO o VALIDACION (más de 200 puntos).
 */
function gps_registrar_puntos(int $rutaId, int $conductorId, array $puntos): array
{
    $puntos = array_values($puntos);
    if (count($puntos) < 1 || count($puntos) > GPS_MAX_PUNTOS_POR_LLAMADA) {
        error_validacion(['puntos' => 'Envía entre 1 y ' . GPS_MAX_PUNTOS_POR_LLAMADA . ' puntos por llamada.']);
    }

    $ahora = microtime(true);
    $precisionMax = (float) config_get('gps_precision_max_m', 200);
    $sinSenalS = (int) config_get('gps_sin_senal_s', 300);

    // 1) Validación punto por punto.
    $rechazados = [];
    $validos = [];
    foreach ($puntos as $indice => $crudo) {
        [$punto, $motivo] = gps_validar_punto($crudo, $ahora);
        if ($punto === null) {
            $rechazados[] = ['indice' => $indice, 'motivo' => $motivo];
            continue;
        }
        $punto['indice'] = $indice;
        $validos[] = $punto;
    }

    // Orden cronológico (el texto 'Y-m-d H:i:s.v' ordena igual que el tiempo); empate: orden de llegada.
    usort($validos, static function (array $a, array $b): int {
        return [$a['capturado_at'], $a['indice']] <=> [$b['capturado_at'], $b['indice']];
    });

    // 2) Inserción dentro de una transacción con la ruta bloqueada.
    $insertados = db_tx(static function () use ($rutaId, $conductorId, $validos, $precisionMax): array {
        $ruta = db_one('SELECT id, conductor_id, estado FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if ($ruta === null) {
            error_no_encontrado('La ruta');
        }
        if ((int) $ruta['conductor_id'] !== $conductorId) {
            error_sin_permiso();
        }
        if ($ruta['estado'] !== 'en_curso') {
            error_estado('La ruta no está en curso.');
        }
        if ($validos === []) {
            return [];
        }

        // Duplicados contra lo ya guardado (puntos_gps no tiene clave única en ruta+capturado_at).
        $desde = $validos[0]['capturado_at'];
        $hasta = $validos[count($validos) - 1]['capturado_at'];
        $existentes = [];
        foreach (db_all(
            'SELECT capturado_at FROM puntos_gps WHERE ruta_id = ? AND capturado_at BETWEEN ? AND ?',
            'iss',
            [$rutaId, $desde, $hasta]
        ) as $fila) {
            $existentes[(string) $fila['capturado_at']] = true;
        }

        $nuevos = [];
        foreach ($validos as $punto) {
            if (isset($existentes[$punto['capturado_at']])) {
                continue;   // duplicado: se ignora sin error
            }
            $existentes[$punto['capturado_at']] = true;   // también cubre duplicados dentro del mismo lote
            $nuevos[] = $punto;
        }
        if ($nuevos === []) {
            return [];
        }

        // Cadena de hashes: parte del último hash de la ruta (o 64 ceros si es el primero).
        $hashPrevio = db_val(
            'SELECT hash_registro FROM puntos_gps WHERE ruta_id = ? ORDER BY id DESC LIMIT 1',
            'i',
            [$rutaId]
        );
        $hash = is_string($hashPrevio) && $hashPrevio !== '' ? $hashPrevio : GPS_HASH_CERO;

        $filasSql = [];
        $tipos = '';
        $params = [];
        foreach ($nuevos as $i => $punto) {
            $hash = gps_hash_punto($hash, $rutaId, $punto['lat'], $punto['lng'], $punto['capturado_at']);
            $nuevos[$i]['hash'] = $hash;
            $filasSql[] = '(?,?,?,?,?,?,?,?,?,?)';
            $tipos .= 'iissddiiss';
            array_push(
                $params,
                $rutaId,
                $conductorId,
                $punto['lat'],
                $punto['lng'],
                $punto['velocidad_kmh'],
                $punto['precision_m'],
                $punto['rumbo'],
                $punto['bateria_pct'],
                $punto['capturado_at'],
                $hash
            );
        }
        // Un solo INSERT multifila: los ids salen en el mismo orden que la cadena de hashes.
        db_exec(
            'INSERT INTO puntos_gps (ruta_id, conductor_id, lat, lng, velocidad_kmh, precision_m, rumbo, bateria_pct, capturado_at, hash_registro) VALUES '
            . implode(',', $filasSql),
            $tipos,
            $params
        );

        // ultima_posicion: el punto usable más reciente, sin pisar uno más nuevo ya guardado
        // (por ejemplo, al reenviar puntos viejos acumulados sin conexión).
        $usable = null;
        foreach ($nuevos as $punto) {
            if ($punto['precision_m'] === null || $punto['precision_m'] <= $precisionMax) {
                $usable = $punto;   // al estar ordenados, queda el más reciente
            }
        }
        if ($usable !== null) {
            // capturado_at se actualiza al final: MySQL evalúa las asignaciones de izquierda a derecha.
            db_exec(
                'INSERT INTO ultima_posicion (conductor_id, ruta_id, lat, lng, velocidad_kmh, rumbo, bateria_pct, capturado_at)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   ruta_id       = IF(VALUES(capturado_at) >= capturado_at, VALUES(ruta_id), ruta_id),
                   lat           = IF(VALUES(capturado_at) >= capturado_at, VALUES(lat), lat),
                   lng           = IF(VALUES(capturado_at) >= capturado_at, VALUES(lng), lng),
                   velocidad_kmh = IF(VALUES(capturado_at) >= capturado_at, VALUES(velocidad_kmh), velocidad_kmh),
                   rumbo         = IF(VALUES(capturado_at) >= capturado_at, VALUES(rumbo), rumbo),
                   bateria_pct   = IF(VALUES(capturado_at) >= capturado_at, VALUES(bateria_pct), bateria_pct),
                   capturado_at  = IF(VALUES(capturado_at) >= capturado_at, VALUES(capturado_at), capturado_at)',
                'iissdiis',
                [
                    $conductorId,
                    $rutaId,
                    $usable['lat'],
                    $usable['lng'],
                    $usable['velocidad_kmh'],
                    $usable['rumbo'],
                    $usable['bateria_pct'],
                    $usable['capturado_at'],
                ]
            );
        }
        return $nuevos;
    });

    // 3) Fuera de la transacción: ETA y geocerca (pueden tardar o fallar sin deshacer los puntos).
    $eventos = [];
    $ultimo = null;
    foreach ($insertados as $punto) {
        if ($punto['precision_m'] === null || $punto['precision_m'] <= $precisionMax) {
            $ultimo = $punto;
        }
    }
    if ($ultimo !== null) {
        $vigente = db_val('SELECT capturado_at FROM ultima_posicion WHERE conductor_id = ?', 'i', [$conductorId]);
        $esLaUltima = $vigente !== null && (string) $vigente === $ultimo['capturado_at'];
        // Un punto de hace más de gps_sin_senal_s es historia (reenvío tras un corte): no debe
        // disparar avisos de "tu repartidor está cerca".
        $esReciente = ($ahora - $ultimo['marca']) <= $sinSenalS;

        if ($esLaUltima && $esReciente) {
            // Primero el ETA: la geocerca usa "ETA <= proximidad_eta_min" y lee paradas.eta.
            try {
                eta_recalcular_ruta($rutaId);
            } catch (Throwable $e) {
                log_app('error', 'eta_recalcular_ruta falló', ['ruta_id' => $rutaId, 'error' => $e->getMessage()]);
            }
            try {
                $eventos = geocerca_evaluar($rutaId, (float) $ultimo['lat'], (float) $ultimo['lng']);
            } catch (Throwable $e) {
                log_app('error', 'geocerca_evaluar falló', ['ruta_id' => $rutaId, 'error' => $e->getMessage()]);
                $eventos = [];
            }
        }
    }

    return [
        'aceptados'  => count($insertados),
        'rechazados' => $rechazados,
        'eventos'    => is_array($eventos) ? $eventos : [],
    ];
}

/**
 * Última posición conocida de un conductor (la usan M1 y M5).
 *
 * @return array{lat:float,lng:float,velocidad_kmh:?float,rumbo:?int,capturado_at:string,antiguedad_s:int}|null
 */
function gps_ultima_posicion(int $conductorId): ?array
{
    $fila = db_one(
        'SELECT lat, lng, velocidad_kmh, rumbo, capturado_at,
                TIMESTAMPDIFF(SECOND, capturado_at, NOW(3)) AS antiguedad_s
           FROM ultima_posicion WHERE conductor_id = ?',
        'i',
        [$conductorId]
    );
    if ($fila === null) {
        return null;
    }
    return [
        'lat'           => (float) $fila['lat'],
        'lng'           => (float) $fila['lng'],
        'velocidad_kmh' => $fila['velocidad_kmh'] === null ? null : (float) $fila['velocidad_kmh'],
        'rumbo'         => $fila['rumbo'] === null ? null : (int) $fila['rumbo'],
        'capturado_at'  => substr((string) $fila['capturado_at'], 0, 19),   // contrato: 'Y-m-d H:i:s'
        'antiguedad_s'  => max(0, (int) $fila['antiguedad_s']),
    ];
}

/**
 * ETA de una parada en 'Y-m-d H:i:s' UTC (la usa M5). Versión básica: lee paradas.eta.
 */
function eta_de_parada(int $paradaId): ?string
{
    $eta = db_val('SELECT eta FROM paradas WHERE id = ?', 'i', [$paradaId]);
    return $eta === null ? null : (string) $eta;
}

/**
 * Estado de la señal según la antigüedad del último punto (M2 §5, gps/flota.php).
 *   en_linea  : antigüedad < gps_demorado_s (60)
 *   demorado  : antigüedad < gps_sin_senal_s (300)
 *   sin_senal : el resto, o sin posición
 */
function gps_estado_senal(?int $antiguedadS): string
{
    if ($antiguedadS === null) {
        return 'sin_senal';
    }
    if ($antiguedadS < (int) config_get('gps_demorado_s', 60)) {
        return 'en_linea';
    }
    if ($antiguedadS < (int) config_get('gps_sin_senal_s', 300)) {
        return 'demorado';
    }
    return 'sin_senal';
}

/**
 * Flota en vivo: una entrada por ruta 'en_curso' (la usa gps/flota.php). Función interna de M2,
 * no forma parte del contrato entre módulos.
 *
 * @param int|null    $zonaId       Filtra por rutas.zona_id.
 * @param string|null $estadoSenal  'en_linea' | 'demorado' | 'sin_senal'.
 * @return array Ver formato en modulos/M2-rastreo-gps.md, sección 5 (proxima_parada lleva además 'estado').
 */
function gps_flota(?int $zonaId = null, ?string $estadoSenal = null): array
{
    $sql = 'SELECT r.id, r.codigo, r.zona_id, r.conductor_id,
                   u.nombre AS conductor_nombre, u.telefono AS conductor_telefono,
                   v.id AS vehiculo_id, v.placa, v.tipo AS vehiculo_tipo,
                   up.lat, up.lng, up.velocidad_kmh, up.rumbo, up.bateria_pct, up.capturado_at,
                   TIMESTAMPDIFF(SECOND, up.capturado_at, NOW(3)) AS antiguedad_s
              FROM rutas r
              JOIN conductores c ON c.id = r.conductor_id
              JOIN usuarios u ON u.id = c.usuario_id
              LEFT JOIN vehiculos v ON v.id = r.vehiculo_id
              LEFT JOIN ultima_posicion up ON up.conductor_id = r.conductor_id AND up.ruta_id = r.id
             WHERE r.estado = \'en_curso\'';
    $tipos = '';
    $params = [];
    if ($zonaId !== null) {
        $sql .= ' AND r.zona_id = ?';
        $tipos .= 'i';
        $params[] = $zonaId;
    }
    $sql .= ' ORDER BY r.codigo';

    $rutas = db_all($sql, $tipos, $params);
    if ($rutas === []) {
        return [];
    }

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rutas);
    $marcas = db_in($ids);              // '?,?,?'
    $tiposIds = str_repeat('i', count($ids));

    // Progreso: paradas resueltas / total por ruta.
    $progreso = [];
    foreach (db_all(
        'SELECT ruta_id, COUNT(*) AS total, SUM(estado IN (\'entregada\',\'fallida\',\'omitida\')) AS resueltas
           FROM paradas WHERE ruta_id IN (' . $marcas . ') GROUP BY ruta_id',
        $tiposIds,
        $ids
    ) as $fila) {
        $progreso[(int) $fila['ruta_id']] = ['total' => (int) $fila['total'], 'resueltas' => (int) $fila['resueltas']];
    }

    // Próxima parada de cada ruta: la 'en_camino' (o, si el conductor ya llegó, la 'llegado').
    $proximas = [];
    foreach (db_all(
        'SELECT p.id, p.ruta_id, p.paquete_id, p.estado, p.lat, p.lng, p.eta, pq.codigo_guia
           FROM paradas p JOIN paquetes pq ON pq.id = p.paquete_id
          WHERE p.ruta_id IN (' . $marcas . ') AND p.estado IN (\'en_camino\',\'llegado\')
          ORDER BY p.ruta_id, p.orden',
        $tiposIds,
        $ids
    ) as $p) {
        $rutaId = (int) $p['ruta_id'];
        if (!isset($proximas[$rutaId]) || ($proximas[$rutaId]['estado'] === 'llegado' && $p['estado'] === 'en_camino')) {
            $proximas[$rutaId] = $p;
        }
    }

    $salida = [];
    foreach ($rutas as $r) {
        $rutaId = (int) $r['id'];
        $tieneUbicacion = $r['lat'] !== null;
        $antiguedad = $tieneUbicacion ? max(0, (int) $r['antiguedad_s']) : null;
        $senal = gps_estado_senal($antiguedad);
        if ($estadoSenal !== null && $senal !== $estadoSenal) {
            continue;
        }

        $posicion = null;
        if ($tieneUbicacion) {
            $posicion = [
                'lat'           => (float) $r['lat'],
                'lng'           => (float) $r['lng'],
                'velocidad_kmh' => $r['velocidad_kmh'] === null ? null : (float) $r['velocidad_kmh'],
                'rumbo'         => $r['rumbo'] === null ? null : (int) $r['rumbo'],
                'bateria_pct'   => $r['bateria_pct'] === null ? null : (int) $r['bateria_pct'],
                'capturado_at'  => fecha_a_iso(substr((string) $r['capturado_at'], 0, 19)),
                'antiguedad_s'  => $antiguedad,
            ];
        }

        $proxima = null;
        if (isset($proximas[$rutaId])) {
            $p = $proximas[$rutaId];
            $distancia = $tieneUbicacion
                ? (int) round(geo_distancia_m((float) $r['lat'], (float) $r['lng'], (float) $p['lat'], (float) $p['lng']))
                : null;
            $proxima = [
                'id'          => (int) $p['id'],
                'paquete_id'  => (int) $p['paquete_id'],
                'codigo_guia' => $p['codigo_guia'],
                'estado'      => $p['estado'],
                'eta'         => $p['eta'] === null ? null : fecha_a_iso((string) $p['eta']),
                'distancia_m' => $distancia,
            ];
        }

        $conteo = $progreso[$rutaId] ?? ['total' => 0, 'resueltas' => 0];
        $salida[] = [
            'ruta' => [
                'id'            => $rutaId,
                'codigo'        => $r['codigo'],
                'total_paradas' => $conteo['total'],
                'resueltas'     => $conteo['resueltas'],
                'zona_id'       => $r['zona_id'] === null ? null : (int) $r['zona_id'],
            ],
            'conductor' => [
                'id'       => (int) $r['conductor_id'],
                'nombre'   => $r['conductor_nombre'],
                'telefono' => $r['conductor_telefono'],
            ],
            'vehiculo' => $r['vehiculo_id'] === null ? null : [
                'id'    => (int) $r['vehiculo_id'],
                'placa' => $r['placa'],
                'tipo'  => $r['vehiculo_tipo'],
            ],
            'posicion'       => $posicion,
            'estado_senal'   => $senal,
            'proxima_parada' => $proxima,
        ];
    }
    return $salida;
}


// =====================================================================
// T5. Geocerca de proximidad y ETA
// =====================================================================

/**
 * Evalúa la parada 'en_camino' de la ruta contra la posición del vehículo (M2 §7.1).
 *
 * Crea el evento 'entrada' UNA sola vez por parada (clave única parada_id+tipo) cuando se cumple
 * el PRIMERO de: distancia <= geocerca_radio_m, o ETA <= proximidad_eta_min. Al crearlo guarda
 * distancia_m y radio_m, llama notificar($paquete, 'proximidad', ['eta_min' => ...], "{paquete}:proximidad:{parada}")
 * y marca notificado = 1. El evento 'salida' es opcional (fase 2) y no se genera.
 *
 * @return array Eventos creados en esta llamada: [['tipo' => 'entrada', 'parada_id' => 3]]. Vacío si no hubo.
 */
function geocerca_evaluar(int $rutaId, float $lat, float $lng): array
{
    $parada = db_one(
        'SELECT id, paquete_id, lat, lng, eta FROM paradas
          WHERE ruta_id = ? AND estado = \'en_camino\' ORDER BY orden LIMIT 1',
        'i',
        [$rutaId]
    );
    if ($parada === null) {
        return [];
    }
    $paradaId = (int) $parada['id'];
    $paqueteId = (int) $parada['paquete_id'];

    // Camino rápido: si ya hubo evento para esta parada no se vuelve a evaluar.
    if (db_val('SELECT id FROM eventos_geocerca WHERE parada_id = ? AND tipo = \'entrada\'', 'i', [$paradaId]) !== null) {
        return [];
    }

    $radio = (int) config_get('geocerca_radio_m', 500);
    $etaMaxMin = (int) config_get('proximidad_eta_min', 10);
    $velocidadMedia = max(1.0, (float) config_get('eta_velocidad_media_kmh', 25));

    $distancia = geo_distancia_m($lat, $lng, (float) $parada['lat'], (float) $parada['lng']);
    $minutosEta = $parada['eta'] === null ? null : (strtotime($parada['eta'] . ' UTC') - time()) / 60;

    $porDistancia = $distancia <= $radio;
    $porEta = $minutosEta !== null && $minutosEta <= $etaMaxMin;
    if (!$porDistancia && !$porEta) {
        return [];
    }

    // INSERT IGNORE + clave única: si dos lotes llegan a la vez, solo uno crea el evento (filas afectadas = 1).
    $creado = db_exec(
        'INSERT IGNORE INTO eventos_geocerca (ruta_id, parada_id, paquete_id, tipo, radio_m, distancia_m, notificado)
         VALUES (?, ?, ?, \'entrada\', ?, ?, 0)',
        'iiiid',
        [$rutaId, $paradaId, $paqueteId, $radio, round($distancia, 1)]
    );
    if ($creado < 1) {
        return [];
    }

    // Minutos que se le dicen al cliente: el ETA si cumplió por ETA; si no, una estimación por distancia.
    $minutosPorDistancia = (int) ceil(($distancia / 1000) / $velocidadMedia * 60);
    $minutos = ($minutosEta === null || $minutosEta > $etaMaxMin) ? $minutosPorDistancia : (int) ceil($minutosEta);

    try {
        notificar($paqueteId, 'proximidad', ['eta_min' => max(1, $minutos)], $paqueteId . ':proximidad:' . $paradaId);
        db_exec('UPDATE eventos_geocerca SET notificado = 1 WHERE parada_id = ? AND tipo = \'entrada\'', 'i', [$paradaId]);
    } catch (Throwable $e) {
        // notificar() no debe lanzar errores; si lo hace, el evento queda con notificado = 0 para revisarlo.
        log_app('error', 'notificar proximidad falló', ['parada_id' => $paradaId, 'error' => $e->getMessage()]);
    }

    return [['tipo' => 'entrada', 'parada_id' => $paradaId]];
}

/**
 * Velocidad media (km/h) de los últimos 5 minutos de la ruta, o null si es <= 5 km/h o no hay datos.
 */
function gps_velocidad_reciente_kmh(int $rutaId): ?float
{
    $media = db_val(
        'SELECT AVG(velocidad_kmh) FROM puntos_gps
          WHERE ruta_id = ? AND velocidad_kmh IS NOT NULL AND capturado_at >= NOW(3) - INTERVAL 5 MINUTE',
        'i',
        [$rutaId]
    );
    return ($media !== null && (float) $media > 5) ? (float) $media : null;
}

/**
 * Matriz de duraciones en segundos entre los puntos (diagonal 0).
 * Usa ruta_matriz() (una sola llamada). Si el proveedor falló (proveedor = 'respaldo') o la respuesta no
 * es válida, se recalcula con la velocidad media de los últimos 5 minutos (si es > 5 km/h) o, si no,
 * con eta_velocidad_media_kmh (M2 §7.2, punto 3).
 *
 * @param array $puntos Lista de ['lat' => float, 'lng' => float]
 * @return float[][]
 */
function gps_matriz_duraciones(array $puntos, int $rutaId): array
{
    $n = count($puntos);
    $matriz = null;
    try {
        $matriz = ruta_matriz($puntos);
    } catch (Throwable $e) {
        log_app('warning', 'ruta_matriz falló en el ETA', ['ruta_id' => $rutaId, 'error' => $e->getMessage()]);
    }

    $valida = is_array($matriz)
        && isset($matriz['duraciones_s'], $matriz['distancias_m'])
        && count($matriz['duraciones_s']) === $n
        && count($matriz['distancias_m']) === $n;

    if ($valida && ($matriz['proveedor'] ?? '') !== 'respaldo') {
        return $matriz['duraciones_s'];
    }

    $velocidad = gps_velocidad_reciente_kmh($rutaId) ?? max(1.0, (float) config_get('eta_velocidad_media_kmh', 25));
    $metrosPorSegundo = $velocidad / 3.6;
    $duraciones = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if ($i === $j) {
                $duraciones[$i][$j] = 0.0;
                continue;
            }
            $metros = $valida
                ? (float) $matriz['distancias_m'][$i][$j]
                : geo_distancia_m($puntos[$i]['lat'], $puntos[$i]['lng'], $puntos[$j]['lat'], $puntos[$j]['lng']) * 1.3;
            $duraciones[$i][$j] = $metros / $metrosPorSegundo;
        }
    }
    return $duraciones;
}

/**
 * Recalcula paradas.eta y paradas.eta_actualizado_at de una ruta 'en_curso' (M2 §7.2).
 *
 *   tiempo_parada_prom = promedio de (salida_real - llegada_real) de las últimas 5 paradas resueltas
 *                        (sin las mayores a dwell_max_s); si no hay, eta_parada_default_min.
 *   eta[0]  = ahora + t_viaje(posición -> parada en camino)
 *   eta[k]  = eta[k-1] + tiempo_parada_prom + t_viaje(k-1 -> k)
 * Si la parada actual está 'llegado', el vehículo ya está allí: su salida se estima como ahora más lo que
 * falte del tiempo de parada promedio (nunca menos de 0) y de ahí se parte.
 *
 * No recalcula más de una vez cada eta_recalculo_min_s por ruta, salvo $forzar.
 */
function eta_recalcular_ruta(int $rutaId, bool $forzar = false): void
{
    $ruta = db_one('SELECT id, conductor_id, hub_id, estado FROM rutas WHERE id = ?', 'i', [$rutaId]);
    if ($ruta === null || $ruta['estado'] !== 'en_curso') {
        return;
    }

    if (!$forzar) {
        $edad = db_val('SELECT TIMESTAMPDIFF(SECOND, MAX(eta_actualizado_at), NOW()) FROM paradas WHERE ruta_id = ?', 'i', [$rutaId]);
        if ($edad !== null && (int) $edad < (int) config_get('eta_recalculo_min_s', 30)) {
            return;
        }
    }

    $paradas = db_all(
        'SELECT id, estado, lat, lng, llegada_real FROM paradas
          WHERE ruta_id = ? AND estado IN (\'pendiente\',\'en_camino\',\'llegado\') ORDER BY orden',
        'i',
        [$rutaId]
    );
    if ($paradas === []) {
        return;
    }

    // Punto de partida: la última posición de esta ruta, o el HUB si aún no hay.
    $origen = db_one('SELECT lat, lng FROM ultima_posicion WHERE conductor_id = ? AND ruta_id = ?', 'ii', [(int) $ruta['conductor_id'], $rutaId]);
    if ($origen === null) {
        $origen = db_one('SELECT lat, lng FROM hubs WHERE id = ?', 'i', [(int) $ruta['hub_id']]);
    }
    if ($origen === null) {
        log_app('warning', 'eta_recalcular_ruta sin punto de partida', ['ruta_id' => $rutaId]);
        return;
    }

    // Tiempo de parada promedio (segundos).
    $filas = db_all(
        'SELECT TIMESTAMPDIFF(SECOND, llegada_real, salida_real) AS seg FROM paradas
          WHERE ruta_id = ? AND estado IN (\'entregada\',\'fallida\')
            AND llegada_real IS NOT NULL AND salida_real IS NOT NULL
            AND TIMESTAMPDIFF(SECOND, llegada_real, salida_real) BETWEEN 0 AND ?
          ORDER BY salida_real DESC LIMIT 5',
        'ii',
        [$rutaId, (int) config_get('dwell_max_s', 7200)]
    );
    $promedioParada = $filas === []
        ? (int) config_get('eta_parada_default_min', 5) * 60
        : array_sum(array_map(static fn(array $f): int => (int) $f['seg'], $filas)) / count($filas);

    // Tiempos de viaje: una sola llamada con [posición, parada 1, parada 2, ...].
    $puntos = [['lat' => (float) $origen['lat'], 'lng' => (float) $origen['lng']]];
    foreach ($paradas as $p) {
        $puntos[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
    }
    $duraciones = gps_matriz_duraciones($puntos, $rutaId);

    $ahora = time();
    $salida = $ahora;     // cuándo se sale del punto anterior
    $anterior = 0;        // índice del punto anterior (0 = posición actual)
    $etas = [];
    foreach ($paradas as $k => $p) {
        $indice = $k + 1;
        if ($p['estado'] === 'llegado') {
            $llegada = $p['llegada_real'] !== null ? strtotime($p['llegada_real'] . ' UTC') : $ahora;
            $eta = min($llegada, $ahora);
            $salida = $ahora + max(0, $promedioParada - max(0, $ahora - $llegada));
        } else {
            $eta = $salida + (float) $duraciones[$anterior][$indice];
            $salida = $eta + $promedioParada;
        }
        $etas[] = [(int) $p['id'], (int) round($eta)];
        $anterior = $indice;
    }

    $actualizadoAt = fecha_utc_ahora();
    db_tx(static function () use ($etas, $actualizadoAt): void {
        foreach ($etas as [$paradaId, $marca]) {
            db_exec(
                'UPDATE paradas SET eta = ?, eta_actualizado_at = ? WHERE id = ?',
                'ssi',
                [gmdate('Y-m-d H:i:s', $marca), $actualizadoAt, $paradaId]
            );
        }
    });
}

/**
 * ETA de todas las paradas de una ruta (la usa gps/eta.php). Función interna de M2.
 *
 * @param int|null $soloConductorId Si viene, la ruta debe ser de ese conductor (si no, SIN_PERMISO).
 * @return array [{parada_id, orden, estado, eta (ISO), minutos, en_riesgo}]; eta y minutos son null en paradas resueltas.
 */
function gps_eta_listar(int $rutaId, ?int $soloConductorId = null): array
{
    $ruta = db_one('SELECT id, conductor_id FROM rutas WHERE id = ?', 'i', [$rutaId]);
    if ($ruta === null) {
        error_no_encontrado('La ruta');
    }
    if ($soloConductorId !== null && (int) $ruta['conductor_id'] !== $soloConductorId) {
        error_sin_permiso();
    }

    $filas = db_all(
        'SELECT p.id, p.orden, p.estado, p.eta, pq.ventana_fin
           FROM paradas p JOIN paquetes pq ON pq.id = p.paquete_id
          WHERE p.ruta_id = ? ORDER BY p.orden',
        'i',
        [$rutaId]
    );
    $ahora = time();
    $salida = [];
    foreach ($filas as $f) {
        $resuelta = in_array($f['estado'], ['entregada', 'fallida', 'omitida'], true);
        $marca = (!$resuelta && $f['eta'] !== null) ? strtotime($f['eta'] . ' UTC') : null;
        $salida[] = [
            'parada_id' => (int) $f['id'],
            'orden'     => (int) $f['orden'],
            'estado'    => $f['estado'],
            'eta'       => $marca === null ? null : fecha_a_iso((string) $f['eta']),
            'minutos'   => $marca === null ? null : max(0, (int) ceil(($marca - $ahora) / 60)),
            'en_riesgo' => $marca !== null && $f['ventana_fin'] !== null && $marca > strtotime($f['ventana_fin'] . ' UTC'),
        ];
    }
    return $salida;
}

/**
 * Eventos de geocerca de una ruta con datos de la parada (la usa gps/eventos.php). Función interna de M2.
 */
function gps_eventos_listar(int $rutaId): array
{
    if (db_val('SELECT id FROM rutas WHERE id = ?', 'i', [$rutaId]) === null) {
        error_no_encontrado('La ruta');
    }
    $salida = [];
    foreach (db_all(
        'SELECT e.id, e.tipo, e.radio_m, e.distancia_m, e.notificado, e.created_at,
                e.parada_id, p.orden, p.estado AS parada_estado, e.paquete_id, pq.codigo_guia
           FROM eventos_geocerca e
           JOIN paradas p ON p.id = e.parada_id
           JOIN paquetes pq ON pq.id = e.paquete_id
          WHERE e.ruta_id = ? ORDER BY e.created_at, e.id',
        'i',
        [$rutaId]
    ) as $e) {
        $salida[] = [
            'id'          => (int) $e['id'],
            'tipo'        => $e['tipo'],
            'radio_m'     => (int) $e['radio_m'],
            'distancia_m' => (float) $e['distancia_m'],
            'notificado'  => (bool) $e['notificado'],
            'creado_at'   => fecha_a_iso((string) $e['created_at']),
            'parada'      => ['id' => (int) $e['parada_id'], 'orden' => (int) $e['orden'], 'estado' => $e['parada_estado']],
            'paquete'     => ['id' => (int) $e['paquete_id'], 'codigo_guia' => $e['codigo_guia']],
        ];
    }
    return $salida;
}


// =====================================================================
// T6. Distancia recorrida, recorrido (playback) e integridad
// =====================================================================

/**
 * Puntos GPS utilizables de una ruta, en orden cronológico y listos para medir o dibujar.
 * Descarta: puntos con precisión peor que gps_precision_max_m, puntos con la misma marca de tiempo
 * que el anterior y saltos imposibles (velocidad implícita > 150 km/h). Cada punto (salvo el primero)
 * trae 'metros': distancia desde el punto aceptado anterior.
 *
 * @param string|null $desde 'Y-m-d H:i:s' UTC (inclusive)
 * @param string|null $hasta 'Y-m-d H:i:s' UTC (inclusive)
 * @return array<int, array{lat:float,lng:float,t:float,capturado_at:string,metros?:float}>
 */
function gps_puntos_validos(int $rutaId, ?string $desde = null, ?string $hasta = null): array
{
    $precisionMax = (float) config_get('gps_precision_max_m', 200);

    $sql = 'SELECT lat, lng, precision_m, capturado_at, UNIX_TIMESTAMP(capturado_at) AS t
              FROM puntos_gps WHERE ruta_id = ?';
    $tipos = 'i';
    $params = [$rutaId];
    if ($desde !== null) {
        $sql .= ' AND capturado_at >= ?';
        $tipos .= 's';
        $params[] = $desde;
    }
    if ($hasta !== null) {
        $sql .= ' AND capturado_at <= ?';
        $tipos .= 's';
        $params[] = $hasta;
    }
    $sql .= ' ORDER BY capturado_at, id';

    $salida = [];
    $previo = null;
    foreach (db_all($sql, $tipos, $params) as $f) {
        if ($f['precision_m'] !== null && (float) $f['precision_m'] > $precisionMax) {
            continue;
        }
        $punto = [
            'lat'          => (float) $f['lat'],
            'lng'          => (float) $f['lng'],
            't'            => (float) $f['t'],
            'capturado_at' => (string) $f['capturado_at'],
        ];
        if ($previo !== null) {
            $segundos = $punto['t'] - $previo['t'];
            if ($segundos <= 0) {
                continue;
            }
            $metros = geo_distancia_m($previo['lat'], $previo['lng'], $punto['lat'], $punto['lng']);
            if ($metros / $segundos * 3.6 > 150) {
                continue;   // salto imposible: se ignora el punto y se compara con el último bueno
            }
            $punto['metros'] = $metros;
        }
        $salida[] = $punto;
        $previo = $punto;
    }
    return $salida;
}

/**
 * Distancia real recorrida por la ruta, en km (la usa M1 al finalizar y M6 en los costos).
 * Suma las distancias entre puntos consecutivos, ignorando precisión mala y saltos > 150 km/h.
 */
function gps_distancia_recorrida_km(int $rutaId): float
{
    $metros = 0.0;
    foreach (gps_puntos_validos($rutaId) as $punto) {
        $metros += $punto['metros'] ?? 0.0;
    }
    return round($metros / 1000, 2);
}

/**
 * Simplificación Douglas-Peucker (iterativa) con tolerancia en metros.
 *
 * @param array $puntos Lista de ['lat' => float, 'lng' => float, ...]
 * @return array Subconjunto de $puntos, conservando el primero y el último.
 */
function gps_simplificar_puntos(array $puntos, float $toleranciaM): array
{
    $n = count($puntos);
    if ($n < 3) {
        return $puntos;
    }

    // Proyección plana local en metros (suficiente para tolerancias de pocos metros).
    $k = cos(deg2rad($puntos[0]['lat']));
    $xs = [];
    $ys = [];
    foreach ($puntos as $i => $p) {
        $xs[$i] = $p['lng'] * 111320.0 * $k;
        $ys[$i] = $p['lat'] * 110540.0;
    }

    $mantener = array_fill(0, $n, false);
    $mantener[0] = true;
    $mantener[$n - 1] = true;
    $pila = [[0, $n - 1]];

    while ($pila !== []) {
        [$a, $b] = array_pop($pila);
        $dx = $xs[$b] - $xs[$a];
        $dy = $ys[$b] - $ys[$a];
        $largo2 = $dx * $dx + $dy * $dy;
        $max = 0.0;
        $indice = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            if ($largo2 == 0.0) {
                $d = hypot($xs[$i] - $xs[$a], $ys[$i] - $ys[$a]);
            } else {
                $u = max(0.0, min(1.0, (($xs[$i] - $xs[$a]) * $dx + ($ys[$i] - $ys[$a]) * $dy) / $largo2));
                $d = hypot($xs[$i] - ($xs[$a] + $u * $dx), $ys[$i] - ($ys[$a] + $u * $dy));
            }
            if ($d > $max) {
                $max = $d;
                $indice = $i;
            }
        }
        if ($indice !== -1 && $max > $toleranciaM) {
            $mantener[$indice] = true;
            $pila[] = [$a, $indice];
            $pila[] = [$indice, $b];
        }
    }

    $salida = [];
    foreach ($puntos as $i => $p) {
        if ($mantener[$i]) {
            $salida[] = $p;
        }
    }
    return $salida;
}

/**
 * Verifica la cadena de hashes de TODA la ruta (M2 §5, verificar=1).
 * Recalcula cada hash con el mismo formato con que se guardó (ver gps_hash_punto) y lo compara.
 * Las filas sin hash que aparecen ANTES del primer punto con hash (por ejemplo, los datos del seed) se
 * consideran anteriores a la cadena y se omiten; una fila sin hash DESPUÉS de empezar la cadena cuenta como alteración.
 *
 * @return bool true si la cadena es íntegra (o no hay nada que verificar), false si se detectó una alteración.
 */
function gps_verificar_integridad(int $rutaId): bool
{
    $previo = GPS_HASH_CERO;
    $ultimoId = 0;
    $cadenaIniciada = false;

    while (true) {
        $filas = db_all(
            'SELECT id, lat, lng, capturado_at, hash_registro FROM puntos_gps
              WHERE ruta_id = ? AND id > ? ORDER BY id LIMIT 5000',
            'ii',
            [$rutaId, $ultimoId]
        );
        if ($filas === []) {
            return true;
        }
        foreach ($filas as $f) {
            $ultimoId = (int) $f['id'];
            $guardado = (string) ($f['hash_registro'] ?? '');

            if ($guardado === '') {
                if ($cadenaIniciada) {
                    log_app('warning', 'integridad GPS: punto sin hash dentro de la cadena', ['ruta_id' => $rutaId, 'punto_id' => $ultimoId]);
                    return false;
                }
                continue;
            }
            $cadenaIniciada = true;

            $esperado = gps_hash_punto(
                $previo,
                $rutaId,
                number_format((float) $f['lat'], 7, '.', ''),
                number_format((float) $f['lng'], 7, '.', ''),
                (string) $f['capturado_at']
            );
            if (!hash_equals($esperado, $guardado)) {
                log_app('warning', 'integridad GPS: hash alterado', ['ruta_id' => $rutaId, 'punto_id' => $ultimoId]);
                return false;
            }
            $previo = $esperado;
        }
    }
}

/**
 * Largo en km de una polyline [[lat, lng], ...].
 */
function gps_largo_polyline_km(array $linea): float
{
    $metros = 0.0;
    for ($i = 1, $n = count($linea); $i < $n; $i++) {
        $metros += geo_distancia_m($linea[$i - 1][0], $linea[$i - 1][1], $linea[$i][0], $linea[$i][1]);
    }
    return $metros / 1000;
}

/**
 * Recorrido real frente al sugerido (playback). Función interna de M2 usada por gps/recorrido.php.
 *
 * @param string|null $desde 'Y-m-d H:i:s' UTC; limita los puntos reales y las estadísticas de distancia.
 * @param string|null $hasta 'Y-m-d H:i:s' UTC
 * @return array {real, sugerida, paradas, estadisticas, integridad_ok, eventos, ruta}
 *               ('eventos' y 'ruta' son adiciones al contrato: así el playback también sirve al gerente).
 */
function gps_recorrido(int $rutaId, ?string $desde = null, ?string $hasta = null, bool $verificar = false): array
{
    $ruta = db_one(
        'SELECT id, codigo, estado, hub_id, distancia_km_estimada, polyline_sugerida, inicio_real, fin_real
           FROM rutas WHERE id = ?',
        'i',
        [$rutaId]
    );
    if ($ruta === null) {
        error_no_encontrado('La ruta');
    }

    // Paradas con su llegada y salida reales.
    $paradas = [];
    $segundosEnParadas = 0;
    foreach (db_all(
        'SELECT p.id, p.orden, p.estado, p.lat, p.lng, p.llegada_real, p.salida_real, pq.codigo_guia
           FROM paradas p JOIN paquetes pq ON pq.id = p.paquete_id
          WHERE p.ruta_id = ? ORDER BY p.orden',
        'i',
        [$rutaId]
    ) as $p) {
        if ($p['llegada_real'] !== null && $p['salida_real'] !== null) {
            $segundosEnParadas += max(0, strtotime($p['salida_real'] . ' UTC') - strtotime($p['llegada_real'] . ' UTC'));
        }
        $paradas[] = [
            'id'           => (int) $p['id'],
            'orden'        => (int) $p['orden'],
            'estado'       => $p['estado'],
            'lat'          => (float) $p['lat'],
            'lng'          => (float) $p['lng'],
            'codigo_guia'  => $p['codigo_guia'],
            'llegada_real' => fecha_a_iso($p['llegada_real']),
            'salida_real'  => fecha_a_iso($p['salida_real']),
        ];
    }

    // Recorrido real.
    $puntos = gps_puntos_validos($rutaId, $desde, $hasta);
    $metros = 0.0;
    foreach ($puntos as $punto) {
        $metros += $punto['metros'] ?? 0.0;
    }
    $distanciaRealKm = round($metros / 1000, 2);

    // Ruta sugerida: la polyline de M1; si no existe, una línea recta HUB -> paradas en orden.
    $sugerida = [];
    if (is_string($ruta['polyline_sugerida']) && $ruta['polyline_sugerida'] !== '') {
        $decodificada = json_decode($ruta['polyline_sugerida'], true);
        if (is_array($decodificada)) {
            foreach ($decodificada as $p) {
                if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) {
                    $sugerida[] = [(float) $p[0], (float) $p[1]];
                }
            }
        }
    }
    $sugeridaDerivada = count($sugerida) < 2;
    if ($sugeridaDerivada) {
        $sugerida = [];
        $hub = db_one('SELECT lat, lng FROM hubs WHERE id = ?', 'i', [(int) $ruta['hub_id']]);
        if ($hub !== null) {
            $sugerida[] = [(float) $hub['lat'], (float) $hub['lng']];
        }
        foreach ($paradas as $p) {
            $sugerida[] = [$p['lat'], $p['lng']];
        }
    }
    if (!$sugeridaDerivada) {
        $distanciaSugeridaKm = gps_largo_polyline_km($sugerida);
    } elseif ($ruta['distancia_km_estimada'] !== null) {
        $distanciaSugeridaKm = (float) $ruta['distancia_km_estimada'];
    } else {
        $distanciaSugeridaKm = gps_largo_polyline_km($sugerida);
    }
    $distanciaSugeridaKm = round($distanciaSugeridaKm, 2);

    // Duración real: de inicio_real al fin (o a ahora si sigue en curso). Con un rango desde/hasta, de su primer a su último punto.
    $duracionMin = null;
    if ($desde === null && $hasta === null && $ruta['inicio_real'] !== null) {
        $inicio = strtotime($ruta['inicio_real'] . ' UTC');
        if ($ruta['fin_real'] !== null) {
            $fin = strtotime($ruta['fin_real'] . ' UTC');
        } elseif ($ruta['estado'] === 'en_curso') {
            $fin = time();
        } else {
            $fin = $puntos !== [] ? $puntos[count($puntos) - 1]['t'] : $inicio;
        }
        $duracionMin = round(max(0, $fin - $inicio) / 60, 1);
    } elseif ($puntos !== []) {
        $duracionMin = round(max(0, $puntos[count($puntos) - 1]['t'] - $puntos[0]['t']) / 60, 1);
    }

    // Se simplifica solo para dibujar (las estadísticas ya se calcularon con todos los puntos).
    $paraDibujar = count($puntos) > 3000 ? gps_simplificar_puntos($puntos, 5.0) : $puntos;
    $real = [];
    foreach ($paraDibujar as $punto) {
        $real[] = [$punto['lat'], $punto['lng'], fecha_a_iso(substr($punto['capturado_at'], 0, 19))];
    }

    return [
        'ruta'          => ['id' => (int) $ruta['id'], 'codigo' => $ruta['codigo'], 'estado' => $ruta['estado']],
        'real'          => $real,
        'sugerida'      => $sugerida,
        'paradas'       => $paradas,
        'estadisticas'  => [
            'distancia_real_km'     => $distanciaRealKm,
            'distancia_sugerida_km' => $distanciaSugeridaKm,
            'desviacion_pct'        => ($puntos !== [] && $distanciaSugeridaKm > 0)
                ? round(($distanciaRealKm - $distanciaSugeridaKm) / $distanciaSugeridaKm * 100, 1)
                : null,
            'duracion_real_min'     => $duracionMin,
            'tiempo_en_paradas_min' => round($segundosEnParadas / 60, 1),
        ],
        'integridad_ok' => $verificar ? gps_verificar_integridad($rutaId) : null,
        'eventos'       => gps_eventos_listar($rutaId),
    ];
}
