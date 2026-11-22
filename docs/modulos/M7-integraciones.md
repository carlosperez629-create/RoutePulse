# M7. Integraciones (APIs y webhooks) y notificaciones

Responsable: **Carlos** (también hace el núcleo y M8). Servicios: `notificacion_service.php`, `integracion_service.php`, `geo_proveedores_service.php`. Carpetas: `public/webhooks/`, `public/api/{integraciones,notificaciones}/`, `public/admin/{integraciones,notificaciones,webhooks,log_api}.php`, `cron/procesar_notificaciones.php`, `cron/reintentar_webhooks.php`, `app/lib/phpmailer/`.

## 1. Propósito y alcance

**Hace:**
1. Recibe pedidos de plataformas e-commerce (Shopify, WooCommerce, Magento, Mercado Libre) por **webhook** y de ERP/WMS por **API de entrada**, y los convierte en paquetes.
2. Envía notificaciones al cliente por WhatsApp, SMS y correo mediante una **cola** con reintentos.
3. Ofrece a los demás módulos los servicios de **geocodificación** y de **distancias/trazado de rutas**.
4. Administra la configuración de cada integración y registra todas las llamadas.

**No hace:** no decide cuándo notificar (lo deciden M3, M2 y M4 llamando a `notificar`), no genera rutas (M1), no crea paquetes por sí mismo salvo mediante `paquete_crear` de M3.

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M3 `paquete_crear`, `paquete_cambiar_estado`, `paquete_obtener` | Crear y cancelar paquetes desde pedidos externos; datos para los mensajes |
| M5 `tracking_url` | Variable `{link}` de las plantillas |
| M8 `api_run`, `audit`, `rate_limit_check` | Permisos y límites |

| Provee a | Qué |
|---|---|
| M3, M2, M4 | `notificar` |
| M3 | `geocodificar` |
| M1, M2 | `ruta_matriz`, `ruta_geometria` |

**Prioridad:** M7 debe entregar el **modo simulado** de `notificar`, `geocodificar`, `ruta_matriz` y `ruta_geometria` en la **primera semana**, porque los otros módulos dependen de ellos para trabajar. Los proveedores reales se hacen después.

## 3. Pantallas (rol A salvo indicación)

| Página | Contenido |
|---|---|
| `admin/integraciones.php` | Tarjeta por integración: nombre, tipo, activa (interruptor), modo simulado (interruptor), estado de la última llamada. Acciones: Editar ajustes (JSON no secreto), **Probar conexión**, **Generar API key** (solo la de ERP/WMS; se muestra una única vez), ver URL del webhook (`https://.../webhooks/<codigo>.php`) y el secreto configurado (solo si existe, sin mostrarlo) |
| `admin/notificaciones.php` (A, D) | Tabla de la cola e historial: fecha, paquete (enlace), tipo, canal, destino (enmascarado: `+58•••••1234`), estado, intentos, error. Filtros por estado, tipo y fechas. Botón **Reenviar** en las fallidas |
| `admin/webhooks.php` | Eventos recibidos: fecha, integración, evento externo, estado, paquete creado, error. Botón **Reprocesar** en los de estado `error` |
| `admin/log_api.php` | Últimas llamadas entrantes y salientes, leídas de `storage/logs/integraciones.log`, con duración y código HTTP |

## 4. Tablas propias

`integraciones`, `webhook_eventos`, `plantillas_notificacion`, `notificaciones`. Las llamadas a proveedores se registran en el archivo `storage/logs/integraciones.log` (una línea JSON por llamada, con `integracion_log()`), y la caché de geocodificación vive en archivos de `storage/cache/geocode/`; no hay tablas para eso.

## 5. Formato normalizado de pedido (`PedidoNormalizado`)

Todo origen externo se convierte a esta forma antes de llamar a `paquete_crear`:

```json
{ "origen_externo_id": "1234567890",
  "remitente_nombre": "Tienda Demo", "remitente_telefono": "+584120009999",
  "destinatario_nombre": "Ana Torres", "destinatario_telefono": "+584121112233", "destinatario_email": "ana@correo.test",
  "direccion_entrega": "Calle 1 #10-20", "referencia": "Apto 301", "ciudad": "San Francisco", "codigo_postal": "4001",
  "lat": null, "lng": null,
  "peso_kg": 1.2, "volumen_m3": 0.02,
  "cobro_contra_entrega": 0, "moneda": "USD",
  "ventana_inicio": null, "ventana_fin": null, "prioridad": "normal", "notas": "" }
```
Obligatorios: `origen_externo_id`, `destinatario_nombre`, `destinatario_telefono`, `direccion_entrega`. El resto toma los valores por defecto de `paquete_crear`.

