<?php
/**
 * RoutePulse - Pruebas HTTP del núcleo y del acceso (equivale a E2E-00).
 *
 * Requisitos: el servidor web funcionando (XAMPP/Apache), config/config.php,
 * la base de datos con schema.sql + seed.sql y la extensión curl de PHP.
 *
 * Uso (desde la raíz del proyecto):
 *     php tests/nucleo/probar_http.php [URL_BASE] [--rate]
 *
 * URL_BASE por defecto es app.url de config/config.php, por ejemplo
 *     http://localhost/routepulse/public
 *
 * --rate  agrega la prueba del límite de intentos de login (hace ~12 intentos
 *         fallidos con un correo inexistente). Después hay que esperar 1 minuto
 *         antes de volver a iniciar sesión desde esta IP.
 *
 * Solo inicia sesión con los usuarios del seed y no modifica datos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "Falta la extensión curl de PHP.\n");
    exit(2);
}

require_once __DIR__ . '/../../app/core/bootstrap.php';

$base = null;
$probarRate = false;
foreach (array_slice($argv, 1) as $argumento) {
    if ($argumento === '--rate') {
        $probarRate = true;
    } else {
        $base = rtrim($argumento, '/');
    }
}
if ($base === null) {
    $base = rtrim((string) app_cfg('app.url'), '/');
}
echo "URL base: $base\n";

$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fallos'] = [];
$GLOBALS['jars'] = [];

function t(string $nombre, callable $fn): void {
    try {
        $fn();
        $GLOBALS['t_ok']++;
        echo "  OK    $nombre\n";
    } catch (Throwable $e) {
        $GLOBALS['t_fallos'][] = $nombre;
        echo "  FALLA $nombre\n        -> " . $e->getMessage() . "\n";
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

/** Archivo de cookies temporal: cada "navegador" de prueba tiene el suyo. */
function nuevo_jar(): string {
    $archivo = tempnam(sys_get_temp_dir(), 'rpjar');
    $GLOBALS['jars'][] = $archivo;
    return $archivo;
}

/**
 * Hace una petición HTTP sin seguir redirecciones.
 *
 * @param array $o  'jar' (archivo de cookies), 'json' (arreglo → cuerpo JSON), 'crudo' (cuerpo tal cual,
 *                  con Content-Type JSON), 'csrf' (token para X-CSRF-Token)
 * @return array{status:int, headers:array<string,string>, cookies:array<int,string>, cuerpo:string, json:?array}
 */
function http(string $metodo, string $ruta, array $o = []): array {
    global $base;
    $url = str_starts_with($ruta, 'http') ? $ruta : $base . '/' . ltrim($ruta, '/');
    $cabeceras = ['Accept: application/json'];
    $respuestaCabeceras = [];
    $cookies = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HEADERFUNCTION => function ($curl, $linea) use (&$respuestaCabeceras, &$cookies) {
            $partes = explode(':', $linea, 2);
            if (count($partes) === 2) {
                $nombre = strtolower(trim($partes[0]));
                $valor = trim($partes[1]);
                $respuestaCabeceras[$nombre] = $valor;
                if ($nombre === 'set-cookie') {
                    $cookies[] = $valor;
                }
            }
            return strlen($linea);
        },
    ]);
    if (isset($o['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']);
    }
    if (isset($o['csrf'])) {
        $cabeceras[] = 'X-CSRF-Token: ' . $o['csrf'];
    }
    if (isset($o['json'])) {
        $cabeceras[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json']));
    } elseif (isset($o['crudo'])) {
        $cabeceras[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['crudo']);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);

    $cuerpo = curl_exec($ch);
    if ($cuerpo === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('No se pudo conectar con ' . $url . ' (' . $error . '). ¿Está el servidor encendido y la URL base es correcta?');
    }
    $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string) $cuerpo, true);
    return [
        'status'  => $estado,
        'headers' => $respuestaCabeceras,
        'cookies' => $cookies,
        'cuerpo'  => (string) $cuerpo,
        'json'    => is_array($json) ? $json : null,
    ];
}

/**
 * Borra los contadores del límite de peticiones (storage/cache/rate_limit) para que las
 * pruebas no se bloqueen entre sí. Solo funciona si este usuario puede borrar esos archivos.
 */
function reiniciar_rate_limit(): void {
    foreach (glob(rp_ruta_storage('cache/rate_limit') . '/*.json') ?: [] as $archivo) {
        @unlink($archivo);
    }
}

