<?php
/**
 * M1. Despacho y optimización de rutas (Juan).
 * Tablas: rutas, paradas. Contrato: docs/00-base/07-servicios-internos.md (sección D) y docs/modulos/M1-despacho-rutas.md.
 *
 * Los estados de los paquetes SIEMPRE cambian con paquete_cambiar_estado() (M3).
 */

const PARADA_ESTADOS_ABIERTOS = ['pendiente', 'en_camino'];
const PARADA_ESTADOS_RESUELTOS = ['entregada', 'fallida', 'omitida'];

/* ====================================================================
 * Algoritmos puros (sin base de datos)
 * ==================================================================== */

/** Matriz de respaldo: haversine × 1.3 a la velocidad media configurada. */
function ruta_matriz_local(array $puntos): array
{
    $vel = max(5.0, (float) config_get('eta_velocidad_media_kmh', 25)) * 1000 / 3600; // m/s
    $n = count($puntos);
    $dist = [];
    $dur = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $d = $i === $j ? 0.0 : geo_distancia_m((float) $puntos[$i]['lat'], (float) $puntos[$i]['lng'], (float) $puntos[$j]['lat'], (float) $puntos[$j]['lng']) * 1.3;
            $dist[$i][$j] = $d;
            $dur[$i][$j] = $d / $vel;
        }
    }
    return ['distancias_m' => $dist, 'duraciones_s' => $dur, 'proveedor' => 'local'];
}

/** Pide la matriz a M7; si falla o no existe, usa la local. */
function ruta_obtener_matriz(array $puntos): array
{
    if (function_exists('ruta_matriz')) {
        try {
            $m = ruta_matriz($puntos);
            if (is_array($m) && isset($m['duraciones_s'], $m['distancias_m']) && count($m['duraciones_s']) === count($puntos)) {
                return $m;
            }
        } catch (Throwable $e) {
            log_app('warning', 'ruta_matriz falló, se usa la matriz local: ' . $e->getMessage());
        }
    }
    return ruta_matriz_local($puntos);
}

/** Vecino más cercano partiendo de $origen. Devuelve la lista de nodos en orden. */
function ruta_vecino_mas_cercano(array $dur, array $nodos, int $origen = 0): array
{
    $pendientes = array_values($nodos);
    $orden = [];
    $actual = $origen;
    while ($pendientes) {
        $mejor = 0;
        $mejorD = INF;
        foreach ($pendientes as $k => $nodo) {
            if ($dur[$actual][$nodo] < $mejorD) {
                $mejorD = $dur[$actual][$nodo];
                $mejor = $k;
            }
        }
        $actual = $pendientes[$mejor];
        $orden[] = $actual;
        array_splice($pendientes, $mejor, 1);
    }
    return $orden;
}

/** Tiempo total de recorrer $orden desde $origen (sin volver). */
function ruta_costo(array $orden, array $dur, int $origen = 0): float
{
    $total = 0.0;
    $prev = $origen;
    foreach ($orden as $nodo) {
        $total += $dur[$prev][$nodo];
        $prev = $nodo;
    }
    return $total;
}

/** 2-opt sobre un camino abierto: máximo 200 iteraciones o 2 segundos. */
function ruta_dos_opt(array $orden, array $dur, int $origen = 0): array
{
    $n = count($orden);
    if ($n < 3) {
        return $orden;
    }
    $inicio = microtime(true);
    $mejorCosto = ruta_costo($orden, $dur, $origen);
    for ($iter = 0; $iter < 200; $iter++) {
        $mejoro = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $prueba = array_merge(array_slice($orden, 0, $i), array_reverse(array_slice($orden, $i, $j - $i + 1)), array_slice($orden, $j + 1));
                $costo = ruta_costo($prueba, $dur, $origen);
                if ($costo < $mejorCosto - 1e-6) {
                    $orden = $prueba;
                    $mejorCosto = $costo;
                    $mejoro = true;
                }
            }
            if (microtime(true) - $inicio > 2.0) {
                return $orden;
            }
        }
        if (!$mejoro) {
            break;
        }
    }
    return $orden;
}

/** Simula las llegadas (timestamps UTC) por nodo: salida en $inicioTs, $minParada minutos por parada. */
function ruta_simular(array $orden, array $dur, int $inicioTs, int $minParada, int $origen = 0): array
{
    $t = $inicioTs;
    $prev = $origen;
    $llegadas = [];
    foreach ($orden as $nodo) {
        $t += (int) round($dur[$prev][$nodo]);
        $llegadas[$nodo] = $t;
        $t += $minParada * 60;
        $prev = $nodo;
    }
    return $llegadas;
}

/** Cuenta las paradas que llegan después de su ventana_fin. */
function ruta_contar_tardias(array $orden, array $llegadas, array $ventanaFinTs): int
{
    $c = 0;
    foreach ($orden as $nodo) {
        if (isset($ventanaFinTs[$nodo]) && $llegadas[$nodo] > $ventanaFinTs[$nodo]) {
            $c++;
        }
    }
    return $c;
}

/** Pasada de ventanas: intercambia paradas tardías con anteriores (máximo 50 intentos). */
function ruta_ajustar_ventanas(array $orden, array $dur, int $inicioTs, int $minParada, array $ventanaFinTs, int $origen = 0): array
{
    $intentos = 0;
    $mejoro = true;
    while ($mejoro && $intentos < 50) {
        $mejoro = false;
        $llegadas = ruta_simular($orden, $dur, $inicioTs, $minParada, $origen);
        $actuales = ruta_contar_tardias($orden, $llegadas, $ventanaFinTs);
        foreach ($orden as $k => $nodo) {
            if (!isset($ventanaFinTs[$nodo]) || $llegadas[$nodo] <= $ventanaFinTs[$nodo]) {
                continue;
            }
            for ($m = 0; $m < $k; $m++) {
                if ($intentos++ >= 50) {
                    break 3;
                }
                $prueba = $orden;
                [$prueba[$m], $prueba[$k]] = [$prueba[$k], $prueba[$m]];
                $ll = ruta_simular($prueba, $dur, $inicioTs, $minParada, $origen);
                if (ruta_contar_tardias($prueba, $ll, $ventanaFinTs) < $actuales) {
                    $orden = $prueba;
                    $mejoro = true;
                    break 2;
                }
            }
        }
    }
    return $orden;
}

/**
 * Ordena (o solo evalúa) un conjunto de paradas desde un origen.
 *
 * @param array $origen   ['lat','lng']
 * @param array $paradas  Lista de ['lat','lng','ventana_fin' => 'Y-m-d H:i:s'|null]
 * @param bool  $optimizar false = mantiene el orden recibido y solo calcula llegadas y distancia
 * @return array ['orden' => [índices 0-based de $paradas en el nuevo orden], 'llegadas' => [índice => ts],
 *               'distancia_m', 'duracion_s', 'puntos' => [[lat,lng]...] (origen + paradas ordenadas)]
 */
