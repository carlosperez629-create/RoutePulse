# M4. App del conductor (PWA)

Responsable: **Guanda** (también hace M2). Apoyo: Juan (M1: rutas y paradas) y Carlos (M7: OTP por `notificar`). Servicios: `entrega_service.php`, `otp_service.php`, `archivo_service.php`. Carpetas: `public/api/conductor/`, `public/api/archivos/`, `public/conductor/` (incluye `manifest.json`, `sw.js`, `offline.html`).

## 1. Propósito y alcance

**Hace:** da al conductor su hoja de ruta digital en el teléfono; permite iniciar y finalizar la ruta; registrar llegada; solicitar el OTP; registrar la prueba de entrega (firma, foto geoetiquetada, OTP) o el fallo; funcionar **sin conexión** y sincronizar al recuperar señal; abrir la navegación en Waze, Google Maps o Apple Maps; enviar su ubicación (con `gps_tracker.js` de M2).

**No hace:** no ordena ni cambia rutas (M1), no calcula ETA (M2), no envía mensajes (llama `notificar` a través de `otp_generar_y_enviar`), no es una app nativa (es una web instalable, PWA).

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M1 `ruta_activa_de_conductor`, `ruta_iniciar`, `ruta_finalizar`, `parada_actualizar_estado`, `conductor_obtener_por_usuario` | Ruta, paradas y ciclo de vida |
| M3 `paquete_cambiar_estado`, `incidencia_crear` | Estado del paquete e incidencias |
| M2 `gps_tracker.js`, `gps/enviar.php` | Envío de ubicación |
| M7 `notificar` | Enviar el OTP |
| M8 `api_run`, `audit`, `validar` | Permisos, auditoría |

| Provee a | Qué |
|---|---|
| M6 | `entregas` (OTIF, FTDR) |
| M3/M1 | Estados y paradas actualizados (vía sus servicios) |
| Despacho/Gerencia | `archivos/ver.php` para ver las pruebas |

## 3. Pantallas (mobile first, 360 px)

| Página | Contenido |
|---|---|
| `conductor/index.php` | **Hoja de ruta**. Cabecera: ruta, vehículo, progreso (resueltas/total), indicador En línea / Sin conexión, número de operaciones pendientes de sincronizar. Botón grande "Iniciar ruta" (si está `planificada`). Lista de paradas **en orden**: número, destinatario, dirección, ventana horaria, ETA, monto a cobrar (si aplica), estado (colores de `09-guia-interfaz.md`). La parada `en_camino` aparece destacada arriba con botones: **Navegar** (Waze / Google Maps / Apple Maps), **Llamar**, **Llegué**. Botón "Finalizar ruta" cuando no quedan paradas abiertas |
| `conductor/parada.php?id=` | Detalle de la parada: datos del paquete (guía, peso, notas, referencia), botones de navegación, estado. Si está `llegado`: acciones **Entregar** y **No se pudo entregar**. Formulario de entrega: nombre de quien recibe (req), documento (opc), código OTP (con botón "Enviar código al cliente"), firma en pantalla (canvas), foto (cámara), check "Entrega completa" (por defecto sí), nota. Formulario de fallo: motivo (lista de tipos), nota, foto opcional |
| `conductor/offline.html` | Página que sirve el Service Worker cuando se abre sin conexión una URL no cacheada |

Elementos fijos: barra inferior con "Ruta" y "Estado" (GPS activo/inactivo, batería, última sincronización). Todos los botones ≥ 44 px.

## 4. Tablas propias

`entregas` y `archivos`, más las columnas `otp_*` de `paquetes` (las escribe solo M4, con `otp_service.php`).

## 5. Endpoints (rol C, siempre sobre la ruta/parada del conductor de la sesión)

### `GET conductor/mi_ruta.php`
Retorna la ruta del conductor (`ruta_activa_de_conductor`) o `null` si no tiene:
```json
{ "ruta": { "id": 1, "codigo": "R-261113-01", "estado": "en_curso", "fecha": "2026-11-13", "total": 5, "resueltas": 2 },
  "vehiculo": { "placa": "MOT-001", "tipo": "motocicleta" },
  "config": { "gps_intervalo_s": 10, "otp_obligatorio": true, "entrega_distancia_max_m": 300 },
  "paradas": [ { "id": 3, "orden": 3, "estado": "en_camino", "eta": "ISO", "lat": 4.635, "lng": -74.078,
                 "paquete": { "id": 7, "codigo_guia": "RP-261113-U3V4W5", "destinatario_nombre": "Gloria Sanz", "destinatario_telefono": "+58...",
                              "direccion_entrega": "Calle 7 #16-26, San Francisco Norte", "referencia": null, "peso_kg": 2.0,
                              "cobro_contra_entrega": 40.00, "moneda": "USD", "ventana_inicio": "ISO", "ventana_fin": "ISO", "notas": null },
                 "navegacion": { "waze": "https://waze.com/ul?ll=4.635,-74.078&navigate=yes",
                                 "google": "https://www.google.com/maps/dir/?api=1&destination=4.635,-74.078",
                                 "apple": "https://maps.apple.com/?daddr=4.635,-74.078&dirflg=d" } } ] }
```
El servidor construye los enlaces de navegación.

