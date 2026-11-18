<?php
/**
 * M1. Zonas de cobertura (Juan).
 * Tablas: zonas, zona_codigos_postales, zona_conductor.
 */

/**
 * Zona de un punto: primero por polígono (la primera zona activa que lo contenga, por id);
 * si ninguno lo contiene, por código postal. null si no hay.
 */
function zona_resolver(float $lat, float $lng, ?string $codigoPostal = null): ?int
{
    $zonas = db_all('SELECT id, poligono_geojson FROM zonas WHERE activa = 1 ORDER BY id');
    foreach ($zonas as $z) {
        if ($z['poligono_geojson'] === null || $z['poligono_geojson'] === '') {
            continue;
        }
        $geo = json_decode($z['poligono_geojson'], true);
        if (is_array($geo) && geo_punto_en_poligono($lat, $lng, $geo)) {
            return (int) $z['id'];
        }
    }
    if ($codigoPostal !== null && trim($codigoPostal) !== '') {
        $id = db_val(
            'SELECT zc.zona_id FROM zona_codigos_postales zc JOIN zonas z ON z.id = zc.zona_id
             WHERE zc.codigo_postal = ? AND z.activa = 1 ORDER BY zc.zona_id LIMIT 1',
            's',
            [trim($codigoPostal)]
        );
        return $id !== null ? (int) $id : null;
    }
    return null;
}

/** Valida un GeoJSON Polygon: anillo cerrado, mínimo 4 puntos, lng/lat en rango. Lanza VALIDACION. */
function zona_validar_poligono(mixed $geo): array
{
    if (is_string($geo)) {
        $geo = json_decode($geo, true);
    }
    $error = static fn(string $m) => error_validacion(['poligono' => $m]);
    if (!is_array($geo) || ($geo['type'] ?? null) !== 'Polygon' || !isset($geo['coordinates'][0]) || !is_array($geo['coordinates'][0])) {
        $error('El polígono debe ser un GeoJSON de tipo Polygon.');
    }
    $anillo = $geo['coordinates'][0];
    if (count($anillo) < 4) {
        $error('El polígono necesita al menos 4 puntos (el último repite el primero).');
    }
    foreach ($anillo as $punto) {
        if (!is_array($punto) || count($punto) < 2 || !is_numeric($punto[0]) || !is_numeric($punto[1])
            || $punto[0] < -180 || $punto[0] > 180 || $punto[1] < -90 || $punto[1] > 90) {
            $error('El polígono tiene coordenadas fuera de rango.');
        }
    }
    $primero = $anillo[0];
    $ultimo = $anillo[count($anillo) - 1];
    if ((float) $primero[0] !== (float) $ultimo[0] || (float) $primero[1] !== (float) $ultimo[1]) {
        $error('El anillo del polígono debe cerrarse (el último punto igual al primero).');
    }
    $limpio = [];
    foreach ($anillo as $p) {
        $limpio[] = [(float) $p[0], (float) $p[1]];
    }
    return ['type' => 'Polygon', 'coordinates' => [$limpio]];
}

/** Normaliza la lista de códigos postales (arreglo o texto separado por comas). */
function zona_normalizar_codigos(mixed $valor): array
{
    if ($valor === null || $valor === '') {
        return [];
    }
    $lista = is_array($valor) ? $valor : explode(',', (string) $valor);
    $salida = [];
    foreach ($lista as $cp) {
        $cp = trim((string) $cp);
        if ($cp === '') {
            continue;
        }
        if (mb_strlen($cp) > 12) {
            error_validacion(['codigos_postales' => 'Cada código postal admite máximo 12 caracteres.']);
        }
        $salida[$cp] = true;
    }
    return array_map('strval', array_keys($salida));
}

/** Lista de zonas con polígono (objeto GeoJSON), códigos postales y conductores asignados. */
function zona_listar(?bool $soloActivas = null): array
{
    $sql = 'SELECT id, nombre, descripcion, color, poligono_geojson, activa FROM zonas';
    if ($soloActivas === true) {
        $sql .= ' WHERE activa = 1';
    }
    $zonas = db_all($sql . ' ORDER BY id');
    foreach ($zonas as &$z) {
        $zid = (int) $z['id'];
        $z['id'] = $zid;
        $z['activa'] = (bool) $z['activa'];
        $z['poligono'] = $z['poligono_geojson'] ? json_decode($z['poligono_geojson'], true) : null;
        unset($z['poligono_geojson']);
        $z['codigos_postales'] = array_column(
            db_all('SELECT codigo_postal FROM zona_codigos_postales WHERE zona_id = ? ORDER BY codigo_postal', 'i', [$zid]),
            'codigo_postal'
        );
        $z['conductores'] = db_all(
            'SELECT c.id, u.nombre FROM zona_conductor zc JOIN conductores c ON c.id = zc.conductor_id JOIN usuarios u ON u.id = c.usuario_id
             WHERE zc.zona_id = ? ORDER BY u.nombre',
            'i',
            [$zid]
        );
    }
    unset($z);
    return $zonas;
}

function zona_obtener(int $id): ?array
{
    foreach (zona_listar() as $z) {
        if ($z['id'] === $id) {
            return $z;
        }
    }
    return null;
}

