<?php
/**
 * RoutePulse - Acceso a la base de datos (MySQLi, solo consultas preparadas).
 *
 * Tipos de parámetros ($tipos), como en mysqli_stmt::bind_param:
 *   i = entero, d = decimal (double), s = texto.
 * Para valores DECIMAL donde importa la precisión exacta (dinero) usar 's'.
 * Ej.: db_one('SELECT * FROM paquetes WHERE id = ?', 'i', [$id]);
 *
 * Requiere la extensión mysqlnd (viene con XAMPP y con los hostings habituales).
 */

function db(): mysqli {
    static $conexion = null;

    if ($conexion instanceof mysqli) {
        return $conexion;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $nueva = new mysqli(
            (string) app_cfg('db.host', '127.0.0.1'),
            (string) app_cfg('db.usuario', 'root'),
            (string) app_cfg('db.clave', ''),
            (string) app_cfg('db.nombre', 'routepulse'),
            (int) app_cfg('db.puerto', 3306)
        );
        $nueva->set_charset('utf8mb4');
        $nueva->query("SET time_zone = '+00:00'");
    } catch (mysqli_sql_exception $e) {
        log_app('error', 'No se pudo conectar con la base de datos: ' . $e->getMessage());
        throw new AppError('ERROR_INTERNO', 'No se pudo conectar con la base de datos.', 500, [], $e);
    }

    // Mismo modo SQL en todos los entornos (así los errores aparecen en desarrollo y no en producción).
    try {
        $nueva->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    } catch (mysqli_sql_exception $e) {
        log_app('warning', 'No se pudo fijar sql_mode: ' . $e->getMessage());
    }

    $conexion = $nueva;
    return $conexion;
}

/**
 * Convierte un error de MySQLi en un AppError sin revelar detalles internos.
 */
function db_convertir_error(mysqli_sql_exception $e, string $sql): AppError {
    $errno = (int) $e->getCode();
    log_app('error', 'Error SQL: ' . $e->getMessage(), [
        'errno' => $errno,
        'sql'   => mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 400),
    ]);

    if ($errno === 1062) {
        return new AppError('CONFLICTO', 'Ya existe un registro con esos datos.', 409, [], $e);
    }
    if ($errno === 1451 || $errno === 1452) {
        return new AppError('CONFLICTO', 'La operación no se puede completar porque hay datos relacionados.', 409, [], $e);
    }
    return new AppError('ERROR_INTERNO', 'Ocurrió un problema al consultar la base de datos.', 500, [], $e);
}

/**
 * Prepara y ejecuta una consulta. Devuelve el statement ya ejecutado.
 * Uso interno de db_all, db_one, db_val, db_exec y db_insert.
 */
function db_ejecutar(string $sql, string $tipos = '', array $params = []): mysqli_stmt {
    $params = array_values($params);

    if (strlen($tipos) !== count($params)) {
        throw new InvalidArgumentException('db: la cantidad de tipos (' . strlen($tipos) . ') no coincide con la de parámetros (' . count($params) . ').');
    }
    if ($tipos !== '' && preg_match('/^[ids]+$/', $tipos) !== 1) {
        throw new InvalidArgumentException('db: tipos inválidos "' . $tipos . '" (solo i, d, s).');
    }
    foreach ($params as $indice => $valor) {
        if (is_bool($valor)) {
            $params[$indice] = $valor ? 1 : 0;
        } elseif (is_array($valor) || is_object($valor)) {
            throw new InvalidArgumentException('db: el parámetro ' . $indice . ' no puede ser un arreglo u objeto.');
        }
    }

    $conexion = db();
    try {
        $stmt = $conexion->prepare($sql);
        if ($tipos !== '') {
            $stmt->bind_param($tipos, ...$params);
        }
        $stmt->execute();
        return $stmt;
    } catch (mysqli_sql_exception $e) {
        throw db_convertir_error($e, $sql);
    }
}

/**
 * Todas las filas como arreglos asociativos.
 */
