<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

/** GET paquetes/ver.php?id= o ?guia= (A, D, G): paquete completo con historial, incidencias y entregas. */
api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario) {
    $d = validar($in, ['id' => 'opcional|int|min:1', 'guia' => 'opcional|str|max:40']);
    if ($d['id'] === null && $d['guia'] === null) {
        lanzar_error('PARAMETROS', 'Indica el id o la guía del paquete.', 400);
    }
    return paquete_ver_completo($d['id'] !== null ? (int) $d['id'] : null, $d['guia']);
});
