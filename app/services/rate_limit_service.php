<?php
/**
 * RoutePulse - Límite de peticiones (M8). Ventana fija con contadores en ARCHIVOS
 * (sin tabla): storage/cache/rate_limit/<sha1(clave)>.json con {inicio, contador},
 * leído y escrito con flock() para que dos peticiones simultáneas no se pisen.
 *
 * Si storage/cache no es escribible, el límite se omite (se registra un aviso y
 * NO se bloquea el servicio).
 */

/**
 * Cuenta una petición para $clave. Lanza LIMITE (429, con Retry-After) si en la
 * ventana de $ventanaSeg segundos se superan $max peticiones.
 *
 * Claves usadas en el proyecto: 'login:ip:{ip}', 'login:email:{sha1}', 'track:ip:{ip}'.
 */
function rate_limit_check(string $clave, int $max, int $ventanaSeg): void {
    $directorio = rp_ruta_storage('cache/rate_limit');
    if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        log_app('warning', 'rate_limit: no se pudo crear ' . $directorio . '; el límite se omite.');
        return;
    }

    $archivo = $directorio . '/' . sha1($clave) . '.json';
    $manejador = @fopen($archivo, 'c+');
    if ($manejador === false) {
        log_app('warning', 'rate_limit: no se pudo abrir el archivo de contadores; el límite se omite.');
        return;
    }

    $excede = false;
    $segundosRestantes = 1;
    try {
        flock($manejador, LOCK_EX);
        $contenido = stream_get_contents($manejador);
        $datos = ($contenido !== false && $contenido !== '') ? json_decode($contenido, true) : null;
        $ahora = time();

        if (!is_array($datos) || !isset($datos['inicio'], $datos['contador']) || ($ahora - (int) $datos['inicio']) >= $ventanaSeg) {
            $datos = ['inicio' => $ahora, 'contador' => 0];
        }
        $datos['contador'] = (int) $datos['contador'] + 1;

        ftruncate($manejador, 0);
        rewind($manejador);
        fwrite($manejador, (string) json_encode($datos));
        fflush($manejador);

        $excede = $datos['contador'] > $max;
        $segundosRestantes = max(1, (int) $datos['inicio'] + $ventanaSeg - $ahora);
    } finally {
        flock($manejador, LOCK_UN);
        fclose($manejador);
    }

    if ($excede) {
        $error = new AppError('LIMITE', 'Demasiadas solicitudes. Inténtalo de nuevo en unos instantes.', 429);
        $error->reintentar_despues = $segundosRestantes;
        throw $error;
    }

    // Limpieza oportunista: 1 de cada 100 llamadas borra contadores de más de un día.
    if (random_int(1, 100) === 1) {
        rate_limit_limpiar($directorio);
    }
}

/**
 * Borra los archivos de contadores con más de un día de antigüedad.
 */
function rate_limit_limpiar(string $directorio): void {
    $limite = time() - 86400;
    foreach (glob($directorio . '/*.json') ?: [] as $archivo) {
        $modificado = @filemtime($archivo);
        if ($modificado !== false && $modificado < $limite) {
            @unlink($archivo);
        }
    }
}
