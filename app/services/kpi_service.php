<?php
/**
 * RoutePulse - M6 Dashboard de analítica: servicio de indicadores (KPI).
 *
 * Solo lectura: este archivo nunca hace INSERT/UPDATE/DELETE.
 * Las definiciones exactas de cada indicador están en docs/modulos/M6-dashboard-analitica.md (sección 5).
 *
 * Periodo: `desde` y `hasta` (fechas inclusivas) se aplican a rutas.fecha. Cuenta una ruta
 * si su estado es `en_curso` o `completada`.
 * Filtros: zona_id (paquetes.zona_id), conductor_id (rutas.conductor_id), vehiculo_tipo (vehiculos.tipo).
 *
 * Funciones públicas:
 *   kpi_calcular, kpi_serie, kpi_ranking_conductores, kpi_por_zona, kpi_mapa_calor,
 *   kpi_exportar_tabla, kpi_csv, kpi_rango, kpi_filtros_normalizar.
 */

const KPI_RANGO_MAX_DIAS = 366;
const KPI_TIPOS_VEHICULO = ['furgon', 'motocicleta', 'vehiculo_electrico', 'bicicleta'];
const KPI_MAPA_MAX_PUNTOS = 5000;
const KPI_EXPORT_MAX_FILAS = 20000;
const KPI_CLAVE_TOTAL = 'total';

/** Tablas base: una fila por parada, con su ruta, su paquete y el vehículo de la ruta. */
const KPI_FROM = 'FROM paradas pa
    JOIN rutas r ON r.id = pa.ruta_id
    JOIN paquetes p ON p.id = pa.paquete_id
    LEFT JOIN vehiculos v ON v.id = r.vehiculo_id';

// ---------------------------------------------------------------------
// Utilidades de periodo, zona horaria y filtros
// ---------------------------------------------------------------------

/** Zona horaria con la que se MUESTRAN las fechas (la base de datos siempre es UTC). */
function kpi_zona_ui(): DateTimeZone
{
    try {
        return new DateTimeZone((string) config_get('zona_horaria_ui', 'UTC'));
    } catch (Throwable $e) {
        return new DateTimeZone('UTC');
    }
}

/** Fecha de hoy (AAAA-MM-DD) en la zona horaria de la interfaz. */
function kpi_hoy_local(): string
{
    return (new DateTimeImmutable('now', kpi_zona_ui()))->format('Y-m-d');
}

