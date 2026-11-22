<?php
/**
 * cron/limpiar_gps.php - M2. Se ejecuta todos los días a las 03:15:
 *   15 3 * * * php /ruta/routepulse/cron/limpiar_gps.php
 *
 * Purga los puntos GPS de las rutas ya cerradas que quedaron fuera de la retención
 * (gps_retencion_dias, 90 por defecto). Solo por línea de comandos.
 *
 * Se borran rutas COMPLETAS de puntos: si una ruta todavía conserva algún punto dentro
 * de la retención no se toca, porque `gps_verificar_integridad()` verifica la cadena de
 * hashes desde el primer punto guardado. Borrar solo la "cabeza" de una cadena válida la
 * dejaría irreconocible. Las rutas antiguas desaparecen enteras y su recorrido ya no se
 * puede reproducir, que es justo el objetivo de la retención.
 *
 * Usa el usuario de mantenimiento (db.mantenimiento_usuario / db.mantenimiento_clave).
 * Si no está configurado, cae al usuario normal de la aplicación (solo válido en dev:
 * en producción ese usuario no tiene DELETE sobre puntos_gps).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/core/bootstrap.php';

const LIMPIAR_GPS_RUTAS_POR_LOTE = 200;
const LIMPIAR_GPS_PUNTOS_POR_BORRADO = 2000;
const LIMPIAR_GPS_MAX_LOTES = 500;

$dias = max(1, (int) config_get('gps_retencion_dias', 90));
$corte = gmdate('Y-m-d H:i:s', time() - $dias * 86400);

$usuarioMantenimiento = (string) app_cfg('db.mantenimiento_usuario', '');
$usaMantenimiento = $usuarioMantenimiento !== '';
$usuario = $usaMantenimiento ? $usuarioMantenimiento : (string) app_cfg('db.usuario', 'root');
$clave = $usaMantenimiento ? (string) app_cfg('db.mantenimiento_clave', '') : (string) app_cfg('db.clave', '');

if (!$usaMantenimiento) {
    log_app('warning', 'cron limpiar_gps: no hay usuario de mantenimiento configurado; se usa el usuario de la aplicación');
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conexion = new mysqli(
        (string) app_cfg('db.host', '127.0.0.1'),
        $usuario,
        $clave,
        (string) app_cfg('db.nombre', 'routepulse'),
        (int) app_cfg('db.puerto', 3306)
    );
    $conexion->set_charset('utf8mb4');
    $conexion->query("SET time_zone = '+00:00'");
    $conexion->query("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    $buscar = $conexion->prepare(
        'SELECT r.id
           FROM rutas r
          WHERE r.estado IN (\'completada\', \'cancelada\')
            AND EXISTS (SELECT 1 FROM puntos_gps p WHERE p.ruta_id = r.id)
            AND NOT EXISTS (
                SELECT 1 FROM puntos_gps p
                 WHERE p.ruta_id = r.id AND p.capturado_at >= ?
            )
          ORDER BY r.id
          LIMIT ' . LIMPIAR_GPS_RUTAS_POR_LOTE
    );
    $borrar = $conexion->prepare('DELETE FROM puntos_gps WHERE ruta_id = ? LIMIT ' . LIMPIAR_GPS_PUNTOS_POR_BORRADO);

    $rutas = 0;
    $puntos = 0;
    $lotes = 0;

    do {
        $lotes++;
        $buscar->bind_param('s', $corte);
        $buscar->execute();
        $resultado = $buscar->get_result();
        $ids = [];
        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int) $fila['id'];
        }
        $resultado->free();

        if ($ids === []) {
            break;
        }

        foreach ($ids as $rutaId) {
            do {
                $borrar->bind_param('i', $rutaId);
                $borrar->execute();
                $afectadas = $borrar->affected_rows;
                $puntos += $afectadas;
            } while ($afectadas === LIMPIAR_GPS_PUNTOS_POR_BORRADO);
            $rutas++;
        }
    } while ($lotes < LIMPIAR_GPS_MAX_LOTES);

    $buscar->close();
    $borrar->close();
    $conexion->close();

    if ($lotes >= LIMPIAR_GPS_MAX_LOTES) {
        log_app('warning', 'cron limpiar_gps: se alcanzó el máximo de lotes; quedan rutas para la próxima ejecución', [
            'lotes' => $lotes,
        ]);
    }

    log_app('info', 'cron limpiar_gps', [
        'corte'         => $corte,
        'retencion_dias' => $dias,
        'rutas'         => $rutas,
        'puntos'        => $puntos,
        'mantenimiento' => $usaMantenimiento,
    ]);
    echo 'Puntos GPS eliminados: ' . $puntos . ' (rutas: ' . $rutas . ', corte: ' . $corte . ' UTC)' . PHP_EOL;
} catch (Throwable $e) {
    log_app('error', 'cron limpiar_gps falló: ' . $e->getMessage());
    fwrite(STDERR, 'No se pudo limpiar los puntos GPS. Revisa storage/logs/app.log.' . PHP_EOL);
    exit(1);
}
