<?php
/**
 * RoutePulse - Entrada de datos: validar(), paginación, orden seguro y escape.
 *
 * validar($in, $reglas) devuelve un arreglo YA TIPADO con solo las claves
 * declaradas. Si hay errores lanza VALIDACION (422) con "campos".
 *
 * Reglas (separadas por "|"):
 *   requerido | opcional      Obligatorio / puede faltar (entonces devuelve null).
 *                             Si no se escribe ninguna de las dos, el campo es REQUERIDO.
 *   int num bool str          Tipos básicos (si no se indica tipo, se usa str).
 *   crudo                     Texto sin recortar ni limpiar (para contraseñas).
 *   email telefono uuid       Formatos.
 *   fecha                     AAAA-MM-DD.
 *   fecha_hora                ISO 8601 → "Y-m-d H:i:s" en UTC.
 *   fecha_hora_ms             ISO 8601 → "Y-m-d H:i:s.v" en UTC (milisegundos).
 *   lat lng                   Coordenadas en rango.
 *   lista_int                 Lista de enteros (arreglo o texto "1,2,3").
 *   enum:a,b,c                Uno de los valores.
 *   min:N max:N               Valor mínimo/máximo (números) o longitud (texto) o cantidad (lista).
 *
 * NOTA: validar() NO pone límites de longitud por defecto. Agregar max:N según la
 * columna de la base de datos (por ejemplo VARCHAR(100) → max:100).
 */

class RpValidacionCampo extends Exception {
}

function rp_validar_vacio(mixed $valor): bool {
    if ($valor === null) {
        return true;
    }
    if (is_string($valor) && trim($valor) === '') {
        return true;
    }
    if (is_array($valor) && count($valor) === 0) {
        return true;
    }
    return false;
}

function rp_validar_parsear(string $regla): array {
    $tiposValidos = [
        'int', 'num', 'bool', 'str', 'crudo', 'email', 'telefono', 'fecha',
        'fecha_hora', 'fecha_hora_ms', 'lat', 'lng', 'lista_int', 'uuid',
    ];
    $resultado = ['requerido' => true, 'tipo' => null, 'min' => null, 'max' => null, 'enum' => null];

    foreach (explode('|', $regla) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if ($token === 'requerido') {
            $resultado['requerido'] = true;
        } elseif ($token === 'opcional') {
            $resultado['requerido'] = false;
        } elseif (in_array($token, $tiposValidos, true)) {
            $resultado['tipo'] = $token;
        } elseif (str_starts_with($token, 'min:')) {
            $numero = substr($token, 4);
            if (!is_numeric($numero)) {
                throw new InvalidArgumentException('Regla de validación inválida: ' . $token);
            }
            $resultado['min'] = $numero + 0;
        } elseif (str_starts_with($token, 'max:')) {
            $numero = substr($token, 4);
            if (!is_numeric($numero)) {
                throw new InvalidArgumentException('Regla de validación inválida: ' . $token);
            }
            $resultado['max'] = $numero + 0;
        } elseif (str_starts_with($token, 'enum:')) {
            $opciones = array_map('trim', explode(',', substr($token, 5)));
            $resultado['enum'] = $opciones;
            $resultado['tipo'] = 'enum';
        } else {
            throw new InvalidArgumentException('Regla de validación desconocida: ' . $token);
        }
    }

    if ($resultado['tipo'] === null) {
        $resultado['tipo'] = 'str';
    }
    return $resultado;
}

function rp_v_entero(mixed $valor): int {
    if (is_int($valor)) {
        return $valor;
    }
    if (is_float($valor) && floor($valor) === $valor && abs($valor) < 9.0E15) {
        return (int) $valor;
    }
    if (is_string($valor)) {
        $texto = trim($valor);
        if (preg_match('/^-?\d{1,18}$/', $texto) === 1) {
            return (int) $texto;
        }
    }
    throw new RpValidacionCampo('Debe ser un número entero.');
}

function rp_v_numero(mixed $valor): float {
    if (is_int($valor) || is_float($valor)) {
        return (float) $valor;
    }
    if (is_string($valor)) {
        $texto = trim($valor);
        if (preg_match('/^-?\d+(\.\d+)?$/', $texto) === 1) {
            return (float) $texto;
        }
    }
    throw new RpValidacionCampo('Debe ser un número.');
}

