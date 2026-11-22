<?php
/** POST integraciones/reprocesar_webhook.php (admin) */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('POST', ['admin'], function (array $in, ?array $u): array {
    $d = validar($in, ['id' => 'requerido|int|min:1']);
    if (!db_val('SELECT COUNT(*) FROM webhook_eventos WHERE id=?', 'i', [$d['id']])) { error_no_encontrado('El evento'); }
    return ['estado' => webhook_procesar($d['id'])];
});
