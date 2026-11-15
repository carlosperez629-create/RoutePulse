# M8. Seguridad y usuarios (RBAC)

Responsable: **Carlos** (núcleo, servicios y endpoints). Las pantallas de administración `admin/usuarios.php`, `admin/auditoria.php` y `admin/configuracion.php` las hace **Karoline**, consumiendo los endpoints de Carlos (ver `GUIA-KAROLINE.md`).

Servicios: `usuario_service.php`, `auditoria_service.php`, `rate_limit_service.php`, y el núcleo `app/core/auth.php`, `csrf.php`. Carpetas: `public/api/{auth,usuarios,auditoria,configuracion}/`, `public/login.php`, `public/index.php`, `public/cambiar_password.php`, `public/admin/{usuarios,auditoria,configuracion}.php`.

## 1. Propósito y alcance

**Hace:** autenticación (login/logout), sesiones seguras, control de acceso por roles (RBAC), gestión de usuarios y contraseñas, protección CSRF, límite de peticiones, cabeceras de seguridad, bitácora de auditoría y administración de los parámetros del sistema.

**No hace:** no gestiona conductores como entidad de negocio (M1), no gestiona tokens de seguimiento de clientes (M5), no implementa autenticación de terceros (OAuth, 2FA: fuera de alcance).

**Por ser transversal, M8 se entrega en dos etapas:**
- **Etapa A (Fase 0, mínimo):** `bootstrap`, `db`, `api_run`, `validar`, `auth_login` básico (verifica la clave con `password_verify` y abre sesión), `auth_requerir`, CSRF, layout y menú por rol. Con esto el Hito 0 queda cumplido.
- **Etapa B (Fase 1):** bloqueo por intentos, expiración de sesión, política de contraseñas, auditoría completa, rate limit, administración de usuarios y parámetros, y cabeceras de seguridad definitivas.

## 2. Dependencias

No depende de otros módulos. Todos dependen de M8 (`api_run`, `auth_*`, `audit`, `validar`, `rate_limit_check`, `config_get`).

## 3. Pantallas

| Página | Rol | Contenido |
|---|---|---|
| `login.php` | Público | Correo, contraseña, botón Entrar. Mensaje genérico ante error. Aviso "Tu sesión expiró" si llega `?expirada=1`. Sin enlace de "registrarse" |
| `index.php` | — | Redirige según el rol (ver `02-estructura-proyecto.md`, sección 4); sin sesión → `login.php` |
| `cambiar_password.php` | * | Formulario: contraseña actual, nueva, confirmar; muestra los requisitos. Obligatoria cuando `debe_cambiar_password = 1` (el usuario no puede ir a otra pantalla hasta cambiarla) |
| `admin/usuarios.php` | A | Tabla de usuarios (nombre, correo, rol, activo, último acceso, bloqueado). Acciones: crear, editar, activar/desactivar, desbloquear, resetear contraseña |
| `admin/auditoria.php` | A, G | Bitácora con filtros: usuario, acción, entidad, fechas. Muestra el detalle JSON en un panel |
| `admin/configuracion.php` | A | Tabla de `configuracion` agrupada por módulo dueño; edición en línea con validación por tipo; muestra la descripción de cada parámetro |

## 4. Tablas propias

`usuarios`, `log_auditoria`, `configuracion`. Los 4 roles con login (`admin`, `despachador`, `gerente`, `conductor`) son un `ENUM` en `usuarios.rol`; el límite de peticiones guarda sus contadores en archivos de `storage/cache/rate_limit/`.

## 5. Endpoints

### `GET auth/csrf.php` (público)
Inicia (o reutiliza) la sesión y devuelve `{ "csrf_token": "..." }`.

### `POST auth/login.php` (público)
`{ "email", "password" }`. Pasos:
1. Rate limit: `login:ip:{ip}` con `rate_login_por_min` por minuto, y `login:email:{sha1(email)}` con 10 por 15 minutos → `LIMITE`.
2. Buscar usuario activo por correo (comparación sin distinguir mayúsculas). Si no existe, hacer igualmente un `password_verify` contra un hash falso (para no revelar por tiempo si el correo existe).
3. Si `bloqueado_hasta > ahora` → `NO_AUTENTICADO` con el **mismo** mensaje genérico ("Correo o contraseña incorrectos") y se audita `login_bloqueado`.
4. Contraseña incorrecta: `intentos_fallidos + 1`; al llegar a `login_max_intentos` (5): `bloqueado_hasta = ahora + login_bloqueo_min` (15 min) y `intentos_fallidos = 0`; auditar `login_fallido`; responder el mismo mensaje genérico.
5. Correcta: `session_regenerate_id(true)`, reiniciar `intentos_fallidos` y `bloqueado_hasta`, `ultimo_login = ahora`, `password_needs_rehash` (si procede, re-guardar el hash), guardar en sesión `usuario_id`, `rol`, `inicio_sesion`, `ultima_actividad`; auditar `login_ok`.
6. Respuesta: `{ usuario: {id, nombre, email, rol, conductor_id?, debe_cambiar_password}, csrf_token, redirigir_a: "/monitor/flota.php" }`.