/** @throws AppError VALIDACION, CONFLICTO (nombre repetido) */
function zona_crear(array $datos, ?int $usuarioId): array
{
    $d = validar($datos, [
        'nombre'      => 'requerido|str|max:80',
        'descripcion' => 'opcional|str|max:255',
        'color'       => 'opcional|str|max:7',
    ]);
    $color = $d['color'] ?? '#0d6efd';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        error_validacion(['color' => 'Usa el formato #RRGGBB.']);
    }
    $poligono = !empty($datos['poligono']) ? zona_validar_poligono($datos['poligono']) : null;
    $codigos = zona_normalizar_codigos($datos['codigos_postales'] ?? null);

    try {
        $id = db_tx(function () use ($d, $color, $poligono, $codigos) {
            $zid = db_insert(
                'INSERT INTO zonas (nombre, descripcion, color, poligono_geojson) VALUES (?,?,?,?)',
                'ssss',
                [$d['nombre'], $d['descripcion'], $color, $poligono ? json_encode($poligono) : null]
            );
            foreach ($codigos as $cp) {
                db_exec('INSERT INTO zona_codigos_postales (zona_id, codigo_postal) VALUES (?,?)', 'is', [$zid, $cp]);
            }
            return $zid;
        });
    } catch (AppError $e) {
        if (paquete_error_duplicado($e) !== null) {
            error_conflicto('Ya existe una zona con ese nombre.');
        }
        throw $e;
    }
    audit('zona_crear', 'zona', $id, ['nombre' => $d['nombre']]);
    return zona_obtener($id);
}

/**
 * Edita una zona. Al cambiar el polígono NO se recalculan las zonas de paquetes ya creados.
 * "poligono": null borra el polígono; "codigos_postales" reemplaza la lista completa.
 */
function zona_actualizar(int $zonaId, array $datos, ?int $usuarioId): array
{
    $actual = zona_obtener($zonaId);
    if (!$actual) {
        error_no_encontrado('La zona');
    }
    $d = validar($datos, [
        'nombre'      => 'opcional|str|max:80',
        'descripcion' => 'opcional|str|max:255',
        'color'       => 'opcional|str|max:7',
        'activa'      => 'opcional|bool',
    ]);
    $nombre = $d['nombre'] ?? $actual['nombre'];
    $descripcion = array_key_exists('descripcion', $datos) ? $d['descripcion'] : $actual['descripcion'];
    $color = $d['color'] ?? $actual['color'];
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        error_validacion(['color' => 'Usa el formato #RRGGBB.']);
    }
    $activa = $d['activa'] === null ? (int) $actual['activa'] : (int) (bool) $d['activa'];
    $poligono = $actual['poligono'];
    if (array_key_exists('poligono', $datos)) {
        $poligono = empty($datos['poligono']) ? null : zona_validar_poligono($datos['poligono']);
    }
    $reemplazaCodigos = array_key_exists('codigos_postales', $datos);
    $codigos = $reemplazaCodigos ? zona_normalizar_codigos($datos['codigos_postales']) : [];

    try {
        db_tx(function () use ($zonaId, $nombre, $descripcion, $color, $activa, $poligono, $reemplazaCodigos, $codigos) {
            db_exec(
                'UPDATE zonas SET nombre = ?, descripcion = ?, color = ?, activa = ?, poligono_geojson = ? WHERE id = ?',
                'sssisi',
                [$nombre, $descripcion, $color, $activa, $poligono ? json_encode($poligono) : null, $zonaId]
            );
            if ($reemplazaCodigos) {
                db_exec('DELETE FROM zona_codigos_postales WHERE zona_id = ?', 'i', [$zonaId]);
                foreach ($codigos as $cp) {
                    db_exec('INSERT INTO zona_codigos_postales (zona_id, codigo_postal) VALUES (?,?)', 'is', [$zonaId, $cp]);
                }
            }
        });
    } catch (AppError $e) {
        if (paquete_error_duplicado($e) !== null) {
            error_conflicto('Ya existe una zona con ese nombre.');
        }
        throw $e;
    }
    audit('zona_actualizar', 'zona', $zonaId, [
        'antes' => ['nombre' => $actual['nombre'], 'activa' => $actual['activa'], 'codigos_postales' => $actual['codigos_postales']],
        'despues' => ['nombre' => $nombre, 'activa' => (bool) $activa, 'poligono_cambio' => $poligono !== $actual['poligono']],
    ]);
    return zona_obtener($zonaId);
}

/** Reemplaza los conductores asignados a una zona. */
function zona_asignar_conductores(int $zonaId, array $conductorIds, ?int $usuarioId): array
{
    if (!zona_obtener($zonaId)) {
        error_no_encontrado('La zona');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $conductorIds), static fn($v) => $v > 0)));
    if ($ids) {
        $existentes = (int) db_val('SELECT COUNT(*) FROM conductores WHERE id IN (' . db_in($ids) . ')', str_repeat('i', count($ids)), $ids);
        if ($existentes !== count($ids)) {
            error_validacion(['conductor_ids' => 'Algún conductor no existe.']);
        }
    }
    db_tx(function () use ($zonaId, $ids) {
        db_exec('DELETE FROM zona_conductor WHERE zona_id = ?', 'i', [$zonaId]);
        foreach ($ids as $cid) {
            db_exec('INSERT INTO zona_conductor (zona_id, conductor_id) VALUES (?,?)', 'ii', [$zonaId, $cid]);
        }
    });
    audit('zona_actualizar', 'zona', $zonaId, ['conductor_ids' => $ids]);
    return zona_obtener($zonaId);
}
