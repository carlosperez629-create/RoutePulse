# M3. Trazabilidad de envíos y control de HUBs

Responsable: **Juan** (también hace M1). `paquete_cambiar_estado` es lo primero que debe quedar listo porque todos los demás lo usan. Servicio: `paquete_service.php`. Carpetas: `public/api/{paquetes,incidencias,hubs}/`, `public/hub/`.

## 1. Propósito y alcance

**Hace:** registra paquetes; genera la guía con QR y GS1-128; controla el ciclo de vida completo (cadena de custodia) con validación estricta de transiciones; gestiona ingreso y clasificación en el HUB; gestiona incidencias y reintentos; mantiene el historial inmutable.

**No hace:** no arma rutas (M1), no registra entregas (M4), no envía mensajes directamente (llama a `notificar`), no geocodifica por sí mismo (usa `geocodificar` de M7).

**Importante:** `paquete_cambiar_estado()` es el **corazón del sistema**: todos los demás módulos dependen de él. Debe estar completo y probado en la primera semana.

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M7 `geocodificar`, `notificar` | Coordenadas de la dirección; avisos al cliente |
| M1 `zona_resolver`, `parada_cancelar_por_paquete` | Zona del paquete; sacar la parada si se cancela |
| M5 `token_seguimiento_generar` | Enlace de seguimiento del paquete |
| M8 `api_run`, `audit`, `validar` | Permisos, auditoría, validación |

| Provee a | Qué |
|---|---|
| M1 | `paquetes_para_ruta`, `paquete_cambiar_estado` |
| M4 | `paquete_cambiar_estado`, `incidencia_crear` |
| M5 | `paquete_obtener`, `incidencia_resolver` |
| M7 | `paquete_crear` (webhooks y API de entrada), `paquete_cambiar_estado` (cancelaciones) |
| M6 | Lectura de `paquetes`, `incidencias`, `historial_estados` |

## 3. Pantallas

| Página | Rol | Contenido |
|---|---|---|
| `hub/paquetes.php` | A, D, G(L) | Tabla paginada con filtros (estado, HUB, zona, prioridad, fechas, texto: guía, nombre, teléfono, dirección). Insignias de color por estado. Botones: Nuevo paquete, Importar CSV (opcional), imprimir guías seleccionadas |
| `hub/paquete_nuevo.php` | A, D | Formulario (datos del remitente y destinatario, dirección, ciudad, código postal, peso, volumen, cobro contra entrega, ventana horaria, prioridad, notas). Botón "Ubicar en mapa": geocodifica y permite arrastrar el marcador para corregir `lat`/`lng`. Muestra zona resuelta |
| `hub/paquete_detalle.php` | A, D, G(L) | Datos del paquete; **línea de tiempo** del historial (estado, quién, cuándo, motivo); incidencias; ruta y parada actual; enlace de seguimiento copiable; botones de acción según estado (cambiar estado manual permitido, editar, cancelar, imprimir etiqueta) |
| `hub/escaneo.php` | A, D | Campo de texto con foco permanente (el lector de código de barras escribe el código y envía Enter). Selector de acción: "Ingreso al HUB" / "Clasificar". Lista de los últimos escaneos con su resultado (ok/error). Sonido o color verde/rojo por escaneo |
| `hub/etiqueta.php` | A, D | Guía imprimible 100×150 mm (impresora térmica) con: logo, código QR, código de barras GS1-128, `codigo_guia` en grande, destinatario, dirección, ciudad, zona, prioridad (sello "URGENTE"), peso, cobro contra entrega (si aplica), fecha. CSS `@media print` sin márgenes. Admite varias etiquetas en una impresión (una por página) |
| `hub/incidencias.php` | A, D, G(L) | Tabla de incidencias (filtro por estado y tipo). Acción "Resolver": reprogramar (fecha y ventana), devolver al remitente, cancelar |
| `hub/hubs.php` | A | CRUD de HUBs con ubicación en mapa |

## 4. Tablas propias

`paquetes`, `historial_estados`, `incidencias`, `hubs`. En `paquetes`, M3 escribe todo salvo las columnas `otp_*` (de M4). El token de seguimiento (`token_seguimiento`, `token_expira_at`) lo escribe solo M3.

## 5. Endpoints

### `POST paquetes/crear.php` (A, D)
Entrada: `remitente_nombre` (req, ≤100), `remitente_telefono?`, `destinatario_nombre` (req, ≤100), `destinatario_telefono` (req, teléfono), `destinatario_email?` (email), `direccion_entrega` (req, ≤255), `referencia?`, `ciudad?`, `codigo_postal?`, `lat?`, `lng?` (ambos o ninguno), `peso_kg` (0.01–1000, por defecto 0.5), `volumen_m3` (0.0001–50, por defecto 0.01), `cobro_contra_entrega?` (≥0), `moneda?` (3 letras), `ventana_inicio?`, `ventana_fin?` (`ventana_fin` > `ventana_inicio` y > ahora), `prioridad?` (`normal`/`urgente`), `hub_id?`, `notas?`. Usa `paquete_crear`. Respuesta `201` con el paquete y `advertencias`. Auditar `paquete_crear`.