### `POST auth/logout.php` (*)
Destruye la sesión; audita `logout`.

### `GET auth/me.php` (*)
`{ usuario: {...}, menu: [ {texto, url, icono} ], csrf_token, zona_horaria_ui }`. El menú según `06-roles-y-permisos.md`, sección 4.

### `POST auth/cambiar_password.php` (*)
`{ password_actual, password_nueva }`. Verifica la actual, aplica la política, guarda con `password_hash(PASSWORD_DEFAULT)`, pone `debe_cambiar_password = 0`, regenera la sesión y audita `password_cambiar`. La nueva no puede ser igual a la actual.

### Usuarios (rol A)
- `GET usuarios/listar.php`: filtros `rol`, `activo`, `q`.
- `GET usuarios/ver.php?usuario_id=`.
- `POST usuarios/crear.php`: `nombre`, `email` (único), `rol` (código; **no** `destinatario`), `telefono?`, `password?` (si falta se genera una temporal de 12 caracteres aleatorios que se devuelve **una sola vez** y se marca `debe_cambiar_password = 1`). Si el rol es `conductor`, el usuario queda sin fila en `conductores` hasta que M1 la cree (se recomienda crear conductores desde M1).
- `POST usuarios/actualizar.php`: `usuario_id` + `nombre?`, `email?`, `telefono?`, `rol?`, `activo?`, `desbloquear?` (limpia `intentos_fallidos` y `bloqueado_hasta`). No permite que un administrador se quite a sí mismo el rol `admin` ni se desactive. Al desactivar o cambiar el rol, el usuario pierde acceso en su siguiente petición (se valida `activo` y `rol` contra la BD cada 5 minutos o al cambiar).
- `POST usuarios/resetear_password.php`: `usuario_id` → contraseña temporal (una sola vez) y `debe_cambiar_password = 1`.

### `GET auditoria/listar.php` (A, G)
Filtros: `usuario_id`, `accion`, `entidad`, `entidad_id`, `desde`, `hasta`. Paginado. `detalle` se devuelve como objeto.

### Configuración (A)
- `GET configuracion/listar.php`: todos los parámetros con tipo, valor y descripción.
- `POST configuracion/actualizar.php`: `clave`, `valor`. Valida según `tipo` (`int` entero, `float` número, `bool` 0/1, `json` válido, `string`) y rangos razonables (por ejemplo `geocerca_radio_m` entre 50 y 5000). Audita `configuracion_actualizar` con el valor anterior y el nuevo.

## 6. Reglas de seguridad (aplican a TODO el proyecto)

### 6.1 Sesión
- Cookie `RPSESSID`: `HttpOnly`, `SameSite=Lax`, `Secure` si hay HTTPS, `use_strict_mode`, `use_only_cookies`.
- Expiración por inactividad: `sesion_inactividad_min` (60 min). Conductor: duración máxima `sesion_conductor_horas` (12 h) y sin cierre por inactividad (para no perder la sesión en plena ruta; se renueva con cada envío de GPS).
- Cada petición actualiza `ultima_actividad`. Sesión vencida → `NO_AUTENTICADO`.
- `session_regenerate_id(true)` al iniciar sesión y al cambiar la contraseña.

### 6.2 Contraseñas
- Mínimo `password_min_largo` (10) caracteres, al menos una letra y un dígito; no puede contener el correo.
- Se guardan con `password_hash(PASSWORD_DEFAULT)`. Nunca se registran ni se devuelven (excepto la temporal, una sola vez).

### 6.3 CSRF
`csrf_validar()` en todo `POST` con sesión (lo hace `api_run`). Token por sesión de 32 bytes aleatorios, enviado en `X-CSRF-Token`, comparado con `hash_equals`.

### 6.4 Cabeceras de seguridad (las envía `bootstrap.php` y `.htaccess`)
```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin   (el portal de seguimiento usa no-referrer)
Permissions-Policy: geolocation=(self), camera=(self), microphone=()
Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'
Strict-Transport-Security: max-age=31536000   (solo con HTTPS)
```
Si el equipo usa otro proveedor de teselas, se agrega su dominio a `img-src`.

### 6.5 Entrada y salida
- Todas las consultas parametrizadas. Salida HTML con `e()`.
- Subidas de archivos: verificación de tipo real y tamaño (M4). Los archivos no se sirven desde `public/`.
- Los mensajes de error al cliente nunca incluyen SQL, rutas ni trazas.

### 6.6 Rate limit (`rate_limit_check($clave, $max, $ventanaSeg)`)
Ventana fija con contadores en **archivos**, sin tabla: un archivo JSON por clave en `storage/cache/rate_limit/<sha1(clave)>.json` con `{ventana_inicio, contador}`, leído y escrito con `flock` para que dos peticiones simultáneas no se pisen. Si el archivo no existe o la ventana expiró, se crea con contador 1; si no, se incrementa; si `contador > max` → `LIMITE` con `Retry-After`. Limpieza oportunista: 1 de cada 100 llamadas borra los archivos con más de 1 día de antigüedad. Si `storage/cache/` no es escribible, el límite se omite y se registra un `warning` (no se bloquea el servicio).