function kpi_es_fecha(?string $texto): bool
{
    if ($texto === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Valida y completa el rango de fechas.
 * Si faltan, el rango por defecto son los últimos 7 días (hoy incluido).
 *
 * @return array{0:string,1:string} [desde, hasta] en formato AAAA-MM-DD
 * @throws AppError VALIDACION si el formato es inválido, están invertidas o exceden 366 días
 */
function kpi_rango(?string $desde, ?string $hasta): array
{
    $desde = ($desde === '') ? null : $desde;
    $hasta = ($hasta === '') ? null : $hasta;

    $errores = [];
    if ($hasta !== null && !kpi_es_fecha($hasta)) {
        $errores['hasta'] = 'Fecha final inválida (use AAAA-MM-DD).';
    }
    if ($desde !== null && !kpi_es_fecha($desde)) {
        $errores['desde'] = 'Fecha inicial inválida (use AAAA-MM-DD).';
    }
    if ($errores) {
        error_validacion($errores);
    }

    $hasta = $hasta ?? kpi_hoy_local();
    $utc = new DateTimeZone('UTC');
    $desde = $desde ?? (new DateTimeImmutable($hasta, $utc))->modify('-6 days')->format('Y-m-d');

    $d1 = new DateTimeImmutable($desde, $utc);
    $d2 = new DateTimeImmutable($hasta, $utc);
    if ($d1 > $d2) {
        error_validacion(['desde' => 'La fecha inicial no puede ser posterior a la final.']);
    }
    $dias = (int) $d1->diff($d2)->format('%a') + 1;
    if ($dias > KPI_RANGO_MAX_DIAS) {
        error_validacion(['hasta' => 'El rango máximo es de ' . KPI_RANGO_MAX_DIAS . ' días.']);
    }
    return [$desde, $hasta];
}

/**
 * Límites del periodo en UTC para columnas DATETIME: [inicio, fin) donde fin es exclusivo.
 * Los días `desde` y `hasta` se interpretan en la zona horaria de la interfaz.
 *
 * @return array{0:string,1:string}
 */
function kpi_limites_utc(string $desde, string $hasta): array
{
    $tz = kpi_zona_ui();
    $utc = new DateTimeZone('UTC');
    $ini = (new DateTimeImmutable($desde . ' 00:00:00', $tz))->setTimezone($utc);
    $fin = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);
    return [$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
}

/** Fecha y hora UTC de la base de datos → texto `dd/mm/aaaa HH:mm` en la zona de la interfaz. */
function kpi_fecha_local(?string $fechaUtc): string
{
    if ($fechaUtc === null || $fechaUtc === '') {
        return '';
    }
    try {
        $f = new DateTimeImmutable($fechaUtc, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return '';
    }
    return $f->setTimezone(kpi_zona_ui())->format('d/m/Y H:i');
}

/**
 * Limpia y valida los filtros opcionales.
 *
 * @return array{zona_id:?int,conductor_id:?int,vehiculo_tipo:?string}
 */
function kpi_filtros_normalizar(array $f): array
{
    $errores = [];
    $zona = null;
    $conductor = null;
    $tipo = null;

    if (isset($f['zona_id']) && $f['zona_id'] !== '') {
        $zona = filter_var($f['zona_id'], FILTER_VALIDATE_INT);
        if ($zona === false || $zona < 1) {
            $errores['zona_id'] = 'Zona inválida.';
            $zona = null;
        }
    }
    if (isset($f['conductor_id']) && $f['conductor_id'] !== '') {
        $conductor = filter_var($f['conductor_id'], FILTER_VALIDATE_INT);
        if ($conductor === false || $conductor < 1) {
            $errores['conductor_id'] = 'Conductor inválido.';
            $conductor = null;
        }
    }
    if (isset($f['vehiculo_tipo']) && $f['vehiculo_tipo'] !== '') {
        if (!in_array($f['vehiculo_tipo'], KPI_TIPOS_VEHICULO, true)) {
            $errores['vehiculo_tipo'] = 'Tipo de vehículo inválido.';
        } else {
            $tipo = (string) $f['vehiculo_tipo'];
        }
    }
    if ($errores) {
        error_validacion($errores);
    }
    return ['zona_id' => $zona, 'conductor_id' => $conductor, 'vehiculo_tipo' => $tipo];
}

/**
 * Condiciones WHERE comunes (periodo + estado de ruta + filtros) sobre KPI_FROM.
 * Agrega los parámetros a $tipos y $params en el mismo orden en que aparecen los `?`.
 */
function kpi_condiciones(string $desde, string $hasta, array $f, string &$tipos, array &$params): string
{
    $w = "r.fecha BETWEEN ? AND ? AND r.estado IN ('en_curso','completada')";
    $tipos .= 'ss';
    $params[] = $desde;
    $params[] = $hasta;
    if ($f['zona_id'] !== null) {
        $w .= ' AND p.zona_id = ?';
        $tipos .= 'i';
        $params[] = $f['zona_id'];
    }
    if ($f['conductor_id'] !== null) {
        $w .= ' AND r.conductor_id = ?';
        $tipos .= 'i';
        $params[] = $f['conductor_id'];
    }
    if ($f['vehiculo_tipo'] !== null) {
        $w .= ' AND v.tipo = ?';
        $tipos .= 's';
        $params[] = $f['vehiculo_tipo'];
    }
    return $w;
}

/** Porcentaje con 1 decimal, o null si no hay denominador. */
function kpi_pct(float|int $numerador, float|int $denominador): ?float
{
    if ($denominador <= 0) {
        return null;
    }
    return round($numerador / $denominador * 100, 1);
}

/** Segundos → `m:ss` (por ejemplo 300 → "5:00"). */
function kpi_mmss(?int $segundos): ?string
{
    if ($segundos === null) {
        return null;
    }
    return sprintf('%d:%02d', intdiv($segundos, 60), $segundos % 60);
}

// ---------------------------------------------------------------------
// Consultas agrupadas
// ---------------------------------------------------------------------

/**
 * Despachados, entregas y tiempo de parada, opcionalmente agrupados.
 *
 * @param string|null $grupo null (todo junto) | 'dia' | 'conductor' | 'zona'
 * @return array<string,array> clave del grupo => contadores. Sin grupo, la clave es KPI_CLAVE_TOTAL.
 */
function kpi_agrupado(string $desde, string $hasta, array $f, ?string $grupo): array
{
    $expresiones = ['dia' => 'r.fecha', 'conductor' => 'r.conductor_id', 'zona' => 'p.zona_id'];
    if ($grupo !== null && !isset($expresiones[$grupo])) {
        throw new InvalidArgumentException('Agrupación no permitida.');
    }
    // La expresión sale de la lista fija de arriba, nunca de datos del usuario.
    $sel = $grupo === null ? "'" . KPI_CLAVE_TOTAL . "'" : $expresiones[$grupo];
    $groupBy = $grupo === null ? '' : 'GROUP BY ' . $expresiones[$grupo];

    $filas = [];

    // 1) Despachados, paradas resueltas/fallidas y tiempo de parada.
    $tipos = 'i';
    $params = [(int) config_get('dwell_max_s', 7200)];
    $where = kpi_condiciones($desde, $hasta, $f, $tipos, $params);
    $sql = "SELECT $sel AS k,
                COUNT(DISTINCT pa.paquete_id) AS despachados,
                SUM(CASE WHEN pa.estado = 'fallida' THEN 1 ELSE 0 END) AS paradas_fallidas,
                SUM(CASE WHEN pa.estado IN ('entregada','fallida') THEN 1 ELSE 0 END) AS paradas_resueltas,
                AVG(CASE WHEN pa.llegada_real IS NOT NULL AND pa.salida_real IS NOT NULL
                          AND TIMESTAMPDIFF(SECOND, pa.llegada_real, pa.salida_real) BETWEEN 0 AND ?
                         THEN TIMESTAMPDIFF(SECOND, pa.llegada_real, pa.salida_real) END) AS dwell_seg
            " . KPI_FROM . "
            WHERE $where
            $groupBy";
    foreach (db_all($sql, $tipos, $params) as $r) {
        $k = (string) ($r['k'] ?? '');
        $filas[$k] = kpi_fila_vacia();
        $filas[$k]['despachados'] = (int) $r['despachados'];
        $filas[$k]['paradas_fallidas'] = (int) $r['paradas_fallidas'];
        $filas[$k]['paradas_resueltas'] = (int) $r['paradas_resueltas'];
        $filas[$k]['dwell_seg'] = $r['dwell_seg'] === null ? null : (int) round((float) $r['dwell_seg']);
    }

    // 2) Entregas (un registro por intento) que pertenecen a paradas del periodo.
    $tipos = '';
    $params = [];
    $where = kpi_condiciones($desde, $hasta, $f, $tipos, $params);
    $sql = "SELECT $sel AS k,
                COUNT(DISTINCT CASE WHEN e.resultado = 'entregado' THEN e.paquete_id END) AS entregados,
                COUNT(DISTINCT CASE WHEN e.resultado = 'entregado' AND e.completa = 1
                                     AND (p.ventana_fin IS NULL OR e.ocurrido_at <= p.ventana_fin)
                                    THEN e.paquete_id END) AS otif_ok,
                SUM(CASE WHEN e.intento_num = 1 AND e.resultado = 'entregado' THEN 1 ELSE 0 END) AS ftdr_ok,
                SUM(CASE WHEN e.intento_num = 1 THEN 1 ELSE 0 END) AS ftdr_total
            " . KPI_FROM . "
            JOIN entregas e ON e.parada_id = pa.id
            WHERE $where
            $groupBy";
    foreach (db_all($sql, $tipos, $params) as $r) {
        $k = (string) ($r['k'] ?? '');
        $filas[$k] ??= kpi_fila_vacia();
        $filas[$k]['entregados'] = (int) $r['entregados'];
        $filas[$k]['otif_ok'] = (int) $r['otif_ok'];
        $filas[$k]['ftdr_ok'] = (int) $r['ftdr_ok'];
        $filas[$k]['ftdr_total'] = (int) $r['ftdr_total'];
    }

    return $filas;
}

function kpi_fila_vacia(): array
{
    return [
        'despachados' => 0, 'entregados' => 0, 'otif_ok' => 0,
        'ftdr_ok' => 0, 'ftdr_total' => 0, 'dwell_seg' => null,
        'paradas_fallidas' => 0, 'paradas_resueltas' => 0,
    ];
}

/** Subconsulta con los paquetes de las rutas del periodo y filtros (para contar incidencias). */
function kpi_subconsulta_paquetes(string $desde, string $hasta, array $f, string &$tipos, array &$params): string
{
    $where = kpi_condiciones($desde, $hasta, $f, $tipos, $params);
    return 'SELECT pa.paquete_id ' . KPI_FROM . ' WHERE ' . $where;
}

/**
 * Incidencias creadas en el periodo (por fecha de creación, en la zona de la interfaz)
 * con paquetes de las rutas filtradas.
 *
 * @param string|null $grupo null | 'zona'
 * @return array<string,int>
 */
function kpi_incidencias(string $desde, string $hasta, array $f, ?string $grupo): array
{
    [$ini, $fin] = kpi_limites_utc($desde, $hasta);
    $tipos = 'ss';
    $params = [$ini, $fin];
    $sub = kpi_subconsulta_paquetes($desde, $hasta, $f, $tipos, $params);
    $sel = $grupo === 'zona' ? 'pq.zona_id' : "'" . KPI_CLAVE_TOTAL . "'";
    $groupBy = $grupo === 'zona' ? 'GROUP BY pq.zona_id' : '';
    $sql = "SELECT $sel AS k, COUNT(*) AS n
            FROM incidencias i
            JOIN paquetes pq ON pq.id = i.paquete_id
            WHERE i.created_at >= ? AND i.created_at < ?
              AND i.paquete_id IN ($sub)
            $groupBy";
    $out = [];
    foreach (db_all($sql, $tipos, $params) as $r) {
        $out[(string) ($r['k'] ?? '')] = (int) $r['n'];
    }
    return $out;
}

/**
 * Incidencias por día de la zona horaria de la interfaz.
 * Se agrupa por hora UTC en SQL y se reparte en días locales en PHP (sin depender de CONVERT_TZ).
 *
 * @return array<string,int> 'AAAA-MM-DD' => cantidad
 */
function kpi_incidencias_por_dia(string $desde, string $hasta, array $f): array
{
    [$ini, $fin] = kpi_limites_utc($desde, $hasta);
    $tipos = 'ss';
    $params = [$ini, $fin];
    $sub = kpi_subconsulta_paquetes($desde, $hasta, $f, $tipos, $params);
    $sql = "SELECT SUBSTR(CAST(i.created_at AS CHAR), 1, 13) AS hora, COUNT(*) AS n
            FROM incidencias i
            WHERE i.created_at >= ? AND i.created_at < ?
              AND i.paquete_id IN ($sub)
            GROUP BY SUBSTR(CAST(i.created_at AS CHAR), 1, 13)";
    $tz = kpi_zona_ui();
    $utc = new DateTimeZone('UTC');
    $out = [];
    foreach (db_all($sql, $tipos, $params) as $r) {
        $dia = (new DateTimeImmutable($r['hora'] . ':00:00', $utc))->setTimezone($tz)->format('Y-m-d');
        $out[$dia] = ($out[$dia] ?? 0) + (int) $r['n'];
    }
    return $out;
}

/**
 * Kilómetros reales y costo operativo de las rutas COMPLETADAS del periodo.
 * Con filtro de zona cuentan las rutas que tengan al menos un paquete de esa zona.
 *
 * @param bool $porConductor true: agrupa por conductor
 * @return array<string,array{km:float,costo:float}>
 */
function kpi_costos(string $desde, string $hasta, array $f, bool $porConductor = false): array
{
    $tipos = 'ss';
    $params = [$desde, $hasta];
    $w = "r.fecha BETWEEN ? AND ? AND r.estado = 'completada'";
    if ($f['conductor_id'] !== null) {
        $w .= ' AND r.conductor_id = ?';
        $tipos .= 'i';
        $params[] = $f['conductor_id'];
    }
    if ($f['vehiculo_tipo'] !== null) {
        $w .= ' AND v.tipo = ?';
        $tipos .= 's';
        $params[] = $f['vehiculo_tipo'];
    }
    if ($f['zona_id'] !== null) {
        $w .= ' AND EXISTS (SELECT 1 FROM paradas pa JOIN paquetes p ON p.id = pa.paquete_id
                            WHERE pa.ruta_id = r.id AND p.zona_id = ?)';
        $tipos .= 'i';
        $params[] = $f['zona_id'];
    }
    $sel = $porConductor ? 'r.conductor_id' : "'" . KPI_CLAVE_TOTAL . "'";
    $groupBy = $porConductor ? 'GROUP BY r.conductor_id' : '';
    $sql = "SELECT $sel AS k,
                COALESCE(SUM(r.distancia_km_real), 0) AS km,
                COALESCE(SUM(r.distancia_km_real * v.costo_por_km), 0) AS costo
            FROM rutas r
            LEFT JOIN vehiculos v ON v.id = r.vehiculo_id
            WHERE $w
            $groupBy";
    $out = [];
    foreach (db_all($sql, $tipos, $params) as $r) {
        $out[(string) ($r['k'] ?? '')] = ['km' => (float) $r['km'], 'costo' => (float) $r['costo']];
    }
    return $out;
}

/** Convierte los contadores de un grupo en los indicadores publicados (porcentajes con 1 decimal). */
function kpi_indicadores(array $c): array
{
    return [
        'despachados' => $c['despachados'],
        'entregados' => $c['entregados'],
        'otif_pct' => kpi_pct($c['otif_ok'], $c['despachados']),
        'ftdr_pct' => kpi_pct($c['ftdr_ok'], $c['ftdr_total']),
        'dwell_seg' => $c['dwell_seg'],
    ];
}

// ---------------------------------------------------------------------
// Servicios públicos
// ---------------------------------------------------------------------

/**
 * Resumen de KPIs del periodo.
 *
 * @param string $desde AAAA-MM-DD (inclusive)
 * @param string $hasta AAAA-MM-DD (inclusive)
 * @param array  $filtros zona_id?, conductor_id?, vehiculo_tipo?
 * @return array periodo, despachados, entregados, otif_pct, ftdr_pct, dwell_seg, dwell_texto, incidencias,
 *               tasa_incidencias_pct, km_reales, costo_operativo, costo_por_km, costo_por_entrega, nivel_servicio_pct
 * @throws AppError VALIDACION si el rango o un filtro no son válidos
 */
function kpi_calcular(string $desde, string $hasta, array $filtros = []): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);

    $grupos = kpi_agrupado($desde, $hasta, $f, null);
    $c = $grupos[KPI_CLAVE_TOTAL] ?? kpi_fila_vacia();
    $ind = kpi_indicadores($c);
    $incidencias = kpi_incidencias($desde, $hasta, $f, null)[KPI_CLAVE_TOTAL] ?? 0;
    $costos = kpi_costos($desde, $hasta, $f)[KPI_CLAVE_TOTAL] ?? ['km' => 0.0, 'costo' => 0.0];

    $km = round($costos['km'], 2);
    $costo = round($costos['costo'], 2);

    return [
        'periodo' => ['desde' => $desde, 'hasta' => $hasta],
        'despachados' => $ind['despachados'],
        'entregados' => $ind['entregados'],
        'otif_pct' => $ind['otif_pct'],
        'ftdr_pct' => $ind['ftdr_pct'],
        'dwell_seg' => $ind['dwell_seg'],
        'dwell_texto' => kpi_mmss($ind['dwell_seg']),
        'incidencias' => $incidencias,
        'tasa_incidencias_pct' => kpi_pct($incidencias, $ind['despachados']),
        'km_reales' => $km,
        'costo_operativo' => $costo,
        // Costo por km: promedio ponderado; se deja con 4 decimales porque vehiculos.costo_por_km los tiene.
        'costo_por_km' => $costos['km'] > 0 ? round($costos['costo'] / $costos['km'], 4) : null,
        'costo_por_entrega' => ($ind['entregados'] > 0 && $costos['km'] > 0)
            ? round($costos['costo'] / $ind['entregados'], 2) : null,
        'nivel_servicio_pct' => $ind['otif_pct'],
    ];
}