### `POST conductor/iniciar_ruta.php`
`{ "ruta_id": 1 }` → `ruta_iniciar`. Respuesta: la ruta actualizada. Errores: `ESTADO_INVALIDO` si no está `planificada`; `SIN_PERMISO` si no es suya.

### `POST conductor/llegada.php`
`{ "parada_id": 3, "lat": 4.6351, "lng": -74.0779, "ocurrido_at": "ISO" }`. La parada debe estar `en_camino` y la ruta `en_curso`. Marca la parada `llegado` con `llegada_real = ocurrido_at`. Idempotente si ya está `llegado`.

### `POST conductor/solicitar_otp.php`
`{ "parada_id": 3 }`. Llama `otp_generar_y_enviar`. Máximo `otp_max_por_hora` (3) solicitudes por paquete por hora (`LIMITE`). Respuesta `{ "canal": "sms", "expira_at": "ISO" }`; en `dev` con mensajería simulada incluye `otp_debug` con el código para pruebas. **Requiere conexión** (si no hay, la app informa y permite entregar sin OTP según 7.3).

### `POST conductor/entregar.php` (multipart)
Campos: `uuid` (req, UUID v4), `parada_id` (req), `receptor_nombre` (req, ≤100), `receptor_documento?` (≤30), `otp?` (solo dígitos), `completa?` (bool, por defecto `true`), `nota?` (≤500), `lat` y `lng` (req, del momento de la entrega), `precision_m?`, `ocurrido_at` (req, ISO), `fuera_de_linea?` (bool), `firma` (archivo) o `firma_base64` (data URI PNG) (req), `foto` (archivo JPEG/PNG/WebP, ≤5 MB) (req).

Proceso en `entrega_registrar` (todo el registro en `db_tx`, archivos guardados antes de abrir la transacción):
1. Si `uuid` ya existe en `entregas` → responder `200` con la entrega existente y `duplicado: true` (no se repite nada).
2. Verificar que la parada es de la ruta del conductor, que la ruta está `en_curso`, y que la parada está `llegado` o `en_camino` (si se envió sin `llegada`, se acepta y se fija `llegada_real = ocurrido_at`). Si la parada ya está `entregada`/`fallida`/`omitida` → `ESTADO_INVALIDO`.
3. OTP según 7.3.
4. Guardar firma y foto con `archivo_guardar_*` (la foto lleva `lat`/`lng` en `archivos`).
5. Si la distancia entre (`lat`,`lng`) y la parada supera `entrega_distancia_max_m` → se acepta y se devuelve `advertencias: ["lejos_de_la_parada"]` (también se audita).
6. `INSERT` en `entregas` con `resultado = 'entregado'`, `intento_num` = `paradas.intento_num`.
7. `parada_actualizar_estado(parada, 'entregada', ['ocurrido_at' => ...])` (esto marca la siguiente parada `en_camino`).
8. `paquete_cambiar_estado(paquete, 'entregado', usuario, motivo, lat, lng)`. Si la entrega llegó fuera de línea, el motivo incluye `ocurrido_at`.
9. Auditar `entrega_registrar`.
10. Fuera de la transacción: `eta_recalcular_ruta($rutaId, true)`.
11. Respuesta `201`: `{ entrega, siguiente_parada: {id}|null, ruta_completa: bool, advertencias: [] }`.

### `POST conductor/fallar.php` (multipart)
Campos: `uuid`, `parada_id`, `motivo_fallo` (req, enum de tipos de incidencia), `nota?`, `lat`, `lng`, `ocurrido_at`, `fuera_de_linea?`, `foto?` (obligatoria si `motivo_fallo = paquete_danado`). Proceso: idempotencia por `uuid`; guardar foto; insertar en `entregas` con `resultado = 'fallida'`; `parada_actualizar_estado(parada, 'fallida', ...)`; `incidencia_crear(paquete, parada, motivo, nota, fotoId, usuario, true)` (suma intento, pasa el paquete a `incidencia` y envía la notificación 3 de falla). Respuesta `201` con la misma forma que `entregar`.

