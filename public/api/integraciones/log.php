<?php
/** GET integraciones/log.php (admin): últimas líneas de integraciones.log o notificaciones_simuladas.log (?archivo=sim) */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('GET', ['admin'], function (array $in, ?array $u): array {
    $d = validar($in, ['archivo' => 'opcional|enum:integraciones,sim', 'lineas' => 'opcional|int|min:1|max:500']);
    $f = rp_ruta_storage('logs/' . (($d['archivo'] ?? 'integraciones') === 'sim' ? 'notificaciones_simuladas.log' : 'integraciones.log'));
    $l = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -($d['lineas'] ?? 100)) : [];
    return array_reverse($l);
});
