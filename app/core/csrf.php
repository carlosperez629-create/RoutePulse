<?php
/**
 * RoutePulse - Protección CSRF.
 * Token por sesión (32 bytes aleatorios) que el navegador envía en el encabezado
 * X-CSRF-Token en todo POST con sesión. Se compara con hash_equals().
 */

function csrf_token(): string {
    sesion_iniciar();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Lanza CSRF_INVALIDO (403) si el encabezado falta o no coincide con el de la sesión.
 */
function csrf_validar(): void {
    sesion_iniciar();
    $esperado = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    $recibido = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($esperado === '' || $recibido === '' || !hash_equals($esperado, $recibido)) {
        lanzar_error('CSRF_INVALIDO', 'La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.', 403);
    }
}