function db_all(string $sql, string $tipos = '', array $params = []): array {
    $stmt = db_ejecutar($sql, $tipos, $params);
    try {
        $resultado = $stmt->get_result();
        return $resultado === false ? [] : $resultado->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

/**
 * Una fila (la primera) o null.
 */
function db_one(string $sql, string $tipos = '', array $params = []): ?array {
    $stmt = db_ejecutar($sql, $tipos, $params);
    try {
        $resultado = $stmt->get_result();
        if ($resultado === false) {
            return null;
        }
        $fila = $resultado->fetch_assoc();
        return $fila === null || $fila === false ? null : $fila;
    } finally {
        $stmt->close();
    }
}

/**
 * Primer campo de la primera fila, o null.
 */
function db_val(string $sql, string $tipos = '', array $params = []): mixed {
    $stmt = db_ejecutar($sql, $tipos, $params);
    try {
        $resultado = $stmt->get_result();
        if ($resultado === false) {
            return null;
        }
        $fila = $resultado->fetch_row();
        return ($fila === null || $fila === false) ? null : $fila[0];
    } finally {
        $stmt->close();
    }
}

/**
 * UPDATE / DELETE. Devuelve las filas afectadas.
 */
function db_exec(string $sql, string $tipos = '', array $params = []): int {
    $stmt = db_ejecutar($sql, $tipos, $params);
    $afectadas = (int) $stmt->affected_rows;
    $stmt->close();
    return $afectadas;
}

/**
 * INSERT. Devuelve el id autogenerado.
 */
function db_insert(string $sql, string $tipos = '', array $params = []): int {
    $stmt = db_ejecutar($sql, $tipos, $params);
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Nivel de anidamiento de transacciones (0 = ninguna abierta).
 */
function db_tx_nivel(?int $establecer = null): int {
    static $nivel = 0;
    if ($establecer !== null) {
        $nivel = $establecer;
    }
    return $nivel;
}

function db_en_transaccion(): bool {
    return db_tx_nivel() > 0;
}

/**
 * Ejecuta $fn dentro de una transacción: confirma si termina bien; revierte y
 * relanza si lanza una excepción. ANIDABLE: si ya hay una transacción abierta,
 * solo ejecuta $fn (la transacción externa decide el commit o el rollback).
 */
function db_tx(callable $fn): mixed {
    if (db_tx_nivel() > 0) {
        db_tx_nivel(db_tx_nivel() + 1);
        try {
            return $fn();
        } finally {
            db_tx_nivel(db_tx_nivel() - 1);
        }
    }

    $conexion = db();
    $conexion->begin_transaction();
    db_tx_nivel(1);
    try {
        $resultado = $fn();
        $conexion->commit();
        db_tx_nivel(0);
        return $resultado;
    } catch (Throwable $e) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
            log_app('error', 'No se pudo revertir la transacción: ' . $ignorado->getMessage());
        }
        db_tx_nivel(0);
        throw $e;
    }
}

/**
 * Marcadores para IN (...): db_in([1,2,3]) → "?,?,?". Con un arreglo vacío devuelve
 * "NULL" (IN (NULL) no coincide con nada y no agrega parámetros).
 */
function db_in(array $valores): string {
    $cantidad = count($valores);
    return $cantidad === 0 ? 'NULL' : implode(',', array_fill(0, $cantidad, '?'));
}

/**
 * Cadena de tipos para una lista de valores: db_tipos([1,'a',2.5]) → "isd".
 */
function db_tipos(array $valores): string {
    $tipos = '';
    foreach ($valores as $valor) {
        if (is_int($valor) || is_bool($valor)) {
            $tipos .= 'i';
        } elseif (is_float($valor)) {
            $tipos .= 'd';
        } else {
            $tipos .= 's';
        }
    }
    return $tipos;
}

/**
 * Texto seguro para LIKE: escapa % _ y \. Ej.: db_like('50%') → "%50\%%".
 */
function db_like(string $texto): string {
    return '%' . addcslashes($texto, '%_\\') . '%';
}

/**
 * Convierte campos de una fila a número (DECIMAL llega como texto) y a booleano
 * (TINYINT(1)). Útil antes de enviar la fila en la respuesta JSON.
 */
function fila_tipos(array $fila, array $numericos = [], array $booleanos = []): array {
    foreach ($numericos as $campo) {
        if (array_key_exists($campo, $fila) && $fila[$campo] !== null && is_numeric($fila[$campo])) {
            $fila[$campo] = $fila[$campo] + 0;
        }
    }
    foreach ($booleanos as $campo) {
        if (array_key_exists($campo, $fila) && $fila[$campo] !== null) {
            $fila[$campo] = (bool) $fila[$campo];
        }
    }
    return $fila;
}

function filas_tipos(array $filas, array $numericos = [], array $booleanos = []): array {
    foreach ($filas as $indice => $fila) {
        $filas[$indice] = fila_tipos($fila, $numericos, $booleanos);
    }
    return $filas;
}
