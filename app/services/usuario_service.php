<?php
/**
 * RoutePulse - Servicio de usuarios (M8).
 *
 * Funciones del contrato (docs/00-base/07-servicios-internos.md, sección B):
 *   usuario_crear(), usuario_obtener(), auth_politica_password().
 * Además: usuario_cambiar_password() y usuario_generar_password_temporal().
 *
 * Los endpoints usuarios/* (listar, ver, crear, actualizar, resetear) se agregan
 * en la etapa B de M8.
 */

/**
 * Valida la política de contraseñas: mínimo password_min_largo caracteres (nunca
 * menos de 8), al menos una letra y un número, y sin contener el correo.
 * Lanza VALIDACION en el campo $campo.
 */
function auth_politica_password(string $password, ?string $email = null, string $campo = 'password'): void {
    $minimo = max(8, (int) config_get('password_min_largo', 10));
    $requisitos = [];

    if (mb_strlen($password) < $minimo) {
        $requisitos[] = 'tener al menos ' . $minimo . ' caracteres';
    }
    if (preg_match('/\p{L}/u', $password) !== 1) {
        $requisitos[] = 'incluir una letra';
    }
    if (preg_match('/[0-9]/', $password) !== 1) {
        $requisitos[] = 'incluir un número';
    }
    if ($email !== null && $email !== '') {
        $correo = mb_strtolower($email);
        $local = explode('@', $correo)[0];
        $minuscula = mb_strtolower($password);
        if (mb_strpos($minuscula, $correo) !== false || (mb_strlen($local) >= 3 && mb_strpos($minuscula, $local) !== false)) {
            $requisitos[] = 'no contener tu correo';
        }
    }

    if ($requisitos !== []) {
        error_validacion([$campo => 'La contraseña debe ' . implode(', ', $requisitos) . '.']);
    }
}

/**
 * Contraseña temporal de 12 caracteres (letras y números sin ambigüedades).
 */
function usuario_generar_password_temporal(): string {
    $letras = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $numeros = '23456789';
    $todos = $letras . $numeros;

    $caracteres = [
        $letras[random_int(0, strlen($letras) - 1)],
        $numeros[random_int(0, strlen($numeros) - 1)],
    ];
    while (count($caracteres) < 12) {
        $caracteres[] = $todos[random_int(0, strlen($todos) - 1)];
    }
    // Mezcla con random_int (Fisher-Yates).
    for ($i = count($caracteres) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $temporal = $caracteres[$i];
        $caracteres[$i] = $caracteres[$j];
        $caracteres[$j] = $temporal;
    }
    return implode('', $caracteres);
}

/**
 * Crea un usuario y devuelve su id.
 *
 * $datos: rol (admin|despachador|gerente|conductor), nombre, email, telefono?, password?
 * Si no se envía password se genera una temporal, se marca debe_cambiar_password = 1
 * y queda en $passwordTemporal (mostrarla UNA sola vez; nunca se guarda en claro).
 * Si se crea un conductor, la fila de `conductores` la crea M1 (conductores/crear).
 *
 * @param string|null $passwordTemporal Se llena solo si se generó una contraseña temporal
 */
function usuario_crear(array $datos, ?string &$passwordTemporal = null): int {
    $d = validar($datos, [
        'rol'      => 'requerido|enum:admin,despachador,gerente,conductor',
        'nombre'   => 'requerido|str|min:2|max:100',
        'email'    => 'requerido|email|max:150',
        'telefono' => 'opcional|telefono|max:30',
        'password' => 'opcional|crudo|max:200',
    ]);

    $esTemporal = false;
    $passwordTemporal = null;
    if ($d['password'] === null) {
        $d['password'] = usuario_generar_password_temporal();
        $passwordTemporal = $d['password'];
        $esTemporal = true;
    }
    auth_politica_password($d['password'], $d['email']);

    if ((int) db_val('SELECT COUNT(*) FROM usuarios WHERE email = ?', 's', [$d['email']]) > 0) {
        error_validacion(['email' => 'Ya existe un usuario con ese correo.']);
    }

    $id = db_insert(
        'INSERT INTO usuarios (rol, nombre, email, password_hash, telefono, activo, debe_cambiar_password) VALUES (?, ?, ?, ?, ?, 1, ?)',
        'sssssi',
        [$d['rol'], $d['nombre'], $d['email'], auth_hash_password($d['password']), $d['telefono'], $esTemporal ? 1 : 0]
    );

    audit('usuario_crear', 'usuario', $id, ['email' => $d['email'], 'rol' => $d['rol']]);
    return $id;
}

/**
 * Usuario sin password_hash, o null si no existe.
 */
function usuario_obtener(int $id): ?array {
    $fila = db_one(
        'SELECT id, rol, nombre, email, telefono, activo, intentos_fallidos, bloqueado_hasta, ultimo_login, debe_cambiar_password, created_at, updated_at
         FROM usuarios WHERE id = ?',
        'i',
        [$id]
    );
    if ($fila === null) {
        return null;
    }
    return fila_tipos($fila, ['id', 'intentos_fallidos'], ['activo', 'debe_cambiar_password']);
}

/**
 * Cambia la contraseña propia: verifica la actual, aplica la política, no permite
 * repetir la actual, quita debe_cambiar_password, regenera la sesión y audita.
 */
function usuario_cambiar_password(int $usuarioId, string $passwordActual, string $passwordNueva): void {
    $fila = db_one('SELECT id, email, password_hash FROM usuarios WHERE id = ? AND activo = 1', 'i', [$usuarioId]);
    if ($fila === null) {
        error_no_encontrado('El usuario');
    }

    if (!password_verify($passwordActual, (string) $fila['password_hash'])) {
        error_validacion(['password_actual' => 'La contraseña actual no es correcta.']);
    }
    if (hash_equals($passwordActual, $passwordNueva)) {
        error_validacion(['password_nueva' => 'La nueva contraseña no puede ser igual a la actual.']);
    }
    auth_politica_password($passwordNueva, (string) $fila['email'], 'password_nueva');

    db_exec(
        'UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 0 WHERE id = ?',
        'si',
        [auth_hash_password($passwordNueva), $usuarioId]
    );
    audit('password_cambiar', 'usuario', $usuarioId);

    if (!empty($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] === $usuarioId) {
        $_SESSION['debe_cambiar_password'] = 0;
        if (!RP_CLI && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        auth_usuario(true);
    }
}
