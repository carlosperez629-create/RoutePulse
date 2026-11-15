# 07. Servicios internos (el contrato entre módulos)

Los módulos se hablan llamando funciones PHP, no con peticiones HTTP entre sí. Este documento fija los nombres, parámetros y retornos **exactos**. Cada dueño publica estas firmas en su archivo de `app/services/` desde el Hito 1 (con cuerpo mínimo que devuelva datos coherentes con el seed) y las implementa completas en su Fase 1.

Convenciones de las firmas: `?tipo` = puede ser `null`; los retornos `array` son arreglos asociativos con las claves de las columnas (`snake_case`); las fechas devueltas por servicios son cadenas `Y-m-d H:i:s` en UTC (la API las convierte a ISO 8601). Los servicios lanzan `AppError` (ver A.3) para errores esperados.

---

## A. Núcleo (`app/core/`, lo entrega Carlos en la Fase 0)

### A.1 Bootstrap y configuración

| Función | Descripción |
|---|---|
| `bootstrap.php` | Se incluye con `require` al inicio de cada endpoint y página. Carga `config/config.php`, fija `date_default_timezone_set('UTC')`, configura errores (`display_errors` según entorno), inicia la sesión segura, envía cabeceras de seguridad y hace `require_once` de todos los archivos de `app/core/` y `app/services/` |
| `app_cfg(string $ruta, mixed $default = null): mixed` | Lee `config/config.php` con notación de puntos: `app_cfg('db.host')`, `app_cfg('twilio.token')`, `app_cfg('app.url')`, `app_cfg('app.entorno')` (`dev` o `prod`) |
| `config_get(string $clave, mixed $default = null): mixed` | Lee la tabla `configuracion`, ya convertida al tipo declarado (`int`, `float`, `bool`, `json` → array). Cachea por petición |
| `config_set(string $clave, mixed $valor, ?int $usuarioId): void` | Actualiza un parámetro (la usa M8 `configuracion/actualizar.php`) |

### A.2 Base de datos (`db.php`, MySQLi)

El texto `$tipos` usa los códigos de `mysqli_stmt::bind_param`: `i` entero, `d` decimal, `s` texto. Ejemplo: `db_one('SELECT * FROM paquetes WHERE id = ?', 'i', [$id])`.

| Función | Retorna |
|---|---|
| `db(): mysqli` | Conexión única por petición: `utf8mb4`, `mysqli_report` en modo excepciones, `SET time_zone = '+00:00'` |
| `db_all(string $sql, string $tipos = '', array $params = []): array` | Todas las filas |
| `db_one(string $sql, string $tipos = '', array $params = []): ?array` | Una fila o `null` |
| `db_val(string $sql, string $tipos = '', array $params = []): mixed` | Primer campo de la primera fila o `null` |
| `db_exec(string $sql, string $tipos = '', array $params = []): int` | Filas afectadas (UPDATE/DELETE) |
| `db_insert(string $sql, string $tipos = '', array $params = []): int` | `insert_id` |
| `db_tx(callable $fn): mixed` | Ejecuta `$fn` dentro de una transacción; confirma si termina bien, revierte y relanza si lanza excepción. **Admite anidamiento**: si ya hay una transacción abierta, solo ejecuta `$fn` (la transacción externa decide) |
| `db_in(array $valores): string` | Devuelve `?,?,?` para cláusulas `IN (...)`; el llamador arma `$tipos` y `$params` |

### A.3 Errores (`errores.php`)

```
class AppError extends Exception { public string $codigo; public int $http; public array $campos; }
lanzar_error(string $codigo, string $mensaje, int $http = 400, array $campos = []): never
```

Los códigos y su HTTP por defecto son los de `05-contrato-api.md`, sección 2. Atajos: `error_no_encontrado(string $que)`, `error_sin_permiso()`, `error_validacion(array $campos)`, `error_conflicto(string $mensaje)`, `error_estado(string $mensaje)`.

### A.4 Entrada y salida de endpoints (`response.php`, `input.php`)

```
api_run(string $metodo, array $roles, callable $manejador): never
```

