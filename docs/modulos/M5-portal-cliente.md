# M5. Portal del cliente final (tracking)

Responsable: **Karoline**, con acompañamiento de Guanda (mapa y GPS) y Juan (datos de paquetes). Guía paso a paso en `GUIA-KAROLINE.md`. Servicio: `tracking_service.php`. Carpetas: `public/t.php`, `public/api/tracking/`, `public/assets/js/m5/`.

## 1. Propósito y alcance

**Hace:** da al destinatario un enlace único (sin registro ni login) para ver el estado de su paquete, su historial, la hora estimada y, cuando está cerca, la posición del vehículo en un mapa; permite reprogramar una entrega fallida; genera los tokens de seguimiento; define y valida el contenido de los tres avisos al cliente.

**No hace:** no envía mensajes (los envía M7 con `notificar`), no revela datos sensibles, no permite iniciar sesión ni editar datos del paquete.

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M3 `paquete_obtener`, `incidencia_resolver` | Datos del paquete y reprogramación |
| M2 `gps_ultima_posicion`, `eta_de_parada` | Posición del vehículo y ETA |
| M1 `ruta_obtener` / lectura de `paradas` | Cuántas paradas faltan |
| M7 `plantillas_notificacion` (lectura/contenido) | Verificar que cada mensaje incluya el enlace |
| M8 `rate_limit_check` | Límite de consultas por IP |

| Provee a | Qué |
|---|---|
| M3 | `token_seguimiento_generar` |
| M7 | `tracking_url` (variable `{link}` de las plantillas) |

## 3. Pantalla pública: `t.php` (URL `/t/{token}`)

*Mobile first.* Sin menú de usuario, sin enlaces al resto del sistema. Contenido:

1. **Cabecera**: nombre de la empresa/logo, "Seguimiento de tu paquete", guía `codigo_guia`.
2. **Estado actual** grande, con el color y el texto de `04-estados-y-reglas.md` (por ejemplo "En tránsito").
3. **Mensaje contextual** según estado:
   - `creado` / `ingresado_hub` / `en_clasificacion` / `asignado_ruta`: "Estamos preparando tu paquete."
   - `en_transito`: "Tu paquete va en camino." + ETA ("Llega aproximadamente a las 3:40 p. m.") si existe.
   - `entregado`: "Entregado el {fecha}. Recibió: {nombre}."
   - `incidencia`: "No pudimos entregar tu paquete ({motivo en español})." + botón **Reprogramar entrega** (si quedan intentos) o "Comunícate con la empresa" (si no).
   - `cancelado` / `devuelto`: "Este envío fue cancelado" / "Este envío fue devuelto al remitente."
4. **Mapa en vivo del "último tramo"** (Leaflet): visible solo cuando el estado es `en_transito` y quedan, a lo sumo, `portal_paradas_en_vivo` (3) paradas sin resolver **antes** de la suya. Muestra el vehículo, el destino (punto aproximado, ver 6) y "Tu repartidor está a N paradas" (0 = "es el siguiente"). Si no hay posición reciente, "Ubicación no disponible por el momento".
5. **Línea de tiempo** con los hitos públicos del historial (estado y fecha/hora).
6. **Formulario de reprogramación** (modal), solo en `incidencia`.
7. Pie con aviso de privacidad breve.

Actualización: `setInterval` cada `portal_refresh_s` (10 s) mientras el estado sea `en_transito` o `incidencia`; cada 60 s si la pestaña está oculta (`visibilitychange`); se detiene en estados terminales.

## 4. Tablas propias

Ninguna propia. Lee `paquetes.token_seguimiento` y `paquetes.token_expira_at` (solo lectura; los escribe M3).

## 5. Endpoints (públicos, protegidos por token y rate limit)

Cabeceras de todas las respuestas del portal: `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`.

### `GET tracking/estado.php?t={token}`
Respuesta (solo estos campos; **ningún otro**):
```json
{ "codigo_guia": "RP-261113-U3V4W5",
  "estado": "en_transito",
  "estado_texto": "En tránsito",
  "destinatario": "Gloria S.",
  "direccion_resumen": "Calle 7 #16-2…",
  "eta": "2026-11-13T15:40:00Z",
  "paradas_antes": 0,
  "mostrar_mapa": true,
  "vehiculo": { "lat": 4.633, "lng": -74.08, "antiguedad_s": 20 },
  "destino": { "lat": 4.635, "lng": -74.078 },
  "historial": [ { "estado": "creado", "estado_texto": "Creado", "fecha": "ISO" } ],
  "puede_reprogramar": false,
  "incidencia": { "motivo_texto": "Cliente ausente" },
  "entrega": { "fecha": "ISO", "recibio": "Felipe R." },
  "intervalo_refresco_s": 10 }
```
Reglas del contenido:
- `destinatario`: nombre de pila + inicial del primer apellido. `direccion_resumen`: primeros 15 caracteres de la dirección + "…". Nunca se devuelven teléfonos, correos, coordenadas exactas del domicilio, nombres de conductores, ni placas.
- `vehiculo` y `destino`: solo si `mostrar_mapa`. El `destino` se redondea a 3 decimales (~110 m) para no revelar el domicilio exacto.
- `paradas_antes`: número de paradas de la misma ruta con `orden` menor a la del paquete y estado `pendiente`/`en_camino`/`llegado`.
- `mostrar_mapa = (estado == en_transito) AND (paradas_antes <= portal_paradas_en_vivo) AND hay posición con antigüedad < gps_sin_senal_s`.
- `historial`: solo `estado`, `estado_texto` y `fecha`; sin usuarios, motivos internos ni coordenadas.
- `incidencia` solo en estado `incidencia`; `entrega` solo en `entregado`.
- `puede_reprogramar = (estado == incidencia) AND (intentos < max_intentos)`.

