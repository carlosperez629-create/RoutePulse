<?php
/**
 * archivo_service.php - M4 App del conductor: firmas y fotos de entrega.
 * Contrato: 00-base/07-servicios-internos.md, sección F. Reglas: modulos/M4-app-conductor.md, §7.1.
 *
 * Los archivos se guardan FUERA de public/, en storage/uploads/AAAA/MM/<sha256 (16)>_<aleatorio 8>.<ext>
 * (nunca con el nombre original). El tipo se verifica con el contenido real (finfo), no con la extensión.
 * No se reduce el tamaño de las fotos en el servidor: la app del conductor las reduce a 1600 px antes de subirlas
 * (así se respeta la orientación de la cámara y se queda por debajo del límite de 5 MB).
 */

const ARCHIVO_MAX_BYTES = 5242880;            // 5 MB
const ARCHIVO_MAX_PIXELES = 50000000;         // evita imágenes "bomba" de descompresión
const ARCHIVO_TIPOS = ['firma', 'foto_entrega', 'foto_incidencia', 'otro'];
const ARCHIVO_MIME_EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

/**
 * Guarda un archivo subido por multipart ($_FILES[$campo]).
 *
 * @param string     $campo     Nombre del campo del formulario ('firma' o 'foto').
 * @param string     $tipo      'firma' | 'foto_entrega' | 'foto_incidencia' | 'otro'.
 * @param float|null $lat       Ubicación de la foto (la que manda la app; el EXIF se ignora).
 * @param float|null $lng
 * @param int|null   $usuarioId Usuario que sube (archivos.subido_por).
 * @return int archivos.id
 * @throws AppError VALIDACION si falta, es muy grande o no es PNG/JPEG/WebP.
 */
function archivo_guardar_subido(string $campo, string $tipo, ?float $lat, ?float $lng, ?int $usuarioId): int
{
    $subido = $_FILES[$campo] ?? null;
    if (!is_array($subido) || !isset($subido['error']) || is_array($subido['error'])) {
        error_validacion([$campo => 'Falta el archivo.']);
    }
    if ((int) $subido['error'] !== UPLOAD_ERR_OK) {
        error_validacion([$campo => archivo_mensaje_error_subida((int) $subido['error'])]);
    }
    $temporal = (string) ($subido['tmp_name'] ?? '');
    if ($temporal === '' || !is_uploaded_file($temporal)) {
        error_validacion([$campo => 'No se pudo recibir el archivo.']);
    }
    $tamano = filesize($temporal);
    if ($tamano === false || $tamano < 1) {
        error_validacion([$campo => 'El archivo está vacío.']);
    }
    if ($tamano > ARCHIVO_MAX_BYTES) {
        error_validacion([$campo => 'El archivo supera el tamaño máximo de 5 MB.']);
    }
    $contenido = file_get_contents($temporal);
    if ($contenido === false) {
        error_validacion([$campo => 'No se pudo leer el archivo.']);
    }

    return archivo_guardar_contenido($contenido, $tipo, $lat, $lng, $usuarioId, $campo);
}

/**
 * Guarda una imagen que llega como data URI en base64 (por ejemplo, la firma del canvas o de la cola offline).
 *
 * @param string $dataUri 'data:image/png;base64,....' (también se aceptan jpeg y webp)
 * @return int archivos.id
 * @throws AppError VALIDACION si el formato es inválido, es muy grande o no es PNG/JPEG/WebP.
 */
function archivo_guardar_base64(string $dataUri, string $tipo, ?float $lat, ?float $lng, ?int $usuarioId): int
{
    $campo = $tipo === 'firma' ? 'firma' : 'foto';

    $coma = strpos($dataUri, ',');
    if ($coma === false || $coma > 60 || !preg_match('#^data:image/(png|jpeg|webp);base64$#i', substr($dataUri, 0, $coma))) {
        error_validacion([$campo => 'Formato de imagen no válido.']);
    }
    $base64 = substr($dataUri, $coma + 1);
    // Se rechaza antes de decodificar si ya es más grande que el máximo (la codificación agrega ~33 %).
    if (strlen($base64) > (int) ceil(ARCHIVO_MAX_BYTES * 4 / 3) + 16) {
        error_validacion([$campo => 'El archivo supera el tamaño máximo de 5 MB.']);
    }
    $contenido = base64_decode($base64, true);
    if ($contenido === false || $contenido === '') {
        error_validacion([$campo => 'La imagen está dañada o vacía.']);
    }

    return archivo_guardar_contenido($contenido, $tipo, $lat, $lng, $usuarioId, $campo);
}