function esperar_error(array $r, int $http, string $codigo): void {
    igual($http, $r['status'], 'HTTP');
    afirmar($r['json'] !== null && ($r['json']['ok'] ?? null) === false, 'la respuesta debía ser {"ok":false,...}: ' . substr($r['cuerpo'], 0, 200));
    igual($codigo, $r['json']['error']['codigo'] ?? null, 'código de error');
}

function pedir_csrf(string $jar): string {
    $r = http('GET', 'api/auth/csrf.php', ['jar' => $jar]);
    afirmar($r['status'] === 200 && isset($r['json']['data']['csrf_token']), 'no se pudo obtener el token CSRF: ' . substr($r['cuerpo'], 0, 200));
    return $r['json']['data']['csrf_token'];
}

/** Inicia sesión y devuelve [respuesta, token CSRF nuevo]. */
function iniciar_sesion(string $jar, string $email, string $password): array {
    $csrf = pedir_csrf($jar);
    $r = http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => $csrf, 'json' => ['email' => $email, 'password' => $password]]);
    return [$r, $r['json']['data']['csrf_token'] ?? ''];
}

reiniciar_rate_limit();
echo "\n== 1. Respuestas de la API y cabeceras\n";

t('sin sesión: GET auth/me.php → 401 NO_AUTENTICADO', function () {
    esperar_error(http('GET', 'api/auth/me.php'), 401, 'NO_AUTENTICADO');
});

t('GET auth/csrf.php: token de 64 caracteres y cookie de sesión segura', function () {
    $r = http('GET', 'api/auth/csrf.php', ['jar' => nuevo_jar()]);
    igual(200, $r['status']);
    igual(true, $r['json']['ok']);
    afirmar(preg_match('/^[0-9a-f]{64}$/', (string) $r['json']['data']['csrf_token']) === 1, 'formato del token');
    $cookie = implode(' | ', $r['cookies']);
    afirmar(str_contains($cookie, 'RPSESSID='), 'falta la cookie RPSESSID: ' . $cookie);
    afirmar(stripos($cookie, 'HttpOnly') !== false, 'la cookie debe ser HttpOnly');
    afirmar(stripos($cookie, 'SameSite=Lax') !== false, 'la cookie debe tener SameSite=Lax');
});

t('cabeceras de seguridad y formato JSON en la API', function () {
    $r = http('GET', 'api/auth/me.php');
    $h = $r['headers'];
    afirmar(str_contains($h['content-type'] ?? '', 'application/json'), 'Content-Type');
    afirmar(str_contains($h['content-type'] ?? '', 'utf-8'), 'charset utf-8');
    igual('no-store', $h['cache-control'] ?? null, 'Cache-Control');
    igual('nosniff', $h['x-content-type-options'] ?? null, 'X-Content-Type-Options');
    igual('SAMEORIGIN', $h['x-frame-options'] ?? null, 'X-Frame-Options');
    afirmar(str_contains($h['content-security-policy'] ?? '', "default-src 'self'"), 'Content-Security-Policy');
    afirmar(str_contains($h['permissions-policy'] ?? '', 'geolocation=(self)'), 'Permissions-Policy');
    afirmar(!isset($h['x-powered-by']), 'no debe mostrarse X-Powered-By');
});

t('los errores no revelan detalles internos (SQL, rutas, trazas)', function () {
    $r = http('GET', 'api/auth/me.php');
    foreach (['mysqli', 'Stack trace', '.php on line', 'SELECT ', 'C:\\', '/var/www'] as $prohibido) {
        afirmar(!str_contains($r['cuerpo'], $prohibido), 'la respuesta contiene "' . $prohibido . '"');
    }
});

t('método incorrecto → 405 METODO_NO_PERMITIDO con cabecera Allow', function () {
    $r = http('POST', 'api/auth/csrf.php', ['jar' => nuevo_jar()]);
    esperar_error($r, 405, 'METODO_NO_PERMITIDO');
    igual('GET', $r['headers']['allow'] ?? null);
});

reiniciar_rate_limit();
echo "\n== 2. Login, CSRF y errores de entrada\n";

t('POST login sin token CSRF → 403 CSRF_INVALIDO', function () {
    $jar = nuevo_jar();
    pedir_csrf($jar);
    esperar_error(http('POST', 'api/auth/login.php', ['jar' => $jar, 'json' => ['email' => 'admin@routepulse.test', 'password' => 'Dev12345!']]), 403, 'CSRF_INVALIDO');
});

t('POST login con token CSRF incorrecto → 403 CSRF_INVALIDO', function () {
    $jar = nuevo_jar();
    pedir_csrf($jar);
    esperar_error(http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => str_repeat('a', 64), 'json' => ['email' => 'admin@routepulse.test', 'password' => 'Dev12345!']]), 403, 'CSRF_INVALIDO');
});