function ruta_ordenar(array $origen, array $paradas, int $inicioTs, bool $optimizar = true): array
{
    $n = count($paradas);
    if ($n === 0) {
        return ['orden' => [], 'llegadas' => [], 'distancia_m' => 0.0, 'duracion_s' => 0, 'puntos' => [[(float) $origen['lat'], (float) $origen['lng']]]];
    }
    $puntos = [['lat' => (float) $origen['lat'], 'lng' => (float) $origen['lng']]];
    foreach ($paradas as $p) {
        $puntos[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
    }
    $m = ruta_obtener_matriz($puntos);
    $dur = $m['duraciones_s'];
    $dist = $m['distancias_m'];
    $minParada = (int) config_get('eta_parada_default_min', 5);

    $nodos = range(1, $n);
    if ($optimizar) {
        $orden = ruta_vecino_mas_cercano($dur, $nodos);
        $orden = ruta_dos_opt($orden, $dur);
        $ventanas = [];
        foreach ($paradas as $i => $p) {
            if (!empty($p['ventana_fin'])) {
                $ventanas[$i + 1] = strtotime($p['ventana_fin'] . ' UTC');
            }
        }
        if ($ventanas) {
            $orden = ruta_ajustar_ventanas($orden, $dur, $inicioTs, $minParada, $ventanas);
        }
    } else {
        $orden = $nodos;
    }

    $llegadasNodo = ruta_simular($orden, $dur, $inicioTs, $minParada);
    $distancia = 0.0;
    $prev = 0;
    $salida = [];
    $ptsOrden = [[(float) $origen['lat'], (float) $origen['lng']]];
    foreach ($orden as $nodo) {
        $distancia += $dist[$prev][$nodo];
        $prev = $nodo;
        $salida[] = $nodo - 1;
        $ptsOrden[] = [$puntos[$nodo]['lat'], $puntos[$nodo]['lng']];
    }
    $llegadas = [];
    foreach ($llegadasNodo as $nodo => $ts) {
        $llegadas[$nodo - 1] = $ts;
    }
    $ultimo = end($orden);
    return [
        'orden'       => $salida,
        'llegadas'    => $llegadas,
        'distancia_m' => $distancia,
        'duracion_s'  => $llegadasNodo[$ultimo] - $inicioTs + $minParada * 60,
        'puntos'      => $ptsOrden,
    ];
}

/** Geometría sugerida: M7 siguiendo calles, o línea recta. */
function ruta_polyline(array $puntos): array
{
    if (function_exists('ruta_geometria')) {
        try {
            $g = ruta_geometria($puntos);
            if (is_array($g) && count($g) >= 2) {
                return $g;
            }
        } catch (Throwable $e) {
            log_app('warning', 'ruta_geometria falló: ' . $e->getMessage());
        }
    }
    return array_map(static fn($p) => [(float) $p[0], (float) $p[1]], $puntos);
}

/* ====================================================================
 * Reparto de paquetes entre conductores (7.1 pasos 3 y 4)
 * ==================================================================== */

/**
 * Reparte los paquetes de un grupo entre un conjunto de conductores.
 *
 * @param array $paquetes   Filas de paquetes_para_ruta()
 * @param array $conductores Filas ['conductor_id','vehiculo_id','capacidad_kg','capacidad_m3']
 * @param array $hub        ['lat','lng']
 * @return array ['rutas' => [['conductor' => fila, 'paquetes' => [...], 'kg', 'm3']], 'sin_asignar' => [['paquete' => fila, 'motivo' => ...]]]
 */
function ruta_repartir(array $paquetes, array $conductores, array $hub): array
{
    $sinAsignar = [];
    if (!$paquetes) {
        return ['rutas' => [], 'sin_asignar' => []];
    }
    if (!$conductores) {
        foreach ($paquetes as $p) {
            $sinAsignar[] = ['paquete' => $p, 'motivo' => 'sin_conductor_disponible'];
        }
        return ['rutas' => [], 'sin_asignar' => $sinAsignar];
    }

    // Urgentes primero y por ventana_fin ascendente (sin ventana al final).
    usort($paquetes, static function ($a, $b) {
        $ua = $a['prioridad'] === 'urgente' ? 0 : 1;
        $ub = $b['prioridad'] === 'urgente' ? 0 : 1;
        if ($ua !== $ub) {
            return $ua <=> $ub;
        }
        $va = $a['ventana_fin'] ?? '9999-12-31 23:59:59';
        $vb = $b['ventana_fin'] ?? '9999-12-31 23:59:59';
        return $va <=> $vb ?: $a['id'] <=> $b['id'];
    });

    // Un paquete que no cabe en NINGÚN vehículo disponible: excede_capacidad.
    $restantes = [];
    foreach ($paquetes as $p) {
        $cabe = false;
        foreach ($conductores as $c) {
            if ($p['peso_kg'] <= (float) $c['capacidad_kg'] && $p['volumen_m3'] <= (float) $c['capacidad_m3']) {
                $cabe = true;
                break;
            }
        }
        if ($cabe) {
            $restantes[] = $p;
        } else {
            $sinAsignar[] = ['paquete' => $p, 'motivo' => 'excede_capacidad'];
        }
    }

    $maxParadas = max(1, (int) config_get('ruta_max_paradas', 40));
    $disponibles = array_values($conductores);
    $rutas = [];
    while ($restantes && $disponibles) {
        usort($disponibles, static fn($a, $b) => [(float) $b['capacidad_kg'], (float) $b['capacidad_m3']] <=> [(float) $a['capacidad_kg'], (float) $a['capacidad_m3']]);
        $c = array_shift($disponibles);
        $ruta = ['conductor' => $c, 'paquetes' => [], 'kg' => 0.0, 'm3' => 0.0];
        $ultimo = ['lat' => (float) $hub['lat'], 'lng' => (float) $hub['lng']];
        while (count($ruta['paquetes']) < $maxParadas) {
            $mejor = null;
            $mejorD = INF;
            foreach ($restantes as $k => $p) {
                if ($ruta['kg'] + $p['peso_kg'] <= (float) $c['capacidad_kg'] && $ruta['m3'] + $p['volumen_m3'] <= (float) $c['capacidad_m3']) {
                    $d = geo_distancia_m($ultimo['lat'], $ultimo['lng'], $p['lat'], $p['lng']);
                    if ($d < $mejorD) {
                        $mejorD = $d;
                        $mejor = $k;
                    }
                }
            }
            if ($mejor === null) {
                break;
            }
            $p = $restantes[$mejor];
            $ruta['paquetes'][] = $p;
            $ruta['kg'] += $p['peso_kg'];
            $ruta['m3'] += $p['volumen_m3'];
            $ultimo = ['lat' => $p['lat'], 'lng' => $p['lng']];
            array_splice($restantes, $mejor, 1);
        }
        if ($ruta['paquetes']) {
            $rutas[] = $ruta;
        }
    }
    foreach ($restantes as $p) {
        $sinAsignar[] = ['paquete' => $p, 'motivo' => 'sin_conductor_disponible'];
    }
    return ['rutas' => $rutas, 'sin_asignar' => $sinAsignar];
}

/* ====================================================================
 * Generación
 * ==================================================================== */

/** Convierte un instante ISO/UTC a timestamp o lanza VALIDACION. */
function ruta_parsear_instante(?string $valor, string $campo): int
{
    try {
        return (new DateTime((string) $valor, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        error_validacion([$campo => 'Fecha y hora no válida.']);
    }
}

/** Siguiente código R-AAMMDD-NN del día de la ruta (dentro de una transacción). */
function ruta_siguiente_codigo(string $fecha): string
{
    $prefijo = 'R-' . date('ymd', strtotime($fecha)) . '-';
    $max = (int) db_val('SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 10) AS UNSIGNED)), 0) FROM rutas WHERE codigo LIKE ?', 's', [$prefijo . '%']);
    return $prefijo . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
}

/**
 * Genera rutas en borrador agrupando por zona, repartiendo por capacidad y ordenando con vecino más cercano + 2-opt + ventanas.
 * No cambia el estado de ningún paquete.
 *
 * @param array $opciones fecha, hub_id, zona_id?, paquete_ids?, conductor_ids?, hora_inicio?, usuario_id
 * @return array ['rutas' => [ruta...], 'sin_asignar' => [['paquete_id','codigo_guia','motivo']], 'sin_coordenadas' => [paquete_id]]
 */
function ruta_generar(array $opciones): array
{
    $fecha = (string) ($opciones['fecha'] ?? gmdate('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
        error_validacion(['fecha' => 'Fecha no válida.']);
    }
    $hubId = (int) ($opciones['hub_id'] ?? 0);
    $hub = $hubId > 0 ? hub_obtener($hubId) : null;
    if (!$hub || !(int) $hub['activo']) {
        error_validacion(['hub_id' => 'Selecciona un HUB activo.']);
    }
    $zonaId = isset($opciones['zona_id']) && $opciones['zona_id'] !== '' ? (int) $opciones['zona_id'] : null;
    $paqueteIds = isset($opciones['paquete_ids']) && is_array($opciones['paquete_ids']) ? $opciones['paquete_ids'] : null;
    $conductorIds = isset($opciones['conductor_ids']) && is_array($opciones['conductor_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $opciones['conductor_ids']), static fn($v) => $v > 0))) : null;
    $usuarioId = isset($opciones['usuario_id']) ? (int) $opciones['usuario_id'] : null;
    $inicioTs = !empty($opciones['hora_inicio']) ? ruta_parsear_instante((string) $opciones['hora_inicio'], 'hora_inicio') : time() + 1800;

    // 1. Candidatos y sin coordenadas
    $candidatos = paquetes_para_ruta($hubId, $zonaId, $fecha, $paqueteIds);
    $sql = "SELECT id FROM paquetes WHERE estado = 'en_clasificacion' AND hub_id = ? AND (lat IS NULL OR lng IS NULL)";
    $tipos = 'i';
    $params = [$hubId];
    if ($zonaId !== null) {
        $sql .= ' AND zona_id = ?';
        $tipos .= 'i';
        $params[] = $zonaId;
    }
    if ($paqueteIds !== null) {
        $ids = array_values(array_unique(array_map('intval', $paqueteIds)));
        if ($ids) {
            $sql .= ' AND id IN (' . db_in($ids) . ')';
            $tipos .= str_repeat('i', count($ids));
            $params = array_merge($params, $ids);
        } else {
            $sql .= ' AND 1 = 0';
        }
    }
    $sinCoordenadas = array_map('intval', array_column(db_all($sql, $tipos, $params), 'id'));

    // 2. Conductores disponibles
    $sqlC = "SELECT c.id AS conductor_id, c.vehiculo_id, v.capacidad_kg, v.capacidad_m3
             FROM conductores c
             JOIN usuarios u ON u.id = c.usuario_id AND u.activo = 1
             JOIN vehiculos v ON v.id = c.vehiculo_id AND v.activo = 1
             WHERE c.estado <> 'inactivo'
               AND NOT EXISTS (SELECT 1 FROM rutas r WHERE r.conductor_id = c.id AND r.fecha = ? AND r.estado IN ('borrador','planificada','en_curso'))";
    $tiposC = 's';
    $paramsC = [$fecha];
    if ($conductorIds !== null) {
        if (!$conductorIds) {
            $sqlC .= ' AND 1 = 0';
        } else {
            $sqlC .= ' AND c.id IN (' . db_in($conductorIds) . ')';
            $tiposC .= str_repeat('i', count($conductorIds));
            $paramsC = array_merge($paramsC, $conductorIds);
        }
    }
    $conductores = [];
    foreach (db_all($sqlC . ' ORDER BY c.id', $tiposC, $paramsC) as $c) {
        $c['conductor_id'] = (int) $c['conductor_id'];
        $conductores[$c['conductor_id']] = $c;
    }
    $zonasDeConductor = [];
    $zonasConAsignados = [];
    foreach (db_all('SELECT zona_id, conductor_id FROM zona_conductor') as $r) {
        $zonasConAsignados[(int) $r['zona_id']] = true;
        $zonasDeConductor[(int) $r['zona_id']][] = (int) $r['conductor_id'];
    }

    // 3. Agrupar por zona
    $grupos = [];
    foreach ($candidatos as $p) {
        $grupos[$p['zona_id'] ?? 0][] = $p;
    }

    // 4. Repartir: primero zonas con conductores asignados, luego el resto con los conductores que sobren.
    $planes = [];
    $sinAsignar = [];
    foreach ($grupos as $zid => $pqs) {
        if ($zid === 0 || !isset($zonasConAsignados[$zid])) {
            continue;
        }
        $pool = [];
        foreach ($zonasDeConductor[$zid] as $cid) {
            if (isset($conductores[$cid])) {
                $pool[] = $conductores[$cid];
            }
        }
        $r = ruta_repartir($pqs, $pool, $hub);
        foreach ($r['rutas'] as $ruta) {
            $ruta['zona_id'] = $zid;
            $planes[] = $ruta;
            unset($conductores[$ruta['conductor']['conductor_id']]);
        }
        $sinAsignar = array_merge($sinAsignar, $r['sin_asignar']);
    }
    foreach ($grupos as $zid => $pqs) {
        if ($zid !== 0 && isset($zonasConAsignados[$zid])) {
            continue;
        }
        $r = ruta_repartir($pqs, array_values($conductores), $hub);
        foreach ($r['rutas'] as $ruta) {
            $ruta['zona_id'] = $zid !== 0 ? $zid : null;
            $planes[] = $ruta;
            unset($conductores[$ruta['conductor']['conductor_id']]);
        }
        $sinAsignar = array_merge($sinAsignar, $r['sin_asignar']);
    }

    // 5. Ordenar paradas de cada ruta (fuera de la transacción: la matriz puede tardar)
    foreach ($planes as &$plan) {
        $plan['calculo'] = ruta_ordenar(['lat' => $hub['lat'], 'lng' => $hub['lng']], $plan['paquetes'], $inicioTs, true);
        $plan['polyline'] = ruta_polyline($plan['calculo']['puntos']);
    }
    unset($plan);

    // 7. Persistir todo en una transacción
    $rutaIds = [];
    if ($planes) {
        for ($reintento = 1; $reintento <= 3; $reintento++) {
            try {
                $rutaIds = db_tx(function () use ($planes, $fecha, $hubId, $usuarioId) {
                    $ids = [];
                    foreach ($planes as $plan) {
                        $calc = $plan['calculo'];
                        $rid = db_insert(
                            'INSERT INTO rutas (codigo, fecha, hub_id, conductor_id, vehiculo_id, zona_id, estado, total_paquetes, carga_kg, carga_m3,
                                distancia_km_estimada, duracion_min_estimada, polyline_sugerida, created_by)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                            'ssiiiisidddisi',
                            [
                                ruta_siguiente_codigo($fecha), $fecha, $hubId, $plan['conductor']['conductor_id'], $plan['conductor']['vehiculo_id'],
                                $plan['zona_id'], 'borrador', count($plan['paquetes']), round($plan['kg'], 2), round($plan['m3'], 3),
                                round($calc['distancia_m'] / 1000, 2), (int) round($calc['duracion_s'] / 60), json_encode($plan['polyline']), $usuarioId,
                            ]
                        );
                        foreach ($calc['orden'] as $pos => $idx) {
                            $p = $plan['paquetes'][$idx];
                            $intentos = (int) db_val('SELECT intentos FROM paquetes WHERE id = ?', 'i', [$p['id']]);
                            db_insert(
                                'INSERT INTO paradas (ruta_id, paquete_id, orden, estado, lat, lng, eta, eta_actualizado_at, intento_num)
                                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)',
                                'iiisddsi',
                                [$rid, $p['id'], $pos + 1, 'pendiente', $p['lat'], $p['lng'], gmdate('Y-m-d H:i:s', $calc['llegadas'][$idx]), $intentos + 1]
                            );
                        }
                        audit('ruta_generar', 'ruta', $rid, ['paquetes' => count($plan['paquetes']), 'conductor_id' => $plan['conductor']['conductor_id']]);
                        $ids[] = $rid;
                    }
                    return $ids;
                });
                break;
            } catch (AppError $e) {
                // Código de ruta repetido por dos generaciones simultáneas: se reintenta con el siguiente número.
                if (paquete_error_duplicado($e) === null || $reintento === 3) {
                    throw $e;
                }
            }
        }
    }

    $rutas = [];
    foreach ($rutaIds as $rid) {
        $rutas[] = ruta_obtener($rid);
    }
    $salidaSin = [];
    foreach ($sinAsignar as $s) {
        $salidaSin[] = ['paquete_id' => (int) $s['paquete']['id'], 'codigo_guia' => $s['paquete']['codigo_guia'], 'motivo' => $s['motivo']];
    }
    foreach ($sinCoordenadas as $pid) {
        $salidaSin[] = ['paquete_id' => $pid, 'codigo_guia' => (string) db_val('SELECT codigo_guia FROM paquetes WHERE id = ?', 'i', [$pid]), 'motivo' => 'sin_coordenadas'];
    }
    return ['rutas' => $rutas, 'sin_asignar' => $salidaSin, 'sin_coordenadas' => $sinCoordenadas];
}

/* ====================================================================
 * Lectura
 * ==================================================================== */

/** Ruta completa: paradas con paquete, conductor, vehículo, polyline y resumen. */
function ruta_obtener(int $rutaId): ?array
{
    $r = db_one(
        'SELECT r.*, h.nombre AS hub_nombre, h.lat AS hub_lat, h.lng AS hub_lng, z.nombre AS zona_nombre
         FROM rutas r JOIN hubs h ON h.id = r.hub_id LEFT JOIN zonas z ON z.id = r.zona_id WHERE r.id = ?',
        'i',
        [$rutaId]
    );
    if (!$r) {
        return null;
    }
    $paradas = db_all(
        'SELECT pa.id, pa.paquete_id, pa.orden, pa.estado, pa.lat, pa.lng, pa.eta, pa.llegada_real, pa.salida_real, pa.intento_num,
                p.codigo_guia, p.destinatario_nombre, p.direccion_entrega, p.peso_kg, p.volumen_m3, p.prioridad, p.ventana_inicio, p.ventana_fin
         FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? ORDER BY pa.orden, pa.id',
        'i',
        [$rutaId]
    );
    $resueltas = 0;
    foreach ($paradas as &$pa) {
        $abierta = in_array($pa['estado'], PARADA_ESTADOS_ABIERTOS, true);
        $enRiesgo = $abierta && $pa['eta'] !== null && $pa['ventana_fin'] !== null && $pa['eta'] > $pa['ventana_fin'];
        if (in_array($pa['estado'], PARADA_ESTADOS_RESUELTOS, true)) {
            $resueltas++;
        }
        $pa['paquete'] = fechas_fila_iso([
            'id' => (int) $pa['paquete_id'], 'codigo_guia' => $pa['codigo_guia'], 'destinatario_nombre' => $pa['destinatario_nombre'],
            'direccion_entrega' => $pa['direccion_entrega'], 'peso_kg' => (float) $pa['peso_kg'], 'volumen_m3' => (float) $pa['volumen_m3'],
            'prioridad' => $pa['prioridad'], 'ventana_inicio' => $pa['ventana_inicio'], 'ventana_fin' => $pa['ventana_fin'],
        ], ['ventana_inicio', 'ventana_fin']);
        foreach (['codigo_guia', 'destinatario_nombre', 'direccion_entrega', 'peso_kg', 'volumen_m3', 'prioridad', 'ventana_inicio', 'ventana_fin'] as $c) {
            unset($pa[$c]);
        }
        $pa['id'] = (int) $pa['id'];
        $pa['paquete_id'] = (int) $pa['paquete_id'];
        $pa['orden'] = (int) $pa['orden'];
        $pa['lat'] = (float) $pa['lat'];
        $pa['lng'] = (float) $pa['lng'];
        $pa['en_riesgo'] = $enRiesgo;
        $pa = fechas_fila_iso($pa, ['eta', 'llegada_real', 'salida_real']);
    }
    unset($pa);

    $conductor = $r['conductor_id'] ? conductor_obtener((int) $r['conductor_id']) : null;
    $vehiculo = $r['vehiculo_id'] ? vehiculo_obtener((int) $r['vehiculo_id']) : null;
    $polyline = $r['polyline_sugerida'] ? json_decode($r['polyline_sugerida'], true) : null;

    $ruta = [
        'id' => (int) $r['id'], 'codigo' => $r['codigo'], 'fecha' => $r['fecha'], 'estado' => $r['estado'],
        'hub_id' => (int) $r['hub_id'], 'hub_nombre' => $r['hub_nombre'], 'hub_lat' => (float) $r['hub_lat'], 'hub_lng' => (float) $r['hub_lng'],
        'conductor_id' => $r['conductor_id'] !== null ? (int) $r['conductor_id'] : null,
        'vehiculo_id' => $r['vehiculo_id'] !== null ? (int) $r['vehiculo_id'] : null,
        'zona_id' => $r['zona_id'] !== null ? (int) $r['zona_id'] : null, 'zona_nombre' => $r['zona_nombre'],
        'inicio_real' => $r['inicio_real'], 'fin_real' => $r['fin_real'], 'distancia_km_real' => $r['distancia_km_real'] !== null ? (float) $r['distancia_km_real'] : null,
        'conductor' => $conductor ? ['id' => $conductor['id'], 'nombre' => $conductor['usuario']['nombre'], 'telefono' => $conductor['usuario']['telefono'], 'estado' => $conductor['estado']] : null,
        'vehiculo' => $vehiculo ? ['id' => $vehiculo['id'], 'placa' => $vehiculo['placa'], 'tipo' => $vehiculo['tipo'], 'marca_modelo' => $vehiculo['marca_modelo']] : null,
        'paradas' => $paradas,
        'polyline_sugerida' => $polyline,
        'resumen' => [
            'total_paquetes' => (int) $r['total_paquetes'], 'resueltas' => $resueltas,
            'carga_kg' => (float) $r['carga_kg'], 'carga_m3' => (float) $r['carga_m3'],
            'capacidad_kg' => $vehiculo ? $vehiculo['capacidad_kg'] : null, 'capacidad_m3' => $vehiculo ? $vehiculo['capacidad_m3'] : null,
            'distancia_km_estimada' => $r['distancia_km_estimada'] !== null ? (float) $r['distancia_km_estimada'] : null,
            'duracion_min_estimada' => $r['duracion_min_estimada'] !== null ? (int) $r['duracion_min_estimada'] : null,
        ],
    ];
    return fechas_fila_iso($ruta, ['inicio_real', 'fin_real']);
}

/** @return array ['filas' => [...], 'meta' => [...]] */
function ruta_listar(array $in): array
{
    $f = validar($in, [
        'fecha' => 'opcional|fecha', 'desde' => 'opcional|fecha', 'hasta' => 'opcional|fecha',
        'conductor_id' => 'opcional|int|min:1', 'zona_id' => 'opcional|int|min:1', 'hub_id' => 'opcional|int|min:1',
    ]);
    $estados = paquete_lista_csv($in['estado'] ?? null, ['borrador', 'planificada', 'en_curso', 'completada', 'cancelada']);
    [$page, $porPagina, $offset] = paginacion($in);
    $where = [];
    $tipos = '';
    $params = [];
    if ($f['fecha'] !== null) {
        $where[] = 'r.fecha = ?';
        $tipos .= 's';
        $params[] = $f['fecha'];
    }
    if ($f['desde'] !== null) {
        $where[] = 'r.fecha >= ?';
        $tipos .= 's';
        $params[] = $f['desde'];
    }
    if ($f['hasta'] !== null) {
        $where[] = 'r.fecha <= ?';
        $tipos .= 's';
        $params[] = $f['hasta'];
    }
    if ($estados) {
        $where[] = 'r.estado IN (' . db_in($estados) . ')';
        $tipos .= str_repeat('s', count($estados));
        $params = array_merge($params, $estados);
    }
    foreach (['conductor_id' => 'r.conductor_id', 'zona_id' => 'r.zona_id', 'hub_id' => 'r.hub_id'] as $campo => $col) {
        if ($f[$campo] !== null) {
            $where[] = $col . ' = ?';
            $tipos .= 'i';
            $params[] = (int) $f[$campo];
        }
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int) db_val('SELECT COUNT(*) FROM rutas r ' . $sqlWhere, $tipos, $params);
    $filas = db_all(
        "SELECT r.id, r.codigo, r.fecha, r.estado, r.hub_id, h.nombre AS hub_nombre, r.conductor_id, u.nombre AS conductor_nombre,
                v.placa AS vehiculo_placa, r.zona_id, z.nombre AS zona_nombre, r.total_paquetes, r.carga_kg, r.carga_m3,
                r.distancia_km_estimada, r.duracion_min_estimada,
                (SELECT COUNT(*) FROM paradas pa WHERE pa.ruta_id = r.id AND pa.estado IN ('entregada','fallida','omitida')) AS resueltas
         FROM rutas r
         JOIN hubs h ON h.id = r.hub_id
         LEFT JOIN conductores c ON c.id = r.conductor_id
         LEFT JOIN usuarios u ON u.id = c.usuario_id
         LEFT JOIN vehiculos v ON v.id = r.vehiculo_id
         LEFT JOIN zonas z ON z.id = r.zona_id " . $sqlWhere . '
         ORDER BY r.fecha DESC, r.id DESC LIMIT ? OFFSET ?',
        $tipos . 'ii',
        array_merge($params, [$porPagina, $offset])
    );
    foreach ($filas as &$fila) {
        foreach (['id', 'hub_id', 'total_paquetes', 'resueltas'] as $c) {
            $fila[$c] = (int) $fila[$c];
        }
        foreach (['carga_kg', 'carga_m3', 'distancia_km_estimada'] as $c) {
            $fila[$c] = $fila[$c] !== null ? (float) $fila[$c] : null;
        }
    }
    unset($fila);
    return ['filas' => $filas, 'meta' => ['page' => $page, 'por_pagina' => $porPagina, 'total' => $total, 'paginas' => (int) ceil($total / max(1, $porPagina))]];
}

/** La ruta en_curso del conductor o, si no hay, la planificada más próxima desde hoy. */
function ruta_activa_de_conductor(int $conductorId): ?array
{
    $id = db_val("SELECT id FROM rutas WHERE conductor_id = ? AND estado = 'en_curso' ORDER BY id DESC LIMIT 1", 'i', [$conductorId]);
    if ($id === null) {
        $id = db_val(
            "SELECT id FROM rutas WHERE conductor_id = ? AND estado = 'planificada' AND fecha >= ? ORDER BY fecha ASC, id ASC LIMIT 1",
            'is',
            [$conductorId, gmdate('Y-m-d')]
        );
    }
    return $id !== null ? ruta_obtener((int) $id) : null;
}

/** Parada en_camino actual de la ruta. */
function parada_siguiente(int $rutaId): ?array
{
    $pa = db_one(
        "SELECT pa.id, pa.paquete_id, pa.orden, pa.estado, pa.lat, pa.lng, pa.eta, p.codigo_guia, p.destinatario_nombre, p.direccion_entrega
         FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? AND pa.estado = 'en_camino' ORDER BY pa.orden LIMIT 1",
        'i',
        [$rutaId]
    );
    return $pa ? fechas_fila_iso($pa, ['eta']) : null;
}

/* ====================================================================
 * Totales y reprogramación de paradas abiertas
 * ==================================================================== */

/** Recalcula total_paquetes, carga_kg y carga_m3 de la ruta (sin contar paradas omitidas). */
function ruta_actualizar_totales(int $rutaId): void
{
    $t = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(p.peso_kg), 0) AS kg, COALESCE(SUM(p.volumen_m3), 0) AS m3
         FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? AND pa.estado <> 'omitida'",
        'i',
        [$rutaId]
    );
    db_exec('UPDATE rutas SET total_paquetes = ?, carga_kg = ?, carga_m3 = ? WHERE id = ?', 'iddi', [(int) $t['n'], round((float) $t['kg'], 2), round((float) $t['m3'], 3), $rutaId]);
}

/** Numera de nuevo 1..N las paradas de la ruta conservando el orden actual. */
function ruta_renumerar(int $rutaId): void
{
    $ids = db_all('SELECT id FROM paradas WHERE ruta_id = ? ORDER BY orden, id', 'i', [$rutaId]);
    foreach ($ids as $i => $fila) {
        db_exec('UPDATE paradas SET orden = ? WHERE id = ?', 'ii', [$i + 1, (int) $fila['id']]);
    }
}

/** Comprueba capacidad y máximo de paradas. Lanza CONFLICTO. */
function ruta_validar_capacidad(float $kg, float $m3, ?array $vehiculo, int $paradas): void
{
    if ($vehiculo && ($kg > $vehiculo['capacidad_kg'] + 1e-6 || $m3 > $vehiculo['capacidad_m3'] + 1e-6)) {
        error_conflicto('La carga excede la capacidad del vehículo');
    }
    $max = (int) config_get('ruta_max_paradas', 40);
    if ($paradas > $max) {
        error_conflicto('La ruta supera el máximo de ' . $max . ' paradas.');
    }
}

/**
 * Recalcula orden, ETA y geometría de las paradas ABIERTAS (pendiente/en_camino) de una ruta.
 * Las resueltas y la que está "llegado" conservan su posición.
 *
 * @param bool       $optimizar     true = reoptimiza (vecino más cercano + 2-opt + ventanas); false = mantiene el orden
 * @param array      $evitar        círculos ['lat','lng','radio_m']: sus paradas pasan al final
 * @param array|null $ordenAbiertas lista de parada_id con el orden pedido (solo si !$optimizar)
 * @return array cambios[] ['parada_id','codigo_guia','orden_anterior','orden_nuevo']
 */
function ruta_reprogramar_paradas(int $rutaId, bool $optimizar, array $evitar = [], ?array $ordenAbiertas = null): array
{
    $ruta = db_one('SELECT * FROM rutas WHERE id = ?', 'i', [$rutaId]);
    $hub = hub_obtener((int) $ruta['hub_id']);
    $paradas = db_all(
        'SELECT pa.id, pa.orden, pa.estado, pa.lat, pa.lng, pa.eta, p.codigo_guia, p.ventana_fin
         FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? ORDER BY pa.orden, pa.id',
        'i',
        [$rutaId]
    );
    $enCurso = $ruta['estado'] === 'en_curso';

    $abiertas = [];
    $fijas = [];
    foreach ($paradas as $pa) {
        if (in_array($pa['estado'], PARADA_ESTADOS_ABIERTOS, true)) {
            $abiertas[$pa['id']] = $pa;
        } else {
            $fijas[] = $pa;
        }
    }
    if (!$abiertas) {
        return [];
    }

    // Punto de partida
    $origen = ['lat' => (float) $hub['lat'], 'lng' => (float) $hub['lng']];
    if ($enCurso) {
        $pos = null;
        if (function_exists('gps_ultima_posicion') && $ruta['conductor_id']) {
            try {
                $pos = gps_ultima_posicion((int) $ruta['conductor_id']);
            } catch (Throwable $e) {
                $pos = null;
            }
        }
        if (is_array($pos) && isset($pos['antiguedad_s']) && (int) $pos['antiguedad_s'] < 300) {
            $origen = ['lat' => (float) $pos['lat'], 'lng' => (float) $pos['lng']];
        } else {
            if ($fijas) {
                $ultima = end($fijas);
                $origen = ['lat' => (float) $ultima['lat'], 'lng' => (float) $ultima['lng']];
            }
        }
    }

    // Hora de salida
    $primera = reset($abiertas);
    if ($enCurso) {
        $inicioTs = time();
    } elseif ($primera['eta'] !== null) {
        $vel = max(5.0, (float) config_get('eta_velocidad_media_kmh', 25)) * 1000 / 3600;
        $tramo = (int) round(geo_distancia_m($origen['lat'], $origen['lng'], (float) $primera['lat'], (float) $primera['lng']) * 1.3 / $vel);
        $inicioTs = max(time(), strtotime($primera['eta'] . ' UTC') - $tramo);
    } else {
        $inicioTs = time() + 1800;
    }

    // Lista de trabajo (en el orden actual o en el pedido)
    $lista = array_values($abiertas);
    if (!$optimizar && $ordenAbiertas !== null) {
        $lista = [];
        foreach ($ordenAbiertas as $pid) {
            $lista[] = $abiertas[(int) $pid];
        }
    }

    // Paradas a evitar van al final
    $normales = [];
    $evitadas = [];
    foreach ($lista as $pa) {
        $dentro = false;
        foreach ($evitar as $c) {
            if (geo_distancia_m((float) $pa['lat'], (float) $pa['lng'], (float) $c['lat'], (float) $c['lng']) <= (float) $c['radio_m']) {
                $dentro = true;
                break;
            }
        }
        if ($dentro && $optimizar) {
            $evitadas[] = $pa;
        } else {
            $normales[] = $pa;
        }
    }

    $secuencia = [];
    $llegadas = [];
    $distanciaM = 0.0;
    $duracionS = 0;
    $puntos = [[$origen['lat'], $origen['lng']]];
    foreach ([$normales, $evitadas] as $grupo) {
        if (!$grupo) {
            continue;
        }
        $desde = $secuencia ? ['lat' => (float) end($secuencia)['lat'], 'lng' => (float) end($secuencia)['lng']] : $origen;
        $inicioGrupo = $llegadas ? (int) end($llegadas) + (int) config_get('eta_parada_default_min', 5) * 60 : $inicioTs;
        $calc = ruta_ordenar($desde, $grupo, $inicioGrupo, $optimizar);
        foreach ($calc['orden'] as $idx) {
            $secuencia[] = $grupo[$idx];
            $llegadas[$grupo[$idx]['id']] = $calc['llegadas'][$idx];
            $puntos[] = [(float) $grupo[$idx]['lat'], (float) $grupo[$idx]['lng']];
        }
        $distanciaM += $calc['distancia_m'];
        $duracionS += $calc['duracion_s'];
    }

    // Asignar los huecos (órdenes de las abiertas) respetando la posición de las resueltas
    $huecos = array_map(static fn($pa) => (int) $pa['orden'], array_values($abiertas));
    sort($huecos);
    $hayLlegado = false;
    foreach ($fijas as $f) {
        if ($f['estado'] === 'llegado') {
            $hayLlegado = true;
        }
    }
    $cambios = [];
    foreach ($secuencia as $i => $pa) {
        $nuevoOrden = $huecos[$i];
        $estado = $pa['estado'];
        if ($enCurso) {
            $estado = ($i === 0 && !$hayLlegado) ? 'en_camino' : 'pendiente';
        }
        db_exec(
            'UPDATE paradas SET orden = ?, estado = ?, eta = ?, eta_actualizado_at = UTC_TIMESTAMP() WHERE id = ?',
            'issi',
            [$nuevoOrden, $estado, gmdate('Y-m-d H:i:s', $llegadas[$pa['id']]), (int) $pa['id']]
        );
        if ($nuevoOrden !== (int) $pa['orden']) {
            $cambios[] = ['parada_id' => (int) $pa['id'], 'codigo_guia' => $pa['codigo_guia'], 'orden_anterior' => (int) $pa['orden'], 'orden_nuevo' => $nuevoOrden];
        }
    }

    // Geometría y métricas
    $polyline = json_encode(ruta_polyline($puntos));
    if ($enCurso) {
        db_exec('UPDATE rutas SET polyline_sugerida = ? WHERE id = ?', 'si', [$polyline, $rutaId]);
    } else {
        db_exec(
            'UPDATE rutas SET polyline_sugerida = ?, distancia_km_estimada = ?, duracion_min_estimada = ? WHERE id = ?',
            'sdii',
            [$polyline, round($distanciaM / 1000, 2), (int) round($duracionS / 60), $rutaId]
        );
    }
    ruta_actualizar_totales($rutaId);

    if ($enCurso && function_exists('eta_recalcular_ruta')) {
        try {
            eta_recalcular_ruta($rutaId, true);
        } catch (Throwable $e) {
            log_app('warning', 'eta_recalcular_ruta falló: ' . $e->getMessage());
        }
    }
    return $cambios;
}

/* ====================================================================
 * Ciclo de vida de la ruta
 * ==================================================================== */

/** Valida conductor y vehículo para una fecha; lanza VALIDACION / CONFLICTO. */
function ruta_validar_asignacion(int $rutaId, string $fecha, ?int $conductorId, ?int $vehiculoId): array
{
    if ($conductorId === null || $vehiculoId === null) {
        error_validacion(['conductor_id' => 'La ruta necesita un conductor y un vehículo activos.']);
    }
    $conductor = conductor_obtener($conductorId);
    if (!$conductor || $conductor['estado'] === 'inactivo' || !$conductor['usuario']['activo']) {
        error_validacion(['conductor_id' => 'El conductor no existe o está inactivo.']);
    }
    $vehiculo = vehiculo_obtener($vehiculoId);
    if (!$vehiculo || !$vehiculo['activo']) {
        error_validacion(['vehiculo_id' => 'El vehículo no existe o está inactivo.']);
    }
    $otra = db_val(
        "SELECT codigo FROM rutas WHERE conductor_id = ? AND fecha = ? AND id <> ? AND estado IN ('borrador','planificada','en_curso') LIMIT 1",
        'isi',
        [$conductorId, $fecha, $rutaId]
    );
    if ($otra !== null) {
        error_conflicto('El conductor ya tiene la ruta ' . $otra . ' para esa fecha.');
    }
    return [$conductor, $vehiculo];
}

/**
 * Asigna conductor y/o vehículo a una ruta borrador o planificada. En en_curso no se permite.
 *
 * @throws AppError ESTADO_INVALIDO, VALIDACION, CONFLICTO
 */
function ruta_reasignar(int $rutaId, ?int $conductorId, ?int $vehiculoId, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $conductorId, $vehiculoId, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if ($r['estado'] === 'en_curso') {
            error_estado('No se puede reasignar una ruta en curso.');
        }
        if (!in_array($r['estado'], ['borrador', 'planificada'], true)) {
            error_estado('Solo se puede reasignar una ruta en borrador o planificada.');
        }
        $nuevoConductor = $conductorId ?? ($r['conductor_id'] !== null ? (int) $r['conductor_id'] : null);
        $nuevoVehiculo = $vehiculoId;
        if ($nuevoVehiculo === null) {
            if ($conductorId !== null) {
                $c = conductor_obtener($conductorId);
                $nuevoVehiculo = $c && $c['vehiculo_id'] ? (int) $c['vehiculo_id'] : ($r['vehiculo_id'] !== null ? (int) $r['vehiculo_id'] : null);
            } else {
                $nuevoVehiculo = $r['vehiculo_id'] !== null ? (int) $r['vehiculo_id'] : null;
            }
        }
        [, $vehiculo] = ruta_validar_asignacion($rutaId, $r['fecha'], $nuevoConductor, $nuevoVehiculo);
        ruta_validar_capacidad((float) $r['carga_kg'], (float) $r['carga_m3'], $vehiculo, (int) $r['total_paquetes']);
        db_exec('UPDATE rutas SET conductor_id = ?, vehiculo_id = ? WHERE id = ?', 'iii', [$nuevoConductor, $nuevoVehiculo, $rutaId]);
        audit('ruta_reasignar', 'ruta', $rutaId, ['conductor_id' => $nuevoConductor, 'vehiculo_id' => $nuevoVehiculo]);
    });
    return ruta_obtener($rutaId);
}

