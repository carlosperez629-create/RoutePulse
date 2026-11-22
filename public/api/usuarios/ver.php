<?php
/** GET usuarios/ver.php?usuario_id= (admin). */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin'], function (array $in, ?array $usuario): array {
    $d = validar($in, ['usuario_id' => 'requerido|int|min:1']);
    $u = usuario_obtener($d['usuario_id']);
    if (!$u) {
        error_no_encontrado('El usuario');
    }
    $u['bloqueado'] = !empty($u['bloqueado_hasta']) && strtotime($u['bloqueado_hasta'] . ' UTC') > time();
    foreach (['ultimo_login', 'bloqueado_hasta', 'created_at', 'updated_at'] as $c) {
        if (array_key_exists($c, $u)) {
            $u[$c] = fecha_a_iso($u[$c]);
        }
    }
    return $u;
});
