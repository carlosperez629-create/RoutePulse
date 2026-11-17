<?php
/**
 * Entrada del sitio: redirige según el rol (o al login si no hay sesión).
 */
require_once __DIR__ . '/../app/core/bootstrap.php';

$usuario = auth_usuario();
if ($usuario === null) {
    header('Location: ' . rp_url('login.php'));
    exit;
}
if ($usuario['debe_cambiar_password']) {
    header('Location: ' . rp_url('cambiar_password.php'));
    exit;
}
header('Location: ' . rp_url(auth_url_inicio($usuario['rol'])));
exit;
