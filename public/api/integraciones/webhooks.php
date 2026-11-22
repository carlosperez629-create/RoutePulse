<?php
/** GET integraciones/webhooks.php (admin): eventos recibidos. Filtros: estado, integracion_id */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('GET', ['admin'], function (array $in, ?array $u): array {
    $f = validar($in, ['estado' => 'opcional|enum:recibido,procesado,error,duplicado', 'integracion_id' => 'opcional|int|min:1']);
    [$page, $pp, $off] = paginacion($in);
    $w = ['1=1']; $t = ''; $p = [];
    if ($f['estado'] !== null) { $w[] = 'w.estado=?'; $t .= 's'; $p[] = $f['estado']; }
    if ($f['integracion_id'] !== null) { $w[] = 'w.integracion_id=?'; $t .= 'i'; $p[] = $f['integracion_id']; }
    $ws = implode(' AND ', $w);
    $total = (int) db_val("SELECT COUNT(*) FROM webhook_eventos w WHERE $ws", $t, $p);
    $filas = db_all("SELECT w.id, i.codigo, w.evento_externo_id, w.tipo, w.estado, w.error, w.paquete_id, w.intentos, w.recibido_at FROM webhook_eventos w JOIN integraciones i ON i.id=w.integracion_id WHERE $ws ORDER BY w.id DESC LIMIT ? OFFSET ?", $t . 'ii', array_merge($p, [$pp, $off]));
    api_meta(meta_paginacion($page, $pp, $total));
    return $filas;
});
