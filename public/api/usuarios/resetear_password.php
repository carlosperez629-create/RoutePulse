<?php
/** POST usuarios/resetear_password.php (admin). Devuelve password_temporal una sola vez. */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['admin'], function (array $in, ?array $usuario): array {
    $d = validar($in, ['usuario_id' => 'requerido|int|min:1']);
    if (!usuario_obtener($d['usuario_id'])) {
        error_no_encontrado('El usuario');
    }
    $temporal = usuario_generar_password_temporal();
    db_exec(
        'UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?',
        'si',
        [auth_hash_password($temporal), $d['usuario_id']]
    );
    audit('usuario_resetear_password', 'usuario', $d['usuario_id']);
    return ['usuario_id' => $d['usuario_id'], 'password_temporal' => $temporal];
});
