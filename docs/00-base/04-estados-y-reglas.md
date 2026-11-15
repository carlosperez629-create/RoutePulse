# 04. Estados y reglas de negocio

Todos los módulos usan **exactamente** estos nombres de estado (en minúsculas, con guion bajo). Son los mismos valores de las columnas `ENUM` del esquema.

## 1. Estados del paquete (`paquetes.estado`)

| Estado | Significado | Texto en pantalla |
|---|---|---|
| `creado` | Registrado, aún no llegó al HUB | Creado |
| `ingresado_hub` | Recibido y escaneado en el HUB | Ingresado en HUB |
| `en_clasificacion` | Clasificado y listo para asignarse a una ruta | En clasificación |
| `asignado_ruta` | Incluido en una ruta confirmada (aún no sale) | Asignado a ruta |
| `en_transito` | El conductor inició la ruta y lo lleva | En tránsito |
| `entregado` | Entrega exitosa con prueba (terminal) | Entregado |
| `incidencia` | Intento de entrega fallido, pendiente de resolver | Incidencia |
| `cancelado` | Cancelado antes de entregarse (terminal) | Cancelado |
| `devuelto` | Devuelto al remitente / centro de distribución (terminal) | Devuelto |

### Transiciones permitidas

Cualquier otra transición se rechaza con `ESTADO_INVALIDO` (409). Solo `paquete_cambiar_estado()` las aplica.

| Desde | Hacia | Quién la dispara | Condición / efecto |
|---|---|---|---|
| `creado` | `ingresado_hub` | Despachador (escaneo en HUB) | Asigna `hub_id` |
| `creado` | `cancelado` | Despachador, admin, webhook de cancelación (M7) | |
| `ingresado_hub` | `en_clasificacion` | Despachador (escaneo/clasificar) | Requiere `lat` y `lng` del destino para poder rutearse (si faltan, advierte) |
| `ingresado_hub` | `cancelado` | Despachador, admin, webhook | |
| `en_clasificacion` | `asignado_ruta` | M1 `ruta_confirmar` | |
| `en_clasificacion` | `cancelado` | Despachador, admin, webhook | |
| `asignado_ruta` | `en_transito` | M1 `ruta_iniciar` (la dispara M4) | **Notificación 1** (`en_camino`) |
| `asignado_ruta` | `en_clasificacion` | M1 (quitar de ruta o cancelar ruta) | |
| `asignado_ruta` | `cancelado` | Despachador, admin, webhook | M1 saca la parada de la ruta |
| `en_transito` | `entregado` | M4 `entrega_registrar` | **Notificación 3** (`entregado`) |
| `en_transito` | `incidencia` | M4 (entrega fallida) o M1 (parada omitida) | Si es fallo real, `intentos + 1`. **Notificación 3** (`fallida`) |
| `incidencia` | `ingresado_hub` | M3 `incidencia_resolver` con `reprogramar` | Solo si `intentos < max_intentos`; guarda `fecha_programada` |
| `incidencia` | `devuelto` | M3 `incidencia_resolver` con `devolver` | |
| `incidencia` | `cancelado` | M3 `incidencia_resolver` con `cancelar` | |

Estados terminales (no tienen salida): `entregado`, `cancelado`, `devuelto`.

Diagrama:

```
creado → ingresado_hub → en_clasificacion → asignado_ruta → en_transito → entregado
   │           │                │                 │  ▲            │
   │           │                │                 │  └─(quitar)───┤
   └───────────┴────────────────┴──── cancelado ◄─┘               ▼
                                                              incidencia → ingresado_hub (reintento)
                                                                  ├─────→ devuelto
                                                                  └─────→ cancelado
```

(`asignado_ruta → en_clasificacion` es el retorno al quitar un paquete de una ruta.)

## 2. Estados de la ruta (`rutas.estado`)

| Estado | Significado | Transiciones |
|---|---|---|
| `borrador` | Propuesta generada por el algoritmo, editable, sin efecto sobre los paquetes | → `planificada` (confirmar), → `cancelada` (descartar) |
| `planificada` | Confirmada: los paquetes pasan a `asignado_ruta`; tiene conductor y vehículo | → `en_curso` (el conductor inicia), → `cancelada` |
| `en_curso` | El conductor salió: paquetes `en_transito` | → `completada` (finalizar) |
| `completada` | Todas las paradas resueltas; se calcula `distancia_km_real` | terminal |
| `cancelada` | Anulada; los paquetes vuelven a `en_clasificacion` | terminal |

Una ruta `en_curso` **no se cancela**: se resuelven o se omiten sus paradas y se finaliza.

## 3. Estados de la parada (`paradas.estado`)

| Estado | Significado | Lo cambia |
|---|---|---|
| `pendiente` | Aún no le toca | M1 |
| `en_camino` | Es la próxima parada del conductor (siempre la de menor `orden` sin resolver) | M1/M4 automáticamente |
| `llegado` | El conductor pulsó "Llegué" (`llegada_real`) | M4 |
| `entregada` | Entrega exitosa (`salida_real`) | M4 |
| `fallida` | Intento fallido (`salida_real`) | M4 |
| `omitida` | El despachador la sacó de la ruta en curso | M1 |

