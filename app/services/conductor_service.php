<?php
/**
 * M1. Flota: vehículos y conductores (Juan).
 * Tablas: vehiculos, conductores. El login del conductor vive en usuarios (M8, usuario_crear).
 */

const VEHICULO_TIPOS = ['furgon', 'motocicleta', 'vehiculo_electrico', 'bicicleta'];
const RUTA_ESTADOS_ACTIVOS = ['borrador', 'planificada', 'en_curso'];

/* ------------------------------ Vehículos ------------------------------ */

function vehiculo_obtener(int $id): ?array
{
    $v = db_one('SELECT * FROM vehiculos WHERE id = ?', 'i', [$id]);
    return $v ? vehiculo_normalizar($v) : null;
}

function vehiculo_normalizar(array $v): array
{
    $v['id'] = (int) $v['id'];
    $v['capacidad_kg'] = (float) $v['capacidad_kg'];
    $v['capacidad_m3'] = (float) $v['capacidad_m3'];
    $v['costo_por_km'] = (float) $v['costo_por_km'];
    $v['activo'] = (bool) $v['activo'];
    return fechas_fila_iso($v, ['created_at']);
}

/** @return array ['filas' => [...], 'meta' => [...]] */
function vehiculo_listar(array $in): array
{
    $f = validar($in, ['activo' => 'opcional|bool', 'tipo' => 'opcional|enum:' . implode(',', VEHICULO_TIPOS), 'q' => 'opcional|str|max:60']);
    [$page, $porPagina, $offset] = paginacion($in);
    $where = [];
    $tipos = '';
    $params = [];
    if ($f['activo'] !== null) {
        $where[] = 'activo = ?';
        $tipos .= 'i';
        $params[] = (int) (bool) $f['activo'];
    }
    if ($f['tipo'] !== null) {
        $where[] = 'tipo = ?';
        $tipos .= 's';
        $params[] = $f['tipo'];
    }
    if ($f['q'] !== null && trim($f['q']) !== '') {
        $where[] = '(placa LIKE ? OR marca_modelo LIKE ?)';
        $tipos .= 'ss';
        $like = paquete_like(trim($f['q']));
        array_push($params, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int) db_val('SELECT COUNT(*) FROM vehiculos ' . $sqlWhere, $tipos, $params);
    $filas = db_all('SELECT * FROM vehiculos ' . $sqlWhere . ' ORDER BY id LIMIT ? OFFSET ?', $tipos . 'ii', array_merge($params, [$porPagina, $offset]));
    return [
        'filas' => array_map('vehiculo_normalizar', $filas),
        'meta'  => ['page' => $page, 'por_pagina' => $porPagina, 'total' => $total, 'paginas' => (int) ceil($total / max(1, $porPagina))],
    ];
}

/** @throws AppError VALIDACION, CONFLICTO (placa repetida) */
function vehiculo_crear(array $datos, ?int $usuarioId): array
{
    $d = validar($datos, [
        'placa'        => 'requerido|str|max:20',
        'tipo'         => 'requerido|enum:' . implode(',', VEHICULO_TIPOS),
        'marca_modelo' => 'opcional|str|max:80',
        'capacidad_kg' => 'requerido|num|min:0.01|max:99999',
        'capacidad_m3' => 'requerido|num|min:0.01|max:9999',
        'costo_por_km' => 'opcional|num|min:0',
    ]);
    try {
        $id = db_insert(
            'INSERT INTO vehiculos (placa, tipo, marca_modelo, capacidad_kg, capacidad_m3, costo_por_km) VALUES (?,?,?,?,?,?)',
            'sssddd',
            [strtoupper(trim($d['placa'])), $d['tipo'], $d['marca_modelo'], (float) $d['capacidad_kg'], (float) $d['capacidad_m3'], (float) ($d['costo_por_km'] ?? 0)]
        );
    } catch (AppError $e) {
        if (paquete_error_duplicado($e) !== null) {
            error_conflicto('Ya existe un vehículo con esa placa.');
        }
        throw $e;
    }
    audit('vehiculo_crear', 'vehiculo', $id, ['placa' => strtoupper(trim($d['placa']))]);
    return vehiculo_obtener($id);
}

/** No se puede desactivar un vehículo con ruta planificada o en curso (CONFLICTO). */
function vehiculo_actualizar(int $vehiculoId, array $datos, ?int $usuarioId): array
{
    $v = vehiculo_obtener($vehiculoId);
    if (!$v) {
        error_no_encontrado('El vehículo');
    }
    $d = validar($datos, [
        'placa'        => 'opcional|str|max:20',
        'tipo'         => 'opcional|enum:' . implode(',', VEHICULO_TIPOS),
        'marca_modelo' => 'opcional|str|max:80',
        'capacidad_kg' => 'opcional|num|min:0.01|max:99999',
        'capacidad_m3' => 'opcional|num|min:0.01|max:9999',
        'costo_por_km' => 'opcional|num|min:0',
        'activo'       => 'opcional|bool',
    ]);
    $n = $v;
    foreach (['tipo', 'capacidad_kg', 'capacidad_m3', 'costo_por_km'] as $c) {
        if ($d[$c] !== null) {
            $n[$c] = $d[$c];
        }
    }
    if ($d['placa'] !== null) {
        $n['placa'] = strtoupper(trim($d['placa']));
    }
    if (array_key_exists('marca_modelo', $datos)) {
        $n['marca_modelo'] = $d['marca_modelo'];
    }
    if ($d['activo'] !== null) {
        $n['activo'] = (bool) $d['activo'];
        if ($v['activo'] && !$n['activo']) {
            $uso = (int) db_val("SELECT COUNT(*) FROM rutas WHERE vehiculo_id = ? AND estado IN ('planificada','en_curso')", 'i', [$vehiculoId]);
            if ($uso > 0) {
                error_conflicto('No se puede desactivar el vehículo: tiene una ruta planificada o en curso.');
            }
        }
    }
    try {
        db_exec(
            'UPDATE vehiculos SET placa = ?, tipo = ?, marca_modelo = ?, capacidad_kg = ?, capacidad_m3 = ?, costo_por_km = ?, activo = ? WHERE id = ?',
            'sssdddii',
            [$n['placa'], $n['tipo'], $n['marca_modelo'], (float) $n['capacidad_kg'], (float) $n['capacidad_m3'], (float) $n['costo_por_km'], (int) $n['activo'], $vehiculoId]
        );
    } catch (AppError $e) {
        if (paquete_error_duplicado($e) !== null) {
            error_conflicto('Ya existe un vehículo con esa placa.');
        }
        throw $e;
    }
    audit('vehiculo_actualizar', 'vehiculo', $vehiculoId, ['antes' => $v, 'despues' => $n]);
    return vehiculo_obtener($vehiculoId);
}

/* ------------------------------ Conductores ------------------------------ */

/** Conductor con su vehículo (para M4). null si el usuario no es conductor. */
function conductor_obtener_por_usuario(int $usuarioId): ?array
{
    $c = db_one('SELECT id FROM conductores WHERE usuario_id = ?', 'i', [$usuarioId]);
    return $c ? conductor_obtener((int) $c['id']) : null;
}

/** Conductor completo: usuario {nombre,email,telefono}, vehiculo y zonas. */
function conductor_obtener(int $id): ?array
{
    $c = db_one(
        'SELECT c.id, c.usuario_id, c.vehiculo_id, c.licencia, c.estado, c.created_at, u.nombre, u.email, u.telefono, u.activo AS usuario_activo
         FROM conductores c JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ?',
        'i',
        [$id]
    );
    if (!$c) {
        return null;
    }
    $c['id'] = (int) $c['id'];
    $c['usuario_id'] = (int) $c['usuario_id'];
    $c['usuario'] = ['nombre' => $c['nombre'], 'email' => $c['email'], 'telefono' => $c['telefono'], 'activo' => (bool) $c['usuario_activo']];
    unset($c['nombre'], $c['email'], $c['telefono'], $c['usuario_activo']);
    $c['vehiculo'] = $c['vehiculo_id'] !== null ? vehiculo_obtener((int) $c['vehiculo_id']) : null;
    $c['vehiculo_id'] = $c['vehiculo_id'] !== null ? (int) $c['vehiculo_id'] : null;
    $c['zonas'] = db_all('SELECT z.id, z.nombre FROM zona_conductor zc JOIN zonas z ON z.id = zc.zona_id WHERE zc.conductor_id = ? ORDER BY z.nombre', 'i', [$id]);
    return fechas_fila_iso($c, ['created_at']);
}

/** @return array ['filas' => [...], 'meta' => [...]] */
function conductor_listar(array $in): array
{
    $f = validar($in, ['estado' => 'opcional|enum:disponible,en_ruta,inactivo', 'zona_id' => 'opcional|int|min:1', 'disponibles_para_fecha' => 'opcional|fecha']);
    [$page, $porPagina, $offset] = paginacion($in);
    $where = [];
    $tipos = '';
    $params = [];
    if ($f['estado'] !== null) {
        $where[] = 'c.estado = ?';
        $tipos .= 's';
        $params[] = $f['estado'];
    }
    if ($f['zona_id'] !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM zona_conductor zc WHERE zc.conductor_id = c.id AND zc.zona_id = ?)';
        $tipos .= 'i';
        $params[] = (int) $f['zona_id'];
    }
    if ($f['disponibles_para_fecha'] !== null) {
        $where[] = "c.estado <> 'inactivo' AND NOT EXISTS (SELECT 1 FROM rutas r WHERE r.conductor_id = c.id AND r.fecha = ? AND r.estado IN ('borrador','planificada','en_curso'))";
        $tipos .= 's';
        $params[] = $f['disponibles_para_fecha'];
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int) db_val('SELECT COUNT(*) FROM conductores c ' . $sqlWhere, $tipos, $params);
    $ids = db_all('SELECT c.id FROM conductores c ' . $sqlWhere . ' ORDER BY c.id LIMIT ? OFFSET ?', $tipos . 'ii', array_merge($params, [$porPagina, $offset]));
    $filas = [];
    foreach ($ids as $r) {
        $filas[] = conductor_obtener((int) $r['id']);
    }
    return ['filas' => $filas, 'meta' => ['page' => $page, 'por_pagina' => $porPagina, 'total' => $total, 'paginas' => (int) ceil($total / max(1, $porPagina))]];
}

