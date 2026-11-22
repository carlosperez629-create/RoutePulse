<?php
/**
 * cron/recalcular_eta.php - M2. Se ejecuta cada minuto:  * * * * * php /ruta/routepulse/cron/recalcular_eta.php
 *
 * Recalcula el ETA de las rutas 'en_curso' que llevan más de 2 minutos sin recalcularse
 * (cubre rutas que no están recibiendo puntos GPS nuevos). Solo por línea de comandos.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

require __DIR__ . '/../app/core/bootstrap.php';

const CRON_ETA_MAX_ANTIGUEDAD_S = 120;

$rutas = db_all(
    'SELECT r.id
       FROM rutas r
       LEFT JOIN paradas p ON p.ruta_id = r.id
      WHERE r.estado = \'en_curso\'
      GROUP BY r.id
     HAVING MAX(p.eta_actualizado_at) IS NULL
         OR TIMESTAMPDIFF(SECOND, MAX(p.eta_actualizado_at), NOW()) > ?',
    'i',
    [CRON_ETA_MAX_ANTIGUEDAD_S]
);

$ok = 0;
$fallos = 0;
foreach ($rutas as $fila) {
    try {
        eta_recalcular_ruta((int) $fila['id']);
        $ok++;
    } catch (Throwable $e) {
        $fallos++;
        log_app('error', 'cron recalcular_eta falló', ['ruta_id' => (int) $fila['id'], 'error' => $e->getMessage()]);
    }
}

log_app('info', 'cron recalcular_eta', ['rutas' => count($rutas), 'ok' => $ok, 'fallos' => $fallos]);
echo 'Rutas recalculadas: ' . $ok . ($fallos > 0 ? ' (con ' . $fallos . ' fallos, ver app.log)' : '') . PHP_EOL;
