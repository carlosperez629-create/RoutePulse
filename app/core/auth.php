<?php
/**
 * RoutePulse - Autenticación, sesión y roles (RBAC).
 *
 * Roles con login: admin, despachador, gerente, conductor.
 * El destinatario NO tiene cuenta: entra por el token de su paquete (M5).
 *
 * Reglas (docs/modulos/M8-seguridad-rbac.md, sección 6):
 *  - Cookie RPSESSID: HttpOnly, SameSite=Lax, Secure si hay HTTPS, modo estricto.
 *  - Inactividad: sesion_inactividad_min (60). Conductor: sin cierre por inactividad,
 *    máximo sesion_conductor_horas (12).
 *  - 5 intentos fallidos → bloqueo de 15 min. Mensaje de error siempre genérico.
 */

/** Hash bcrypt de relleno: se verifica cuando el correo no existe para igualar tiempos. */
const AUTH_HASH_FALSO = '$2y$12$nDx8VzFgFKyfgIMyTqdPUuWRQLEziFmTH4ygGpkSz6WP5ztPy163.';

const AUTH_MENSAJE_GENERICO = 'Correo o contraseña incorrectos.';

/**
 * Inicia la sesión de PHP con los parámetros seguros (una sola vez por petición).
 * En línea de comandos (pruebas) usa un arreglo $_SESSION sin sesión real.
 */
function sesion_iniciar(): void {
    if (RP_CLI) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('RPSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => rp_es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Cierra la sesión actual (vacía los datos y expira la cookie).
 */
function rp_auth_destruir_sesion(): void {
    $_SESSION = [];
    if (!RP_CLI && session_status() === PHP_SESSION_ACTIVE) {
        if ((bool) ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] !== '' ? $p['samesite'] : 'Lax',
            ]);
        }
        session_destroy();
    }
}

/**
 * Lee el usuario de la sesión, aplicando expiración y revalidándolo contra la
 * base de datos cada 5 minutos (un usuario desactivado pierde el acceso).
 */
function rp_auth_cargar_usuario(): ?array {
    sesion_iniciar();
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    $ahora = time();
    $rol = (string) ($_SESSION['rol'] ?? '');
    $inicio = (int) ($_SESSION['inicio_sesion'] ?? 0);
    $ultima = (int) ($_SESSION['ultima_actividad'] ?? 0);

    if ($rol === 'conductor') {
        $maximo = max(1, (int) config_get('sesion_conductor_horas', 12)) * 3600;
        if ($inicio > 0 && ($ahora - $inicio) > $maximo) {
            rp_auth_destruir_sesion();
            return null;
        }
    } else {
        $maximo = max(1, (int) config_get('sesion_inactividad_min', 60)) * 60;
        if ($ultima > 0 && ($ahora - $ultima) > $maximo) {
            rp_auth_destruir_sesion();
            return null;
        }
    }
    $_SESSION['ultima_actividad'] = $ahora;

    if (($ahora - (int) ($_SESSION['ultima_validacion'] ?? 0)) > 300) {
        $fila = db_one(
            'SELECT id, nombre, email, rol, activo, debe_cambiar_password FROM usuarios WHERE id = ?',
            'i',
            [(int) $_SESSION['usuario_id']]
        );
        if ($fila === null || (int) $fila['activo'] !== 1) {
            rp_auth_destruir_sesion();
            return null;
        }
        $_SESSION['rol'] = (string) $fila['rol'];
        $_SESSION['nombre'] = (string) $fila['nombre'];
        $_SESSION['email'] = (string) $fila['email'];
        $_SESSION['debe_cambiar_password'] = (int) $fila['debe_cambiar_password'];
        $_SESSION['conductor_id'] = $fila['rol'] === 'conductor'
            ? db_val('SELECT id FROM conductores WHERE usuario_id = ?', 'i', [(int) $fila['id']])
            : null;
        $_SESSION['ultima_validacion'] = $ahora;
    }

    return [
        'id'                    => (int) $_SESSION['usuario_id'],
        'nombre'                => (string) ($_SESSION['nombre'] ?? ''),
        'email'                 => (string) ($_SESSION['email'] ?? ''),
        'rol'                   => (string) ($_SESSION['rol'] ?? ''),
        'conductor_id'          => isset($_SESSION['conductor_id']) && $_SESSION['conductor_id'] !== null ? (int) $_SESSION['conductor_id'] : null,
        'debe_cambiar_password' => !empty($_SESSION['debe_cambiar_password']),
    ];
}

/**
 * Usuario de la sesión: ['id','nombre','email','rol','conductor_id','debe_cambiar_password'] o null.
 * Cacheado por petición; $recalcular = true fuerza la lectura (tras login/logout).
 */
function auth_usuario(bool $recalcular = false): ?array {
    static $cache = null;
    static $calculado = false;

    if (!$calculado || $recalcular) {
        $cache = rp_auth_cargar_usuario();
        $calculado = true;
    }
    return $cache;
}