/**
 * Crea el usuario (rol conductor, vía usuario_crear del núcleo) y la fila de conductores.
 * Si no se envía password, usuario_crear genera una temporal (con debe_cambiar_password = 1) y se
 * devuelve UNA sola vez en password_temporal.
 */
function conductor_crear(array $datos, ?int $usuarioId): array
{
    $d = validar($datos, [
        'nombre'      => 'requerido|str|max:100',
        'email'       => 'requerido|email|max:150',
        'telefono'    => 'requerido|telefono',
        'password'    => 'opcional|crudo|max:200',
        'licencia'    => 'opcional|str|max:40',
        'vehiculo_id' => 'opcional|int|min:1',
    ]);
    if ($d['vehiculo_id'] !== null && !vehiculo_obtener((int) $d['vehiculo_id'])) {
        error_validacion(['vehiculo_id' => 'El vehículo no existe.']);
    }
    $temporal = null;

    $id = db_tx(function () use ($d, &$temporal) {
        $uid = usuario_crear(['rol' => 'conductor', 'nombre' => $d['nombre'], 'email' => $d['email'], 'password' => $d['password'], 'telefono' => $d['telefono']], $temporal);
        return db_insert(
            'INSERT INTO conductores (usuario_id, vehiculo_id, licencia) VALUES (?,?,?)',
            'iis',
            [$uid, $d['vehiculo_id'], $d['licencia']]
        );
    });
    audit('conductor_crear', 'conductor', $id, ['email' => $d['email']]);
    $c = conductor_obtener($id);
    if ($temporal !== null) {
        $c['password_temporal'] = $temporal;
    }
    return $c;
}