/**
 * borrador → planificada. Valida todo de nuevo y pasa los paquetes a asignado_ruta.
 *
 * @throws AppError ESTADO_INVALIDO, VALIDACION, CONFLICTO
 */
function ruta_confirmar(int $rutaId, int $usuarioId): array
{
    db_tx(function () use ($rutaId, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if ($r['estado'] !== 'borrador') {
            error_estado('Solo se puede confirmar una ruta en borrador.');
        }
        [, $vehiculo] = ruta_validar_asignacion($rutaId, $r['fecha'], $r['conductor_id'] !== null ? (int) $r['conductor_id'] : null, $r['vehiculo_id'] !== null ? (int) $r['vehiculo_id'] : null);

        $paradas = db_all(
            "SELECT pa.id, pa.paquete_id, p.codigo_guia, p.estado AS estado_paquete, p.peso_kg, p.volumen_m3
             FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? AND pa.estado <> 'omitida' ORDER BY pa.orden FOR UPDATE",
            'i',
            [$rutaId]
        );
        if (!$paradas) {
            error_validacion(['ruta_id' => 'La ruta no tiene paradas.']);
        }
        $malos = [];
        $kg = 0.0;
        $m3 = 0.0;
        foreach ($paradas as $pa) {
            if ($pa['estado_paquete'] !== 'en_clasificacion') {
                $malos[] = $pa['codigo_guia'] . ' (' . paquete_texto_estado($pa['estado_paquete']) . ')';
            }
            $kg += (float) $pa['peso_kg'];
            $m3 += (float) $pa['volumen_m3'];
        }
        if ($malos) {
            error_conflicto('Estos paquetes ya no están «En clasificación»: ' . implode(', ', $malos) . '. Quítalos de la ruta y vuelve a confirmar.');
        }
        ruta_validar_capacidad($kg, $m3, $vehiculo, count($paradas));

        foreach ($paradas as $pa) {
            paquete_cambiar_estado((int) $pa['paquete_id'], 'asignado_ruta', $usuarioId, 'Ruta ' . $r['codigo']);
        }
        db_exec("UPDATE rutas SET estado = 'planificada' WHERE id = ?", 'i', [$rutaId]);
        audit('ruta_confirmar', 'ruta', $rutaId, ['codigo' => $r['codigo'], 'paquetes' => count($paradas)]);
    });
    return ruta_obtener($rutaId);
}