t('POST login con JSON inválido → 400 PARAMETROS', function () {
    $jar = nuevo_jar();
    $csrf = pedir_csrf($jar);
    esperar_error(http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => $csrf, 'crudo' => 'esto no es json']), 400, 'PARAMETROS');
});

t('POST login sin campos → 422 VALIDACION con el detalle por campo', function () {
    $jar = nuevo_jar();
    $csrf = pedir_csrf($jar);
    $r = http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => $csrf, 'json' => new stdClass()]);
    esperar_error($r, 422, 'VALIDACION');
    afirmar(isset($r['json']['error']['campos']['email'], $r['json']['error']['campos']['password']), 'faltan los campos en el error');
});

t('POST login con credenciales incorrectas → 401 y mensaje genérico (también con correo inexistente)', function () {
    $jar = nuevo_jar();
    [$r1] = iniciar_sesion($jar, 'admin@routepulse.test', 'ClaveEquivocada99');
    esperar_error($r1, 401, 'NO_AUTENTICADO');
    $jar2 = nuevo_jar();
    [$r2] = iniciar_sesion($jar2, 'no.existe@routepulse.test', 'ClaveEquivocada99');
    esperar_error($r2, 401, 'NO_AUTENTICADO');
    igual('Correo o contraseña incorrectos.', $r1['json']['error']['mensaje']);
    igual($r1['json']['error']['mensaje'], $r2['json']['error']['mensaje'], 'el mensaje no debe revelar si el correo existe');
});

t('la inyección SQL en el correo no entra ni rompe nada', function () {
    $jar = nuevo_jar();
    $csrf = pedir_csrf($jar);
    $r = http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => $csrf, 'json' => ['email' => "' OR 1=1 --", 'password' => 'x']]);
    afirmar(in_array($r['status'], [401, 422], true), 'debe rechazarse (401/422) y fue ' . $r['status']);
    igual(false, $r['json']['ok']);
});

reiniciar_rate_limit();
echo "\n== 3. Sesión, menú y cierre de sesión (administrador)\n";

$jarAdmin = nuevo_jar();
$csrfAdmin = '';

t('login del administrador: usuario, token nuevo y redirección', function () use ($jarAdmin, &$csrfAdmin) {
    $anterior = pedir_csrf($jarAdmin);
    [$r, $csrfAdmin] = iniciar_sesion($jarAdmin, 'admin@routepulse.test', 'Dev12345!');
    igual(200, $r['status'], 'HTTP: ' . substr($r['cuerpo'], 0, 200));
    $d = $r['json']['data'];
    igual('admin', $d['usuario']['rol']);
    igual('admin@routepulse.test', $d['usuario']['email']);
    igual(false, $d['usuario']['debe_cambiar_password']);
    afirmar(!array_key_exists('password_hash', $d['usuario']), 'no debe devolver el hash');
    afirmar(str_ends_with($d['redirigir_a'], '/admin/usuarios.php'), 'redirigir_a: ' . $d['redirigir_a']);
    afirmar(preg_match('/^[0-9a-f]{64}$/', $d['csrf_token']) === 1, 'token nuevo');
    afirmar($d['csrf_token'] !== $anterior, 'el token CSRF debe renovarse al iniciar sesión');
    $cookies = implode(' | ', $r['cookies']);
    afirmar(str_contains($cookies, 'RPSESSID='), 'la sesión debe regenerarse y enviar la cookie');
});

t('GET auth/me.php con sesión: usuario, menú de 9 opciones y zona horaria', function () use ($jarAdmin, &$csrfAdmin) {
    $r = http('GET', 'api/auth/me.php', ['jar' => $jarAdmin]);
    igual(200, $r['status']);
    $d = $r['json']['data'];
    igual('admin', $d['usuario']['rol']);
    igual(9, count($d['menu']));
    igual('Usuarios', $d['menu'][0]['texto']);
    afirmar(str_ends_with($d['menu'][0]['url'], '/admin/usuarios.php'));
    igual('America/Bogota', $d['zona_horaria_ui']);
    igual($csrfAdmin, $d['csrf_token'], 'el token CSRF es el de la sesión');
});

