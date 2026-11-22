<?php
/**
 * GET /api/auth/me.php (cualquier usuario con sesión)
 * Salida: usuario, menú según su rol, token CSRF y zona horaria para mostrar fechas.
 */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['*'], function (array $in, ?array $usuario): array {
    $menu = [];
    foreach (auth_menu_por_rol($usuario['rol']) as $opcion) {
        $menu[] = [
            'texto' => $opcion['texto'],
            'url'   => rp_url($opcion['url']),
            'icono' => $opcion['icono'],
        ];
    }

    return [
        'usuario'         => $usuario,
        'menu'            => $menu,
        'csrf_token'      => csrf_token(),
        'zona_horaria_ui' => (string) config_get('zona_horaria_ui', 'UTC'),
    ];
}, ['permitir_cambio_pendiente' => true]);