### `POST conductor/finalizar_ruta.php`
`{ "ruta_id": 1 }`. Exige que no queden paradas `pendiente`, `en_camino` ni `llegado`; si quedan → `ESTADO_INVALIDO` con la lista. Llama `ruta_finalizar`. El cliente detiene `GpsTracker`.

### `GET archivos/ver.php?id=` (A, D, G, C)
Entrega el archivo con su `mime`. Control de acceso: A, D y G cualquiera; C solo archivos de entregas suyas. `Cache-Control: private, max-age=300`. Nunca expone la ruta del disco.

## 6. Servicios que expone

Firmas en `07-servicios-internos.md`, sección F.

## 7. Reglas de negocio

### 7.1 Archivos (`archivo_service.php`)

- Se guardan en `storage/uploads/AAAA/MM/<sha256_primeros_16>_<aleatorio8>.<ext>`; el nombre original del usuario no se usa.
- Se verifica el tipo real con `finfo` (`image/png`, `image/jpeg`, `image/webp`) y el tamaño ≤ 5 MB. La firma puede venir como data URI `data:image/png;base64,...`.
- Se guarda `sha256` del contenido en `archivos.sha256`. Si la foto es JPEG con coordenadas EXIF distintas, se ignoran: manda la ubicación enviada por la app.
- Opcional: reducir fotos mayores a 1600 px por lado con GD.

### 7.2 OTP

El OTP vigente de cada paquete vive en las columnas `otp_*` de `paquetes` (un solo OTP vigente por paquete; no se guarda historial de códigos anteriores).

- Código de `otp_longitud` dígitos (6) con `random_int`. En BD solo `otp_hash = hash_hmac('sha256', codigo, app.clave_secreta)` y `otp_expira_at = ahora + otp_expira_min` (30 min).
- Al generar uno nuevo se **sobrescribe** el anterior (queda inválido) y se reinicia `otp_intentos = 0`.
- Límite de solicitudes: `otp_ventana_at` marca el inicio de una ventana de 1 hora y `otp_solicitudes` cuenta las solicitudes dentro de ella. Si la ventana está vacía o venció, se reinicia (`otp_ventana_at = ahora`, `otp_solicitudes = 1`); si no, y `otp_solicitudes >= otp_max_por_hora` (3), se responde `LIMITE`; si no, se suma 1.
- `otp_validar`: si no hay `otp_hash` o `otp_expira_at` venció → `false`. Compara con `hash_equals`; si coincide, se limpia `otp_hash` (queda **usado**) y retorna `true`. Si no coincide, `otp_intentos + 1`; al llegar a `otp_max_intentos` (3) se limpia `otp_hash` (código bloqueado) y hay que solicitar otro.
- El mensaje usa la plantilla `otp` de M7.

### 7.3 Cuándo se exige el OTP

| Situación | Regla |
|---|---|
| `otp_obligatorio = 1` y la entrega **no** es fuera de línea | `otp` requerido y válido; si falta o es incorrecto → `OTP_INVALIDO` (422) y **no** se registra la entrega |
| `fuera_de_linea = true` | El OTP es opcional (el teléfono no puede validarlo). Si el conductor lo digitó y hay conexión al sincronizar, se valida; si no valida, se registra igual con `otp_validado = 0` |
| `otp_obligatorio = 0` | Opcional siempre |

`entregas.otp_validado` refleja si se validó. Las entregas con `otp_validado = 0` y `fuera_de_linea = 1` aparecen marcadas en el panel del despachador (columna "Sin OTP") para auditoría.

### 7.4 Permisos y propiedad

Todos los endpoints usan `auth_conductor_id()`; el `conductor_id` **nunca** viene del cliente. Una parada o ruta ajena → `SIN_PERMISO`.

### 7.5 Cobro contra entrega

Si `cobro_contra_entrega > 0`, la pantalla de entrega muestra el monto y exige marcar "Cobro realizado"; si no se cobró, el conductor debe usar "No se pudo entregar" con motivo `cobro_no_realizado`. (El monto cobrado no se registra por separado en esta versión.)

## 8. Modo offline (PWA)

### 8.1 Archivos
- `manifest.json`: `name: "RoutePulse Conductor"`, `short_name: "RoutePulse"`, `display: "standalone"`, `start_url: "/conductor/"`, íconos 192 y 512 px, `theme_color`.
- `sw.js` (Service Worker): precarga el *shell* (HTML, CSS, JS, vendor de Bootstrap) con estrategia *cache first*; `GET conductor/mi_ruta.php` con *network first* y caída al último resultado cacheado; `offline.html` como respaldo.

