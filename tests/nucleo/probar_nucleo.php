<?php
/**
 * RoutePulse - Pruebas del núcleo (línea de comandos).
 *
 * Uso (desde la raíz del proyecto, con config/config.php y la base de datos importada):
 *     php tests/nucleo/probar_nucleo.php
 *
 * Sección A-C: no necesitan base de datos (validar, utilidades, CSRF, geo, fechas...).
 * Sección D:   necesita la base de datos con schema.sql + seed.sql. Usa una tabla
 *              TEMPORAL para las transacciones y crea/borra usuarios de prueba
 *              (prueba.nucleo*@routepulse.test). No toca los datos del seed.
 *
 * Termina con código 0 si todo pasa y 1 si algo falla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../app/core/bootstrap.php';

$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fallos'] = [];

function t(string $nombre, callable $fn): void {
    try {
        $fn();
        $GLOBALS['t_ok']++;
        echo "  OK    $nombre\n";
    } catch (Throwable $e) {
        $GLOBALS['t_fallos'][] = $nombre;
        echo "  FALLA $nombre\n        -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function afirmar(bool $condicion, string $mensaje = 'la afirmación es falsa'): void {
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function igual(mixed $esperado, mixed $real, string $mensaje = ''): void {
    if ($esperado !== $real) {
        throw new RuntimeException(
            ($mensaje !== '' ? $mensaje . ': ' : '') . 'esperado ' . var_export($esperado, true) . ' pero fue ' . var_export($real, true)
        );
    }
}

/** Espera que $fn lance AppError con ese código (y opcionalmente con error en ese campo). */
function falla_con(string $codigo, callable $fn, ?string $campo = null): AppError {
    try {
        $fn();
    } catch (AppError $e) {
        if ($e->codigo !== $codigo) {
            throw new RuntimeException('esperaba ' . $codigo . ' pero fue ' . $e->codigo . ': ' . $e->getMessage());
        }
        if ($campo !== null && !isset($e->campos[$campo])) {
            throw new RuntimeException('esperaba error en el campo "' . $campo . '"; campos: ' . json_encode($e->campos));
        }
        return $e;
    }
    throw new RuntimeException('esperaba el error ' . $codigo . ' pero no hubo ningún error');
}

function seccion(string $titulo): void {
    echo "\n== $titulo\n";
}

// ===================================================================== A. validar()
seccion('A. validar()');

t('tipos básicos y recorte de espacios', function () {
    $r = validar(
        ['a' => '5', 'b' => '3.5', 'c' => 'true', 'd' => '  hola  ', 'e' => '7', 'f' => 12],
        ['a' => 'requerido|int', 'b' => 'requerido|num', 'c' => 'requerido|bool', 'd' => 'requerido|str', 'e' => 'requerido|int|min:5|max:10', 'f' => 'requerido|str']
    );
    igual(5, $r['a']);
    igual(3.5, $r['b']);
    igual(true, $r['c']);
    igual('hola', $r['d']);
    igual(7, $r['e']);
    igual('12', $r['f'], 'un número en un campo str se convierte a texto');
});

t('solo devuelve los campos declarados', function () {
    $r = validar(['a' => 1, 'extra' => 'x'], ['a' => 'requerido|int']);
    igual(['a' => 1], $r);
});

t('campo requerido ausente, null o vacío → VALIDACION', function () {
    falla_con('VALIDACION', fn() => validar([], ['a' => 'requerido|int']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => null], ['a' => 'requerido|int']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => '   '], ['a' => 'requerido|str']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => []], ['a' => 'requerido|lista_int']), 'a');
});

t('sin "requerido" ni "opcional" el campo es REQUERIDO', function () {
    falla_con('VALIDACION', fn() => validar([], ['a' => 'int']), 'a');
});

t('opcional ausente devuelve null; el cero y false NO cuentan como vacíos', function () {
    $r = validar(['z' => 0, 'f' => false], ['a' => 'opcional|int', 'z' => 'opcional|int', 'f' => 'opcional|bool']);
    igual(null, $r['a']);
    igual(0, $r['z']);
    igual(false, $r['f']);
});

t('int: acepta "12" y 12.0; rechaza 3.5, "abc", true', function () {
    igual(12, validar(['a' => '12'], ['a' => 'int'])['a']);
    igual(12, validar(['a' => 12.0], ['a' => 'int'])['a']);
    falla_con('VALIDACION', fn() => validar(['a' => 3.5], ['a' => 'int']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => 'abc'], ['a' => 'int']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => true], ['a' => 'int']), 'a');
});

t('min/max en números, texto y listas', function () {
    falla_con('VALIDACION', fn() => validar(['a' => 3], ['a' => 'int|min:5']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => 11], ['a' => 'int|max:10']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => 'ab'], ['a' => 'str|min:3']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => 'abcdef'], ['a' => 'str|max:5']), 'a');
    igual('ñandú', validar(['a' => 'ñandú'], ['a' => 'str|max:5'])['a'], 'la longitud cuenta caracteres, no bytes');
    falla_con('VALIDACION', fn() => validar(['a' => '1,2,3'], ['a' => 'lista_int|max:2']), 'a');
});

t('bool: acepta true/false/1/0/"true"/"no"; rechaza otras cosas', function () {
    igual(true, validar(['a' => '1'], ['a' => 'bool'])['a']);
    igual(false, validar(['a' => 'no'], ['a' => 'bool'])['a']);
    igual(true, validar(['a' => 1], ['a' => 'bool'])['a']);
    falla_con('VALIDACION', fn() => validar(['a' => 'quizás'], ['a' => 'bool']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => 2], ['a' => 'bool']), 'a');
});

