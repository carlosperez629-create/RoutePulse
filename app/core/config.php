<?php
/**
 * RoutePulse - Configuración.
 *
 *  - app_cfg()    lee config/config.php (credenciales, URL, entorno). Notación de puntos.
 *  - config_get() lee la tabla `configuracion` (parámetros del sistema), ya con su tipo.
 *  - config_set() actualiza un parámetro de la tabla `configuracion`.
 */

/**
 * Lee un valor de config/config.php. Ej.: app_cfg('db.host'), app_cfg('app.url').
 * Lanza RuntimeException si falta el archivo (bootstrap.php lo atrapa y muestra un aviso).
 */
function app_cfg(string $ruta, mixed $default = null): mixed {
    static $configuracion = null;

    if ($configuracion === null) {
        $archivo = RP_RAIZ . '/config/config.php';
        if (!is_file($archivo)) {
            throw new RuntimeException('Falta config/config.php (copiar config/config.example.php).');
        }
        $cargado = require $archivo;
        if (!is_array($cargado)) {
            throw new RuntimeException('config/config.php debe devolver un arreglo (return [...]).');
        }
        $configuracion = $cargado;
    }

    $actual = $configuracion;
    foreach (explode('.', $ruta) as $clave) {
        if (is_array($actual) && array_key_exists($clave, $actual)) {
            $actual = $actual[$clave];
        } else {
            return $default;
        }
    }
    return $actual;
}

/**
 * Convierte el texto guardado en la tabla `configuracion` al tipo declarado.
 */
function config_convertir(string $valor, string $tipo): mixed {
    switch ($tipo) {
        case 'int':
            return (int) $valor;
        case 'float':
            return (float) $valor;
        case 'bool':
            return in_array(strtolower(trim($valor)), ['1', 'true', 'si', 'sí', 'on'], true);
        case 'json':
            $decodificado = json_decode($valor, true);
            return $decodificado;
        default:
            return $valor;
    }
}

/**
 * Todos los parámetros de la tabla `configuracion` (cacheados por petición).
 * Si la base de datos falla, devuelve [] y se usan los valores por defecto.
 *
 * @return array<string, array{valor: mixed, tipo: string}>
 */
function config_todos(bool $recargar = false): array {
    static $cache = null;

    if ($cache === null || $recargar) {
        $cache = [];
        try {
            foreach (db_all('SELECT clave, valor, tipo FROM configuracion') as $fila) {
                $cache[$fila['clave']] = [
                    'valor' => config_convertir((string) $fila['valor'], (string) $fila['tipo']),
                    'tipo'  => (string) $fila['tipo'],
                ];
            }
        } catch (Throwable $e) {
            $cache = [];
            log_app('error', 'No se pudo leer la tabla configuracion: ' . $e->getMessage());
        }
    }
    return $cache;
}

/**
 * Lee un parámetro del sistema. Ej.: config_get('geocerca_radio_m', 500).
 */
function config_get(string $clave, mixed $default = null): mixed {
    $todos = config_todos();
    return array_key_exists($clave, $todos) ? $todos[$clave]['valor'] : $default;
}

/**
 * Convierte un valor al texto que se guarda en la tabla, validando su tipo.
 * Lanza VALIDACION (campo "valor") si no corresponde al tipo.
 */
function config_a_texto(mixed $valor, string $tipo): string {
    switch ($tipo) {
        case 'int':
            if (is_bool($valor) || filter_var($valor, FILTER_VALIDATE_INT) === false) {
                error_validacion(['valor' => 'Debe ser un número entero.']);
            }
            return (string) (int) $valor;

        case 'float':
            if (is_bool($valor) || !is_numeric($valor)) {
                error_validacion(['valor' => 'Debe ser un número.']);
            }
            return (string) (float) $valor;

        case 'bool':
            if ($valor === true || $valor === 1 || $valor === '1' || $valor === 'true') {
                return '1';
            }
            if ($valor === false || $valor === 0 || $valor === '0' || $valor === 'false') {
                return '0';
            }
            error_validacion(['valor' => 'Debe ser verdadero o falso.']);

        case 'json':
            if (is_string($valor)) {
                json_decode($valor);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_validacion(['valor' => 'Debe ser un JSON válido.']);
                }
                $texto = $valor;
            } else {
                $texto = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($texto === false) {
                    error_validacion(['valor' => 'Debe ser un JSON válido.']);
                }
            }
            break;

        default:
            if (!is_scalar($valor)) {
                error_validacion(['valor' => 'Debe ser un texto.']);
            }
            $texto = (string) $valor;
    }

    if (mb_strlen($texto) > 500) {
        error_validacion(['valor' => 'Debe tener como máximo 500 caracteres.']);
    }
    return $texto;
}

/**
 * Actualiza un parámetro de la tabla `configuracion` y audita el cambio.
 * Los rangos específicos de cada parámetro (por ejemplo geocerca_radio_m entre
 * 50 y 5000) los valida el endpoint configuracion/actualizar.php.
 */
function config_set(string $clave, mixed $valor, ?int $usuarioId = null): void {
    $fila = db_one('SELECT clave, valor, tipo FROM configuracion WHERE clave = ?', 's', [$clave]);
    if ($fila === null) {
        error_no_encontrado('El parámetro');
    }

    $texto = config_a_texto($valor, (string) $fila['tipo']);
    db_exec('UPDATE configuracion SET valor = ? WHERE clave = ?', 'ss', [$texto, $clave]);
    config_todos(true);

    audit('configuracion_actualizar', 'configuracion', $clave, [
        'antes'   => $fila['valor'],
        'despues' => $texto,
    ], $usuarioId);
}
