<?php
/**
 * POST /api/auth/cambiar_password.php (cualquier usuario con sesión)
 * Entrada: {"password_actual": "...", "password_nueva": "..."}
 */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['*'], function (array $in, ?array $usuario): array {
    // Frena intentos repetidos de adivinar la contraseña actual.
    rate_limit_check('pwd:usuario:' . $usuario['id'], 10, 900);

    $d = validar($in, [
        'password_actual' => 'requerido|crudo|max:200',
        'password_nueva'  => 'requerido|crudo|max:200',
    ]);

    usuario_cambiar_password($usuario['id'], $d['password_actual'], $d['password_nueva']);

    return [
        'csrf_token'  => csrf_token(),
        'redirigir_a' => rp_url(auth_url_inicio($usuario['rol'])),
    ];
}, ['permitir_cambio_pendiente' => true]);