### Mapeo por proveedor (entregado en esta versión: Shopify, WooCommerce y genérico)

| Campo normalizado | Shopify (`orders/create`) | WooCommerce (`order.created`) |
|---|---|---|
| `origen_externo_id` | `id` | `id` |
| `destinatario_nombre` | `shipping_address.name` | `shipping.first_name` + ` ` + `shipping.last_name` |
| `destinatario_telefono` | `shipping_address.phone` o `customer.phone` o `phone` | `shipping.phone` o `billing.phone` |
| `destinatario_email` | `email` | `billing.email` |
| `direccion_entrega` | `shipping_address.address1` + `address2` | `shipping.address_1` + `address_2` |
| `ciudad`, `codigo_postal` | `shipping_address.city`, `zip` | `shipping.city`, `postcode` |
| `lat`, `lng` | `shipping_address.latitude`, `longitude` si vienen | no vienen |
| `peso_kg` | `total_weight` (gramos) ÷ 1000, mínimo 0.1 | suma de `line_items[].weight` × cantidad, o 0.5 |
| `cobro_contra_entrega` | `total_price` si `financial_status = pending` y hay pasarela `cash_on_delivery`; si no, 0 | `total` si `payment_method = cod`; si no, 0 |
| `moneda` | `currency` | `currency` |
| Cancelación | topic `orders/cancelled` | `status = cancelled` en `order.updated` |

Magento y Mercado Libre quedan como mapeadores adicionales (**fase 2**): llegan al webhook, se registran en `webhook_eventos` con estado `recibido` y se procesan como "genérico" si el cuerpo ya es un `PedidoNormalizado`. El código genérico acepta directamente un `PedidoNormalizado` en el cuerpo.

## 6. Endpoints

### `POST /webhooks/{codigo}.php` (público, firmado)
`codigo` ∈ `shopify`, `woocommerce`, `magento`, `mercadolibre`.
1. Buscar la integración por `codigo`; si no existe o `activo = 0` → `404`.
2. **Firma**: Shopify → cabecera `X-Shopify-Hmac-Sha256` = base64(HMAC-SHA256(cuerpo crudo, secreto)); WooCommerce → `X-WC-Webhook-Signature` igual; Magento/ML → cabecera `X-Signature` = hex(HMAC-SHA256). El secreto sale de `app_cfg('webhook_secretos.<codigo>')`. Comparar con `hash_equals`. Firma inválida → `401`. Se omite la verificación **solo** si `app.entorno = dev` e `integraciones.modo_simulado = 1`.
3. **Idempotencia**: `evento_externo_id` = `<topic>:<id del pedido>` (Shopify: también `X-Shopify-Webhook-Id`). Insertar en `webhook_eventos`; si la clave `(integracion_id, evento_externo_id)` ya existe, responder `200` con `{"duplicado": true}` y no procesar.
4. Procesar: convertir a `PedidoNormalizado` y llamar `integracion_paquete_desde_pedido`. Éxito → evento `procesado` con `paquete_id`. Error de validación o de negocio → evento `error` con el mensaje (se reintenta por cron).
5. Para cancelaciones: buscar el paquete por `(integracion_id, origen_externo_id)`; si está en `creado`..`asignado_ruta` → `paquete_cambiar_estado('cancelado', null, 'Cancelado por la tienda')`; si ya salió (`en_transito` o más) → no se cancela y el evento queda `error` con "El paquete ya está en tránsito" (lo ve el despachador).
6. Responder siempre rápido (< 5 s): `200 {"ok": true}` si el evento quedó almacenado (aunque el procesamiento falle, para que el proveedor no reintente en bucle).
7. Registrar la llamada con `integracion_log()` (sin datos personales completos: solo tipo de evento e id).

### `POST integraciones/paquetes_entrada.php` (ERP/WMS, API key)
Cabecera `X-API-Key: <clave>` (se compara `sha256(clave)` con `integraciones.api_key_hash` de la integración `erp_generico` activa). Cuerpo: `{ "paquetes": [ PedidoNormalizado, ... ] }` (máximo 100). Cada elemento se procesa de forma independiente (idempotente por `origen_externo_id`). Respuesta `200`:
```json
{ "ok": true, "data": { "resultados": [ { "origen_externo_id": "A1", "ok": true, "paquete_id": 31, "codigo_guia": "RP-...", "duplicado": false },
                                         { "origen_externo_id": "A2", "ok": false, "error": "destinatario_telefono: Teléfono inválido" } ] } }
```
Clave inválida → `401`. Rate limit 60 llamadas por minuto por clave.