### 8.2 Cola de operaciones (IndexedDB `routepulse_cola`)
Almacén `operaciones`: `{ uuid, endpoint, campos, firma_base64, foto_blob, creado_at, estado: 'pendiente'|'enviando'|'error', intentos, ultimo_error }`.
- Al registrar una entrega/fallo/llegada, la app **primero** lo guarda en la cola y luego intenta enviarlo. Así el flujo es el mismo con o sin conexión.
- Sincronización: al evento `online`, al abrir la app y cada 30 s mientras haya pendientes. Se envían **en orden FIFO** (llegada → entrega → siguiente...), una a la vez, reutilizando el `uuid` (idempotencia).
- Resultado: `2xx` → se borra de la cola; `4xx` (excepto 429) → se marca `error` con el mensaje del servidor y se muestra al conductor (no se reintenta solo); `5xx`, red o `429` → se reintenta con espera creciente (5, 15, 30, 60 s).
- La interfaz muestra el número de pendientes y permite ver/reintentar los que están en `error`.
- La app guarda en IndexedDB también la última `mi_ruta` para poder trabajar sin conexión desde el arranque.

### 8.3 Orden y estados sin conexión
Sin conexión, la app avanza las paradas localmente (marca `en_camino` la siguiente) usando el `orden` descargado. Al sincronizar, el servidor es la fuente de verdad; si el despachador reordenó mientras tanto, se muestra un aviso y se recarga la ruta.

### 8.4 Límites del navegador
- El rastreo GPS solo funciona con la app abierta en primer plano (Wake Lock ayuda a mantener la pantalla encendida).
- No se depende de Background Sync (no es fiable en iOS): se sincroniza con la app abierta.

## 9. Datos de prueba (seed)

Conductor 1 (`conductor1@routepulse.test`) tiene la ruta 1 `en_curso` con una parada `entregada`, una `fallida`, una `en_camino` (paquete 7, con cobro de 40.00), y dos `pendiente`. Conductor 2 tiene la ruta 2 `planificada` para probar "Iniciar ruta". Los archivos de firma/foto del seed son marcadores: crear imágenes de ejemplo en `storage/uploads/seed/` para ver pruebas de entrega.

## 10. Criterios de aceptación

- [ ] El conductor inicia sesión en el teléfono y ve **solo** su ruta; pedir otra ruta o parada da `SIN_PERMISO`.
- [ ] "Iniciar ruta" cambia la ruta a `en_curso`, los paquetes a `en_transito` y dispara las notificaciones `en_camino`.
- [ ] Los botones Waze, Google Maps y Apple Maps abren la app o el sitio con las coordenadas correctas.
- [ ] Solicitar OTP lo envía (simulado) y el cuarto intento de la hora responde `LIMITE`.
- [ ] Entregar sin firma, sin foto o sin coordenadas es rechazado con `VALIDACION`.
- [ ] Con conexión y `otp_obligatorio = 1`, un OTP incorrecto da `OTP_INVALIDO` y no registra la entrega; al tercer error el código se bloquea.
- [ ] Una entrega correcta deja: fila en `entregas`, archivos guardados, parada `entregada`, paquete `entregado`, siguiente parada `en_camino`, notificación `entregado` y auditoría.
- [ ] Reenviar la misma entrega (mismo `uuid`) responde `duplicado: true` y no crea registros nuevos.
- [ ] Una entrega fallida crea incidencia, suma un intento, pasa el paquete a `incidencia` y envía la notificación de falla.
- [ ] **Sin conexión** (modo avión): se pueden registrar llegada y entrega; aparecen como pendientes; al volver la señal se sincronizan en orden y sin duplicados.
- [ ] Una operación rechazada por el servidor (`4xx`) queda visible como error y no bloquea las siguientes.
- [ ] No se puede finalizar la ruta con paradas abiertas.
- [ ] La app se puede "Instalar" (agregar a pantalla de inicio) y abre sin conexión mostrando la última ruta.
- [ ] `archivos/ver.php` niega a un conductor el archivo de una entrega ajena.

## 11. Límites conocidos

- Es una web móvil, no una app nativa: sin rastreo en segundo plano, sin notificaciones push.
- La validación del OTP necesita conexión; sin conexión se registra la entrega marcada como "sin OTP validado".
- La cámara usa `<input type="file" accept="image/*" capture="environment">`; el control de calidad de la foto lo hace el conductor.
