<?php
/** POST usuarios/actualizar.php (admin). usuario_id + nombre?, email?, telefono?, rol?, activo?, desbloquear?. */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['admin'], function (array $in, ?array $usuario): array {
    $d = validar($in, [
        'usuario_id' => 'requerido|int|min:1',
        'nombre'     => 'opcional|str|min:2|max:100',
        'email'      => 'opcional|email|max:150',
        'telefono'   => 'opcional|telefono|max:30',
        'rol'        => 'opcional|enum:admin,despachador,gerente,conductor',
        'activo'     => 'opcional|bool',
        'desbloquear' => 'opcional|bool',
    ]);
    $id = $d['usuario_id'];
    $actual = usuario_obtener($id);
    if (!$actual) {
        error_no_encontrado('El usuario');
    }
    if ($id === (int) $usuario['id']) {
        if ($d['activo'] === false) {
            error_conflicto('No puedes desactivarte a ti mismo.');
        }
        if ($d['rol'] !== null && $d['rol'] !== 'admin') {
            error_conflicto('No puedes quitarte el rol de administrador.');
        }
    }
    if ($d['email'] !== null && (int) db_val('SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?', 'si', [$d['email'], $id]) > 0) {
        error_validacion(['email' => 'Ya existe un usuario con ese correo.']);
    }

    $sets = [];
    $tipos = '';
    $params = [];
    foreach (['nombre' => 's', 'email' => 's', 'telefono' => 's', 'rol' => 's'] as $campo => $t) {
        if ($d[$campo] !== null) { $sets[] = "$campo = ?"; $tipos .= $t; $params[] = $d[$campo]; }
    }
    if ($d['activo'] !== null) { $sets[] = 'activo = ?'; $tipos .= 'i'; $params[] = $d['activo'] ? 1 : 0; }
    if ($d['desbloquear']) { $sets[] = 'intentos_fallidos = 0'; $sets[] = 'bloqueado_hasta = NULL'; }

    if ($sets) {
        $params[] = $id;
        db_exec('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?', $tipos . 'i', $params);
        audit('usuario_actualizar', 'usuario', $id, array_diff_key($d, ['usuario_id' => 1]));
    }
    return usuario_obtener($id);
});
