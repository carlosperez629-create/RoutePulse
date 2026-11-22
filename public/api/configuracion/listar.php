<?php
/** GET configuracion/listar.php (admin). Todos los parámetros. */
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin'], function (array $in, ?array $usuario): array {
    return db_all('SELECT clave, valor, tipo, descripcion, modulo_duenio FROM configuracion ORDER BY modulo_duenio, clave');
});
