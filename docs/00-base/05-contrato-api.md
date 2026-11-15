# 05. Contrato de la API (AJAX)

Todas las pantallas hablan con el servidor mediante endpoints JSON. Este documento fija el formato común y el catálogo completo de endpoints. El detalle de entradas y salidas de cada endpoint está en la especificación de su módulo (`modulos/`).

## 1. Reglas generales

- URL: `/api/<recurso>/<accion>.php`. Ejemplo: `POST /api/paquetes/crear.php`.
- Lectura = `GET` (parámetros en la query). Escritura = `POST` con cuerpo `application/json`. Excepción: subida de archivos = `POST multipart/form-data`.
- Respuesta siempre `Content-Type: application/json; charset=utf-8`.
- Claves JSON en `snake_case` y en español, igual que las columnas.
- Identificadores: enteros. Dinero: número con 2 decimales. Coordenadas: números (`lat`, `lng`). Booleanos: `true`/`false`. Valores ausentes: `null`.
- **Fechas y horas**: en la API van en ISO 8601 UTC con `Z` (`2026-11-13T15:04:05Z`); fechas sin hora como `2026-11-13`. Si llegan con zona (`-05:00`), `validar()` las convierte a UTC. La pantalla convierte a la zona local (`zona_horaria_ui`).
- Un método HTTP equivocado responde `405 METODO_NO_PERMITIDO`.

## 2. Formato de respuesta

Éxito (HTTP 200 o 201):

```json
{ "ok": true, "data": { "...": "..." }, "meta": { "page": 1, "por_pagina": 25, "total": 130, "paginas": 6 } }
```

`meta` solo aparece en listados. `data` es un objeto, un arreglo o `null`.

Error:

```json
{ "ok": false, "error": { "codigo": "VALIDACION", "mensaje": "Revisa los campos marcados.", "campos": { "destinatario_telefono": "Teléfono inválido" } } }
```

`campos` solo aparece en `VALIDACION`. El `mensaje` está en español, listo para mostrarse al usuario, y nunca contiene detalles internos (SQL, rutas de archivos, trazas).

### Códigos de error

| `codigo` | HTTP | Cuándo |
|---|---|---|
| `PARAMETROS` | 400 | Falta un parámetro o el JSON es inválido |
| `NO_AUTENTICADO` | 401 | No hay sesión válida o expiró |
| `SIN_PERMISO` | 403 | El rol no puede usar el endpoint, o el recurso no le pertenece |
| `CSRF_INVALIDO` | 403 | Falta o no coincide `X-CSRF-Token` |
| `NO_ENCONTRADO` | 404 | El recurso no existe (o el token de seguimiento no es válido) |
| `METODO_NO_PERMITIDO` | 405 | Método HTTP incorrecto |
| `CONFLICTO` | 409 | Duplicado o conflicto de datos (placa repetida, correo repetido, etc.) |
| `ESTADO_INVALIDO` | 409 | La transición de estado no está permitida |
| `OTP_INVALIDO` | 422 | Código OTP incorrecto, vencido o con intentos agotados |
| `VALIDACION` | 422 | Datos con formato o valores inválidos (incluye `campos`) |
| `LIMITE` | 429 | Demasiadas peticiones (límite de velocidad) |
| `ERROR_INTERNO` | 500 | Fallo no previsto (el detalle va a `app.log`) |
| `PROVEEDOR_EXTERNO` | 502 | Falló un servicio externo (mapas, mensajería) |

## 3. Autenticación, sesión y CSRF

- Sesión PHP con cookie `RPSESSID` (`HttpOnly`, `SameSite=Lax`, `Secure` si hay HTTPS).
- Antes de cualquier `POST`, la pantalla obtiene el token con `GET /api/auth/csrf.php` (el login ya lo devuelve) y lo envía en el encabezado `X-CSRF-Token`. `api.js` lo hace automáticamente.
- Endpoints **sin sesión** (públicos): `auth/csrf.php`, `auth/login.php`, `tracking/*` (por token de seguimiento), `webhooks/*` (por firma), `integraciones/paquetes_entrada.php` (por API key). Estos no usan CSRF; se protegen con rate limit y su propia credencial.
- `api_run` recibe la lista de roles permitidos: `[]` = público, `['*']` = cualquier usuario con sesión, o lista de códigos de rol (`['admin','despachador']`).
- Además del rol, los servicios validan **propiedad**: un conductor solo ve y modifica **su** ruta.

## 4. Listados, filtros y paginación

