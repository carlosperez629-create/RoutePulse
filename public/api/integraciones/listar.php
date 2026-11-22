<?php
/** GET integraciones/listar.php (admin) */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('GET', ['admin'], function (array $in, ?array $u): array {
    $f = db_all('SELECT id, codigo, nombre, tipo, activo, modo_simulado, (api_key_hash IS NOT NULL) AS tiene_api_key FROM integraciones ORDER BY id');
    return filas_tipos($f, ['id'], ['activo', 'modo_simulado', 'tiene_api_key']);
});