/**
 * Serie diaria: una fila por cada día del rango (con ceros o null los días sin datos).
 *
 * @return list<array{fecha:string,despachados:int,entregados:int,otif_pct:?float,ftdr_pct:?float,incidencias:int}>
 */
function kpi_serie(string $desde, string $hasta, array $filtros = []): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);

    $porDia = kpi_agrupado($desde, $hasta, $f, 'dia');
    $incPorDia = kpi_incidencias_por_dia($desde, $hasta, $f);

    $utc = new DateTimeZone('UTC');
    $dia = new DateTimeImmutable($desde, $utc);
    $fin = new DateTimeImmutable($hasta, $utc);
    $out = [];
    while ($dia <= $fin) {
        $fecha = $dia->format('Y-m-d');
        $ind = kpi_indicadores($porDia[$fecha] ?? kpi_fila_vacia());
        $out[] = [
            'fecha' => $fecha,
            'despachados' => $ind['despachados'],
            'entregados' => $ind['entregados'],
            'otif_pct' => $ind['otif_pct'],
            'ftdr_pct' => $ind['ftdr_pct'],
            'incidencias' => $incPorDia[$fecha] ?? 0,
        ];
        $dia = $dia->modify('+1 day');
    }
    return $out;
}

/**
 * KPIs por conductor. Incluye a todos los conductores no inactivos (aunque no tengan datos)
 * y a los inactivos que sí tengan datos en el periodo.
 *
 * @param string $orden `campo_asc` o `campo_desc` (por defecto otif_pct_desc)
 * @return list<array>
 */