/**
 * planificada → en_curso (la llama M4). Paquetes a en_transito, primera parada en_camino, conductor en_ruta.
 *
 * @throws AppError SIN_PERMISO, ESTADO_INVALIDO, CONFLICTO
 */
function ruta_iniciar(int $rutaId, int $conductorId): array
{
    db_tx(function () use ($rutaId, $conductorId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if ((int) $r['conductor_id'] !== $conductorId) {
            error_sin_permiso();
        }
        if ($r['estado'] !== 'planificada') {
            error_estado('La ruta debe estar «Planificada» para iniciarla.');
        }
        $otra = db_val("SELECT codigo FROM rutas WHERE conductor_id = ? AND estado = 'en_curso' AND id <> ? LIMIT 1", 'ii', [$conductorId, $rutaId]);
        if ($otra !== null) {
            error_conflicto('Ya tienes la ruta ' . $otra . ' en curso.');
        }
        db_exec("UPDATE rutas SET estado = 'en_curso', inicio_real = UTC_TIMESTAMP() WHERE id = ?", 'i', [$rutaId]);
        $paradas = db_all("SELECT id, paquete_id FROM paradas WHERE ruta_id = ? AND estado = 'pendiente' ORDER BY orden, id", 'i', [$rutaId]);
        foreach ($paradas as $i => $pa) {
            paquete_cambiar_estado((int) $pa['paquete_id'], 'en_transito', null, 'Ruta ' . $r['codigo'] . ' iniciada');
            if ($i === 0) {
                db_exec("UPDATE paradas SET estado = 'en_camino' WHERE id = ?", 'i', [(int) $pa['id']]);
            }
        }
        db_exec("UPDATE conductores SET estado = 'en_ruta' WHERE id = ?", 'i', [$conductorId]);
        audit('ruta_iniciar', 'ruta', $rutaId, ['conductor_id' => $conductorId]);
    });
    if (function_exists('eta_recalcular_ruta')) {
        try {
            eta_recalcular_ruta($rutaId, true);
        } catch (Throwable $e) {
            log_app('warning', 'eta_recalcular_ruta falló: ' . $e->getMessage());
        }
    }
    return ruta_obtener($rutaId);
}