function auth_conductor_id(): ?int {
    $usuario = auth_usuario();
    return $usuario === null ? null : $usuario['conductor_id'];
}

/**
 * Para endpoints: exige sesión y uno de los roles (['*'] = cualquier rol).
 * Lanza NO_AUTENTICADO (401) o SIN_PERMISO (403).
 */
function auth_requerir(array $roles): array {
    $usuario = auth_usuario();
    if ($usuario === null) {
        lanzar_error('NO_AUTENTICADO', 'Debes iniciar sesión para continuar.', 401);
    }
    if (!in_array('*', $roles, true) && !in_array($usuario['rol'], $roles, true)) {
        error_sin_permiso();
    }
    if ($usuario['debe_cambiar_password'] && empty($GLOBALS['rp_permitir_cambio_pendiente'])) {
        lanzar_error('SIN_PERMISO', 'Debes cambiar tu contraseña antes de continuar.', 403);
    }
    return $usuario;
}

/**
 * Para pantallas: redirige al login, a cambiar la contraseña, o muestra "Sin permiso".
 */
function auth_requerir_pagina(array $roles, array $opciones = []): array {
    $usuario = auth_usuario();
    if ($usuario === null) {
        $destino = isset($_COOKIE['RPSESSID']) ? 'login.php?expirada=1' : 'login.php';
        header('Location: ' . rp_url($destino));
        exit;
    }

    if ($usuario['debe_cambiar_password'] && empty($opciones['permitir_cambio_pendiente'])) {
        header('Location: ' . rp_url('cambiar_password.php'));
        exit;
    }

    if (!in_array('*', $roles, true) && !in_array($usuario['rol'], $roles, true)) {
        http_response_code(403);
        render_header('Sin permiso', 'm8');
        echo '<div class="container py-5"><div class="alert alert-warning mb-0">No tienes permiso para ver esta página.</div></div>';
        render_footer();
        exit;
    }
    return $usuario;
}

/**
 * Valida credenciales y abre la sesión. Devuelve el usuario.
 * Error siempre genérico (NO_AUTENTICADO) para no revelar si el correo existe.
 */
