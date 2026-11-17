# Notas del núcleo (Fase 0) y M8 etapa A

Entrega de Carlos: núcleo compartido (`app/core`), login y acceso (M8 etapa A). Objetivo: **Hito 0** (los 4 pueden iniciar sesión, llamar a `GET /api/auth/me.php` y ver el menú de su rol).

## 1. Estado de la verificación (leer primero)

| Parte | Cómo se verificó |
|---|---|
| JavaScript (`api.js`, `ui.js`) | **Ejecutado**: 24 pruebas en Node con un navegador simulado (`node tests/nucleo/probar_js.js`). Encontraron y corrigieron un error real |
| PHP (todo `app/` y `public/`) | **No se pudo ejecutar** donde se escribió (sin PHP). Se revisó con un analizador estructural (llaves y paréntesis balanceados, ninguna función sin definir) y con revisión manual línea por línea |
| Base de datos / HTTP | Se verifican con los dos scripts de prueba de abajo, que hay que correr en un PC con PHP y la base importada |

**Hasta que `probar_nucleo.php` y `probar_http.php` pasen en tu PC, el PHP debe considerarse sin probar.** Si alguna prueba falla, el mensaje indica qué función y qué valor.

## 2. Instalación

1. Copiar el contenido de esta entrega dentro del repositorio, en una rama `feature/m8-nucleo` (respeta las rutas).
2. `config/config.example.php` se actualizó (agrega `app.proxy_confiable`). Si ya tienes tu `config/config.php`, agrega esa línea o vuelve a copiarlo.
3. Importar `database/schema.sql` y luego `database/seed.sql` en una base vacía.
4. Comprobar que `storage/logs` y `storage/cache` existen y son escribibles.
5. Abrir `http://localhost/routepulse/public/login.php` e iniciar sesión con `admin@routepulse.test` / `Dev12345!`.

## 3. Pruebas (en este orden)

```
node tests/nucleo/probar_js.js                       # JavaScript. Sin servidor ni base de datos
php  tests/nucleo/probar_nucleo.php                  # PHP: validar, utilidades, base de datos, login, sesiones
php  tests/nucleo/probar_http.php                    # HTTP: endpoints, CSRF, cabeceras, pantallas (servidor encendido)
php  tests/nucleo/probar_http.php --rate             # además, el límite de intentos de login (esperar 1 min después)
php  tests/nucleo/probar_http.php http://localhost/routepulse/public    # si app.url no es la correcta
```

- `probar_nucleo.php` usa una tabla temporal y crea/borra usuarios `prueba.nucleo*@routepulse.test`; no toca el seed (salvo `ultimo_login` del admin y del conductor 1).
- `probar_http.php` solo inicia sesión con los usuarios del seed y borra los contadores de `storage/cache/rate_limit` al empezar.
- Si en el ordenador no hay `curl` en PHP, `probar_http.php` avisa. Se puede probar a mano con el navegador y las herramientas de desarrollo.

## 4. Qué contiene

| Ruta | Contenido |
|---|---|
| `app/core/bootstrap.php` | Arranque: UTC, UTF-8, carga el núcleo y los servicios, errores y cabeceras de seguridad |
| `app/core/errores.php`, `logger.php`, `config.php`, `entorno.php`, `textos.php` | `AppError` y atajos; `log_app`; `app_cfg`/`config_get`/`config_set`; URLs, IP, manejo de errores de PHP; textos de estados |
| `app/core/db.php` | `db`, `db_all`, `db_one`, `db_val`, `db_exec`, `db_insert`, `db_tx` (anidable), `db_in` y ayudas |
| `app/core/input.php` | `validar` y todas sus reglas, `paginacion`, `meta_paginacion`, `orden_seguro`, `e`, lectura de la entrada |
| `app/core/response.php` | `json_ok`, `json_error`, `api_meta`, `api_status`, `api_run` |
| `app/core/auth.php`, `csrf.php`, `geo.php`, `layout.php` | Sesión, login, roles, menús; CSRF; geografía, fechas y UUID; `render_header`/`render_footer` |
| `app/services/auditoria_service.php`, `rate_limit_service.php`, `usuario_service.php` | `audit`, `rate_limit_check`, `usuario_crear`, `usuario_obtener`, `usuario_cambiar_password`, `auth_politica_password` |
| `app/views/partials/*` | Cabecera, barra de navegación, avisos y pie |
| `public/index.php`, `login.php`, `cambiar_password.php` | Pantallas de M8 de Carlos |
| `public/api/auth/*.php` | `csrf`, `login`, `logout`, `me`, `cambiar_password` |
| `public/assets/js/api.js`, `ui.js`, `m8/*.js` | El helper `api()`, utilidades de interfaz y los scripts de login y cambio de contraseña |
| `public/assets/css/estados.css`, `app.css` | Colores de estado y estilos globales |
| `public/.htaccess` | Reescritura de `/t/{token}` (M5), sin listado de carpetas, archivos ocultos bloqueados |
| `{app,config,cron,database,docs,storage,tests}/.htaccess` | Bloquean el acceso por URL (importante en XAMPP si el proyecto está dentro de `htdocs`) |
| `tests/nucleo/*` | Las tres pruebas |

