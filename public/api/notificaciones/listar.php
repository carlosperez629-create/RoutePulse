<?php
/** GET notificaciones/listar.php (admin, despachador, gerente). Filtros: estado, canal, paquete_id */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $u): array {
    $f = validar($in, ['estado' => 'opcional|enum:pendiente,enviado,fallido,cancelado', 'canal' => 'opcional|enum:whatsapp,sms,email', 'paquete_id' => 'opcional|int|min:1']);
    [$page, $pp, $off] = paginacion($in);
    $w = ['1=1']; $t = ''; $p = [];
    foreach (['estado' => 's', 'canal' => 's', 'paquete_id' => 'i'] as $c => $ty) {
        if ($f[$c] !== null) { $w[] = "n.$c=?"; $t .= $ty; $p[] = $f[$c]; }
    }
    $ws = implode(' AND ', $w);
    $total = (int) db_val("SELECT COUNT(*) FROM notificaciones n WHERE $ws", $t, $p);
    $filas = db_all("SELECT n.id, n.paquete_id, pq.codigo_guia, n.tipo, n.canal, n.destino, n.mensaje, n.estado, n.intentos, n.error, n.proveedor_mensaje_id, n.created_at, n.enviado_at FROM notificaciones n LEFT JOIN paquetes pq ON pq.id=n.paquete_id WHERE $ws ORDER BY n.id DESC LIMIT ? OFFSET ?", $t . 'ii', array_merge($p, [$pp, $off]));
    api_meta(meta_paginacion($page, $pp, $total));
    return $filas;
});
