<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/**
 * POST paquetes/importar_csv.php (A, D) multipart con "archivo": CSV UTF-8, coma, primera fila = encabezados
 * con los mismos nombres de los campos de crear. Máximo 500 filas. No se detiene ante errores.
 */
api_run('POST', ['admin', 'despachador'], function (array $in, ?array $usuario) {
    $archivo = $_FILES['archivo'] ?? null;
    if (!$archivo || (int) $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        lanzar_error('PARAMETROS', 'Adjunta el archivo CSV en el campo «archivo».', 400);
    }
    if ((int) $archivo['size'] > 2 * 1024 * 1024) {
        error_validacion(['archivo' => 'El archivo supera 2 MB.']);
    }
    $fh = fopen($archivo['tmp_name'], 'r');
    if (!$fh) {
        lanzar_error('PARAMETROS', 'No se pudo leer el archivo.', 400);
    }
    $encabezados = fgetcsv($fh, 0, ',', '"', '');
    if (!$encabezados) {
        fclose($fh);
        error_validacion(['archivo' => 'El archivo está vacío.']);
    }
    $encabezados[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $encabezados[0]);
    $encabezados = array_map(static fn($h) => strtolower(trim((string) $h)), $encabezados);

    $creados = 0;
    $errores = [];
    $fila = 1;
    while (($campos = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $fila++;
        if ($fila - 1 > 500) {
            $errores[] = ['fila' => $fila, 'mensaje' => 'Se alcanzó el máximo de 500 filas; el resto no se procesó.'];
            break;
        }
        if (count($campos) === 1 && trim((string) $campos[0]) === '') {
            continue;
        }
        $datos = [];
        foreach ($encabezados as $i => $nombre) {
            if ($nombre !== '' && isset($campos[$i]) && trim($campos[$i]) !== '') {
                $datos[$nombre] = trim($campos[$i]);
            }
        }
        try {
            paquete_crear($datos, (int) $usuario['id']);
            $creados++;
        } catch (AppError $e) {
            $detalle = $e->campos ? ' (' . implode('; ', $e->campos) . ')' : '';
            $errores[] = ['fila' => $fila, 'mensaje' => $e->getMessage() . $detalle];
        }
    }
    fclose($fh);
    return ['creados' => $creados, 'errores' => $errores];
});