/**
 * en_curso → completada. Exige que no queden paradas pendiente/en_camino/llegado.
 *
 * @throws AppError ESTADO_INVALIDO, CONFLICTO
 */
function ruta_finalizar(int $rutaId, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if ($r['estado'] !== 'en_curso') {
            error_estado('Solo se puede finalizar una ruta en curso.');
        }
        $abiertas = (int) db_val("SELECT COUNT(*) FROM paradas WHERE ruta_id = ? AND estado IN ('pendiente','en_camino','llegado')", 'i', [$rutaId]);
        if ($abiertas > 0) {
            error_conflicto('Aún quedan ' . $abiertas . ' parada(s) sin resolver.');
        }
        $km = null;
        if (function_exists('gps_distancia_recorrida_km')) {
            try {
                $km = round((float) gps_distancia_recorrida_km($rutaId), 2);
            } catch (Throwable $e) {
                $km = null;
            }
        }
        db_exec("UPDATE rutas SET estado = 'completada', fin_real = UTC_TIMESTAMP(), distancia_km_real = ? WHERE id = ?", 'di', [$km, $rutaId]);
        if ($r['conductor_id'] !== null) {
            db_exec("UPDATE conductores SET estado = 'disponible' WHERE id = ? AND estado = 'en_ruta'", 'i', [(int) $r['conductor_id']]);
        }
        audit('ruta_finalizar', 'ruta', $rutaId, ['distancia_km_real' => $km]);
    });
    return ruta_obtener($rutaId);
}