t('email: minúsculas y formato', function () {
    igual('carlos@correo.test', validar(['e' => ' Carlos@Correo.TEST '], ['e' => 'email'])['e']);
    falla_con('VALIDACION', fn() => validar(['e' => 'carlos@'], ['e' => 'email']), 'e');
    falla_con('VALIDACION', fn() => validar(['e' => 'sin arroba'], ['e' => 'email']), 'e');
});

t('telefono: normaliza separadores y valida largo', function () {
    igual('+573001234567', validar(['t' => '+57 300-123 4567'], ['t' => 'telefono'])['t']);
    igual('3001234567', validar(['t' => '(300) 123.4567'], ['t' => 'telefono'])['t']);
    falla_con('VALIDACION', fn() => validar(['t' => 'abc'], ['t' => 'telefono']), 't');
    falla_con('VALIDACION', fn() => validar(['t' => '123'], ['t' => 'telefono']), 't');
});

t('fecha: AAAA-MM-DD válida y existente', function () {
    igual('2026-11-13', validar(['f' => '2026-11-13'], ['f' => 'fecha'])['f']);
    falla_con('VALIDACION', fn() => validar(['f' => '2026-02-30'], ['f' => 'fecha']), 'f');
    falla_con('VALIDACION', fn() => validar(['f' => '13/11/2026'], ['f' => 'fecha']), 'f');
});

t('fecha_hora: convierte a UTC (Z, offsets, sin zona)', function () {
    igual('2026-11-13 15:04:05', validar(['f' => '2026-11-13T15:04:05Z'], ['f' => 'fecha_hora'])['f']);
    igual('2026-11-13 20:04:05', validar(['f' => '2026-11-13T15:04:05-05:00'], ['f' => 'fecha_hora'])['f']);
    igual('2026-11-13 20:04:05', validar(['f' => '2026-11-13T15:04:05-0500'], ['f' => 'fecha_hora'])['f']);
    igual('2026-11-13 15:04:00', validar(['f' => '2026-11-13 15:04'], ['f' => 'fecha_hora'])['f'], 'sin zona se asume UTC');
    igual('2026-11-14 01:00:00', validar(['f' => '2026-11-13T20:00:00-05:00'], ['f' => 'fecha_hora'])['f'], 'cruza de día');
});

t('fecha_hora: rechaza fechas imposibles y formatos raros', function () {
    falla_con('VALIDACION', fn() => validar(['f' => '2026-02-31T10:00:00Z'], ['f' => 'fecha_hora']), 'f');
    falla_con('VALIDACION', fn() => validar(['f' => '2026-11-13T25:00:00Z'], ['f' => 'fecha_hora']), 'f');
    falla_con('VALIDACION', fn() => validar(['f' => '2026-11-13'], ['f' => 'fecha_hora']), 'f');
    falla_con('VALIDACION', fn() => validar(['f' => 'mañana'], ['f' => 'fecha_hora']), 'f');
});

t('fecha_hora_ms conserva milisegundos', function () {
    igual('2026-11-13 15:04:05.120', validar(['f' => '2026-11-13T15:04:05.120Z'], ['f' => 'fecha_hora_ms'])['f']);
    igual('2026-11-13 15:04:05.000', validar(['f' => '2026-11-13T15:04:05Z'], ['f' => 'fecha_hora_ms'])['f']);
});

t('lat y lng en rango', function () {
    igual(4.64, validar(['a' => '4.64'], ['a' => 'lat'])['a']);
    igual(-74.08, validar(['a' => -74.08], ['a' => 'lng'])['a']);
    falla_con('VALIDACION', fn() => validar(['a' => 91], ['a' => 'lat']), 'a');
    falla_con('VALIDACION', fn() => validar(['a' => -181], ['a' => 'lng']), 'a');
});

t('lista_int: arreglo o texto con comas, sin repetidos', function () {
    igual([1, 2, 3], validar(['l' => '1,2,2,3'], ['l' => 'lista_int'])['l']);
    igual([4, 5], validar(['l' => [4, '5']], ['l' => 'lista_int'])['l']);
    falla_con('VALIDACION', fn() => validar(['l' => '1,x'], ['l' => 'lista_int']), 'l');
});

t('uuid v4', function () {
    $u = uuid_v4();
    igual($u, validar(['u' => strtoupper($u)], ['u' => 'uuid'])['u'], 'se devuelve en minúsculas');
    falla_con('VALIDACION', fn() => validar(['u' => 'no-es-uuid'], ['u' => 'uuid']), 'u');
});

t('enum', function () {
    igual('a', validar(['x' => 'a'], ['x' => 'enum:a,b,c'])['x']);
    falla_con('VALIDACION', fn() => validar(['x' => 'z'], ['x' => 'enum:a,b,c']), 'x');
});

t('crudo: NO recorta ni modifica (contraseñas)', function () {
    igual(' clave con espacios ', validar(['p' => ' clave con espacios '], ['p' => 'crudo'])['p']);
    falla_con('VALIDACION', fn() => validar(['p' => "con\0nulo"], ['p' => 'crudo']), 'p');
});

t('str: quita caracteres de control y rechaza UTF-8 inválido', function () {
    igual('hola', validar(['a' => "ho\x01la"], ['a' => 'str'])['a']);
    falla_con('VALIDACION', fn() => validar(['a' => "\xB1\x31"], ['a' => 'str']), 'a');
});