- Parámetros: `page` (desde 1, por defecto 1), `por_pagina` (por defecto 25, máximo 100), `q` (búsqueda de texto), `orden` (`campo_asc` o `campo_desc`; solo campos permitidos por cada endpoint), más los filtros propios.
- Filtros de varios valores: separados por coma (`estado=creado,ingresado_hub`).
- Rangos de fechas: `desde` y `hasta` (`AAAA-MM-DD`, inclusivos, interpretados en `zona_horaria_ui` y convertidos a UTC por el servidor).

## 5. Subida de archivos

- `multipart/form-data`; los campos de archivo se llaman `firma` y `foto`.
- Tipos permitidos: `image/png`, `image/jpeg`, `image/webp`. Tamaño máximo 5 MB por archivo. El servidor verifica el tipo real con `finfo`, no por la extensión.
- La firma también puede enviarse como `firma_base64` (data URI PNG) para facilitar el canvas y la cola offline.

## 6. Idempotencia

Las operaciones que el teléfono puede repetir al recuperar la conexión llevan un `uuid` generado en el cliente (`entregar`, `fallar`). Si el servidor ya lo recibió, responde `200` con el registro existente y `"duplicado": true`. Los webhooks y la API de entrada de paquetes son idempotentes por `origen_externo_id`.

## 7. Límites de velocidad

`rate_limit_check()` devuelve `429 LIMITE` con el encabezado `Retry-After` (segundos). Límites por defecto: login 10/min por IP, portal de seguimiento 60/min por IP.

## 8. Catálogo de endpoints

Roles: A = admin, D = despachador, G = gerente, C = conductor, P = público.

### M8. Seguridad y usuarios

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `auth/csrf.php` | GET | P | Inicia sesión anónima y devuelve el token CSRF |
| `auth/login.php` | POST | P | Inicia sesión |
| `auth/logout.php` | POST | * | Cierra sesión |
| `auth/me.php` | GET | * | Usuario actual, rol, menú permitido |
| `auth/cambiar_password.php` | POST | * | Cambia la contraseña propia |
| `usuarios/listar.php` | GET | A | Lista usuarios |
| `usuarios/ver.php` | GET | A | Detalle de un usuario |
| `usuarios/crear.php` | POST | A | Crea usuario |
| `usuarios/actualizar.php` | POST | A | Edita datos, rol, activo, desbloquea |
| `usuarios/resetear_password.php` | POST | A | Genera contraseña temporal |
| `auditoria/listar.php` | GET | A, G | Consulta la bitácora |
| `configuracion/listar.php` | GET | A | Lista parámetros |
| `configuracion/actualizar.php` | POST | A | Cambia un parámetro |

### M3. Trazabilidad y HUBs

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `paquetes/crear.php` | POST | A, D | Crea un paquete |
| `paquetes/importar_csv.php` | POST | A, D | Carga masiva desde CSV (opcional, prioridad baja) |
| `paquetes/listar.php` | GET | A, D, G | Lista con filtros |
| `paquetes/ver.php` | GET | A, D, G | Detalle con historial e incidencias |
| `paquetes/actualizar.php` | POST | A, D | Edita datos (solo estados previos a ruta) |
| `paquetes/cambiar_estado.php` | POST | A, D | Cambio manual de estado permitido |
| `paquetes/escanear.php` | POST | A, D | Ingreso al HUB o clasificación por código |
| `paquetes/etiqueta.php` | GET | A, D | Datos para imprimir la guía |
| `paquetes/historial.php` | GET | A, D, G | Cadena de custodia del paquete |
| `incidencias/listar.php` | GET | A, D, G | Lista incidencias |
| `incidencias/crear.php` | POST | A, D | Registra una incidencia manual |
| `incidencias/resolver.php` | POST | A, D | Reprogramar, devolver o cancelar |
| `hubs/listar.php` | GET | A, D, G | Lista HUBs |
| `hubs/crear.php`, `hubs/actualizar.php` | POST | A | Gestión de HUBs |