function kpi_ranking_conductores(string $desde, string $hasta, array $filtros = [], string $orden = 'otif_pct_desc'): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);

    $permitidos = ['otif_pct', 'ftdr_pct', 'despachados', 'entregados', 'dwell_seg', 'km_reales', 'costo_operativo', 'nombre'];
    if (!preg_match('/^([a-z_]+)_(asc|desc)$/', $orden, $m) || !in_array($m[1], $permitidos, true)) {
        error_validacion(['orden' => 'Orden no permitido.']);
    }
    [$campo, $sentido] = [$m[1], $m[2]];

    $datos = kpi_agrupado($desde, $hasta, $f, 'conductor');
    $costos = kpi_costos($desde, $hasta, $f, true);

    $tipos = '';
    $params = [];
    $w = '1 = 1';
    if ($f['conductor_id'] !== null) {
        $w = 'c.id = ?';
        $tipos = 'i';
        $params[] = $f['conductor_id'];
    }
    $conductores = db_all(
        "SELECT c.id, c.estado, u.nombre
         FROM conductores c JOIN usuarios u ON u.id = c.usuario_id
         WHERE $w",
        $tipos,
        $params
    );

    $out = [];
    foreach ($conductores as $c) {
        $id = (int) $c['id'];
        $fila = $datos[(string) $id] ?? null;
        if ($c['estado'] === 'inactivo' && $fila === null) {
            continue;
        }
        $ind = kpi_indicadores($fila ?? kpi_fila_vacia());
        $costo = $costos[(string) $id] ?? ['km' => 0.0, 'costo' => 0.0];
        $out[] = [
            'conductor_id' => $id,
            'nombre' => (string) $c['nombre'],
            'despachados' => $ind['despachados'],
            'entregados' => $ind['entregados'],
            'otif_pct' => $ind['otif_pct'],
            'ftdr_pct' => $ind['ftdr_pct'],
            'dwell_seg' => $ind['dwell_seg'],
            'km_reales' => round($costo['km'], 2),
            'costo_operativo' => round($costo['costo'], 2),
        ];
    }

    // Los valores nulos siempre quedan al final; el desempate es el nombre.
    usort($out, function (array $a, array $b) use ($campo, $sentido): int {
        $x = $a[$campo];
        $y = $b[$campo];
        if ($x === null && $y !== null) {
            return 1;
        }
        if ($x !== null && $y === null) {
            return -1;
        }
        $cmp = ($x === $y) ? 0 : (($x < $y) ? -1 : 1);
        if ($sentido === 'desc') {
            $cmp = -$cmp;
        }
        return $cmp !== 0 ? $cmp : strcmp($a['nombre'], $b['nombre']);
    });
    return $out;
}