/**
 * Cancela una ruta borrador o planificada; sus paquetes asignado_ruta vuelven a en_clasificacion.
 *
 * @throws AppError ESTADO_INVALIDO
 */
function ruta_cancelar(int $rutaId, ?string $motivo, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $motivo, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if (!in_array($r['estado'], ['borrador', 'planificada'], true)) {
            error_estado('Solo se puede cancelar una ruta en borrador o planificada.');
        }
        $paquetes = db_all(
            "SELECT p.id FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id WHERE pa.ruta_id = ? AND p.estado = 'asignado_ruta'",
            'i',
            [$rutaId]
        );
        foreach ($paquetes as $p) {
            paquete_cambiar_estado((int) $p['id'], 'en_clasificacion', $usuarioId, 'Ruta ' . $r['codigo'] . ' cancelada');
        }
        db_exec("UPDATE rutas SET estado = 'cancelada' WHERE id = ?", 'i', [$rutaId]);
        audit('ruta_cancelar', 'ruta', $rutaId, ['codigo' => $r['codigo'], 'motivo' => $motivo]);
    });
    return ruta_obtener($rutaId);
}

/**
 * Reenrutamiento dinámico (7.4). Solo rutas planificadas o en curso.
 *
 * @param array $opciones ['evitar' => [['lat','lng','radio_m'], ...]]
 * @return array La ruta actualizada + 'cambios' (paradas que cambiaron de posición)
 */