### 6.7 Auditoría (`audit()`)
Inserta en `log_auditoria` con `usuario_id` de la sesión (o `NULL` para acciones del sistema o públicas), IP (`REMOTE_ADDR`; solo se confía en `X-Forwarded-For` si `app.proxy_confiable = true`), `user_agent` (recortado a 255) y `detalle` JSON (sin contraseñas, tokens ni claves). **Un fallo al auditar nunca interrumpe la operación**: se registra con `log_app`.

Catálogo de acciones (todos los módulos usan **estos** nombres):

| Módulo | Acciones |
|---|---|
| M8 | `login_ok`, `login_fallido`, `login_bloqueado`, `logout`, `password_cambiar`, `usuario_crear`, `usuario_actualizar`, `password_resetear`, `configuracion_actualizar` |
| M3 | `paquete_crear`, `paquete_actualizar`, `paquete_cambiar_estado`, `incidencia_crear`, `incidencia_resolver`, `hub_crear`, `hub_actualizar` |
| M1 | `ruta_generar`, `ruta_confirmar`, `ruta_reordenar`, `ruta_recalcular`, `ruta_cancelar`, `ruta_reasignar`, `parada_omitir`, `vehiculo_crear`, `vehiculo_actualizar`, `conductor_crear`, `conductor_actualizar`, `zona_crear`, `zona_actualizar` |
| M4 | `ruta_iniciar`, `ruta_finalizar`, `entrega_registrar`, `entrega_fallida`, `otp_solicitar` |
| M7 | `integracion_actualizar`, `api_key_regenerar`, `notificacion_reenviar`, `webhook_reprocesar` |
| M6 | `kpi_exportar` |

### 6.8 Acceso por rol
Descrito en `06-roles-y-permisos.md`. En el código: `api_run(método, roles, ...)` para endpoints y `auth_requerir_pagina(roles)` para pantallas. La propiedad del recurso (el conductor solo ve lo suyo) la valida cada servicio con `auth_conductor_id()`.

## 7. Núcleo que entrega M8 en la Fase 0 (resumen)

Ver `07-servicios-internos.md`, sección A. Lista de verificación del Hito 0:
- [ ] `bootstrap.php` carga la configuración, la zona horaria UTC, los errores y la sesión segura.
- [ ] `db()`, `db_all`, `db_one`, `db_val`, `db_exec`, `db_insert`, `db_tx` (anidable), `db_in`.
- [ ] `api_run` completo (método, rol, CSRF, JSON de entrada, errores, respuesta).
- [ ] `validar()` con todas las reglas del documento.
- [ ] `auth_login`, `auth_logout`, `auth_usuario`, `auth_requerir`, `auth_requerir_pagina`, `auth_conductor_id`.
- [ ] `csrf_token`, `csrf_validar`.
- [ ] `log_app`, `audit`, `config_get`, `app_cfg`.
- [ ] `render_header`, `render_footer`, `api.js`, `ui.js`.
- [ ] Login funcionando con los 5 usuarios del seed.

## 8. Datos de prueba (seed)

5 usuarios (cada uno con su rol) (clave `Dev12345!`) y los parámetros de `configuracion`.

## 9. Criterios de aceptación

- [ ] Se puede iniciar sesión con cada usuario del seed y cada uno llega a su pantalla inicial con su menú.
- [ ] Credenciales erróneas muestran siempre el mismo mensaje, tanto si el correo existe como si no.
- [ ] Tras 5 intentos fallidos la cuenta se bloquea 15 minutos (incluso con la contraseña correcta) y el administrador puede desbloquearla.
- [ ] Más de `rate_login_por_min` intentos por minuto desde una IP responden `LIMITE`.
- [ ] Un `POST` sin el encabezado `X-CSRF-Token` o con uno incorrecto responde `CSRF_INVALIDO`.
- [ ] Un conductor que abre una URL de administrador (pantalla o API) recibe "Sin permiso" / `SIN_PERMISO`; sin sesión, `NO_AUTENTICADO` o redirección al login.
- [ ] Una sesión vencida por inactividad fuerza el login (excepto la del conductor, que dura hasta 12 h).
- [ ] Una contraseña que no cumple la política es rechazada con el detalle del requisito.
- [ ] `usuarios/crear.php` devuelve la contraseña temporal una sola vez y obliga a cambiarla en el primer acceso.
- [ ] Un administrador no puede quitarse el rol ni desactivarse a sí mismo.
- [ ] Todas las acciones del catálogo de la sección 6.7 dejan fila en `log_auditoria` con usuario, IP y detalle sin datos sensibles.
- [ ] Las cabeceras de seguridad están presentes en páginas y APIs.
- [ ] Intentar `' OR 1=1 --` en el correo del login no altera la consulta.
- [ ] El texto `<script>` en el nombre de un usuario se ve como texto en la pantalla (no se ejecuta).
- [ ] `configuracion/actualizar.php` rechaza valores fuera de rango o de tipo incorrecto y audita el cambio.