t('POST con sesión pero sin CSRF → 403; con CSRF correcto pasa a validar (422)', function () use ($jarAdmin, $csrfAdmin) {
    esperar_error(http('POST', 'api/auth/cambiar_password.php', ['jar' => $jarAdmin, 'json' => ['password_actual' => 'x', 'password_nueva' => 'y']]), 403, 'CSRF_INVALIDO');
    $r = http('POST', 'api/auth/cambiar_password.php', ['jar' => $jarAdmin, 'csrf' => $csrfAdmin, 'json' => ['password_actual' => 'ClaveEquivocada99', 'password_nueva' => 'OtraClave2026xyz']]);
    esperar_error($r, 422, 'VALIDACION');
    afirmar(isset($r['json']['error']['campos']['password_actual']), 'debe marcar password_actual');
});

t('POST auth/cambiar_password.php: la nueva contraseña débil se rechaza con el campo correcto', function () use ($jarAdmin, $csrfAdmin) {
    $r = http('POST', 'api/auth/cambiar_password.php', ['jar' => $jarAdmin, 'csrf' => $csrfAdmin, 'json' => ['password_actual' => 'Dev12345!', 'password_nueva' => 'corta1']]);
    esperar_error($r, 422, 'VALIDACION');
    afirmar(isset($r['json']['error']['campos']['password_nueva']), 'debe marcar password_nueva');
});

t('sesión vigente: login.php e index.php redirigen a la página de inicio', function () use ($jarAdmin) {
    $r = http('GET', 'login.php', ['jar' => $jarAdmin]);
    igual(302, $r['status']);
    afirmar(str_ends_with($r['headers']['location'] ?? '', '/admin/usuarios.php'), 'Location: ' . ($r['headers']['location'] ?? ''));
    $r = http('GET', 'index.php', ['jar' => $jarAdmin]);
    igual(302, $r['status']);
    afirmar(str_ends_with($r['headers']['location'] ?? '', '/admin/usuarios.php'));
});

t('POST auth/logout.php cierra la sesión: después /me da 401', function () use ($jarAdmin, $csrfAdmin) {
    $r = http('POST', 'api/auth/logout.php', ['jar' => $jarAdmin, 'csrf' => $csrfAdmin, 'json' => new stdClass()]);
    igual(200, $r['status']);
    igual(true, $r['json']['ok']);
    esperar_error(http('GET', 'api/auth/me.php', ['jar' => $jarAdmin]), 401, 'NO_AUTENTICADO');
});

reiniciar_rate_limit();
echo "\n== 4. Cada rol llega a su pantalla y ve su menú\n";

foreach ([
    ['despachador@routepulse.test', 'despachador', '/monitor/flota.php', 9],
    ['gerente@routepulse.test', 'gerente', '/gerencia/dashboard.php', 5],
    ['conductor1@routepulse.test', 'conductor', '/conductor/', 1],
    ['conductor2@routepulse.test', 'conductor', '/conductor/', 1],
] as [$correo, $rol, $inicio, $opciones]) {
    t("$rol ($correo): redirección $inicio y $opciones opciones de menú", function () use ($correo, $rol, $inicio, $opciones) {
        $jar = nuevo_jar();
        [$r, $csrf] = iniciar_sesion($jar, $correo, 'Dev12345!');
        igual(200, $r['status'], 'login: ' . substr($r['cuerpo'], 0, 200));
        $d = $r['json']['data'];
        igual($rol, $d['usuario']['rol']);
        afirmar(str_ends_with($d['redirigir_a'], $inicio), 'redirigir_a: ' . $d['redirigir_a']);
        if ($rol === 'conductor') {
            afirmar(isset($d['usuario']['conductor_id']) && $d['usuario']['conductor_id'] > 0, 'el conductor debe traer conductor_id');
        }
        $me = http('GET', 'api/auth/me.php', ['jar' => $jar]);
        igual($opciones, count($me['json']['data']['menu']));
        http('POST', 'api/auth/logout.php', ['jar' => $jar, 'csrf' => $csrf, 'json' => new stdClass()]);
    });
}

echo "\n== 5. Pantallas y archivos estáticos\n";

