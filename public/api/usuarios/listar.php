<?php
/** GET usuarios/listar.php (admin). Filtros: rol, activo, q. Paginado. */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin'], function (array $in, ?array $usuario): array {
    $f = validar($in, [
        'rol'    => 'opcional|enum:admin,despachador,gerente,conductor',
        'activo' => 'opcional|bool',
        'q'      => 'opcional|str|max:100',
    ]);
    [$page, $porPagina, $offset] = paginacion($in);

    $where = ['1=1'];
    $tipos = '';
    $params = [];
    if ($f['rol'] !== null)    { $where[] = 'rol = ?';    $tipos .= 's'; $params[] = $f['rol']; }
    if ($f['activo'] !== null) { $where[] = 'activo = ?'; $tipos .= 'i'; $params[] = $f['activo'] ? 1 : 0; }
    if ($f['q'] !== null)      { $where[] = '(nombre LIKE ? OR email LIKE ?)'; $tipos .= 'ss'; $params[] = db_like($f['q']); $params[] = db_like($f['q']); }
    $w = implode(' AND ', $where);

    $total = (int) db_val("SELECT COUNT(*) FROM usuarios WHERE $w", $tipos, $params);
    $filas = db_all(
        "SELECT id, rol, nombre, email, telefono, activo, ultimo_login, bloqueado_hasta,
                (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > UTC_TIMESTAMP()) AS bloqueado
         FROM usuarios WHERE $w ORDER BY nombre LIMIT ? OFFSET ?",
        $tipos . 'ii',
        array_merge($params, [$porPagina, $offset])
    );
    foreach ($filas as &$u) {
        $u['activo'] = (bool) $u['activo'];
        $u['bloqueado'] = (bool) $u['bloqueado'];
        $u['ultimo_login'] = fecha_a_iso($u['ultimo_login']);
        $u['bloqueado_hasta'] = fecha_a_iso($u['bloqueado_hasta']);
    }
    api_meta(meta_paginacion($page, $porPagina, $total));
    return $filas;
});