function rp_v_bool(mixed $valor): bool {
    if (is_bool($valor)) {
        return $valor;
    }
    if (is_int($valor) && ($valor === 0 || $valor === 1)) {
        return $valor === 1;
    }
    if (is_string($valor)) {
        $texto = strtolower(trim($valor));
        if (in_array($texto, ['1', 'true', 'on', 'si', 'sí'], true)) {
            return true;
        }
        if (in_array($texto, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
    }
    throw new RpValidacionCampo('Debe ser verdadero o falso.');
}

/**
 * Texto UTF-8 válido. Con $recortar: quita espacios en los extremos y caracteres de control.
 */
function rp_v_texto(mixed $valor, bool $recortar = true): string {
    if (is_int($valor) || is_float($valor)) {
        $valor = (string) $valor;
    }
    if (!is_string($valor)) {
        throw new RpValidacionCampo('Debe ser un texto.');
    }
    if (!mb_check_encoding($valor, 'UTF-8')) {
        throw new RpValidacionCampo('Contiene caracteres no válidos.');
    }
    if ($recortar) {
        $valor = trim($valor);
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $valor);
        $valor = $limpio === null ? $valor : $limpio;
    } elseif (str_contains($valor, "\0")) {
        throw new RpValidacionCampo('Contiene caracteres no válidos.');
    }
    return $valor;
}

function rp_v_limites_texto(string $valor, array $p): void {
    $largo = mb_strlen($valor);
    if ($p['min'] !== null && $largo < $p['min']) {
        throw new RpValidacionCampo('Debe tener al menos ' . $p['min'] . ' caracteres.');
    }
    if ($p['max'] !== null && $largo > $p['max']) {
        throw new RpValidacionCampo('Debe tener como máximo ' . $p['max'] . ' caracteres.');
    }
}

function rp_v_limites_numero(int|float $valor, array $p): void {
    if ($p['min'] !== null && $valor < $p['min']) {
        throw new RpValidacionCampo('Debe ser mayor o igual a ' . $p['min'] . '.');
    }
    if ($p['max'] !== null && $valor > $p['max']) {
        throw new RpValidacionCampo('Debe ser menor o igual a ' . $p['max'] . '.');
    }
}

/**
 * ISO 8601 → DateTimeImmutable en UTC. Sin zona horaria se asume UTC.
 */
function rp_v_fecha_hora(mixed $valor): DateTimeImmutable {
    $texto = rp_v_texto($valor);
    $patron = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-]\d{2}:?\d{2})?$/i';
    if (preg_match($patron, $texto, $m) !== 1) {
        throw new RpValidacionCampo('Fecha y hora inválida. Usa el formato AAAA-MM-DDTHH:MM:SSZ.');
    }

    $anio = (int) $m[1];
    $mes = (int) $m[2];
    $dia = (int) $m[3];
    $hora = (int) $m[4];
    $minuto = (int) $m[5];
    $segundo = (isset($m[6]) && $m[6] !== '') ? (int) $m[6] : 0;

    if (!checkdate($mes, $dia, $anio) || $hora > 23 || $minuto > 59 || $segundo > 59) {
        throw new RpValidacionCampo('La fecha u hora no existe.');
    }

    $fraccion = (isset($m[7]) && $m[7] !== '') ? '.' . $m[7] : '';
    $zona = (isset($m[8]) && $m[8] !== '') ? strtoupper($m[8]) : 'Z';
    if (preg_match('/^[+-]\d{4}$/', $zona) === 1) {
        $zona = substr($zona, 0, 3) . ':' . substr($zona, 3);
    }
    if ($zona !== 'Z') {
        $horasZona = (int) substr($zona, 1, 2);
        $minutosZona = (int) substr($zona, 4, 2);
        if ($horasZona > 14 || $minutosZona > 59) {
            throw new RpValidacionCampo('La zona horaria no es válida.');
        }
    }

    $iso = sprintf('%04d-%02d-%02dT%02d:%02d:%02d%s%s', $anio, $mes, $dia, $hora, $minuto, $segundo, $fraccion, $zona);
    try {
        $fecha = new DateTimeImmutable($iso);
    } catch (Exception $e) {
        throw new RpValidacionCampo('Fecha y hora inválida.');
    }
    return $fecha->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Valida y convierte UN valor según las reglas ya parseadas.
 */