/**
 * KPIs y tasa de rechazo por zona. Lista las zonas activas (y las inactivas con datos) y,
 * si hay datos de paquetes sin zona, una fila final con zona_id null.
 *
 * Tasa de rechazo % = paradas fallidas ÷ (paradas entregadas + fallidas) × 100.
 *
 * @return list<array>
 */
function kpi_por_zona(string $desde, string $hasta, array $filtros = []): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);

    $datos = kpi_agrupado($desde, $hasta, $f, 'zona');
    $incidencias = kpi_incidencias($desde, $hasta, $f, 'zona');

    $tipos = '';
    $params = [];
    $w = '1 = 1';
    if ($f['zona_id'] !== null) {
        $w = 'id = ?';
        $tipos = 'i';
        $params[] = $f['zona_id'];
    }
    $zonas = db_all("SELECT id, nombre, activa FROM zonas WHERE $w ORDER BY nombre", $tipos, $params);

    $armar = function (?int $id, string $nombre, string $clave) use ($datos, $incidencias): array {
        $c = $datos[$clave] ?? kpi_fila_vacia();
        $ind = kpi_indicadores($c);
        return [
            'zona_id' => $id,
            'nombre' => $nombre,
            'despachados' => $ind['despachados'],
            'entregados' => $ind['entregados'],
            'incidencias' => $incidencias[$clave] ?? 0,
            'tasa_rechazo_pct' => kpi_pct($c['paradas_fallidas'], $c['paradas_resueltas']),
            'otif_pct' => $ind['otif_pct'],
        ];
    };

    $out = [];
    foreach ($zonas as $z) {
        $clave = (string) (int) $z['id'];
        if ((int) $z['activa'] !== 1 && !isset($datos[$clave]) && !isset($incidencias[$clave])) {
            continue;
        }
        $out[] = $armar((int) $z['id'], (string) $z['nombre'], $clave);
    }
    if ($f['zona_id'] === null && (isset($datos['']) || isset($incidencias['']))) {
        $out[] = $armar(null, 'Sin zona', '');
    }
    return $out;
}