## 5. Cómo se usa (ejemplos para los demás)

**Endpoint** (`public/api/paquetes/ver.php`):

```php
<?php
require_once __DIR__ . '/../../../app/core/bootstrap.php';

api_run('GET', ['admin', 'despachador', 'gerente'], function (array $in, ?array $usuario): array {
    $d = validar($in, ['id' => 'requerido|int|min:1']);
    $paquete = paquete_obtener($d['id']);
    if ($paquete === null) {
        error_no_encontrado('El paquete');
    }
    return $paquete;
});
```

**Listado con paginación y orden seguro:**

```php
[$page, $porPagina, $offset] = paginacion($in);
$orden = orden_seguro($in['orden'] ?? null, ['created_at', 'estado'], 'created_at_desc');
$total = (int) db_val('SELECT COUNT(*) FROM paquetes');
$filas = db_all("SELECT id, codigo_guia, estado FROM paquetes ORDER BY $orden LIMIT ? OFFSET ?", 'ii', [$porPagina, $offset]);
api_meta(meta_paginacion($page, $porPagina, $total));
return $filas;
```

**Pantalla** (`public/hub/paquetes.php`):

```php
<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Paquetes', 'm3');
?>
<div class="container-fluid py-3">  <!-- HTML con contenedores vacíos -->
    <div id="tabla-paquetes"></div>
</div>
<?php
render_footer(['m3/paquetes.js']);   // relativo a public/assets/js/ ; librerías: 'vendor/leaflet/leaflet.js'
```

Reglas que recuerda `api()` en el navegador: `await api('paquetes/ver.php', { datos: { id: 5 } })`, `await api.post('paquetes/crear.php', datos, { formulario: form })`. La política de seguridad (CSP) **no permite scripts en línea**: toda la lógica va en archivos `.js`.

## 6. Cambios y adiciones respecto a `docs/00-base/07-servicios-internos.md`

Hay que reflejarlos en la documentación mediante pull request (cambio de contrato, `01-convenciones.md` sección 7). Todos son **compatibles** con las firmas publicadas (solo agregan parámetros opcionales o funciones nuevas).

