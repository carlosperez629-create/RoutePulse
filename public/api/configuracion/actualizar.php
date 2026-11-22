<?php
/** POST configuracion/actualizar.php (admin). clave + valor; valida solo el tipo (y un rango para el radio de geocerca). */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('POST', ['admin'], function (array $in, ?array $usuario): array {
    $d = validar($in, ['clave' => 'requerido|str|max:80']);
    $fila = db_one('SELECT clave, tipo FROM configuracion WHERE clave = ?', 's', [$d['clave']]);
    if (!$fila) {
        error_no_encontrado('El parámetro');
    }
    $v = $in['valor'] ?? null;
    if (is_array($v)) {
        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    $v = trim((string) $v);

    $error = null;
    switch ($fila['tipo']) {
        case 'int':   if (!preg_match('/^-?\d+$/', $v)) { $error = 'Debe ser un número entero.'; } break;
        case 'float': if (!is_numeric($v)) { $error = 'Debe ser un número.'; } break;
        case 'bool':  if (!in_array($v, ['0', '1', 'true', 'false'], true)) { $error = 'Debe ser 0 o 1.'; } else { $v = ($v === '1' || $v === 'true') ? '1' : '0'; } break;
        case 'json':  json_decode($v); if (json_last_error() !== JSON_ERROR_NONE) { $error = 'JSON no válido.'; } break;
        default:      if ($v === '') { $error = 'No puede estar vacío.'; }
    }
    if ($error === null && $d['clave'] === 'geocerca_radio_m' && ((int) $v < 50 || (int) $v > 5000)) {
        $error = 'Debe estar entre 50 y 5000.';
    }
    if ($error !== null) {
        error_validacion(['valor' => $error]);
    }

    config_set($d['clave'], $v, (int) $usuario['id']);
    return db_one('SELECT clave, valor, tipo, descripcion, modulo_duenio FROM configuracion WHERE clave = ?', 's', [$d['clave']]);
});