`$manejador` recibe `(array $in, ?array $usuario)` y **retorna los datos** (arreglo, objeto o `null`). `api_run` hace, en este orden: comprueba el método → rate limit global → autenticación y rol → validación CSRF (solo `POST` con sesión) → arma `$in` (mezcla de query y cuerpo JSON; en multipart, `$_POST`; archivos aparte con `$_FILES`) → ejecuta `$manejador` → responde `json_ok`. Captura `AppError` → `json_error`; cualquier otra excepción → registra en `app.log` y responde `ERROR_INTERNO`.

| Función | Descripción |
|---|---|
| `api_meta(array $meta): void` | El manejador la llama antes de retornar para agregar `meta` (paginación) |
| `api_status(int $http): void` | Cambia el HTTP de éxito (por ejemplo `201` al crear) |
| `json_ok(mixed $data = null, array $meta = [], int $http = 200): never` | Respuesta de éxito |
| `json_error(string $codigo, string $mensaje, int $http, array $campos = []): never` | Respuesta de error |
| `paginacion(array $in, int $max = 100): array` | Devuelve `[$page, $por_pagina, $offset]` ya saneados |
| `e(?string $texto): string` | Escapa para HTML |

### A.5 Validación (`input.php`)

```
validar(array $in, array $reglas): array
```

Devuelve un arreglo **ya tipado y limpio** (solo las claves declaradas). Si hay errores lanza `VALIDACION` con `campos`. Las reglas se escriben como texto separado por `|`:

| Regla | Significado |
|---|---|
| `requerido` / `opcional` | Obligatorio / puede faltar o venir `null` (entonces se devuelve `null`) |
| `int`, `num`, `bool`, `str` | Tipos básicos |
| `min:N`, `max:N` | Mínimo/máximo numérico, o de longitud si es texto |
| `email` | Correo válido |
| `telefono` | `^\+?[0-9]{7,15}$` |
| `fecha` | `AAAA-MM-DD` |
| `fecha_hora` | ISO 8601 → se devuelve como `Y-m-d H:i:s` en UTC |
| `enum:a,b,c` | Uno de los valores |
| `lat`, `lng` | Rango válido |
| `lista_int` | Lista de enteros (acepta arreglo o texto separado por comas) |
| `uuid` | UUID v4 |

Ejemplo: `$d = validar($in, ['paquete_id' => 'requerido|int|min:1', 'estado_nuevo' => 'requerido|enum:ingresado_hub,en_clasificacion,cancelado', 'motivo' => 'opcional|str|max:255']);`

### A.6 Sesión y autenticación (`auth.php`, `csrf.php`)

El arranque lo entrega Carlos en la Fase 0 con `auth_login` básico (verifica la clave y abre sesión). M8 lo completa (bloqueos, tiempos, auditoría).

| Función | Descripción |
|---|---|
| `auth_login(string $email, string $password): array` | Valida credenciales y abre sesión. Retorna el usuario. Lanza `NO_AUTENTICADO` con mensaje genérico "Correo o contraseña incorrectos" |
| `auth_logout(): void` | Cierra y destruye la sesión |
| `auth_usuario(): ?array` | Usuario de la sesión: `['id','nombre','email','rol','conductor_id']` (`conductor_id` solo si es conductor) o `null` |
| `auth_requerir(array $roles): array` | Para endpoints: lanza `NO_AUTENTICADO` o `SIN_PERMISO`; retorna el usuario |
| `auth_requerir_pagina(array $roles): array` | Para pantallas: redirige a `login.php` o muestra "Sin permiso" |
| `auth_conductor_id(): ?int` | `conductor_id` del usuario en sesión |
| `csrf_token(): string` | Token de la sesión (se crea si no existe) |
| `csrf_validar(): void` | Compara `X-CSRF-Token` con la sesión; lanza `CSRF_INVALIDO` |

### A.7 Utilidades