Regla: cuando una parada pasa a `entregada`, `fallida` u `omitida`, `parada_actualizar_estado()` marca como `en_camino` la siguiente `pendiente` por `orden`. Al iniciar la ruta, la primera parada queda `en_camino`.

## 4. Incidencias (`incidencias`)

- Tipos: `direccion_no_encontrada`, `cliente_ausente`, `cobro_no_realizado`, `rechazado_por_cliente`, `paquete_danado`, `zona_insegura`, `otro`.
- Estados: `abierta` → `reprogramada` | `devuelta` | `cancelada`.
- Textos en pantalla: Dirección no encontrada, Cliente ausente, Cobro no realizado, Rechazado por el cliente, Paquete dañado, Zona insegura, Otro.
- Reintentos: `paquetes.intentos` cuenta los intentos fallidos. Si `intentos >= max_intentos`, solo se permite `devolver` o `cancelar`.
- Una parada omitida por el despachador crea una incidencia de tipo `otro` con descripción "Parada omitida por despacho" y **no** suma intento.

## 5. Disparadores de notificaciones

`notificar()` (M7) se llama automáticamente desde estos puntos. Ninguna otra parte del código envía mensajes al cliente.

| Evento | Tipo | Quién la llama | Clave anti-duplicado (`clave_dedup`) |
|---|---|---|---|
| Paquete pasa a `en_transito` | `en_camino` (Disparo 1) | `paquete_cambiar_estado` | `{paquete_id}:en_camino:{intentos}` |
| Primer criterio que se cumpla para la parada `en_camino`: entra a la geocerca (`geocerca_radio_m`) **o** ETA ≤ `proximidad_eta_min` | `proximidad` (Disparo 2) | `geocerca_evaluar` (M2) | `{paquete_id}:proximidad:{parada_id}` |
| Paquete pasa a `entregado` | `entregado` (Disparo 3) | `paquete_cambiar_estado` | `{paquete_id}:entregado` |
| Paquete pasa a `incidencia` (fallo real) | `fallida` (Disparo 3) | `paquete_cambiar_estado` | `{paquete_id}:fallida:{intentos}` |
| El conductor solicita el código de entrega | `otp` | `otp_generar_y_enviar` (M4) | sin dedup (limitado por `otp_max_por_hora`) |

Una parada omitida (sin suma de intento) **no** envía la notificación `fallida`.

## 6. Formatos de códigos

- **Guía del paquete** `codigo_guia`: `RP-AAMMDD-XXXXXX`. `AAMMDD` es la fecha de creación en UTC; `XXXXXX` son 6 caracteres aleatorios del alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (sin I, O, 0, 1). Se verifica unicidad y se reintenta hasta 5 veces.
- **Código de ruta** `codigo`: `R-AAMMDD-NN`, con `NN` consecutivo de 2 dígitos por día (01, 02, ...).
- **QR de la etiqueta**: contiene únicamente el `codigo_guia` (sin URL).
- **GS1-128 de la etiqueta**: Identificador de Aplicación (91) + `codigo_guia` sin guiones, texto legible `(91)RP261113A1B2C3`.
- **Token de seguimiento**: 32 caracteres hexadecimales (`bin2hex(random_bytes(16))`), único por paquete.
- **OTP**: `otp_longitud` dígitos (por defecto 6), generado con `random_int`, guardado solo como HMAC-SHA256.
- **UUID de cliente** (`entregas.uuid_cliente`): UUID v4 generado en el teléfono con `crypto.randomUUID()`.

## 7. Reglas transversales de negocio

1. Un paquete solo puede estar en una ruta activa (`borrador`, `planificada`, `en_curso`) a la vez.
2. Solo los paquetes en `en_clasificacion`, con coordenadas, y con `fecha_programada` vacía o menor o igual a la fecha de la ruta, pueden entrar a una ruta.
3. Un paquete `urgente` se ordena antes que los normales al generar rutas y puede agregarse a una ruta `en_curso`.
4. La ventana horaria (`ventana_inicio`, `ventana_fin`) es una restricción blanda: el algoritmo intenta respetarla y marca las paradas en riesgo; no impide la ruta.
5. Cobro contra entrega (`cobro_contra_entrega > 0`): el conductor ve el monto en la parada; si no se cobra, se registra fallo `cobro_no_realizado`.
6. Una entrega registrada es definitiva. Si hubo un error, el despachador lo gestiona con una incidencia y una nueva entrega; no se edita.
7. Cancelar un paquete que está en una ruta saca su parada de la ruta y recalcula las paradas restantes.
8. El enlace de seguimiento no vence mientras el paquete está en curso; vence `token_seguimiento_dias` días después de llegar a un estado terminal.