/**
 * Puntos del mapa de calor: [[lat, lng, peso], ...] (máximo 5000), agrupados a 4 decimales (~11 m).
 * `entregas`: ubicación de cada entrega exitosa. `incidencias`: ubicación de cada entrega fallida
 * (si no tiene coordenadas, la del paquete).
 *
 * @param string $tipo 'entregas' | 'incidencias'
 * @return list<array{0:float,1:float,2:int}>
 */
function kpi_mapa_calor(string $desde, string $hasta, array $filtros, string $tipo): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);
    if (!in_array($tipo, ['entregas', 'incidencias'], true)) {
        error_validacion(['tipo' => 'Tipo de mapa inválido.']);
    }
    $resultado = $tipo === 'entregas' ? 'entregado' : 'fallida';

    $tipos = '';
    $params = [];
    $where = kpi_condiciones($desde, $hasta, $f, $tipos, $params);
    $tipos .= 's';
    $params[] = $resultado;
    $limite = KPI_MAPA_MAX_PUNTOS;
    $sql = "SELECT t.lat4, t.lng4, COUNT(*) AS peso
            FROM (
                SELECT ROUND(COALESCE(e.lat, p.lat), 4) AS lat4, ROUND(COALESCE(e.lng, p.lng), 4) AS lng4
                " . KPI_FROM . "
                JOIN entregas e ON e.parada_id = pa.id
                WHERE $where AND e.resultado = ?
            ) t
            WHERE t.lat4 IS NOT NULL AND t.lng4 IS NOT NULL
            GROUP BY t.lat4, t.lng4
            ORDER BY peso DESC
            LIMIT $limite";
    $out = [];
    foreach (db_all($sql, $tipos, $params) as $r) {
        $out[] = [(float) $r['lat4'], (float) $r['lng4'], (int) $r['peso']];
    }
    return $out;
}