| Función | Descripción |
|---|---|
| `log_app(string $nivel, string $mensaje, array $contexto = []): void` | Escribe en `storage/logs/app.log` (`debug`, `info`, `warning`, `error`) |
| `audit(string $accion, string $entidad, int\|string\|null $entidadId = null, array $detalle = []): void` | Inserta en `log_auditoria` con el usuario de la sesión, IP y user agent. Catálogo de acciones en M8 |
| `rate_limit_check(string $clave, int $max, int $ventanaSeg): void` | Cuenta en archivos de `storage/cache/rate_limit/` (uno por clave, con bloqueo `flock`); lanza `LIMITE` (429) si se supera |
| `geo_distancia_m(float $lat1, float $lng1, float $lat2, float $lng2): float` | Haversine en metros |
| `geo_punto_en_poligono(float $lat, float $lng, array $geojson): bool` | Punto dentro de un `Polygon` GeoJSON (ray casting) |
| `fecha_utc_ahora(): string` | `Y-m-d H:i:s` en UTC |
| `fecha_a_iso(?string $fechaUtc): ?string` | `Y-m-d H:i:s` UTC → `2026-11-13T15:04:05Z` |
| `uuid_v4(): string` | UUID v4 |
| `render_header(string $titulo, string $modulo): void`, `render_footer(array $scripts = []): void` | Plantilla común de pantallas |

---

## B. M8. Seguridad y usuarios

| Función | Descripción |
|---|---|
| `usuario_crear(array $datos): int` | `datos`: `rol` (código), `nombre`, `email`, `password` (o `null` para generar una temporal), `telefono`. Valida la política de contraseña, correo único. Retorna `usuario_id`. La usa M1 al crear conductores |
| `usuario_obtener(int $id): ?array` | Sin `password_hash` |
| `auth_politica_password(string $password): void` | Lanza `VALIDACION` si no cumple (mínimo `password_min_largo`, al menos una letra y un dígito) |

## C. M3. Trazabilidad

| Función | Descripción |
|---|---|
| `paquete_crear(array $datos, ?int $usuarioId, ?int $integracionId = null): array` | Crea el paquete: valida, genera `codigo_guia`, geocodifica si faltan `lat`/`lng` (llama `geocodificar`), resuelve la zona (llama `zona_resolver`), genera el token (llama `token_seguimiento_generar()`), inserta el paquete con ese token y registra el historial `creado`. Retorna el paquete más `advertencias` (`['sin_coordenadas','sin_zona']`). Idempotente por `(integracion_id, origen_externo_id)`: si existe, retorna el existente con `duplicado => true` |
| `paquete_obtener(int $id): ?array` | Fila completa |
| `paquete_obtener_por_guia(string $guia): ?array` | Por `codigo_guia` |
| `paquete_cambiar_estado(int $paqueteId, string $estadoNuevo, ?int $usuarioId, ?string $motivo = null, ?float $lat = null, ?float $lng = null): array` | **Única** forma de cambiar el estado. Valida la transición (`04-estados-y-reglas.md`), actualiza, inserta en `historial_estados`, dispara notificaciones (sección 5 de `04`), actualiza la vigencia del token en estados terminales, llama `parada_cancelar_por_paquete` si pasa a `cancelado`, audita. Atómica (`db_tx`). Retorna el paquete actualizado |
| `paquetes_para_ruta(int $hubId, ?int $zonaId, string $fecha, ?array $paqueteIds = null): array` | Paquetes `en_clasificacion` con coordenadas y `fecha_programada` nula o `<= $fecha`, sin ruta activa. Cada elemento incluye `id`, `lat`, `lng`, `peso_kg`, `volumen_m3`, `prioridad`, `ventana_inicio`, `ventana_fin`, `zona_id` |
| `incidencia_crear(int $paqueteId, ?int $paradaId, string $tipo, ?string $descripcion, ?int $fotoArchivoId, ?int $usuarioId, bool $cuentaIntento = true): int` | Inserta la incidencia, suma `intentos` si `$cuentaIntento`, y pasa el paquete `en_transito → incidencia` con `paquete_cambiar_estado`. Retorna `incidencia_id` |
| `incidencia_resolver(int $incidenciaId, string $accion, ?string $fecha, ?int $usuarioId, ?array $ventana = null): array` | `$accion`: `reprogramar` (requiere `$fecha`; `$ventana` opcional `['inicio','fin']` en UTC), `devolver`, `cancelar`. Aplica las reglas de intentos. Retorna la incidencia. `$usuarioId` es `null` cuando la resuelve el cliente desde el portal |
| `hub_obtener(int $id): ?array` | |