/** licencia, vehiculo_id (puede ser null) y estado (disponible/inactivo; en_ruta solo lo cambia el sistema). */
function conductor_actualizar(int $conductorId, array $datos, ?int $usuarioId): array
{
    $c = conductor_obtener($conductorId);
    if (!$c) {
        error_no_encontrado('El conductor');
    }
    $d = validar($datos, ['licencia' => 'opcional|str|max:40', 'vehiculo_id' => 'opcional|int|min:1', 'estado' => 'opcional|enum:disponible,inactivo']);
    $licencia = array_key_exists('licencia', $datos) ? $d['licencia'] : $c['licencia'];
    $vehiculoId = array_key_exists('vehiculo_id', $datos) ? $d['vehiculo_id'] : $c['vehiculo_id'];
    if ($vehiculoId !== null && !vehiculo_obtener((int) $vehiculoId)) {
        error_validacion(['vehiculo_id' => 'El vehículo no existe.']);
    }
    $estado = $c['estado'];
    if ($d['estado'] !== null && $d['estado'] !== $c['estado']) {
        if ($c['estado'] === 'en_ruta') {
            error_estado('El conductor está en ruta: su estado cambia cuando termina la ruta.');
        }
        if ($d['estado'] === 'inactivo') {
            $activas = (int) db_val("SELECT COUNT(*) FROM rutas WHERE conductor_id = ? AND estado IN ('planificada','en_curso')", 'i', [$conductorId]);
            if ($activas > 0) {
                error_conflicto('No se puede inactivar al conductor: tiene una ruta planificada o en curso.');
            }
        }
        $estado = $d['estado'];
    }
    db_exec('UPDATE conductores SET licencia = ?, vehiculo_id = ?, estado = ? WHERE id = ?', 'sisi', [$licencia, $vehiculoId, $estado, $conductorId]);
    audit('conductor_actualizar', 'conductor', $conductorId, ['estado' => $estado, 'vehiculo_id' => $vehiculoId]);
    return conductor_obtener($conductorId);
}