function rp_validar_valor(mixed $valor, array $p): mixed {
    switch ($p['tipo']) {
        case 'int':
            $numero = rp_v_entero($valor);
            rp_v_limites_numero($numero, $p);
            return $numero;

        case 'num':
            $numero = rp_v_numero($valor);
            rp_v_limites_numero($numero, $p);
            return $numero;

        case 'bool':
            return rp_v_bool($valor);

        case 'str':
            $texto = rp_v_texto($valor, true);
            rp_v_limites_texto($texto, $p);
            return $texto;

        case 'crudo':
            $texto = rp_v_texto($valor, false);
            rp_v_limites_texto($texto, $p);
            return $texto;

        case 'email':
            $texto = strtolower(rp_v_texto($valor, true));
            if (strlen($texto) > 254 || filter_var($texto, FILTER_VALIDATE_EMAIL) === false) {
                throw new RpValidacionCampo('Correo electrónico inválido.');
            }
            rp_v_limites_texto($texto, $p);
            return $texto;

        case 'telefono':
            $texto = rp_v_texto($valor, true);
            $texto = (string) preg_replace('/[\s\-\(\)\.]/', '', $texto);
            if (preg_match('/^\+?[0-9]{7,15}$/', $texto) !== 1) {
                throw new RpValidacionCampo('Teléfono inválido. Usa formato internacional, por ejemplo +584121234567.');
            }
            return $texto;

        case 'fecha':
            $texto = rp_v_texto($valor, true);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new RpValidacionCampo('Fecha inválida. Usa el formato AAAA-MM-DD.');
            }
            return $texto;

        case 'fecha_hora':
            return rp_v_fecha_hora($valor)->format('Y-m-d H:i:s');

        case 'fecha_hora_ms':
            return rp_v_fecha_hora($valor)->format('Y-m-d H:i:s.v');

        case 'lat':
            $numero = rp_v_numero($valor);
            if ($numero < -90 || $numero > 90) {
                throw new RpValidacionCampo('La latitud debe estar entre -90 y 90.');
            }
            return $numero;

        case 'lng':
            $numero = rp_v_numero($valor);
            if ($numero < -180 || $numero > 180) {
                throw new RpValidacionCampo('La longitud debe estar entre -180 y 180.');
            }
            return $numero;

        case 'lista_int':
            if (is_string($valor)) {
                $valor = explode(',', $valor);
            }
            if (!is_array($valor)) {
                throw new RpValidacionCampo('Debe ser una lista de números enteros.');
            }
            $lista = [];
            foreach ($valor as $elemento) {
                try {
                    $lista[] = rp_v_entero($elemento);
                } catch (RpValidacionCampo $e) {
                    throw new RpValidacionCampo('Debe ser una lista de números enteros.');
                }
            }
            $lista = array_values(array_unique($lista));
            if (count($lista) > 1000) {
                throw new RpValidacionCampo('Hay demasiados elementos (máximo 1000).');
            }
            if ($p['min'] !== null && count($lista) < $p['min']) {
                throw new RpValidacionCampo('Debe tener al menos ' . $p['min'] . ' elementos.');
            }
            if ($p['max'] !== null && count($lista) > $p['max']) {
                throw new RpValidacionCampo('Debe tener como máximo ' . $p['max'] . ' elementos.');
            }
            return $lista;

        case 'uuid':
            $texto = strtolower(rp_v_texto($valor, true));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $texto) !== 1) {
                throw new RpValidacionCampo('Identificador (UUID) inválido.');
            }
            return $texto;

        case 'enum':
            $texto = rp_v_texto($valor, true);
            if (!in_array($texto, $p['enum'], true)) {
                throw new RpValidacionCampo('Debe ser uno de: ' . implode(', ', $p['enum']) . '.');
            }
            return $texto;
    }

    throw new InvalidArgumentException('Tipo de validación no soportado: ' . $p['tipo']);
}