## D. M1. Despacho y rutas

| Función | Descripción |
|---|---|
| `zona_resolver(float $lat, float $lng, ?string $codigoPostal = null): ?int` | Primero por polígono (`geo_punto_en_poligono`), si no, por código postal; `null` si ninguna |
| `conductor_obtener_por_usuario(int $usuarioId): ?array` | Conductor con su vehículo |
| `ruta_generar(array $opciones): array` | `opciones`: `fecha`, `hub_id`, `zona_id?`, `paquete_ids?`, `conductor_ids?`, `hora_inicio?`, `usuario_id`. Retorna `['rutas' => [...borradores], 'sin_asignar' => [['paquete_id','motivo']]]`. Algoritmo en M1 |
| `ruta_obtener(int $rutaId): ?array` | Ruta con `paradas` ordenadas y datos básicos del paquete en cada una |
| `ruta_activa_de_conductor(int $conductorId): ?array` | La ruta `en_curso`, o si no hay, la `planificada` más próxima de hoy |
| `ruta_confirmar(int $rutaId, int $usuarioId): array` | `borrador → planificada`; pasa los paquetes a `asignado_ruta` |
| `ruta_iniciar(int $rutaId, int $conductorId): array` | `planificada → en_curso`; paquetes a `en_transito`; primera parada `en_camino`; conductor `en_ruta`; `inicio_real` |
| `ruta_finalizar(int $rutaId, ?int $usuarioId): array` | Exige que no queden paradas `pendiente`/`en_camino`/`llegado`; `en_curso → completada`; `fin_real`; conductor `disponible`; `distancia_km_real = gps_distancia_recorrida_km()` |
| `ruta_recalcular(int $rutaId, string $motivo, ?int $usuarioId, array $opciones = []): array` | Reenrutamiento dinámico (ver M1). `opciones['evitar']`: lista de `['lat','lng','radio_m']` |
| `ruta_quitar_paquete(int $rutaId, int $paqueteId, ?int $usuarioId): void` | Solo `borrador`/`planificada` |
| `parada_actualizar_estado(int $paradaId, string $estado, array $extra = []): array` | Aplica el estado, `llegada_real`/`salida_real` desde `$extra['ocurrido_at']`, y marca la siguiente parada `en_camino`. Retorna la parada |
| `parada_siguiente(int $rutaId): ?array` | Parada `en_camino` actual |
| `parada_cancelar_por_paquete(int $paqueteId, ?int $usuarioId): void` | La llama `paquete_cambiar_estado` al cancelar: omite la parada abierta y, si la ruta está en curso, recalcula |

## E. M2. Rastreo GPS

| Función | Descripción |
|---|---|
| `gps_registrar_puntos(int $rutaId, int $conductorId, array $puntos): array` | Valida, inserta con cadena de hash, actualiza `ultima_posicion`, evalúa geocerca y ETA. Retorna `['aceptados' => n, 'rechazados' => [['indice','motivo']], 'eventos' => [...]]` |
| `gps_ultima_posicion(int $conductorId): ?array` | `['lat','lng','velocidad_kmh','rumbo','capturado_at','antiguedad_s']` |
| `gps_distancia_recorrida_km(int $rutaId): float` | Suma de distancias entre puntos consecutivos (descarta saltos imposibles > 150 km/h) |
| `geocerca_evaluar(int $rutaId, float $lat, float $lng): array` | Evalúa la parada `en_camino`; crea `eventos_geocerca` y llama `notificar` una sola vez por parada |
| `eta_recalcular_ruta(int $rutaId, bool $forzar = false): void` | Recalcula `paradas.eta` (algoritmo en M2). Respeta `eta_recalculo_min_s` salvo `$forzar` |
| `eta_de_parada(int $paradaId): ?string` | ETA en `Y-m-d H:i:s` UTC |

## F. M4. App del conductor

