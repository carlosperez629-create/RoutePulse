<?php
/**
 * GET /api/auth/csrf.php (público)
 * Inicia (o reutiliza) la sesión y devuelve el token CSRF.
 */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', [], function (array $in, ?array $usuario): array {
    return ['csrf_token' => csrf_token()];
}, ['sesion' => true]);
