<?php
/**
 * POST /api/auth/login.php (público; exige token CSRF)
 * Entrada: {"email": "...", "password": "..."}
 * Salida:  {"usuario": {...}, "csrf_token": "...", "redirigir_a": "https://.../monitor/flota.php"}
 */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', [], function (array $in, ?array $usuario): array {
    // Límite por IP antes de tocar la base de datos.
    rate_limit_check('login:ip:' . rp_ip_cliente(), (int) config_get('rate_login_por_min', 10), 60);

    $d = validar($in, [
        'email'    => 'requerido|email|max:150',
        'password' => 'requerido|crudo|max:200',
    ]);

    // Límite por correo (frena ataques distribuidos contra una misma cuenta).
    rate_limit_check('login:email:' . sha1($d['email']), 10, 900);

    $autenticado = auth_login($d['email'], $d['password']);

    return [
        'usuario'     => $autenticado,
        'csrf_token'  => csrf_token(),
        'redirigir_a' => rp_url(auth_url_inicio($autenticado['rol'])),
    ];
}, ['sesion' => true, 'csrf' => true]);