| Función | Descripción |
|---|---|
| `otp_generar_y_enviar(int $paqueteId): array` | Genera el código, guarda el HMAC en las columnas `otp_*` de `paquetes`, llama `notificar($paqueteId,'otp',['otp'=>$codigo])`. Retorna `['canal','expira_at']` (y `otp_debug` solo si `app.entorno = dev` y la mensajería está simulada) |
| `otp_validar(int $paqueteId, string $codigo): bool` | Comprueba el último OTP vigente, suma intentos, lo marca `usado` si coincide |
| `archivo_guardar_subido(string $campo, string $tipo, ?float $lat, ?float $lng, ?int $usuarioId): int` | Valida y guarda un archivo de `$_FILES`. Retorna `archivos.id` |
| `archivo_guardar_base64(string $dataUri, string $tipo, ?float $lat, ?float $lng, ?int $usuarioId): int` | Igual, desde un data URI |
| `entrega_registrar(array $datos, int $conductorId): array` | Registra entrega o fallo (idempotente por `uuid`) y orquesta los efectos (ver M4) |

## G. M5. Portal del cliente

| Función | Descripción |
|---|---|
| `token_seguimiento_generar(): string` | Genera un token nuevo de 32 caracteres hexadecimales. **No toca la base de datos**: M3 lo guarda en `paquetes.token_seguimiento` al crear el paquete (y reintenta si hubiera colisión) |
| `tracking_url(string $token): string` | URL absoluta `app_cfg('app.url') . '/t/' . $token` |
| `tracking_estado_publico(string $token): array` | Datos públicos del seguimiento (campos en M5) |
| `tracking_reprogramar(string $token, array $datos): array` | Valida y llama `incidencia_resolver(..., 'reprogramar', ...)` |

## H. M6. Dashboard

| Función | Descripción |
|---|---|
| `kpi_calcular(string $desde, string $hasta, array $filtros = []): array` | Retorna el resumen de KPIs (definiciones exactas en M6) |

## I. M7. Integraciones y notificaciones

| Función | Descripción |
|---|---|
| `notificar(int $paqueteId, string $tipo, array $vars = [], ?string $claveDedup = null): int` | Encola (o, en modo simulado, "envía") una notificación. Elige el primer canal disponible según `canales_orden`. Retorna cuántas filas creó (0 si `claveDedup` ya existía). Nunca lanza error al llamador: un fallo de mensajería se registra y no revierte la operación de negocio |
| `geocodificar(string $direccion, ?string $ciudad = null): ?array` | `['lat','lng','confianza','proveedor']` o `null`. Usa una caché en archivos (`storage/cache/geocode/`) |
| `ruta_matriz(array $puntos): array` | `$puntos` = lista de `['lat','lng']`. Retorna `['distancias_m' => [[...]], 'duraciones_s' => [[...]], 'proveedor' => '...']`. Si el proveedor falla, usa haversine × 1.3 a `eta_velocidad_media_kmh` |
| `ruta_geometria(array $puntosOrdenados): ?array` | Lista de `[lat,lng]` siguiendo calles, o `null` (entonces se usa la línea recta entre paradas) |
| `integracion_log(array $datos): void` | Agrega una línea JSON a `storage/logs/integraciones.log`: `fecha`, `integracion`, `direccion` (`entrante`/`saliente`), `endpoint`, `metodo`, `http_status`, `exito`, `duracion_ms`, `resumen` (sin claves, tokens ni datos personales completos). Nunca lanza errores |
| `integracion_paquete_desde_pedido(string $integracionCodigo, array $pedido): array` | Convierte un pedido externo al formato normalizado y llama `paquete_crear` |

---

## J. Reglas para usar los servicios

1. Un módulo **nunca** modifica una tabla ajena con `UPDATE` directo. Llama al servicio del dueño.
2. Los servicios no leen `$_GET`, `$_POST` ni la sesión; reciben todo por parámetros (excepto `audit()` y `auth_*`).
3. Los servicios que modifican varias tablas usan `db_tx`; como es anidable, un servicio puede llamar a otro sin preocuparse.
4. Las llamadas a `notificar()`, `geocodificar()` y `ruta_matriz()` pueden tardar o fallar: se llaman **fuera** de las transacciones largas cuando sea posible y su fallo no cancela la operación de negocio.
5. Las firmas publicadas son contrato: agregar parámetros opcionales al final es compatible; cambiar o quitar uno requiere el procedimiento de cambio de contrato.