function ruta_recalcular(int $rutaId, string $motivo, ?int $usuarioId, array $opciones = []): array
{
    $r = db_one('SELECT estado FROM rutas WHERE id = ?', 'i', [$rutaId]);
    if (!$r) {
        error_no_encontrado('La ruta');
    }
    if (!in_array($r['estado'], ['planificada', 'en_curso'], true)) {
        error_estado('Solo se puede recalcular una ruta planificada o en curso.');
    }
    $evitar = [];
    foreach ($opciones['evitar'] ?? [] as $c) {
        if (is_array($c) && isset($c['lat'], $c['lng'], $c['radio_m']) && is_numeric($c['lat']) && is_numeric($c['lng']) && is_numeric($c['radio_m']) && $c['radio_m'] > 0) {
            $evitar[] = ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'radio_m' => (float) $c['radio_m']];
        }
    }
    $cambios = db_tx(function () use ($rutaId, $motivo, $usuarioId, $evitar) {
        db_exec('SELECT id FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        $cambios = ruta_reprogramar_paradas($rutaId, true, $evitar);
        audit('ruta_recalcular', 'ruta', $rutaId, ['motivo' => $motivo, 'cambios' => count($cambios), 'evitar' => count($evitar)]);
        return $cambios;
    });
    $ruta = ruta_obtener($rutaId);
    $ruta['cambios'] = $cambios;
    return $ruta;
}

/**
 * Cambia el orden de las paradas abiertas (borrador, planificada o en curso).
 *
 * @param int[] $orden parada_id de TODAS las paradas abiertas, en el nuevo orden
 * @throws AppError ESTADO_INVALIDO, VALIDACION
 */
function ruta_reordenar(int $rutaId, array $orden, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $orden, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if (!in_array($r['estado'], ['borrador', 'planificada', 'en_curso'], true)) {
            error_estado('No se puede reordenar una ruta ' . ($r['estado'] === 'completada' ? 'completada' : 'cancelada') . '.');
        }
        $orden = array_values(array_map('intval', $orden));
        $abiertas = array_map('intval', array_column(db_all("SELECT id FROM paradas WHERE ruta_id = ? AND estado IN ('pendiente','en_camino')", 'i', [$rutaId]), 'id'));
        $a = $orden;
        $b = $abiertas;
        sort($a);
        sort($b);
        if ($a !== $b || count(array_unique($orden)) !== count($orden)) {
            error_validacion(['orden' => 'La lista debe contener exactamente todas las paradas no resueltas, sin repetir.']);
        }
        ruta_reprogramar_paradas($rutaId, false, [], $orden);
        audit('ruta_reordenar', 'ruta', $rutaId, ['orden' => $orden]);
    });
    return ruta_obtener($rutaId);
}

/**
 * Agrega un paquete a una ruta borrador, planificada o en curso.
 *
 * @param int|null $posicion 1-based entre las paradas abiertas; null = inserción más barata
 * @throws AppError ESTADO_INVALIDO, CONFLICTO, VALIDACION
 */
function ruta_agregar_paquete(int $rutaId, int $paqueteId, ?int $posicion, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $paqueteId, $posicion, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if (!in_array($r['estado'], ['borrador', 'planificada', 'en_curso'], true)) {
            error_estado('No se pueden agregar paquetes a una ruta ' . ($r['estado'] === 'completada' ? 'completada' : 'cancelada') . '.');
        }
        $p = db_one('SELECT * FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);
        if (!$p) {
            error_no_encontrado('El paquete');
        }
        if ($p['estado'] !== 'en_clasificacion') {
            error_estado('El paquete debe estar «En clasificación» (está «' . paquete_texto_estado($p['estado']) . '»).');
        }
        if ($p['lat'] === null || $p['lng'] === null) {
            error_validacion(['paquete_id' => 'El paquete no tiene coordenadas.']);
        }
        if ((int) $p['hub_id'] !== (int) $r['hub_id']) {
            error_conflicto('El paquete pertenece a otro HUB.');
        }
        $enOtra = db_val(
            "SELECT r.codigo FROM paradas pa JOIN rutas r ON r.id = pa.ruta_id
             WHERE pa.paquete_id = ? AND r.estado IN ('borrador','planificada','en_curso') AND pa.estado <> 'omitida' LIMIT 1",
            'i',
            [$paqueteId]
        );
        if ($enOtra !== null) {
            error_conflicto('El paquete ya está en la ruta ' . $enOtra . '.');
        }
        $vehiculo = $r['vehiculo_id'] !== null ? vehiculo_obtener((int) $r['vehiculo_id']) : null;
        ruta_validar_capacidad((float) $r['carga_kg'] + (float) $p['peso_kg'], (float) $r['carga_m3'] + (float) $p['volumen_m3'], $vehiculo, (int) $r['total_paquetes'] + 1);

        $maxOrden = (int) db_val('SELECT COALESCE(MAX(orden), 0) FROM paradas WHERE ruta_id = ?', 'i', [$rutaId]);
        $paradaId = db_insert(
            'INSERT INTO paradas (ruta_id, paquete_id, orden, estado, lat, lng, intento_num) VALUES (?,?,?,?,?,?,?)',
            'iiisddi',
            [$rutaId, $paqueteId, $maxOrden + 1, 'pendiente', (float) $p['lat'], (float) $p['lng'], (int) $p['intentos'] + 1]
        );
        // Si la ruta ya estaba confirmada, el paquete queda asignado (y en tránsito si la ruta va en curso).
        if ($r['estado'] !== 'borrador') {
            paquete_cambiar_estado($paqueteId, 'asignado_ruta', $usuarioId, 'Agregado a la ruta ' . $r['codigo']);
        }
        if ($r['estado'] === 'en_curso') {
            paquete_cambiar_estado($paqueteId, 'en_transito', $usuarioId, 'Agregado a la ruta en curso ' . $r['codigo']);
        }

        // Posición: urgente en ruta en curso → reoptimizar; si no, la indicada o la inserción más barata
        $abiertas = db_all(
            "SELECT pa.id, pa.lat, pa.lng FROM paradas pa WHERE pa.ruta_id = ? AND pa.estado IN ('pendiente','en_camino') AND pa.id <> ? ORDER BY pa.orden, pa.id",
            'ii',
            [$rutaId, $paradaId]
        );
        if ($r['estado'] === 'en_curso' && $p['prioridad'] === 'urgente') {
            ruta_reprogramar_paradas($rutaId, true);
        } else {
            $hub = hub_obtener((int) $r['hub_id']);
            $k = count($abiertas);
            if ($posicion !== null) {
                $idx = max(0, min($k, $posicion - 1));
            } else {
                $idx = $k;
                $mejor = INF;
                for ($i = 0; $i <= $k; $i++) {
                    $prev = $i === 0 ? ['lat' => (float) $hub['lat'], 'lng' => (float) $hub['lng']] : ['lat' => (float) $abiertas[$i - 1]['lat'], 'lng' => (float) $abiertas[$i - 1]['lng']];
                    $dPrevNuevo = geo_distancia_m($prev['lat'], $prev['lng'], (float) $p['lat'], (float) $p['lng']);
                    if ($i < $k) {
                        $sig = ['lat' => (float) $abiertas[$i]['lat'], 'lng' => (float) $abiertas[$i]['lng']];
                        $costo = $dPrevNuevo + geo_distancia_m((float) $p['lat'], (float) $p['lng'], $sig['lat'], $sig['lng']) - geo_distancia_m($prev['lat'], $prev['lng'], $sig['lat'], $sig['lng']);
                    } else {
                        $costo = $dPrevNuevo;
                    }
                    if ($costo < $mejor) {
                        $mejor = $costo;
                        $idx = $i;
                    }
                }
            }
            $ids = array_map('intval', array_column($abiertas, 'id'));
            array_splice($ids, $idx, 0, [$paradaId]);
            ruta_reprogramar_paradas($rutaId, false, [], $ids);
        }
        audit('ruta_agregar_paquete', 'ruta', $rutaId, ['paquete_id' => $paqueteId, 'parada_id' => $paradaId]);
    });
    return ruta_obtener($rutaId);
}