t('login.php sin sesión: formulario y cabeceras de seguridad', function () {
    $r = http('GET', 'login.php');
    igual(200, $r['status']);
    afirmar(str_contains($r['cuerpo'], 'id="form-login"'), 'falta el formulario de login');
    afirmar(str_contains($r['cuerpo'], 'name="rp-url-base"'), 'falta la meta rp-url-base');
    afirmar(str_contains($r['cuerpo'], 'assets/js/m8/login.js'), 'falta el script de login');
    afirmar(!str_contains($r['cuerpo'], '<script>'), 'la CSP no permite scripts en línea');
    afirmar(str_contains($r['headers']['content-type'] ?? '', 'text/html'), 'Content-Type HTML');
    afirmar(str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'self'"), 'CSP en páginas');
});

t('index.php sin sesión redirige a login.php', function () {
    $r = http('GET', 'index.php');
    igual(302, $r['status']);
    afirmar(str_ends_with($r['headers']['location'] ?? '', '/login.php'), 'Location: ' . ($r['headers']['location'] ?? ''));
});

t('login.php?expirada=1 muestra el aviso de sesión vencida', function () {
    $r = http('GET', 'login.php?expirada=1');
    afirmar(str_contains($r['cuerpo'], 'Tu sesión expiró'), 'falta el aviso');
});

t('cambiar_password.php sin sesión redirige al login', function () {
    $r = http('GET', 'cambiar_password.php');
    igual(302, $r['status']);
    afirmar(str_contains($r['headers']['location'] ?? '', 'login.php'));
});

foreach ([
    ['assets/vendor/bootstrap/bootstrap.min.css', 'css'],
    ['assets/vendor/bootstrap/bootstrap.bundle.min.js', 'javascript'],
    ['assets/vendor/bootstrap-icons/bootstrap-icons.min.css', 'css'],
    ['assets/css/estados.css', 'css'],
    ['assets/css/app.css', 'css'],
    ['assets/js/api.js', 'javascript'],
    ['assets/js/ui.js', 'javascript'],
    ['assets/js/m8/login.js', 'javascript'],
] as [$recurso, $tipo]) {
    t("recurso estático $recurso", function () use ($recurso, $tipo) {
        $r = http('GET', $recurso);
        igual(200, $r['status']);
        afirmar(str_contains(strtolower($r['headers']['content-type'] ?? ''), $tipo), 'Content-Type: ' . ($r['headers']['content-type'] ?? ''));
        afirmar(strlen($r['cuerpo']) > 100, 'archivo vacío');
    });
}

t('los archivos ocultos (.htaccess) no se pueden descargar', function () {
    $r = http('GET', '.htaccess');
    afirmar(in_array($r['status'], [403, 404], true), 'se esperaba 403/404 y fue ' . $r['status']);
    afirmar(!str_contains($r['cuerpo'], 'RewriteRule'), 'se está mostrando el contenido de .htaccess');
});

t('las carpetas internas no son accesibles por URL (si la raíz del proyecto está dentro del servidor web)', function () use ($base) {
    // Solo aplica cuando app.url cuelga de la raíz del proyecto (XAMPP: .../routepulse/public).
    if (!preg_match('#^(.*)/public$#', $base, $m)) {
        echo "        (omitida: la URL base no termina en /public)\n";
        return;
    }
    foreach (['config/config.php', 'database/seed.sql', 'storage/logs/app.log', 'app/core/db.php'] as $interno) {
        $r = http('GET', $m[1] . '/' . $interno);
        afirmar(in_array($r['status'], [403, 404], true), $interno . ' devolvió ' . $r['status'] . ' (debe ser 403 o 404)');
        afirmar(!str_contains($r['cuerpo'], 'password_hash') && !str_contains($r['cuerpo'], "'clave'"), $interno . ' muestra contenido interno');
    }
});

if ($probarRate) {
    reiniciar_rate_limit();
    echo "\n== 6. Límite de intentos de login (--rate)\n";
    t('tras varios intentos seguidos responde 429 LIMITE con Retry-After', function () {
        $jar = nuevo_jar();
        $csrf = pedir_csrf($jar);
        $visto429 = false;
        for ($i = 1; $i <= 14; $i++) {
            $r = http('POST', 'api/auth/login.php', ['jar' => $jar, 'csrf' => $csrf, 'json' => ['email' => 'limite.http@routepulse.test', 'password' => 'Intento' . $i . 'abc']]);
            if ($r['status'] === 429) {
                $visto429 = true;
                esperar_error($r, 429, 'LIMITE');
                $espera = (int) ($r['headers']['retry-after'] ?? 0);
                afirmar($espera >= 1 && $espera <= 900, 'Retry-After inválido: ' . ($r['headers']['retry-after'] ?? 'ausente'));
                break;
            }
        }
        afirmar($visto429, 'no se recibió ningún 429 en 14 intentos (rate_login_por_min = ' . (int) config_get('rate_login_por_min', 10) . ')');
    });
}

foreach ($GLOBALS['jars'] as $archivoJar) {
    @unlink($archivoJar);
}

echo "\n------------------------------------------------------------\n";
$fallos = $GLOBALS['t_fallos'];
echo 'Pruebas correctas: ' . $GLOBALS['t_ok'] . '   Fallidas: ' . count($fallos) . "\n";
foreach ($fallos as $nombre) {
    echo '  - ' . $nombre . "\n";
}
exit(count($fallos) === 0 ? 0 : 1);