### Administración (rol A)
- `GET integraciones/listar.php`: integraciones con estado y último resultado (sin secretos; `api_key_hash` no se devuelve).
- `POST integraciones/actualizar.php`: `integracion_id`, `activo?`, `modo_simulado?`, `config_json?` (objeto, sin secretos), `regenerar_api_key?` (bool; devuelve la clave en claro **una sola vez** en `api_key`). Audita `integracion_actualizar`.
- `POST integraciones/probar.php`: `integracion_id`. Hace una llamada de prueba mínima (mensajería: envío a un destino de prueba configurado en `config_json.destino_prueba`; mapas: geocodifica una dirección fija; e-commerce: valida que exista el secreto). Retorna `{ok, detalle, duracion_ms}`.
- `GET integraciones/log.php`: devuelve las últimas `limite` líneas (máximo 500, por defecto 100) de `integraciones.log`, más recientes primero; filtros `integracion` (código), `direccion`, `exito`.
- `GET integraciones/webhooks.php`: filtros `integracion_id`, `estado`.
- `POST integraciones/reprocesar_webhook.php`: `evento_id`; solo estado `error`.
- `GET notificaciones/listar.php` (A, D): filtros `estado`, `tipo`, `canal`, `paquete_id`, `desde`, `hasta`.
- `POST notificaciones/reenviar.php` (A, D): `notificacion_id`; solo `fallido`; la vuelve a `pendiente` con `intentos = 0`.

## 7. Notificaciones: `notificar()` y la cola

### 7.1 `notificar(paqueteId, tipo, vars, claveDedup)`

1. Si `claveDedup` no es `null` y ya existe en `notificaciones.clave_dedup` → retornar `0` (sin error).
2. Cargar el paquete; si no existe → registrar y retornar `0`.
3. **Elegir canal**: recorrer `canales_orden` (por defecto `whatsapp`, `sms`, `email`) y tomar el primero **disponible**: la integración de ese canal está `activo`, y hay destino (`destinatario_telefono` para WhatsApp/SMS; `destinatario_email` para correo) y existe plantilla activa para (`tipo`, `canal`). Si ninguno → registrar `warning` y retornar `0`.
4. **Construir el mensaje** con la plantilla, reemplazando `{nombre}` (nombre de pila), `{guia}`, `{link}` (`tracking_url` del token del paquete), `{eta_min}`, `{otp}`, `{motivo}` (texto en español del tipo de incidencia más reciente), `{fecha}`. Una variable sin valor se reemplaza por cadena vacía y se registra un `warning`.
5. **Modo simulado** (`integraciones.modo_simulado = 1` del canal elegido): insertar con `estado = 'enviado'`, `proveedor_mensaje_id = 'SIM-' + uuid`, `enviado_at = ahora`, y escribir una línea en `storage/logs/notificaciones_simuladas.log` (`fecha | canal | destino | mensaje`). No hay que ejecutar el cron.
6. **Modo real**: insertar `estado = 'pendiente'`, `proximo_intento_at = NULL`. El cron lo envía.
7. Retornar `1`. **Nunca lanzar excepciones** al llamador: capturar todo, registrar con `log_app` y retornar `0`.

### 7.2 Cola (`cron/procesar_notificaciones.php`, cada minuto)

1. Tomar hasta 50 notificaciones `pendiente` con `proximo_intento_at` nulo o vencido, en orden de `created_at`. Marcar `proximo_intento_at = ahora + 2 min` para evitar que dos ejecuciones se pisen.
2. Enviar con el proveedor del canal (7.3). Éxito → `enviado`, `enviado_at`, `proveedor_mensaje_id`.
3. Fallo temporal (red, 5xx, 429): `intentos + 1`; si `intentos < notif_max_reintentos` (3), `proximo_intento_at = ahora + notif_backoff_min[intentos-1]` minutos (1, 5, 15). Si se agotan → `fallido` con el error.
4. Fallo permanente (4xx de validación, número inválido): `fallido` de inmediato.
5. **Respaldo por canal**: al quedar `fallido` una notificación de tipo distinto de `otp`, se encola una nueva con el **siguiente canal** disponible de `canales_orden` (`clave_dedup` = clave original + `:fb` + número de canal). Para `otp` el respaldo también aplica (es urgente).
6. Cada intento se registra con `integracion_log()` (dirección `saliente`).

### 7.3 Proveedores (modo real)

- **Twilio (SMS y WhatsApp)**: `POST https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json`, autenticación básica (`sid`:`token`), campos `To`, `From`, `Body`. WhatsApp: `To = 'whatsapp:+58...'`, `From = 'whatsapp:' + twilio.from_whatsapp`. Con cURL, tiempo máximo 10 s.
- **Meta WhatsApp Business API** (opcional, desactivada por defecto): `POST https://graph.facebook.com/v{version}/{phone_id}/messages` con plantillas aprobadas por Meta para mensajes iniciados por la empresa (requiere aprobación previa; fase 2).
- **Correo**: PHPMailer 6.9 por SMTP (`smtp.*` de `config.php`), cuerpo en texto y HTML simple, asunto de la plantilla.
- Nunca se registran tokens, claves ni el contenido completo del mensaje en `integraciones.log`.