/**
 * Quita un paquete de una ruta borrador o planificada (en planificada vuelve a en_clasificacion).
 *
 * @throws AppError ESTADO_INVALIDO, NO_ENCONTRADO
 */
function ruta_quitar_paquete(int $rutaId, int $paqueteId, ?int $usuarioId): void
{
    db_tx(function () use ($rutaId, $paqueteId, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if (!in_array($r['estado'], ['borrador', 'planificada'], true)) {
            error_estado('Solo se pueden quitar paquetes de una ruta en borrador o planificada.');
        }
        $pa = db_one('SELECT id, estado FROM paradas WHERE ruta_id = ? AND paquete_id = ?', 'ii', [$rutaId, $paqueteId]);
        if (!$pa) {
            error_no_encontrado('El paquete en esta ruta');
        }
        if ($r['estado'] === 'planificada') {
            $estadoPaquete = db_val('SELECT estado FROM paquetes WHERE id = ?', 'i', [$paqueteId]);
            if ($estadoPaquete === 'asignado_ruta') {
                paquete_cambiar_estado($paqueteId, 'en_clasificacion', $usuarioId, 'Quitado de la ruta ' . $r['codigo']);
            }
        }
        db_exec('DELETE FROM paradas WHERE id = ?', 'i', [(int) $pa['id']]);
        ruta_renumerar($rutaId);
        ruta_reprogramar_paradas($rutaId, false);
        audit('ruta_quitar_paquete', 'ruta', $rutaId, ['paquete_id' => $paqueteId]);
    });
}

/**
 * Omite una parada abierta de una ruta en curso: incidencia «otro» sin sumar intento y recálculo.
 *
 * @throws AppError ESTADO_INVALIDO
 */
function ruta_omitir_parada(int $rutaId, int $paradaId, ?string $motivo, ?int $usuarioId): array
{
    db_tx(function () use ($rutaId, $paradaId, $motivo, $usuarioId) {
        $r = db_one('SELECT * FROM rutas WHERE id = ? FOR UPDATE', 'i', [$rutaId]);
        if (!$r) {
            error_no_encontrado('La ruta');
        }
        if ($r['estado'] !== 'en_curso') {
            error_estado('Solo se pueden omitir paradas de una ruta en curso.');
        }
        $pa = db_one('SELECT * FROM paradas WHERE id = ? AND ruta_id = ? FOR UPDATE', 'ii', [$paradaId, $rutaId]);
        if (!$pa) {
            error_no_encontrado('La parada');
        }
        if (!in_array($pa['estado'], PARADA_ESTADOS_ABIERTOS, true)) {
            error_estado('Solo se pueden omitir paradas pendientes o en camino.');
        }
        incidencia_crear((int) $pa['paquete_id'], $paradaId, 'otro', $motivo !== null && $motivo !== '' ? $motivo : 'Parada omitida por despacho', null, $usuarioId, false);
        db_exec("UPDATE paradas SET estado = 'omitida', salida_real = UTC_TIMESTAMP() WHERE id = ?", 'i', [$paradaId]);
        ruta_reprogramar_paradas($rutaId, true);
        audit('ruta_omitir_parada', 'ruta', $rutaId, ['parada_id' => $paradaId, 'paquete_id' => (int) $pa['paquete_id'], 'motivo' => $motivo]);
    });
    return ruta_obtener($rutaId);
}

/* ====================================================================
 * Paradas (las usa M4 y M3)
 * ==================================================================== */

/**
 * Aplica un estado a una parada, guarda llegada/salida real y marca la siguiente como en_camino.
 *
 * @param array $extra ['ocurrido_at' => 'Y-m-d H:i:s' UTC] (por defecto, ahora)
 * @return array La parada
 * @throws AppError NO_ENCONTRADO, ESTADO_INVALIDO, VALIDACION
 */
function parada_actualizar_estado(int $paradaId, string $estado, array $extra = []): array
{
    if (!in_array($estado, ['pendiente', 'en_camino', 'llegado', 'entregada', 'fallida', 'omitida'], true)) {
        error_validacion(['estado' => 'Estado de parada no válido.']);
    }
    $ocurrido = $extra['ocurrido_at'] ?? fecha_utc_ahora();
    return db_tx(function () use ($paradaId, $estado, $ocurrido) {
        $pa = db_one('SELECT * FROM paradas WHERE id = ? FOR UPDATE', 'i', [$paradaId]);
        if (!$pa) {
            error_no_encontrado('La parada');
        }
        if ($pa['estado'] === $estado) {
            return $pa;
        }
        if (in_array($pa['estado'], PARADA_ESTADOS_RESUELTOS, true)) {
            error_estado('La parada ya está resuelta.');
        }
        if ($estado === 'llegado') {
            db_exec('UPDATE paradas SET estado = ?, llegada_real = ? WHERE id = ?', 'ssi', [$estado, $ocurrido, $paradaId]);
        } elseif (in_array($estado, PARADA_ESTADOS_RESUELTOS, true)) {
            db_exec('UPDATE paradas SET estado = ?, salida_real = ?, llegada_real = COALESCE(llegada_real, ?) WHERE id = ?', 'sssi', [$estado, $ocurrido, $ocurrido, $paradaId]);
        } else {
            db_exec('UPDATE paradas SET estado = ? WHERE id = ?', 'si', [$estado, $paradaId]);
        }
        if (in_array($estado, PARADA_ESTADOS_RESUELTOS, true)) {
            $hayEnCamino = (int) db_val("SELECT COUNT(*) FROM paradas WHERE ruta_id = ? AND estado IN ('en_camino','llegado')", 'i', [(int) $pa['ruta_id']]);
            if ($hayEnCamino === 0) {
                $sig = db_val("SELECT id FROM paradas WHERE ruta_id = ? AND estado = 'pendiente' ORDER BY orden, id LIMIT 1", 'i', [(int) $pa['ruta_id']]);
                if ($sig !== null) {
                    db_exec("UPDATE paradas SET estado = 'en_camino' WHERE id = ?", 'i', [(int) $sig]);
                }
            }
        }
        return db_one('SELECT * FROM paradas WHERE id = ?', 'i', [$paradaId]);
    });
}

/**
 * Paquete cancelado: omite su parada abierta y, si la ruta va en curso, recalcula. La llama paquete_cambiar_estado (M3).
 */
function parada_cancelar_por_paquete(int $paqueteId, ?int $usuarioId): void
{
    $pa = db_one(
        "SELECT pa.id, pa.ruta_id, r.estado AS ruta_estado FROM paradas pa JOIN rutas r ON r.id = pa.ruta_id
         WHERE pa.paquete_id = ? AND pa.estado IN ('pendiente','en_camino') AND r.estado IN ('borrador','planificada','en_curso')
         ORDER BY pa.id DESC LIMIT 1",
        'i',
        [$paqueteId]
    );
    if (!$pa) {
        return;
    }
    db_tx(function () use ($pa, $paqueteId, $usuarioId) {
        db_exec("UPDATE paradas SET estado = 'omitida', salida_real = UTC_TIMESTAMP() WHERE id = ?", 'i', [(int) $pa['id']]);
        ruta_actualizar_totales((int) $pa['ruta_id']);
        if ($pa['ruta_estado'] === 'en_curso') {
            ruta_reprogramar_paradas((int) $pa['ruta_id'], true);
        } else {
            ruta_reprogramar_paradas((int) $pa['ruta_id'], false);
        }
        audit('ruta_recalcular', 'ruta', (int) $pa['ruta_id'], ['motivo' => 'Paquete ' . $paqueteId . ' cancelado', 'parada_id' => (int) $pa['id']]);
    });
}