/**
 * Valida $in contra $reglas y devuelve los valores limpios y tipados.
 * Lanza VALIDACION (422) con el detalle por campo si algo no cumple.
 */
function validar(array $in, array $reglas): array {
    $limpio = [];
    $errores = [];

    foreach ($reglas as $campo => $regla) {
        $campo = (string) $campo;
        $parseada = rp_validar_parsear((string) $regla);
        $valor = array_key_exists($campo, $in) ? $in[$campo] : null;

        if (rp_validar_vacio($valor)) {
            if ($parseada['requerido']) {
                $errores[$campo] = 'Este campo es obligatorio.';
            } else {
                $limpio[$campo] = null;
            }
            continue;
        }

        try {
            $limpio[$campo] = rp_validar_valor($valor, $parseada);
        } catch (RpValidacionCampo $e) {
            $errores[$campo] = $e->getMessage();
        }
    }

    if ($errores !== []) {
        error_validacion($errores);
    }
    return $limpio;
}

/**
 * Página, tamaño de página y desplazamiento ya saneados: [$page, $por_pagina, $offset].
 */
function paginacion(array $in, int $max = 100): array {
    $page = isset($in['page']) ? (int) $in['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    if ($page > 100000) {
        $page = 100000;
    }

    $porPagina = isset($in['por_pagina']) ? (int) $in['por_pagina'] : 25;
    if ($porPagina < 1) {
        $porPagina = 25;
    }
    if ($porPagina > $max) {
        $porPagina = $max;
    }

    return [$page, $porPagina, ($page - 1) * $porPagina];
}

/**
 * Metadatos de paginación para api_meta().
 */
function meta_paginacion(int $page, int $porPagina, int $total): array {
    return [
        'page'       => $page,
        'por_pagina' => $porPagina,
        'total'      => $total,
        'paginas'    => max(1, (int) ceil($total / max(1, $porPagina))),
    ];
}

/**
 * Fragmento ORDER BY seguro. $orden llega como "campo_asc" o "campo_desc".
 * $permitidos: lista de campos ['created_at','estado'] o mapa nombre_api => expresión SQL
 * de confianza ['creado' => 'p.created_at']. Si no es válido, se usa $defecto.
 *
 * Ej.: 'ORDER BY ' . orden_seguro($in['orden'] ?? null, ['created_at', 'estado'], 'created_at_desc')
 */
function orden_seguro(?string $orden, array $permitidos, string $defecto): string {
    $mapa = [];
    foreach ($permitidos as $clave => $valor) {
        if (is_int($clave)) {
            $mapa[(string) $valor] = (string) $valor;
        } else {
            $mapa[(string) $clave] = (string) $valor;
        }
    }

    foreach ([$orden, $defecto] as $candidato) {
        if ($candidato === null) {
            continue;
        }
        if (preg_match('/^([a-z0-9_]+)_(asc|desc)$/', $candidato, $m) === 1 && isset($mapa[$m[1]])) {
            return $mapa[$m[1]] . ' ' . strtoupper($m[2]);
        }
    }
    throw new InvalidArgumentException('orden_seguro: el orden por defecto no es válido.');
}

/**
 * Escapa texto para imprimirlo en HTML.
 */
function e(?string $texto): string {
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Mezcla de query (GET) y cuerpo (JSON, formulario o multipart) de la petición.
 * El cuerpo tiene prioridad sobre la query.
 */
function rp_leer_entrada(string $metodo): array {
    $consulta = $_GET;
    if ($metodo === 'GET') {
        return $consulta;
    }

    $tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($tipo, 'application/json')) {
        $crudo = entrada_cuerpo_crudo();
        if (strlen($crudo) > 5 * 1024 * 1024) {
            lanzar_error('PARAMETROS', 'La petición es demasiado grande.', 400);
        }
        if (trim($crudo) === '') {
            return $consulta;
        }
        $json = json_decode($crudo, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            lanzar_error('PARAMETROS', 'El cuerpo de la petición no es un JSON válido.', 400);
        }
        return array_merge($consulta, $json);
    }

    return array_merge($consulta, $_POST);
}