| Tema | Cambio |
|---|---|
| `api_run` | 4.º parámetro opcional `$opciones`: `sesion`, `csrf`, `rate` (`['clave'=>'track','max'=>60,'segundos'=>60]`, límite por IP) y `permitir_cambio_pendiente`. Login usa `sesion` + `csrf`; el portal usará `rate` |
| `validar` | Reglas nuevas `crudo` (sin recortar; para contraseñas) y `fecha_hora_ms` (milisegundos; para `capturado_at` del GPS). Un campo sin `requerido` ni `opcional` es **requerido**. No pone límites de longitud por defecto: agregar `max:N` según la columna |
| Ayudas nuevas (db) | `db_tipos`, `db_like`, `db_tx_nivel`, `db_en_transaccion`, `fila_tipos`, `filas_tipos` (DECIMAL → número, TINYINT → booleano) |
| Ayudas nuevas (entrada/salida) | `meta_paginacion`, `orden_seguro`, `entrada_cuerpo_crudo` (para firmas de webhooks) |
| Ayudas nuevas (entorno) | `rp_url`, `rp_ruta_storage`, `rp_ip_cliente`, `rp_es_dev`, `rp_es_https`, `rp_asset` |
| Textos | `rp_estado_texto`, `rp_incidencia_texto`, `rp_rol_texto` (mismos textos de `04-estados-y-reglas.md`) |
| `audit` | 5.º parámetro opcional `?int $usuarioId` (si no hay sesión) |
| `auth_usuario` | Parámetro opcional `bool $recalcular`. `auth_requerir_pagina` acepta `$opciones` (`permitir_cambio_pendiente`) |
| `auth_politica_password` | 2.º y 3.er parámetros opcionales: `?string $email`, `string $campo` |
| `usuario_crear` | 2.º parámetro opcional por referencia `&$passwordTemporal` (se llena si se generó una contraseña temporal; mostrarla **una sola vez**) |
| `render_header` | 3.er parámetro `$opciones`: `navbar`, `css` (relativos a `assets/css/`), `vendor_css` (relativos a `assets/vendor/`) |
| `render_footer` | Las rutas son relativas a `assets/js/`; si empiezan con `vendor/` o `js/` son relativas a `assets/` |
| Nuevo | `usuario_cambiar_password`, `usuario_generar_password_temporal`, `auth_menu_por_rol`, `auth_url_inicio`, `auth_hash_password` |
| `config_set` | Valida el tipo del parámetro (los rangos específicos los valida el endpoint) y audita |
| Token CSRF en el navegador | `api.js` lo pide a `auth/csrf.php` cuando hace falta; las pantallas no necesitan ponerlo en ninguna parte |

## 7. Decisiones tomadas (revisar)

1. **Sin `declare(strict_types=1)`**: evita errores de tipo por datos que llegan como texto desde la web; la validación estricta la hace `validar()`.
2. **Modo SQL fijo** en cada conexión: `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION`. Así las consultas mal escritas fallan al desarrollar y no al desplegar. Importante para M6 (GROUP BY).
3. **Errores SQL**: `1062` (duplicado) y `1451/1452` (claves foráneas) se convierten en `CONFLICTO` (409); cualquier otro error SQL → `ERROR_INTERNO` y el detalle va solo a `storage/logs/app.log`.
4. **`api_run` descarta cualquier salida accidental** antes del JSON (avisos de PHP, espacios) y la registra en el log, para no corromper la respuesta.
5. **Cookie de sesión** con `path=/`; si el proyecto se instala en un subdirectorio de un servidor compartido con otras aplicaciones PHP, usar un nombre de cookie distinto (`RPSESSID` ya lo es).
6. **Login**: límite de 10 intentos por minuto por IP y 10 por 15 minutos por correo (además del bloqueo de cuenta a los 5 fallos). Con el mismo correo desde muchas IPs, la cuenta se bloquea 15 min a los 5 fallos.
7. **Rate limit por archivos**: si `storage/cache` no es escribible, el límite se omite y se avisa en el log (no se cae el servicio).
8. **Si la base de datos no responde**, `config_get` devuelve los valores por defecto y registra el error; `db()` lanza `ERROR_INTERNO` con un mensaje genérico.
9. **Contraseñas**: bcrypt con costo 12 (igual que el seed); mínimo 10 caracteres (nunca menos de 8 aunque se configure), con letra y número, sin contener el correo.
10. **Pantallas** con la CSP `script-src 'self'`: nada de `<script>` en línea ni `onclick=""`.

## 8. Limitaciones conocidas

- Falta M8 etapa B: los endpoints `usuarios/*`, `auditoria/*` y `configuracion/*`, y la revisión completa de seguridad. El bloqueo de cuenta, la política de contraseñas, el límite de peticiones, la auditoría y las cabeceras de seguridad ya están en esta entrega.
- `layout.php` supone que existen `vendor/bootstrap` y `vendor/bootstrap-icons` en `public/assets/vendor/` (ya están en el repositorio).
- `auth_menu_por_rol()` enlaza pantallas que todavía no existen (`hub/paquetes.php`, etc.); darán 404 hasta que cada compañero las cree.
- El idioma de los mensajes está fijo en español.

## 9. Siguiente (lo que sigue en `IA-CARLOS.txt`)

T9 (M7 modo simulado: `notificar`, `geocodificar`, `ruta_matriz`, `ruta_geometria`, `integracion_log`) y T10 (publicar las firmas de los servicios para el Hito 1). Después T11 (M8 etapa B) y T12 (M7 completo).
