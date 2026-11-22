<?php
/** POST integraciones/actualizar.php (admin): activo, modo_simulado, generar_api_key */
require_once __DIR__ . '/../../../app/core/bootstrap.php';
api_run('POST', ['admin'], function (array $in, ?array $u): array {
    $d = validar($in, ['id' => 'requerido|int|min:1', 'activo' => 'opcional|bool', 'modo_simulado' => 'opcional|bool', 'generar_api_key' => 'opcional|bool']);
    $i = db_one('SELECT * FROM integraciones WHERE id = ?', 'i', [$d['id']]);
    if (!$i) { error_no_encontrado('La integración'); }
    if ($d['activo'] !== null) { db_exec('UPDATE integraciones SET activo=? WHERE id=?', 'ii', [$d['activo'] ? 1 : 0, $d['id']]); }
    if ($d['modo_simulado'] !== null) { db_exec('UPDATE integraciones SET modo_simulado=? WHERE id=?', 'ii', [$d['modo_simulado'] ? 1 : 0, $d['id']]); }
    $out = ['id' => $d['id']];
    if (!empty($d['generar_api_key'])) {
        $k = bin2hex(random_bytes(24));
        db_exec('UPDATE integraciones SET api_key_hash=? WHERE id=?', 'si', [hash('sha256', $k), $d['id']]);
        $out['api_key'] = $k; // se muestra una sola vez
    }
    integracion_log((string) $i['codigo'], 'actualizada', ['por' => $u['id'] ?? null]);
    return $out;
});