// ---------------------------------------------------------------------
// Exportación
// ---------------------------------------------------------------------

/** Etiquetas en español de los resultados y motivos para los reportes. */
function kpi_texto_resultado(string $resultado): string
{
    return $resultado === 'entregado' ? 'Entregado' : 'Fallida';
}

/**
 * Tabla de un reporte lista para convertir en CSV.
 *
 * @param string $reporte resumen | conductores | zonas | entregas
 * @return array{0:list<string>,1:list<list<mixed>>} [cabecera, filas]
 */
function kpi_exportar_tabla(string $reporte, string $desde, string $hasta, array $filtros = []): array
{
    [$desde, $hasta] = kpi_rango($desde, $hasta);
    $f = kpi_filtros_normalizar($filtros);

    switch ($reporte) {
        case 'resumen':
            $r = kpi_calcular($desde, $hasta, $f);
            return [['Indicador', 'Valor'], [
                ['Desde', $desde],
                ['Hasta', $hasta],
                ['Despachados', $r['despachados']],
                ['Entregados', $r['entregados']],
                ['OTIF (%)', $r['otif_pct']],
                ['FTDR (%)', $r['ftdr_pct']],
                ['Tiempo de parada (mm:ss)', $r['dwell_texto']],
                ['Tiempo de parada (segundos)', $r['dwell_seg']],
                ['Incidencias', $r['incidencias']],
                ['Tasa de incidencias (%)', $r['tasa_incidencias_pct']],
                ['Km reales', $r['km_reales']],
                ['Costo operativo', $r['costo_operativo']],
                ['Costo por km', $r['costo_por_km']],
                ['Costo por entrega', $r['costo_por_entrega']],
                ['Nivel de servicio global (%)', $r['nivel_servicio_pct']],
            ]];

        case 'conductores':
            $filas = [];
            foreach (kpi_ranking_conductores($desde, $hasta, $f) as $c) {
                $filas[] = [$c['nombre'], $c['despachados'], $c['entregados'], $c['otif_pct'], $c['ftdr_pct'],
                    $c['dwell_seg'], kpi_mmss($c['dwell_seg']), $c['km_reales'], $c['costo_operativo']];
            }
            return [['Conductor', 'Despachados', 'Entregados', 'OTIF (%)', 'FTDR (%)',
                'Tiempo de parada (s)', 'Tiempo de parada (mm:ss)', 'Km reales', 'Costo operativo'], $filas];

        case 'zonas':
            $filas = [];
            foreach (kpi_por_zona($desde, $hasta, $f) as $z) {
                $filas[] = [$z['nombre'], $z['despachados'], $z['entregados'], $z['incidencias'],
                    $z['tasa_rechazo_pct'], $z['otif_pct']];
            }
            return [['Zona', 'Despachados', 'Entregados', 'Incidencias', 'Tasa de rechazo (%)', 'OTIF (%)'], $filas];

        case 'entregas':
            $tipos = '';
            $params = [];
            $where = kpi_condiciones($desde, $hasta, $f, $tipos, $params);
            $limite = KPI_EXPORT_MAX_FILAS;
            $sql = "SELECT p.codigo_guia, r.codigo AS ruta, u.nombre AS conductor, e.resultado, e.motivo_fallo,
                           e.intento_num, e.ocurrido_at, e.completa, e.otp_validado, p.ventana_fin
                    " . KPI_FROM . "
                    JOIN entregas e ON e.parada_id = pa.id
                    JOIN conductores c ON c.id = e.conductor_id
                    JOIN usuarios u ON u.id = c.usuario_id
                    WHERE $where
                    ORDER BY e.ocurrido_at, e.id
                    LIMIT $limite";
            $filas = [];
            foreach (db_all($sql, $tipos, $params) as $e) {
                $entregado = $e['resultado'] === 'entregado';
                $aTiempo = $entregado
                    ? (($e['ventana_fin'] === null || strcmp((string) $e['ocurrido_at'], (string) $e['ventana_fin']) <= 0) ? 'Sí' : 'No')
                    : '';
                $filas[] = [
                    $e['codigo_guia'], $e['ruta'], $e['conductor'], kpi_texto_resultado((string) $e['resultado']),
                    $e['motivo_fallo'] ?? '', (int) $e['intento_num'], kpi_fecha_local((string) $e['ocurrido_at']),
                    $aTiempo, (int) $e['completa'] === 1 ? 'Sí' : 'No', (int) $e['otp_validado'] === 1 ? 'Sí' : 'No',
                ];
            }
            return [['Guía', 'Ruta', 'Conductor', 'Resultado', 'Motivo de fallo', 'Intento', 'Fecha',
                'A tiempo', 'Completa', 'OTP validado'], $filas];
    }
    error_validacion(['reporte' => 'Reporte no disponible.']);
}

/**
 * Convierte una tabla en texto CSV: UTF-8 con BOM, separador coma, textos entre comillas dobles.
 * Los textos que empiezan con = + - @ se neutralizan con un apóstrofo (evita fórmulas en Excel).
 */
function kpi_csv(array $cabecera, array $filas): string
{
    $celda = function (mixed $v): string {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $t = (string) $v;
        if ($t !== '' && strpbrk($t[0], "=+-@\t\r") !== false) {
            $t = "'" . $t;
        }
        return '"' . str_replace('"', '""', $t) . '"';
    };
    $lineas = [implode(',', array_map($celda, $cabecera))];
    foreach ($filas as $fila) {
        $lineas[] = implode(',', array_map($celda, $fila));
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lineas) . "\r\n";
}