function auth_login(string $email, string $password): array {
    sesion_iniciar();
    $email = mb_strtolower(trim($email));

    $usuario = db_one(
        'SELECT id, nombre, email, password_hash, rol, activo, intentos_fallidos, bloqueado_hasta, debe_cambiar_password
         FROM usuarios WHERE email = ? LIMIT 1',
        's',
        [$email]
    );

    // Siempre se verifica una contraseña (aunque el correo no exista) para igualar los tiempos.
    $claveCorrecta = password_verify($password, $usuario['password_hash'] ?? AUTH_HASH_FALSO);

    if ($usuario === null) {
        audit('login_fallido', 'usuario', null, ['email' => $email, 'motivo' => 'correo_inexistente']);
        lanzar_error('NO_AUTENTICADO', AUTH_MENSAJE_GENERICO, 401);
    }
    if ((int) $usuario['activo'] !== 1) {
        audit('login_fallido', 'usuario', (int) $usuario['id'], ['email' => $email, 'motivo' => 'usuario_inactivo']);
        lanzar_error('NO_AUTENTICADO', AUTH_MENSAJE_GENERICO, 401);
    }
    if ($usuario['bloqueado_hasta'] !== null && strtotime((string) $usuario['bloqueado_hasta']) > time()) {
        audit('login_bloqueado', 'usuario', (int) $usuario['id'], ['email' => $email]);
        lanzar_error('NO_AUTENTICADO', AUTH_MENSAJE_GENERICO, 401);
    }

    if (!$claveCorrecta) {
        $intentos = (int) $usuario['intentos_fallidos'] + 1;
        $maximo = max(1, (int) config_get('login_max_intentos', 5));
        if ($intentos >= $maximo) {
            $minutos = max(1, (int) config_get('login_bloqueo_min', 15));
            db_exec(
                'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?',
                'ii',
                [$minutos, (int) $usuario['id']]
            );
        } else {
            db_exec('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?', 'ii', [$intentos, (int) $usuario['id']]);
        }
        audit('login_fallido', 'usuario', (int) $usuario['id'], ['email' => $email, 'motivo' => 'clave_incorrecta', 'intentos' => $intentos]);
        lanzar_error('NO_AUTENTICADO', AUTH_MENSAJE_GENERICO, 401);
    }

    // Credenciales correctas.
    db_exec(
        'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_login = UTC_TIMESTAMP() WHERE id = ?',
        'i',
        [(int) $usuario['id']]
    );
    if (password_needs_rehash((string) $usuario['password_hash'], PASSWORD_DEFAULT, ['cost' => 12])) {
        db_exec('UPDATE usuarios SET password_hash = ? WHERE id = ?', 'si', [auth_hash_password($password), (int) $usuario['id']]);
    }

    if (!RP_CLI && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    unset($_SESSION['csrf_token']);

    $conductorId = $usuario['rol'] === 'conductor'
        ? db_val('SELECT id FROM conductores WHERE usuario_id = ?', 'i', [(int) $usuario['id']])
        : null;
    $ahora = time();

    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['rol'] = (string) $usuario['rol'];
    $_SESSION['nombre'] = (string) $usuario['nombre'];
    $_SESSION['email'] = (string) $usuario['email'];
    $_SESSION['conductor_id'] = $conductorId;
    $_SESSION['debe_cambiar_password'] = (int) $usuario['debe_cambiar_password'];
    $_SESSION['inicio_sesion'] = $ahora;
    $_SESSION['ultima_actividad'] = $ahora;
    $_SESSION['ultima_validacion'] = $ahora;

    $autenticado = auth_usuario(true);
    audit('login_ok', 'usuario', (int) $usuario['id'], ['email' => $email]);
    return $autenticado;
}

function auth_logout(): void {
    $usuario = auth_usuario();
    if ($usuario !== null) {
        audit('logout', 'usuario', $usuario['id']);
    }
    rp_auth_destruir_sesion();
    auth_usuario(true);
}

/**
 * Hash de contraseña (bcrypt, costo 12: el mismo del seed).
 */
function auth_hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

/**
 * Página de inicio de cada rol (relativa a app.url).
 */
function auth_url_inicio(string $rol): string {
    $mapa = [
        'admin'       => 'admin/usuarios.php',
        'despachador' => 'monitor/flota.php',
        'gerente'     => 'gerencia/dashboard.php',
        'conductor'   => 'conductor/',
    ];
    return $mapa[$rol] ?? 'login.php';
}

/**
 * Menú de navegación por rol (docs/00-base/06-roles-y-permisos.md, sección 4).
 * Cada opción: ['texto', 'url' (relativa a app.url), 'icono' (Bootstrap Icons)].
 */
function auth_menu_por_rol(string $rol): array {
    switch ($rol) {
        case 'admin':
            return [
                ['texto' => 'Usuarios',       'url' => 'admin/usuarios.php',       'icono' => 'bi-people'],
                ['texto' => 'Auditoría',      'url' => 'admin/auditoria.php',      'icono' => 'bi-journal-text'],
                ['texto' => 'Configuración',  'url' => 'admin/configuracion.php',  'icono' => 'bi-gear'],
                ['texto' => 'Integraciones',  'url' => 'admin/integraciones.php',  'icono' => 'bi-plug'],
                ['texto' => 'Notificaciones', 'url' => 'admin/notificaciones.php', 'icono' => 'bi-bell'],
                ['texto' => 'Paquetes',       'url' => 'hub/paquetes.php',         'icono' => 'bi-box-seam'],
                ['texto' => 'Rutas',          'url' => 'despacho/rutas.php',       'icono' => 'bi-signpost-split'],
                ['texto' => 'Monitor',        'url' => 'monitor/flota.php',        'icono' => 'bi-broadcast'],
                ['texto' => 'Dashboard',      'url' => 'gerencia/dashboard.php',   'icono' => 'bi-bar-chart'],
            ];
        case 'despachador':
            return [
                ['texto' => 'Monitor de flota', 'url' => 'monitor/flota.php',        'icono' => 'bi-broadcast'],
                ['texto' => 'Rutas',            'url' => 'despacho/rutas.php',       'icono' => 'bi-signpost-split'],
                ['texto' => 'Paquetes',         'url' => 'hub/paquetes.php',         'icono' => 'bi-box-seam'],
                ['texto' => 'Escaneo HUB',      'url' => 'hub/escaneo.php',          'icono' => 'bi-upc-scan'],
                ['texto' => 'Incidencias',      'url' => 'hub/incidencias.php',      'icono' => 'bi-exclamation-triangle'],
                ['texto' => 'Conductores',      'url' => 'despacho/conductores.php', 'icono' => 'bi-person-badge'],
                ['texto' => 'Vehículos',        'url' => 'despacho/vehiculos.php',   'icono' => 'bi-truck'],
                ['texto' => 'Zonas',            'url' => 'despacho/zonas.php',       'icono' => 'bi-map'],
                ['texto' => 'Notificaciones',   'url' => 'admin/notificaciones.php', 'icono' => 'bi-bell'],
            ];
        case 'gerente':
            return [
                ['texto' => 'Dashboard',     'url' => 'gerencia/dashboard.php',  'icono' => 'bi-bar-chart'],
                ['texto' => 'Mapa de calor', 'url' => 'gerencia/mapa_calor.php', 'icono' => 'bi-fire'],
                ['texto' => 'Reportes',      'url' => 'gerencia/reportes.php',   'icono' => 'bi-file-earmark-text'],
                ['texto' => 'Playback',      'url' => 'monitor/playback.php',    'icono' => 'bi-play-circle'],
                ['texto' => 'Auditoría',     'url' => 'admin/auditoria.php',     'icono' => 'bi-journal-text'],
            ];
        case 'conductor':
            return [
                ['texto' => 'Mi ruta', 'url' => 'conductor/', 'icono' => 'bi-signpost'],
            ];
    }
    return [];
}