### `POST paquetes/importar_csv.php` (A, D, prioridad baja)
`multipart` con `archivo` (CSV UTF-8, separador coma, primera fila = encabezados con los mismos nombres de los campos de crear). Máximo 500 filas. Procesa fila por fila con `paquete_crear`; no se detiene ante errores. Respuesta: `{creados: n, errores: [{fila, mensaje}]}`.

### `GET paquetes/listar.php` (A, D, G)
Filtros: `estado` (varios), `hub_id`, `zona_id`, `prioridad`, `desde`, `hasta` (sobre `created_at`), `q` (guía, nombre, teléfono, dirección, `origen_externo_id`), `con_incidencia_abierta`. Orden permitido: `created_at`, `estado`, `ventana_fin`, `prioridad`. Cada fila: datos básicos + `token` de seguimiento.

### `GET paquetes/ver.php?id=` o `?guia=` (A, D, G)
Paquete completo + `historial[]` + `incidencias[]` + `parada_actual` (`ruta_id`, `ruta_codigo`, `orden`, `estado`, `eta`) + `entregas[]` (resultado, receptor, fecha) + `enlace_seguimiento`.

### `POST paquetes/actualizar.php` (A, D)
`paquete_id` + campos editables de crear. Solo si el estado es `creado`, `ingresado_hub` o `en_clasificacion`; si no, `ESTADO_INVALIDO`. Si cambia la dirección y no se envían coordenadas, se vuelve a geocodificar; si cambian las coordenadas o el código postal, se recalcula la zona. Auditar con valores anteriores en `detalle`.

### `POST paquetes/cambiar_estado.php` (A, D)
`paquete_id`, `estado_nuevo`, `motivo?`. Solo permite estas transiciones manuales: `creado→cancelado`, `ingresado_hub→cancelado`, `en_clasificacion→cancelado`, `asignado_ruta→cancelado`. Las demás las hacen escaneo, M1, M4 o la resolución de incidencias. `motivo` es obligatorio al cancelar.

### `POST paquetes/escanear.php` (A, D)
`codigo_guia` (req), `accion` (`ingreso_hub` | `clasificar`), `hub_id` (req si `ingreso_hub`). `ingreso_hub` exige estado `creado` y lo pasa a `ingresado_hub` asignando `hub_id`. `clasificar` exige `ingresado_hub` y lo pasa a `en_clasificacion`; si el paquete no tiene coordenadas, lo clasifica pero devuelve `advertencias: ["sin_coordenadas"]`. Un código inexistente → `NO_ENCONTRADO`; un estado incorrecto → `ESTADO_INVALIDO` con el estado actual en el mensaje.

### `GET paquetes/etiqueta.php?ids=1,2,3` (A, D)
Retorna por paquete: `codigo_guia`, `qr_contenido` (= `codigo_guia`), `gs1_128: { ai: "91", valor: "RP261113A1B2C3", texto_legible: "(91)RP261113A1B2C3" }`, y los datos impresos. El dibujo del QR y del código de barras lo hace el navegador con `qrcode.js` y JsBarcode (CODE128 con FNC1 para GS1-128). Máximo 100 ids.

### `GET paquetes/historial.php?paquete_id=` (A, D, G)
Lista cronológica de `historial_estados` con nombre del usuario (o "Sistema").

### Incidencias
- `GET incidencias/listar.php` (A, D, G): filtros `estado`, `tipo`, `desde`, `hasta`, `paquete_id`.
- `POST incidencias/crear.php` (A, D): `paquete_id`, `tipo`, `descripcion?`. Solo para paquetes `en_transito`; usa `incidencia_crear`.
- `POST incidencias/resolver.php` (A, D): `incidencia_id`, `accion` (`reprogramar` | `devolver` | `cancelar`), `fecha?` (req si reprogramar; hoy o futura), `ventana_inicio?`, `ventana_fin?`, `nota?`.

### HUBs
- `GET hubs/listar.php` (A, D, G); `POST hubs/crear.php` y `POST hubs/actualizar.php` (A): `nombre` (req), `direccion?`, `lat`, `lng` (req), `activo?`. No se desactiva un HUB con paquetes en estados no terminales (`CONFLICTO`).

## 6. Servicios que expone

Firmas en `07-servicios-internos.md`, sección C.

## 7. Reglas de negocio

### 7.1 `paquete_cambiar_estado` (detalle de implementación)

