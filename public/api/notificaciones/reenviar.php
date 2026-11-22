<?php
/** POST notificaciones/reenviar.php: reintenta una fallida/pendiente */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $u): array {
    $d = validar($in, ['id' => 'requerido|int|min:1']);
    $n = db_one('SELECT estado FROM notificaciones WHERE id=?', 'i', [$d['id']]);
    if (!$n) { error_no_encontrado('La notificación'); }
    if ($n['estado'] === 'enviado') { error_conflicto('Ya fue enviada.'); }
    db_exec("UPDATE notificaciones SET estado='pendiente', intentos=0, error=NULL WHERE id=?", 'i', [$d['id']]);
    return ['estado' => notificacion_enviar($d['id'])];
});
