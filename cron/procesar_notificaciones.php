<?php
/** cron/procesar_notificaciones.php — cada minuto: * * * * * php /ruta/cron/procesar_notificaciones.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
require __DIR__ . '/../app/core/bootstrap.php';
$r = notificacion_procesar_cola();
echo json_encode($r) . "\n";
$ev = db_all("SELECT id FROM webhook_eventos WHERE estado='error' AND intentos < 3 LIMIT 20");
foreach ($ev as $e) { webhook_procesar((int) $e['id']); }
