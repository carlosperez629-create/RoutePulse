<?php
/** GET auditoria/listar.php (admin, gerente). Filtros: usuario_id, accion, entidad, entidad_id, desde, hasta. */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'gerente'], function (array $in, ?array $usuario): array {
    $f = validar($in, [
        'usuario_id' => 'opcional|int|min:1',
        'accion'     => 'opcional|str|max:60',
        'entidad'    => 'opcional|str|max:40',
        'entidad_id' => 'opcional|str|max:40',
        'desde'      => 'opcional|fecha',
        'hasta'      => 'opcional|fecha',
    ]);
    [$page, $porPagina, $offset] = paginacion($in);

    $where = ['1=1'];
    $tipos = '';
    $params = [];
    foreach (['usuario_id' => ['a.usuario_id', 'i'], 'accion' => ['a.accion', 's'], 'entidad' => ['a.entidad', 's'], 'entidad_id' => ['a.entidad_id', 's']] as $k => [$col, $t]) {
        if ($f[$k] !== null) { $where[] = "$col = ?"; $tipos .= $t; $params[] = $f[$k]; }
    }
    if ($f['desde'] !== null) { $where[] = 'a.created_at >= ?'; $tipos .= 's'; $params[] = $f['desde'] . ' 00:00:00'; }
    if ($f['hasta'] !== null) { $where[] = 'a.created_at <= ?'; $tipos .= 's'; $params[] = $f['hasta'] . ' 23:59:59'; }
    $w = implode(' AND ', $where);

    $total = (int) db_val("SELECT COUNT(*) FROM log_auditoria a WHERE $w", $tipos, $params);
    $filas = db_all(
        "SELECT a.id, a.usuario_id, u.nombre AS usuario_nombre, a.accion, a.entidad, a.entidad_id, a.detalle, a.ip, a.created_at
         FROM log_auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE $w ORDER BY a.id DESC LIMIT ? OFFSET ?",
        $tipos . 'ii',
        array_merge($params, [$porPagina, $offset])
    );
    foreach ($filas as &$r) {
        $r['detalle'] = $r['detalle'] !== null ? json_decode($r['detalle'], true) : null;
        $r['created_at'] = fecha_a_iso($r['created_at']);
    }
    api_meta(meta_paginacion($page, $porPagina, $total));
    return $filas;
});