### 7.4 Plantillas

15 filas en `plantillas_notificacion` (5 tipos × 3 canales), cargadas por el seed. Se editan desde la BD en esta versión (pantalla de edición = mejora opcional). Variables permitidas: `{nombre} {guia} {eta_min} {link} {otp} {motivo} {fecha}`. Toda plantilla de tipo distinto de `otp` debe contener `{link}`.

## 8. Servicios de mapas

Proveedor según `configuracion.mapas_proveedor`: `osrm` (Nominatim para geocodificar + OSRM para rutas, gratuitos) o `google` (Geocoding + Routes). Con la integración correspondiente en `modo_simulado = 1` se usa el comportamiento simulado.

### 8.1 `geocodificar(direccion, ciudad)`
1. Normalizar (minúsculas, sin dobles espacios, `direccion + ', ' + ciudad`); `sha256` → buscar el archivo `storage/cache/geocode/<sha256>.json`. Si existe → devolver.
2. Simulado: lat/lng deterministas = HUB 1 ± 0.03° calculado a partir del hash de la dirección; `confianza = 0.5`, `proveedor = 'simulado'`.
3. Real: Nominatim (`/search?format=jsonv2&limit=1&q=...`, con `User-Agent` que incluya `nominatim.contacto`, **máximo 1 petición por segundo**) o Google Geocoding. Guardar el resultado en `storage/cache/geocode/<sha256>.json`. Sin resultado → `null`.

### 8.2 `ruta_matriz(puntos)`
Máximo 100 puntos. OSRM: `GET {base}/table/v1/driving/{lng,lat;...}?annotations=duration,distance`. Google: Routes `computeRouteMatrix`. Simulado o fallo del proveedor: distancia = haversine × 1.3; duración = distancia ÷ `eta_velocidad_media_kmh`. Siempre retorna una matriz completa (el fallo no es visible para el llamador, solo `proveedor = 'respaldo'`).

### 8.3 `ruta_geometria(puntosOrdenados)`
OSRM `route/v1/driving/...?overview=full&geometries=geojson` (o Google Routes). Retorna la lista de `[lat, lng]`. Simulado o fallo → `null` (M1 usa la línea recta entre paradas).

## 9. Datos de prueba (seed)

10 integraciones (activas: shopify, woocommerce, erp_generico, twilio, smtp, osrm; todas en modo simulado), 15 plantillas, y los parámetros de `configuracion` de M7. Para probar webhooks sin Shopify real: enviar con `curl` un JSON de ejemplo a `/webhooks/shopify.php` (en `dev` no exige firma); repetirlo para comprobar la idempotencia.

## 10. Criterios de aceptación

- [ ] `notificar` en modo simulado crea una fila `enviado` y una línea en `notificaciones_simuladas.log`; llamarla otra vez con la misma `claveDedup` no crea nada.
- [ ] Una plantilla se rellena con las variables correctas y el `{link}` abre el portal del paquete (M5).
- [ ] Si el canal preferido no está activo o falta el destino, se usa el siguiente de `canales_orden`.
- [ ] `notificar` nunca propaga una excepción (probar con un paquete inexistente).
- [ ] Un webhook de Shopify de ejemplo crea un paquete `creado` con guía, zona y coordenadas simuladas; el mismo evento enviado dos veces **no** crea un segundo paquete.
- [ ] Con `app.entorno = prod`, un webhook con firma inválida responde `401` y no crea nada.
- [ ] El webhook de cancelación cancela un paquete `creado` y rechaza (evento `error`) uno `en_transito`.
- [ ] `paquetes_entrada.php` con una API key válida procesa un lote con un elemento válido y uno inválido, devolviendo el resultado de cada uno; sin la clave responde `401`.
- [ ] La API key se muestra una sola vez al generarla y no se puede volver a ver.
- [ ] El cron reintenta una notificación fallida con la espera 1 → 5 → 15 min y luego la pasa al canal de respaldo.
- [ ] `geocodificar` simulado devuelve siempre las mismas coordenadas para la misma dirección y deja el archivo de caché en `storage/cache/geocode/`.
- [ ] `ruta_matriz` devuelve una matriz cuadrada con diagonal 0, también cuando el proveedor real falla.
- [ ] Ninguna clave, token o secreto aparece en `integraciones.log`, en las respuestas de la API ni en el repositorio.
- [ ] La pantalla de integraciones permite activar/desactivar y cambiar a modo real solo al administrador.