/**
 * Valida y guarda el contenido de una imagen (función interna: la comparten las dos de arriba).
 *
 * @param string $campo Nombre del campo, para el mensaje de VALIDACION.
 */
function archivo_guardar_contenido(string $contenido, string $tipo, ?float $lat, ?float $lng, ?int $usuarioId, string $campo): int
{
    if (!in_array($tipo, ARCHIVO_TIPOS, true)) {
        throw new InvalidArgumentException('Tipo de archivo no válido: ' . $tipo);
    }
    if (($lat === null) !== ($lng === null)) {
        error_validacion(['lat' => 'Envía la latitud y la longitud juntas.']);
    }
    if ($lat !== null && ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)) {
        error_validacion(['lat' => 'Las coordenadas están fuera de rango.']);
    }

    $tamano = strlen($contenido);
    if ($tamano < 1) {
        error_validacion([$campo => 'El archivo está vacío.']);
    }
    if ($tamano > ARCHIVO_MAX_BYTES) {
        error_validacion([$campo => 'El archivo supera el tamaño máximo de 5 MB.']);
    }

    // Tipo REAL por contenido: nunca se confía en la extensión ni en el tipo que declara el cliente.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
    if (!is_string($mime) || !isset(ARCHIVO_MIME_EXT[$mime])) {
        error_validacion([$campo => 'Formato no permitido. Usa una imagen PNG, JPG o WebP.']);
    }
    // Además debe ser una imagen que se pueda leer (descarta archivos con cabecera falsa).
    $info = @getimagesizefromstring($contenido);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > ARCHIVO_MAX_PIXELES) {
        error_validacion([$campo => 'La imagen no es válida.']);
    }

    $sha256 = hash('sha256', $contenido);
    $extension = ARCHIVO_MIME_EXT[$mime];

    $raiz = (string) app_cfg('storage.ruta', '');
    if ($raiz === '') {
        log_app('error', 'archivo_service: falta storage.ruta en config.php');
        lanzar_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
    }
    $subcarpeta = gmdate('Y') . '/' . gmdate('m');
    $directorio = rtrim($raiz, '/\\') . '/uploads/' . $subcarpeta;
    if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
        log_app('error', 'archivo_service: no se pudo crear la carpeta de archivos', ['carpeta' => $subcarpeta]);
        lanzar_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
    }

    $nombre = substr($sha256, 0, 16) . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $rutaDisco = $directorio . '/' . $nombre;
    $manejador = @fopen($rutaDisco, 'xb');   // 'x': nunca sobrescribe un archivo existente
    if ($manejador === false || fwrite($manejador, $contenido) !== $tamano) {
        if (is_resource($manejador)) {
            fclose($manejador);
        }
        @unlink($rutaDisco);
        log_app('error', 'archivo_service: no se pudo escribir el archivo', ['carpeta' => $subcarpeta]);
        lanzar_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
    }
    fclose($manejador);
    @chmod($rutaDisco, 0640);

    try {
        return db_insert(
            'INSERT INTO archivos (tipo, ruta_relativa, mime, tamano_bytes, sha256, lat, lng, subido_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'sssisssi',
            [
                $tipo,
                $subcarpeta . '/' . $nombre,   // relativa a storage/uploads (nunca dentro de public/)
                $mime,
                $tamano,
                $sha256,
                $lat === null ? null : number_format($lat, 7, '.', ''),
                $lng === null ? null : number_format($lng, 7, '.', ''),
                $usuarioId,
            ]
        );
    } catch (Throwable $e) {
        @unlink($rutaDisco);   // no queda un archivo huérfano si la fila no se pudo guardar
        throw $e;
    }
}

/**
 * Mensaje en español para un código UPLOAD_ERR_* de PHP.
 */
function archivo_mensaje_error_subida(int $codigo): string
{
    switch ($codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño máximo de 5 MB.';
        case UPLOAD_ERR_PARTIAL:
            return 'La subida se interrumpió. Intenta de nuevo.';
        case UPLOAD_ERR_NO_FILE:
            return 'Falta el archivo.';
        default:
            return 'No se pudo recibir el archivo.';
    }
}