t('devuelve TODOS los errores a la vez', function () {
    $e = falla_con('VALIDACION', fn() => validar(['a' => 'x'], ['a' => 'int', 'b' => 'requerido|str']));
    afirmar(isset($e->campos['a'], $e->campos['b']), 'faltan campos en el error');
    igual(422, $e->http);
});

t('una regla desconocida es un error de programación (no VALIDACION)', function () {
    try {
        validar(['a' => 1], ['a' => 'inventada']);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('debió lanzar InvalidArgumentException');
});

// ===================================================================== B. utilidades
seccion('B. Utilidades (paginación, orden, escape, geo, fechas, textos)');

t('paginacion()', function () {
    igual([1, 25, 0], paginacion([]));
    igual([3, 10, 20], paginacion(['page' => '3', 'por_pagina' => '10']));
    igual([1, 100, 0], paginacion(['por_pagina' => 1000]), 'tope de 100');
    igual([1, 25, 0], paginacion(['page' => -5, 'por_pagina' => 0]));
    igual([1, 50, 0], paginacion(['por_pagina' => 99], 50), 'tope personalizado');
});

t('meta_paginacion()', function () {
    igual(['page' => 2, 'por_pagina' => 25, 'total' => 101, 'paginas' => 5], meta_paginacion(2, 25, 101));
    igual(1, meta_paginacion(1, 25, 0)['paginas']);
});

t('orden_seguro(): solo campos permitidos, sin inyección', function () {
    igual('created_at DESC', orden_seguro('created_at_desc', ['created_at', 'estado'], 'created_at_desc'));
    igual('estado ASC', orden_seguro('estado_asc', ['created_at', 'estado'], 'created_at_desc'));
    igual('p.created_at DESC', orden_seguro('creado_desc', ['creado' => 'p.created_at'], 'creado_desc'));
    igual('created_at DESC', orden_seguro('password_asc', ['created_at'], 'created_at_desc'), 'campo no permitido → defecto');
    igual('created_at DESC', orden_seguro('created_at; DROP TABLE usuarios', ['created_at'], 'created_at_desc'));
    igual('created_at DESC', orden_seguro(null, ['created_at'], 'created_at_desc'));
});

t('db_in(), db_tipos(), db_like()', function () {
    igual('?,?,?', db_in([1, 2, 3]));
    igual('NULL', db_in([]));
    igual('isd', db_tipos([1, 'a', 2.5]));
    igual('', db_tipos([]));
    igual('%50\\%\\_x%', db_like('50%_x'));
});

t('e() escapa HTML', function () {
    igual('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;', e('<script>alert("x")</script> & \''));
    igual('', e(null));
});

t('geo_distancia_m()', function () {
    igual(0.0, geo_distancia_m(4.6, -74.0, 4.6, -74.0));
    $d = geo_distancia_m(0.0, 0.0, 0.0, 1.0);
    afirmar(abs($d - 111195) < 300, 'un grado de longitud en el ecuador ≈ 111,2 km (fue ' . round($d) . ')');
    $corta = geo_distancia_m(4.6350, -74.0780, 4.6395, -74.0780);
    afirmar($corta > 480 && $corta < 520, 'unos 500 m (fue ' . round($corta) . ')');
});

t('geo_punto_en_poligono(): dentro, fuera y huecos', function () {
    $cuadrado = ['type' => 'Polygon', 'coordinates' => [[[-74.10, 4.62], [-74.05, 4.62], [-74.05, 4.67], [-74.10, 4.67], [-74.10, 4.62]]]];
    afirmar(geo_punto_en_poligono(4.64, -74.08, $cuadrado), 'debe estar dentro');
    afirmar(!geo_punto_en_poligono(4.60, -74.08, $cuadrado), 'debe estar fuera (al sur)');
    afirmar(!geo_punto_en_poligono(4.64, -74.20, $cuadrado), 'debe estar fuera (al oeste)');
    $conHueco = ['type' => 'Polygon', 'coordinates' => [
        [[-74.10, 4.62], [-74.05, 4.62], [-74.05, 4.67], [-74.10, 4.67], [-74.10, 4.62]],
        [[-74.08, 4.64], [-74.07, 4.64], [-74.07, 4.65], [-74.08, 4.65], [-74.08, 4.64]],
    ]];
    afirmar(!geo_punto_en_poligono(4.645, -74.075, $conHueco), 'dentro del hueco = fuera');
    afirmar(geo_punto_en_poligono(4.63, -74.09, $conHueco), 'dentro del polígono y fuera del hueco');
    afirmar(geo_punto_en_poligono(4.64, -74.08, ['type' => 'Feature', 'geometry' => $cuadrado]), 'acepta Feature');
    afirmar(!geo_punto_en_poligono(4.64, -74.08, ['type' => 'Point', 'coordinates' => [0, 0]]), 'otros tipos → false');
    afirmar(!geo_punto_en_poligono(4.64, -74.08, []), 'vacío → false');
});

t('fecha_a_iso() y fecha_utc_ahora()', function () {
    igual('2026-11-13T15:04:05Z', fecha_a_iso('2026-11-13 15:04:05'));
    igual('2026-11-13T15:04:05.120Z', fecha_a_iso('2026-11-13 15:04:05.120'));
    igual('2026-11-13T15:04:05Z', fecha_a_iso('2026-11-13 15:04:05.000'));
    igual('2026-11-13', fecha_a_iso('2026-11-13'));
    igual(null, fecha_a_iso('0000-00-00 00:00:00'));
    igual(null, fecha_a_iso(null));
    igual(null, fecha_a_iso('basura'));
    afirmar(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', fecha_utc_ahora()) === 1);
    afirmar(abs(strtotime(fecha_utc_ahora()) - time()) <= 2, 'fecha_utc_ahora() debe coincidir con la hora actual en UTC');
});

t('uuid_v4()', function () {
    $u = uuid_v4();
    afirmar(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u) === 1, $u);
    afirmar($u !== uuid_v4());
});

t('textos de estados, incidencias y roles', function () {
    igual('En clasificación', rp_estado_texto('en_clasificacion'));
    igual('Llegó', rp_estado_texto('llegado'));
    igual('raro', rp_estado_texto('raro'));
    igual('Cliente ausente', rp_incidencia_texto('cliente_ausente'));
    igual('Gerente de logística', rp_rol_texto('gerente'));
    igual(7, count(rp_incidencias_texto()));
});

t('rp_ocultar_sensibles() enmascara claves, tokens y OTP (también anidados)', function () {
    $r = rp_ocultar_sensibles(['password' => 'x', 'ok' => 1, 'anidado' => ['api_key' => 'y', 'token_seguimiento' => 'z', 'nombre' => 'n'], 'otp' => '123456']);
    igual('***', $r['password']);
    igual(1, $r['ok']);
    igual('***', $r['anidado']['api_key']);
    igual('***', $r['anidado']['token_seguimiento']);
    igual('n', $r['anidado']['nombre']);
    igual('***', $r['otp']);
});

t('config_convertir() y config_a_texto()', function () {
    igual(500, config_convertir('500', 'int'));
    igual(25.5, config_convertir('25.5', 'float'));
    igual(true, config_convertir('1', 'bool'));
    igual(false, config_convertir('0', 'bool'));
    igual(['a', 'b'], config_convertir('["a","b"]', 'json'));
    igual('osrm', config_convertir('osrm', 'string'));
    igual('5', config_a_texto('5', 'int'));
    igual('1', config_a_texto(true, 'bool'));
    igual('0', config_a_texto('false', 'bool'));
    igual('["x","y"]', config_a_texto(['x', 'y'], 'json'));
    falla_con('VALIDACION', fn() => config_a_texto('abc', 'int'), 'valor');
    falla_con('VALIDACION', fn() => config_a_texto('no', 'bool'), 'valor');
    falla_con('VALIDACION', fn() => config_a_texto('{mal', 'json'), 'valor');
    falla_con('VALIDACION', fn() => config_a_texto(str_repeat('x', 501), 'string'), 'valor');
});

t('error_http_por_codigo() y atajos de error', function () {
    igual(403, error_http_por_codigo('CSRF_INVALIDO'));
    igual(409, error_http_por_codigo('ESTADO_INVALIDO'));
    igual(429, error_http_por_codigo('LIMITE'));
    falla_con('NO_ENCONTRADO', fn() => error_no_encontrado('El paquete'));
    falla_con('SIN_PERMISO', fn() => error_sin_permiso());
    falla_con('CONFLICTO', fn() => error_conflicto('x'));
    falla_con('ESTADO_INVALIDO', fn() => error_estado('x'));
    igual(404, falla_con('NO_ENCONTRADO', fn() => error_no_encontrado('El paquete'))->http);
});

// ===================================================================== C. CSRF, menús, URLs
seccion('C. CSRF, menús y URLs');

t('csrf_token() y csrf_validar()', function () {
    $_SESSION = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $token = csrf_token();
    afirmar(preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'el token debe tener 64 caracteres hexadecimales');
    igual($token, csrf_token(), 'el token se mantiene dentro de la sesión');
    falla_con('CSRF_INVALIDO', fn() => csrf_validar());
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'incorrecto';
    falla_con('CSRF_INVALIDO', fn() => csrf_validar());
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    csrf_validar();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
});

t('menús por rol y páginas de inicio', function () {
    igual(9, count(auth_menu_por_rol('admin')));
    igual(9, count(auth_menu_por_rol('despachador')));
    igual(5, count(auth_menu_por_rol('gerente')));
    igual(1, count(auth_menu_por_rol('conductor')));
    igual([], auth_menu_por_rol('destinatario'));
    igual('admin/usuarios.php', auth_url_inicio('admin'));
    igual('monitor/flota.php', auth_url_inicio('despachador'));
    igual('gerencia/dashboard.php', auth_url_inicio('gerente'));
    igual('conductor/', auth_url_inicio('conductor'));
    foreach (['admin', 'despachador', 'gerente', 'conductor'] as $rol) {
        foreach (auth_menu_por_rol($rol) as $opcion) {
            afirmar(isset($opcion['texto'], $opcion['url'], $opcion['icono']), 'opción de menú incompleta en ' . $rol);
        }
    }
});

t('rp_url() y rp_ruta_storage()', function () {
    $base = rtrim((string) app_cfg('app.url'), '/');
    igual($base . '/login.php', rp_url('login.php'));
    igual($base . '/login.php', rp_url('/login.php'));
    igual($base, rp_url());
    afirmar(str_ends_with(rp_ruta_storage('logs'), 'logs'));
});

t('contraseña: política', function () {
    auth_politica_password('ClaveSegura2026');
    falla_con('VALIDACION', fn() => auth_politica_password('corta1'), 'password');
    falla_con('VALIDACION', fn() => auth_politica_password('sololetrasmuylarga'), 'password');
    falla_con('VALIDACION', fn() => auth_politica_password('12345678901234'), 'password');
    falla_con('VALIDACION', fn() => auth_politica_password('xxcarlosxx2026abc', 'carlos@correo.test'), 'password');
    falla_con('VALIDACION', fn() => auth_politica_password('corta1', null, 'password_nueva'), 'password_nueva');
    $temporal = usuario_generar_password_temporal();
    igual(12, strlen($temporal));
    auth_politica_password($temporal);
    afirmar($temporal !== usuario_generar_password_temporal(), 'deben ser distintas');
});

t('rp_ip_cliente() sin servidor web', function () {
    igual('0.0.0.0', rp_ip_cliente());
});

// ===================================================================== D. base de datos
seccion('D. Base de datos (requiere schema.sql + seed.sql importados)');

$hayBd = true;
try {
    db();
} catch (Throwable $e) {
    $hayBd = false;
    echo '  (se omiten las pruebas de base de datos: ' . $e->getMessage() . ")\n";
}

if ($hayBd) {
    t('conexión: zona horaria UTC y modo SQL estricto', function () {
        igual('+00:00', db_val('SELECT @@session.time_zone'));
        $modo = (string) db_val('SELECT @@session.sql_mode');
        afirmar(str_contains($modo, 'ONLY_FULL_GROUP_BY') && str_contains($modo, 'STRICT_TRANS_TABLES'), 'sql_mode: ' . $modo);
    });

    t('seed: usuarios, paquetes y consultas preparadas', function () {
        afirmar((int) db_val('SELECT COUNT(*) FROM usuarios') >= 5, 'debe haber al menos 5 usuarios (¿importaste seed.sql?)');
        afirmar((int) db_val('SELECT COUNT(*) FROM paquetes') >= 12, 'el seed trae 12 paquetes');
        $admin = db_one('SELECT rol, activo FROM usuarios WHERE email = ?', 's', ['admin@routepulse.test']);
        igual('admin', $admin['rol']);
        igual(null, db_one('SELECT id FROM usuarios WHERE email = ?', 's', ['no.existe@x.test']));
        igual([], db_all('SELECT id FROM usuarios WHERE email = ?', 's', ["' OR 1=1 --"]), 'la inyección SQL no debe devolver filas');
        igual(3, count(db_all('SELECT id FROM paquetes WHERE id IN (' . db_in([1, 2, 3]) . ')', 'iii', [1, 2, 3])));
        igual([], db_all('SELECT id FROM paquetes WHERE id IN (' . db_in([]) . ')'));
        afirmar(count(db_all('SELECT id FROM paquetes WHERE destinatario_nombre LIKE ?', 's', [db_like('Ana')])) >= 1);
        igual(null, db_val('SELECT id FROM paquetes WHERE id = ?', 'i', [999999]));
    });

    t('db: errores de programación y tipos', function () {
        try {
            db_all('SELECT 1 WHERE 1 = ?', 'ii', [1]);
            throw new RuntimeException('debió fallar por tipos distintos a parámetros');
        } catch (InvalidArgumentException $e) {
            // esperado
        }
        try {
            db_all('SELECT 1 WHERE 1 = ?', 'x', [1]);
            throw new RuntimeException('debió fallar por tipos inválidos');
        } catch (InvalidArgumentException $e) {
            // esperado
        }
        $fila = db_one('SELECT lat, lng, geocodificado FROM paquetes WHERE id = 1');
        $f = fila_tipos($fila, ['lat', 'lng'], ['geocodificado']);
        igual(4.64, $f['lat']);
        igual(true, $f['geocodificado']);
    });

    t('db_tx(): confirmar, revertir y anidar', function () {
        db_exec('CREATE TEMPORARY TABLE rp_prueba_tx (id INT PRIMARY KEY, v VARCHAR(20)) ENGINE=InnoDB');
        try {
            // Confirmar y devolver el resultado
            $r = db_tx(function () {
                db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [1, 'a']);
                return 'listo';
            });
            igual('listo', $r);
            igual(1, (int) db_val('SELECT COUNT(*) FROM rp_prueba_tx WHERE id = 1'));
            igual(0, db_tx_nivel());

            // Revertir ante una excepción (y volver a lanzarla)
            try {
                db_tx(function () {
                    db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [2, 'b']);
                    throw new RuntimeException('falla a propósito');
                });
                throw new RuntimeException('la excepción debía propagarse');
            } catch (RuntimeException $e) {
                igual('falla a propósito', $e->getMessage());
            }
            igual(0, (int) db_val('SELECT COUNT(*) FROM rp_prueba_tx WHERE id = 2'), 'debió revertirse');
            igual(0, db_tx_nivel());

            // Anidada: una excepción interna revierte TODO
            try {
                db_tx(function () {
                    db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [3, 'c']);
                    igual(1, db_tx_nivel());
                    db_tx(function () {
                        igual(2, db_tx_nivel());
                        db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [4, 'd']);
                        throw new RuntimeException('falla interna');
                    });
                });
            } catch (RuntimeException $e) {
                igual('falla interna', $e->getMessage());
            }
            igual(0, (int) db_val('SELECT COUNT(*) FROM rp_prueba_tx WHERE id IN (3, 4)'), 'la anidada debió revertir todo');
            igual(0, db_tx_nivel());

            // Anidada correcta: confirma ambas
            db_tx(function () {
                db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [5, 'e']);
                db_tx(function () {
                    db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [6, 'f']);
                });
                afirmar(db_en_transaccion());
            });
            igual(2, (int) db_val('SELECT COUNT(*) FROM rp_prueba_tx WHERE id IN (5, 6)'));
            afirmar(!db_en_transaccion());

            // Duplicado → CONFLICTO sin revelar detalles
            $e = falla_con('CONFLICTO', fn() => db_insert('INSERT INTO rp_prueba_tx (id, v) VALUES (?, ?)', 'is', [1, 'otra']));
            igual(409, $e->http);
            afirmar(!str_contains($e->getMessage(), 'rp_prueba_tx'), 'el mensaje no debe mostrar nombres de tablas');

            // db_exec devuelve filas afectadas
            igual(1, db_exec('UPDATE rp_prueba_tx SET v = ? WHERE id = ?', 'si', ['z', 1]));
            igual(0, db_exec('UPDATE rp_prueba_tx SET v = ? WHERE id = ?', 'si', ['z', 999]));
        } finally {
            db_exec('DROP TEMPORARY TABLE IF EXISTS rp_prueba_tx');
        }
    });

    t('config_get() / config_set() con la tabla configuracion', function () {
        igual(500, config_get('geocerca_radio_m'));
        igual(25.0, config_get('eta_velocidad_media_kmh'));
        igual(true, config_get('otp_obligatorio'));
        igual(['whatsapp', 'sms', 'email'], config_get('canales_orden'));
        igual('America/Bogota', config_get('zona_horaria_ui'));
        igual('z', config_get('clave_que_no_existe', 'z'));

        $original = config_get('otp_max_por_hora');
        config_set('otp_max_por_hora', 5);
        igual(5, config_get('otp_max_por_hora'));
        config_set('otp_max_por_hora', $original);
        igual($original, config_get('otp_max_por_hora'));

        falla_con('VALIDACION', fn() => config_set('otp_max_por_hora', 'abc'), 'valor');
        falla_con('NO_ENCONTRADO', fn() => config_set('clave_que_no_existe', 1));
        igual($original, config_get('otp_max_por_hora'), 'un valor inválido no debe cambiar nada');
    });

    t('audit(): guarda y enmascara datos sensibles', function () {
        db_exec("DELETE FROM log_auditoria WHERE accion = 'prueba_nucleo'");
        audit('prueba_nucleo', 'prueba', 7, ['password' => 'secreta', 'ok' => 1]);
        $fila = db_one("SELECT entidad, entidad_id, detalle, ip FROM log_auditoria WHERE accion = 'prueba_nucleo'");
        afirmar($fila !== null, 'no se guardó la fila de auditoría');
        igual('prueba', $fila['entidad']);
        igual('7', $fila['entidad_id']);
        igual('0.0.0.0', $fila['ip']);
        $detalle = json_decode((string) $fila['detalle'], true);
        igual('***', $detalle['password']);
        igual(1, $detalle['ok']);
        db_exec("DELETE FROM log_auditoria WHERE accion = 'prueba_nucleo'");
    });

    t('rate_limit_check(): cuenta, bloquea y envía Retry-After', function () {
        $clave = 'prueba:nucleo:' . uniqid('', true);
        rate_limit_check($clave, 3, 60);
        rate_limit_check($clave, 3, 60);
        rate_limit_check($clave, 3, 60);
        $e = falla_con('LIMITE', fn() => rate_limit_check($clave, 3, 60));
        igual(429, $e->http);
        afirmar($e->reintentar_despues !== null && $e->reintentar_despues >= 1 && $e->reintentar_despues <= 60, 'Retry-After inválido');
        // otra clave no se ve afectada
        rate_limit_check($clave . ':otra', 3, 60);
        @unlink(rp_ruta_storage('cache/rate_limit/' . sha1($clave) . '.json'));
        @unlink(rp_ruta_storage('cache/rate_limit/' . sha1($clave . ':otra') . '.json'));
    });

    // ---------------------------------------------- usuarios, login, sesión
    $correo = 'prueba.nucleo@routepulse.test';
    $clave = 'ClaveSegura2026';
    $limpiar = function () {
        foreach (db_all("SELECT id FROM usuarios WHERE email LIKE 'prueba.nucleo%@routepulse.test'") as $fila) {
            $id = (int) $fila['id'];
            db_exec('DELETE FROM log_auditoria WHERE usuario_id = ? OR (entidad = ? AND entidad_id = ?)', 'iss', [$id, 'usuario', (string) $id]);
        }
        db_exec("DELETE FROM usuarios WHERE email LIKE 'prueba.nucleo%@routepulse.test'");
        db_exec("DELETE FROM log_auditoria WHERE detalle LIKE '%prueba.nucleo%'");
    };
    $limpiar();
    $idPrueba = 0;

    t('usuario_crear(): crea, valida y rechaza duplicados', function () use ($correo, $clave, &$idPrueba) {
        $idPrueba = usuario_crear(['rol' => 'despachador', 'nombre' => 'Prueba Núcleo', 'email' => $correo, 'password' => $clave, 'telefono' => '+570000000099']);
        afirmar($idPrueba > 0);
        $u = usuario_obtener($idPrueba);
        igual('despachador', $u['rol']);
        igual(true, $u['activo']);
        igual(false, $u['debe_cambiar_password']);
        afirmar(!array_key_exists('password_hash', $u), 'usuario_obtener no debe devolver el hash');
        $hash = (string) db_val('SELECT password_hash FROM usuarios WHERE id = ?', 'i', [$idPrueba]);
        afirmar(str_starts_with($hash, '$2y$12$'), 'el hash debe ser bcrypt con costo 12');
        afirmar(password_verify($clave, $hash));
        falla_con('VALIDACION', fn() => usuario_crear(['rol' => 'despachador', 'nombre' => 'Otro', 'email' => strtoupper($correo), 'password' => $clave]), 'email');
        falla_con('VALIDACION', fn() => usuario_crear(['rol' => 'despachador', 'nombre' => 'Otro', 'email' => 'prueba.nucleo9@routepulse.test', 'password' => 'corta1']), 'password');
        falla_con('VALIDACION', fn() => usuario_crear(['rol' => 'destinatario', 'nombre' => 'Otro', 'email' => 'prueba.nucleo9@routepulse.test', 'password' => $clave]), 'rol');
        igual(null, usuario_obtener(999999));
    });

    t('usuario_crear() sin contraseña genera una temporal', function () {
        $temporal = null;
        $id = usuario_crear(['rol' => 'gerente', 'nombre' => 'Temporal', 'email' => 'prueba.nucleo2@routepulse.test'], $temporal);
        igual(12, strlen((string) $temporal));
        igual(true, usuario_obtener($id)['debe_cambiar_password']);
        afirmar(password_verify((string) $temporal, (string) db_val('SELECT password_hash FROM usuarios WHERE id = ?', 'i', [$id])));
    });

    t('login: credenciales correctas abren sesión y devuelven el usuario', function () use ($correo, $clave, $idPrueba) {
        $_SESSION = [];
        $u = auth_login($correo, $clave);
        igual($idPrueba, $u['id']);
        igual('despachador', $u['rol']);
        igual(null, $u['conductor_id']);
        igual($idPrueba, auth_usuario()['id']);
        $fila = db_one('SELECT ultimo_login, intentos_fallidos FROM usuarios WHERE id = ?', 'i', [$idPrueba]);
        afirmar($fila['ultimo_login'] !== null, 'debe registrar ultimo_login');
        igual(1, (int) db_val("SELECT COUNT(*) FROM log_auditoria WHERE accion = 'login_ok' AND usuario_id = ?", 'i', [$idPrueba]));
    });

    t('login: el correo no distingue mayúsculas y el usuario admin del seed funciona', function () {
        $_SESSION = [];
        $u = auth_login('  ADMIN@routepulse.test ', 'Dev12345!');
        igual('admin', $u['rol']);
        $_SESSION = [];
        auth_usuario(true);
    });

    t('auth_requerir(): roles, comodín y sesión', function () use ($correo, $clave) {
        $_SESSION = [];
        auth_login($correo, $clave);
        auth_requerir(['despachador']);
        auth_requerir(['admin', 'despachador']);
        auth_requerir(['*']);
        falla_con('SIN_PERMISO', fn() => auth_requerir(['admin']));
        falla_con('SIN_PERMISO', fn() => auth_requerir(['conductor', 'gerente']));
        auth_logout();
        igual(null, auth_usuario());
        igual([], $_SESSION);
        falla_con('NO_AUTENTICADO', fn() => auth_requerir(['*']));
    });

    t('login: errores SIEMPRE con el mismo mensaje genérico', function () use ($correo) {
        $_SESSION = [];
        $mensajes = [];
        foreach ([
            [$correo, 'ClaveIncorrecta99'],
            ['no.existe@routepulse.test', 'ClaveSegura2026'],
        ] as [$mail, $pass]) {
            $e = falla_con('NO_AUTENTICADO', fn() => auth_login($mail, $pass));
            igual(401, $e->http);
            $mensajes[] = $e->getMessage();
        }
        igual('Correo o contraseña incorrectos.', $mensajes[0]);
        igual($mensajes[0], $mensajes[1], 'el mensaje no debe revelar si el correo existe');
        igual(null, auth_usuario(true));
    });

    t('login: 5 intentos fallidos bloquean la cuenta (aunque luego use la clave correcta)', function () use ($correo, $clave, $idPrueba) {
        db_exec('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?', 'i', [$idPrueba]);
        $_SESSION = [];
        for ($i = 1; $i <= 5; $i++) {
            falla_con('NO_AUTENTICADO', fn() => auth_login($correo, 'Incorrecta' . $i . 'abc'));
        }
        $fila = db_one('SELECT intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id = ?', 'i', [$idPrueba]);
        afirmar($fila['bloqueado_hasta'] !== null, 'debe quedar bloqueado');
        afirmar(strtotime((string) $fila['bloqueado_hasta']) > time() + 600, 'el bloqueo debe ser de unos 15 minutos');
        $e = falla_con('NO_AUTENTICADO', fn() => auth_login($correo, $clave));
        igual('Correo o contraseña incorrectos.', $e->getMessage(), 'bloqueado: mismo mensaje genérico');
        afirmar((int) db_val("SELECT COUNT(*) FROM log_auditoria WHERE accion = 'login_bloqueado' AND usuario_id = ?", 'i', [$idPrueba]) >= 1, 'debe auditar login_bloqueado');
        // El administrador lo desbloquea
        db_exec('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?', 'i', [$idPrueba]);
        $u = auth_login($correo, $clave);
        igual($idPrueba, $u['id']);
        auth_logout();
    });

    t('login: un usuario inactivo no puede entrar', function () use ($correo, $clave, $idPrueba) {
        db_exec('UPDATE usuarios SET activo = 0 WHERE id = ?', 'i', [$idPrueba]);
        $_SESSION = [];
        falla_con('NO_AUTENTICADO', fn() => auth_login($correo, $clave));
        db_exec('UPDATE usuarios SET activo = 1 WHERE id = ?', 'i', [$idPrueba]);
    });

    t('sesión: expira por inactividad y se revalida contra la base de datos', function () use ($correo, $clave, $idPrueba) {
        $_SESSION = [];
        auth_login($correo, $clave);
        // Inactividad > 60 min
        $_SESSION['ultima_actividad'] = time() - 7200;
        igual(null, auth_usuario(true), 'debió expirar por inactividad');
        // Usuario desactivado mientras tenía sesión
        $_SESSION = [];
        auth_login($correo, $clave);
        db_exec('UPDATE usuarios SET activo = 0 WHERE id = ?', 'i', [$idPrueba]);
        $_SESSION['ultima_validacion'] = 0;
        igual(null, auth_usuario(true), 'un usuario desactivado debe perder la sesión');
        db_exec('UPDATE usuarios SET activo = 1 WHERE id = ?', 'i', [$idPrueba]);
        // Cambio de rol en la base de datos
        $_SESSION = [];
        auth_login($correo, $clave);
        db_exec("UPDATE usuarios SET rol = 'gerente' WHERE id = ?", 'i', [$idPrueba]);
        $_SESSION['ultima_validacion'] = 0;
        igual('gerente', auth_usuario(true)['rol'], 'el rol debe refrescarse desde la base de datos');
        db_exec("UPDATE usuarios SET rol = 'despachador' WHERE id = ?", 'i', [$idPrueba]);
        $_SESSION = [];
        auth_usuario(true);
    });

    t('sesión del conductor: no expira por inactividad, sí a las 12 horas', function () {
        $idConductor = (int) db_val("SELECT usuario_id FROM conductores ORDER BY id LIMIT 1");
        afirmar($idConductor > 0, 'el seed debe tener conductores');
        $email = (string) db_val('SELECT email FROM usuarios WHERE id = ?', 'i', [$idConductor]);
        $_SESSION = [];
        $u = auth_login($email, 'Dev12345!');
        igual('conductor', $u['rol']);
        afirmar($u['conductor_id'] !== null && $u['conductor_id'] > 0, 'el conductor debe traer su conductor_id');
        igual($u['conductor_id'], auth_conductor_id());
        $_SESSION['ultima_actividad'] = time() - 7200;
        afirmar(auth_usuario(true) !== null, 'el conductor no debe expirar por inactividad');
        $_SESSION['inicio_sesion'] = time() - (13 * 3600);
        igual(null, auth_usuario(true), 'debe expirar a las 12 horas');
        $_SESSION = [];
        auth_usuario(true);
    });

    t('contraseña: obligatorio cambiarla y cambio propio', function () use ($correo, $clave, $idPrueba) {
        $nueva = 'NuevaClave2026xyz';
        $_SESSION = [];
        db_exec('UPDATE usuarios SET debe_cambiar_password = 1 WHERE id = ?', 'i', [$idPrueba]);
        $u = auth_login($correo, $clave);
        igual(true, $u['debe_cambiar_password']);
        $GLOBALS['rp_permitir_cambio_pendiente'] = false;
        $e = falla_con('SIN_PERMISO', fn() => auth_requerir(['despachador']));
        afirmar(str_contains($e->getMessage(), 'cambiar tu contraseña'));
        $GLOBALS['rp_permitir_cambio_pendiente'] = true;
        auth_requerir(['*']);   // los endpoints de cambio de contraseña sí se permiten

        falla_con('VALIDACION', fn() => usuario_cambiar_password($idPrueba, 'ClaveActualMal9', $nueva), 'password_actual');
        falla_con('VALIDACION', fn() => usuario_cambiar_password($idPrueba, $clave, $clave), 'password_nueva');
        falla_con('VALIDACION', fn() => usuario_cambiar_password($idPrueba, $clave, 'corta1'), 'password_nueva');
        usuario_cambiar_password($idPrueba, $clave, $nueva);
        igual(false, usuario_obtener($idPrueba)['debe_cambiar_password']);
        igual(false, auth_usuario()['debe_cambiar_password']);
        $GLOBALS['rp_permitir_cambio_pendiente'] = false;
        auth_requerir(['despachador']);

        auth_logout();
        falla_con('NO_AUTENTICADO', fn() => auth_login($correo, $clave));
        auth_login($correo, $nueva);
        auth_logout();
        afirmar((int) db_val("SELECT COUNT(*) FROM log_auditoria WHERE accion = 'password_cambiar' AND usuario_id = ?", 'i', [$idPrueba]) >= 1, 'debe auditar password_cambiar');
        $GLOBALS['rp_permitir_cambio_pendiente'] = false;
    });

    $limpiar();
    echo "  (usuarios de prueba eliminados)\n";
}

// ===================================================================== resumen
echo "\n------------------------------------------------------------\n";
$fallos = $GLOBALS['t_fallos'];
echo 'Pruebas correctas: ' . $GLOBALS['t_ok'] . '   Fallidas: ' . count($fallos) . "\n";
foreach ($fallos as $nombre) {
    echo '  - ' . $nombre . "\n";
}
exit(count($fallos) === 0 ? 0 : 1);