En `db_tx`:
1. Leer el paquete con `SELECT ... FOR UPDATE`. Si no existe → `NO_ENCONTRADO`.
2. Si `estado_nuevo` es igual al actual → devolver el paquete sin cambios (idempotente, no duplica historial).
3. Verificar que `(estado_actual, estado_nuevo)` esté en la tabla de transiciones de `04-estados-y-reglas.md`; si no → `ESTADO_INVALIDO` con mensaje en español: "No se puede pasar de «{actual}» a «{nuevo}»."
4. Reglas por destino: `ingresado_hub` desde `incidencia` exige `intentos < max_intentos`; `asignado_ruta`/`en_transito` no se permiten sin parada correspondiente (la valida el llamador, M1).
5. `UPDATE paquetes SET estado = ?`, e `INSERT` en `historial_estados` (`estado_anterior`, `estado_nuevo`, `usuario_id`, `motivo`, `lat`, `lng`).
6. Si el nuevo estado es terminal: fijar `paquetes.token_expira_at = ahora + token_seguimiento_dias`.
7. Auditar `paquete_cambiar_estado` con `{desde, hacia, motivo}`.
8. **Después del commit** (fuera de la transacción): disparar las notificaciones según la tabla de `04-estados-y-reglas.md` sección 5 y, si el destino es `cancelado`, llamar `parada_cancelar_por_paquete`. Un fallo de estas llamadas se registra con `log_app` y no revierte el cambio.

### 7.2 Resolución de incidencias

- `reprogramar`: exige `intentos < max_intentos`. Guarda `fecha_programada`, y `ventana_inicio`/`ventana_fin` si se enviaron; la incidencia pasa a `reprogramada`; el paquete pasa `incidencia → ingresado_hub` (queda listo para volver a clasificarse y a una ruta).
- `devolver`: la incidencia pasa a `devuelta`; el paquete a `devuelto`.
- `cancelar`: incidencia `cancelada`; paquete `cancelado`.
- Si `intentos >= max_intentos`, solo `devolver` o `cancelar`; reprogramar → `CONFLICTO` "Se alcanzó el máximo de intentos".
- Resolver una incidencia que no está `abierta` → `ESTADO_INVALIDO`.
- Registra `resuelta_por` y `resuelta_at`; audita `incidencia_resolver`.

### 7.3 Generación de guía

`RP-AAMMDD-XXXXXX` (ver `04-estados-y-reglas.md`, sección 6). Se valida unicidad en la BD con reintento (hasta 5); si falla → `ERROR_INTERNO`.

### 7.4 Geocodificación y zona al crear

1. Si no vienen `lat`/`lng`: `geocodificar(direccion, ciudad)`. Si responde, se guardan y `geocodificado = 1`. Si no, el paquete se crea sin coordenadas y con la advertencia `sin_coordenadas`.
2. Con coordenadas: `zona_resolver(lat, lng, codigo_postal)`. Si no hay zona, `zona_id = NULL` y advertencia `sin_zona`.
3. Un paquete sin coordenadas no puede clasificarse sin advertencia ni entrar a una ruta; se corrige con `paquetes/actualizar.php`.

### 7.5 Reglas de edición

Un paquete en `asignado_ruta` o posterior no se edita (la ruta ya se planificó con esos datos). Para corregirlo hay que quitarlo de la ruta (M1) o cancelarlo.

## 8. Datos de prueba (seed)

12 paquetes en todos los estados: 1 `creado`, 2 `ingresado_hub`, 3 y 4 `en_clasificacion`, 5 `cancelado`, 6 `entregado`, 7/8/9 `en_transito`, 10 `incidencia` (con incidencia abierta `cliente_ausente`), 11 y 12 `asignado_ruta`. Cada uno con su historial y token (`/t/000…0NN`).

## 9. Criterios de aceptación

- [ ] Crear un paquete genera guía única, historial `creado`, token de seguimiento, zona y coordenadas (simuladas).
- [ ] `paquete_cambiar_estado` rechaza **todas** las transiciones que no están en la tabla (probar al menos: `creado→entregado`, `entregado→en_transito`, `cancelado→creado`) y acepta todas las permitidas.
- [ ] Cambiar al mismo estado no duplica historial.
- [ ] Pasar a `en_transito` genera una sola notificación `en_camino`; repetir la llamada no genera otra.
- [ ] Escanear una guía en `creado` con `ingreso_hub` la pasa a `ingresado_hub`; escanearla otra vez da `ESTADO_INVALIDO` claro.
- [ ] La etiqueta imprime QR y código de barras legibles con el `codigo_guia`, en una página de 100×150 mm.
- [ ] Resolver incidencia con `reprogramar` deja el paquete en `ingresado_hub` con `fecha_programada`; con `intentos >= max_intentos` rechaza reprogramar.
- [ ] Cancelar un paquete que está en una ruta saca su parada (verificar con M1).
- [ ] El historial muestra quién, cuándo y por qué de cada cambio; el servidor no permite editarlo.
- [ ] Buscar con `q` no es vulnerable a inyección SQL (probar `' OR 1=1 --`).
- [ ] Todas las acciones auditan (`paquete_crear`, `paquete_cambiar_estado`, `incidencia_resolver`).