Errores: token con formato inválido, inexistente o vencido → `404 NO_ENCONTRADO` con el mismo mensaje ("Este enlace de seguimiento no es válido o ya venció."), para no revelar si el token existió.

### `POST tracking/reprogramar.php`
`{ "t": "{token}", "fecha": "2026-11-15", "franja": "manana|tarde|cualquiera", "nota": "..." }`
- Requiere estado `incidencia` y `intentos < max_intentos`; si no → `ESTADO_INVALIDO`.
- `fecha`: desde mañana hasta `reprogramar_dias_max` (7) días adelante (en `zona_horaria_ui`).
- `franja`: mañana = 08:00–12:00, tarde = 13:00–18:00, cualquiera = 08:00–18:00 (hora local, convertida a UTC para `ventana_inicio`/`ventana_fin`).
- Llama `tracking_reprogramar` → `incidencia_resolver($incidenciaAbierta, 'reprogramar', $fecha, null, ['inicio'=>..,'fin'=>..])` (el `usuario_id` es `null`: acción del cliente).
- Respuesta: `{ "estado": "ingresado_hub", "fecha_programada": "2026-11-15" }`. Es una operación única: tras reprogramar, el botón desaparece.
- Rate limit propio: 5 reprogramaciones por hora por IP.

## 6. Reglas de negocio

### 6.1 Token

- 32 caracteres hexadecimales (`bin2hex(random_bytes(16))`), uno por paquete, guardado en `paquetes.token_seguimiento` (índice único). `token_seguimiento_generar()` solo **produce** un token nuevo; M3 lo guarda al crear el paquete.
- `token_expira_at = NULL` mientras el paquete está activo; M3 lo fija a "ahora + `token_seguimiento_dias`" al llegar a un estado terminal.
- El enlace completo (`tracking_url`) es `app.url/t/{token}`.

### 6.2 Seguridad

- Rate limit: `rate_limit_check("track:ip:{ip}", rate_tracking_por_min, 60)` en cada llamada → `LIMITE` 429.
- Los endpoints públicos **no aceptan** `paquete_id` ni `codigo_guia`: solo el token.
- Salida siempre escapada; el mapa carga solo teselas y los datos de `estado.php`.
- Sin cookies ni sesión; sin CSRF (la protección es el token + rate limit).

### 6.3 Contenido de los tres avisos (plantillas de M7)

M5 valida que las plantillas incluyan la variable `{link}` (salvo `otp`). Textos del seed:
- **Disparo 1** (`en_camino`): "Hola {nombre}, tu paquete {guia} ya va en camino. Síguelo en vivo aquí: {link}".
- **Disparo 2** (`proximidad`): "Hola {nombre}, tu repartidor llegará en aproximadamente {eta_min} minutos con tu paquete {guia}. Seguimiento: {link}".
- **Disparo 3** (`entregado` o `fallida`): comprobante de entrega, o aviso de falla con el motivo y el enlace para reprogramar.

### 6.4 Zona horaria y textos

Fechas en `zona_horaria_ui`, formato "13 nov, 3:40 p. m.". Los textos de estado son los de `04-estados-y-reglas.md`. Los motivos de incidencia en español (tabla de la sección 4 de ese documento).

## 7. Datos de prueba (seed)

Tokens de prueba: `/t/000…001` a `/t/000…012` (el número final = id del paquete, rellenando con ceros hasta 32 caracteres hexadecimales). Casos útiles:
- Paquete 7 (`…0007`): `en_transito`, es la parada `en_camino` de la ruta 1 → debe mostrar el mapa.
- Paquete 9 (`…0009`): `en_transito` con 2 paradas antes (la 7 y la 8) → debe mostrar el mapa.
- Paquete 6 (`…0006`): `entregado`.
- Paquete 10 (`…0010`): `incidencia` con intento 1 de 3 → muestra el botón de reprogramar.
- Paquete 3 (`…0003`): `en_clasificacion` → mensaje "Estamos preparando tu paquete".
- Paquete 5 (`…0005`): `cancelado`.

## 8. Criterios de aceptación

- [ ] Un token válido muestra el estado correcto para cada paquete del seed; un token inventado o de formato inválido muestra la pantalla de enlace no válido (404).
- [ ] No aparece ningún dato sensible en la respuesta de `estado.php` (revisar manualmente el JSON completo).
- [ ] El mapa aparece solo cuando se cumple la regla (`en_transito`, ≤ 3 paradas antes, posición reciente) y desaparece al entregarse.
- [ ] La posición del vehículo se actualiza sola cada 10 s sin recargar.
- [ ] Reprogramar desde el paquete 10 lo deja en `ingresado_hub` con `fecha_programada`, y el botón desaparece.
- [ ] Reprogramar con fecha pasada o a más de 7 días da `VALIDACION`; con un paquete que no está en `incidencia`, `ESTADO_INVALIDO`.
- [ ] Más de 60 consultas por minuto desde una IP responden `LIMITE`.
- [ ] Las cabeceras `Cache-Control: no-store` y `X-Robots-Tag` están presentes.
- [ ] La página es usable con 360 px de ancho y el mapa funciona con el dedo.
- [ ] Los tres mensajes de las plantillas incluyen el enlace que abre el portal del paquete correcto.
- [ ] Un paquete en estado terminal deja de refrescar y, pasados `token_seguimiento_dias`, su enlace da 404.
