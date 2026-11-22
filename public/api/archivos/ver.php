<?php
/**
 * GET /api/archivos/ver.php?id=  (roles: admin, despachador, gerente, conductor)
 * Muestra una firma o foto. A, D y G pueden ver cualquiera; C solo archivos de entregas suyas.
 * Nunca expone la ruta del disco. Cache-Control: private, max-age=300.
 */
require __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador', 'gerente', 'conductor'], function (array $in, ?array $usuario) {
    $d = validar($in, ['id' => 'requerido|int|min:1']);
    $archivoId = (int) $d['id'];

    $archivo = db_one(
        'SELECT id, ruta_relativa, mime, tamano_bytes FROM archivos WHERE id = ?',
        'i',
        [$archivoId]
    );
    if ($archivo === null) {
        error_no_encontrado('El archivo');
    }

    if (($usuario['rol'] ?? '') === 'conductor') {
        $conductorId = auth_conductor_id();
        if ($conductorId === null) {
            error_sin_permiso();
        }
        $propias = (int) db_val(
            'SELECT COUNT(*) FROM entregas
              WHERE conductor_id = ? AND (firma_archivo_id = ? OR foto_archivo_id = ?)',
            'iii',
            [$conductorId, $archivoId, $archivoId]
        );
        if ($propias === 0) {
            error_sin_permiso();
        }
    }

    $extensiones = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = (string) $archivo['mime'];
    if (!isset($extensiones[$mime])) {
        error_no_encontrado('El archivo');
    }

    $raiz = realpath(rp_ruta_storage('uploads'));
    $ruta = $raiz === false ? false : realpath($raiz . '/' . ltrim((string) $archivo['ruta_relativa'], '/\\'));
    if ($raiz === false || $ruta === false || !is_file($ruta) || !str_starts_with($ruta, $raiz . DIRECTORY_SEPARATOR)) {
        error_no_encontrado('El archivo');
    }

    $tamano = filesize($ruta);
    if ($tamano === false) {
        error_no_encontrado('El archivo');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();   // no retener la sesión mientras se envía el archivo
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) $tamano);
        header('Content-Disposition: inline; filename="archivo-' . $archivoId . '.' . $extensiones[$mime] . '"');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
    }

    if (@readfile($ruta) === false) {
        log_app('error', 'archivos/ver: no se pudo leer el archivo', ['archivo_id' => $archivoId]);
    }
    exit;
});
