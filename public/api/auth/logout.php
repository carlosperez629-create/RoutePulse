<?php
/**
 * POST /api/auth/logout.php (cualquier usuario con sesión)
 */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['*'], function (array $in, ?array $usuario): ?array {
    auth_logout();
    return null;
}, ['permitir_cambio_pendiente' => true]);