### M1. Despacho y rutas

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `vehiculos/listar.php` | GET | A, D, G | Lista vehículos |
| `vehiculos/crear.php`, `vehiculos/actualizar.php` | POST | A, D | Gestión de vehículos |
| `conductores/listar.php` | GET | A, D, G | Lista conductores |
| `conductores/crear.php` | POST | A, D | Crea conductor (y su usuario) |
| `conductores/actualizar.php` | POST | A, D | Edita estado, licencia, vehículo |
| `zonas/listar.php` | GET | A, D, G | Lista zonas con polígono |
| `zonas/crear.php`, `zonas/actualizar.php` | POST | A, D | Gestión de zonas y códigos postales |
| `zonas/asignar_conductores.php` | POST | A, D | Asigna conductores a una zona |
| `zonas/resolver.php` | GET | A, D | Zona de un punto o código postal |
| `rutas/generar.php` | POST | A, D | Genera rutas en borrador |
| `rutas/listar.php` | GET | A, D, G | Lista rutas |
| `rutas/ver.php` | GET | A, D, G | Ruta con paradas |
| `rutas/confirmar.php` | POST | A, D | Borrador → planificada |
| `rutas/reordenar.php` | POST | A, D | Cambia el orden de paradas |
| `rutas/agregar_paquete.php` | POST | A, D | Agrega un paquete a una ruta |
| `rutas/quitar_paquete.php` | POST | A, D | Quita un paquete (borrador/planificada) |
| `rutas/omitir_parada.php` | POST | A, D | Omite una parada de una ruta en curso |
| `rutas/reasignar.php` | POST | A, D | Cambia conductor y/o vehículo |
| `rutas/recalcular.php` | POST | A, D | Reenrutamiento dinámico |
| `rutas/cancelar.php` | POST | A, D | Cancela borrador o planificada |

### M2. Rastreo GPS

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `gps/enviar.php` | POST | C | Envía uno o varios puntos GPS |
| `gps/flota.php` | GET | A, D, G | Posición de toda la flota activa |
| `gps/recorrido.php` | GET | A, D, G | Recorrido real vs sugerido (playback) |
| `gps/eventos.php` | GET | A, D | Eventos de geocerca de una ruta |
| `gps/eta.php` | GET | A, D, G, C | ETA de las paradas de una ruta |

### M4. App del conductor

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `conductor/mi_ruta.php` | GET | C | Hoja de ruta del conductor |
| `conductor/iniciar_ruta.php` | POST | C | Inicia la ruta |
| `conductor/llegada.php` | POST | C | Marca llegada a la parada |
| `conductor/solicitar_otp.php` | POST | C | Envía el OTP al receptor |
| `conductor/entregar.php` | POST (multipart) | C | Registra entrega con pruebas |
| `conductor/fallar.php` | POST (multipart) | C | Registra entrega fallida |
| `conductor/finalizar_ruta.php` | POST | C | Cierra la ruta |
| `archivos/ver.php` | GET | A, D, G, C | Muestra una firma o foto (con control de acceso) |

### M5. Portal del cliente

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `t/{token}` (`t.php`) | GET | P | Página de seguimiento |
| `tracking/estado.php` | GET | P (token) | Estado, historial, ETA y posición |
| `tracking/reprogramar.php` | POST | P (token) | Reprogramar una entrega fallida |

### M6. Dashboard

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `kpi/resumen.php` | GET | A, G | OTIF, FTDR, tiempo de parada, costos |
| `kpi/serie.php` | GET | A, G | Serie diaria de KPIs |
| `kpi/ranking_conductores.php` | GET | A, G | KPIs por conductor |
| `kpi/por_zona.php` | GET | A, G | KPIs y tasa de rechazo por zona |
| `kpi/mapa_calor.php` | GET | A, G | Puntos para el mapa de calor |
| `kpi/exportar.php` | GET | A, G | Exporta CSV |

### M7. Integraciones y notificaciones

| Endpoint | Método | Roles | Descripción |
|---|---|---|---|
| `webhooks/{codigo}.php` | POST | P (firma) | Recibe pedidos del e-commerce |
| `integraciones/paquetes_entrada.php` | POST | P (API key) | ERP/WMS envía paquetes por lote |
| `integraciones/listar.php` | GET | A | Lista integraciones |
| `integraciones/actualizar.php` | POST | A | Activa, modo simulado, ajustes, API key |
| `integraciones/probar.php` | POST | A | Prueba de conexión |
| `integraciones/log.php` | GET | A | Bitácora de llamadas |
| `integraciones/webhooks.php` | GET | A | Eventos de webhook recibidos |
| `integraciones/reprocesar_webhook.php` | POST | A | Reprocesa un evento en error |
| `notificaciones/listar.php` | GET | A, D | Cola e historial de mensajes |
| `notificaciones/reenviar.php` | POST | A, D | Reenvía una notificación fallida |

## 9. Ejemplos

Petición:

```
POST /api/paquetes/cambiar_estado.php
X-CSRF-Token: 9f2c...
Content-Type: application/json

{ "paquete_id": 3, "estado_nuevo": "asignado_ruta", "motivo": "Prueba" }
```

Respuesta con error de transición:

```json
{ "ok": false, "error": { "codigo": "ESTADO_INVALIDO", "mensaje": "No se puede pasar de «En clasificación» a «Entregado»." } }
```
