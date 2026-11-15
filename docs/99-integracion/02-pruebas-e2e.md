# 99-02. Pruebas de punta a punta (E2E)

Pruebas manuales que el equipo ejecuta al integrar. Se hacen sobre una base recién importada (`schema.sql` + `seed.sql`) salvo que se indique otra cosa. Usuarios y clave: ver `README.md`. Anotar el resultado (✅/❌), fecha y quién la ejecutó en `tests/resultados.md`.

Para simular GPS sin teléfono: enviar `POST gps/enviar.php` con coordenadas intermedias entre el HUB y las paradas (con el conductor 1 y la ruta 1 del seed).

---

## E2E-00. Base y acceso (Hito 0)

| # | Paso | Resultado esperado |
|---|---|---|
| 1 | Iniciar sesión con cada uno de los 5 usuarios | Cada uno llega a su pantalla inicial con su menú |
| 2 | Con el conductor, abrir `/admin/usuarios.php` | "Sin permiso" |
| 3 | Sin sesión, abrir `/api/auth/me.php` | `401 NO_AUTENTICADO` |
| 4 | Un `POST` sin `X-CSRF-Token` | `403 CSRF_INVALIDO` |
| 5 | Revisar `storage/logs/app.log` | Sin avisos ni errores de PHP |

## E2E-01. Flujo feliz completo (paquete nuevo → entrega → KPIs)

**Parte 1 (M3 + M7):**
1. (Despachador) Crear un paquete en `hub/paquete_nuevo.php` con dirección en Zona Norte. → Se crea con guía `RP-…`, `creado`, coordenadas simuladas, zona Norte y token. Historial con 1 fila.
2. Escanear la guía con "Ingreso al HUB" (HUB 1). → `ingresado_hub`.
3. Escanear con "Clasificar". → `en_clasificacion`.
4. Imprimir la etiqueta. → QR y GS1-128 legibles con la guía.

**Parte 2 (M1):**
5. En `despacho/rutas.php`, "Generar rutas" (fecha de hoy, HUB 1, Zona Norte). → Aparece una ruta `borrador` con el paquete nuevo y el paquete 3 del seed, ordenadas, con ETA.
6. Asignar conductor 1 y vehículo y **Confirmar**. → Ruta `planificada`; ambos paquetes `asignado_ruta`. (Antes, finalizar o cancelar la ruta 1 del seed si el conductor 1 ya tiene ruta ese día; o usar el conductor 2.)

**Parte 3 (M4 + M2):**
7. (Conductor) Abrir la app, ver la ruta, **Iniciar ruta**. → Ruta `en_curso`; paquetes `en_transito`; primera parada `en_camino`; **una** notificación `en_camino` por paquete.
8. Simular GPS acercándose a la parada actual hasta < 500 m. → Un evento de geocerca y **una** notificación `proximidad`. Enviar más puntos: no se repiten.
9. Pulsar **Llegué**, solicitar OTP (en `dev` aparece `otp_debug`), llenar nombre, OTP, firma y foto, **Entregar**. → Entrega registrada; parada `entregada`; paquete `entregado`; siguiente parada `en_camino`; notificación `entregado`.
10. Repetir con la segunda parada y **Finalizar ruta**. → Ruta `completada`, `distancia_km_real` calculada.

**Parte 4 (M5):**
11. Abrir `/t/{token}` del paquete durante el paso 7–8. → Estado "En tránsito", ETA y, si hay ≤ 3 paradas antes, el mapa con el vehículo que se mueve.
12. Abrirlo después del paso 9. → "Entregado el …".

**Verificación final:** historial del paquete con todos los estados, quién y cuándo; auditoría con las acciones; KPIs del día reflejan la entrega (E2E-06).

## E2E-02. Entrega fallida, reintento y reprogramación

1. Con la ruta 1 del seed (conductor 1), en la parada `en_camino` (paquete 7): **Llegué** → **No se pudo entregar** con motivo `cliente_ausente`. → Parada `fallida`; incidencia creada; paquete 7 `incidencia` con `intentos = 1`; notificación `fallida`; siguiente parada `en_camino`.
2. Abrir `/t/…0007`. → Mensaje de falla y botón "Reprogramar entrega".
3. Reprogramar para mañana, franja tarde. → Paquete `ingresado_hub` con `fecha_programada` = mañana; incidencia `reprogramada`; el botón desaparece.
4. (Despachador) Clasificar el paquete y generar una ruta para mañana. → Entra a la nueva ruta (la `fecha_programada` coincide).
5. Repetir el fallo hasta llegar a 3 intentos y resolver la incidencia: **Reprogramar** debe rechazarse (`CONFLICTO`); **Devolver** → paquete `devuelto` (terminal).
6. Con el paquete 10 del seed (`incidencia`, intento 1): resolver como `cancelar`. → `cancelado`.

## E2E-03. Modo offline del conductor

1. Con la ruta en curso, activar el modo avión en el teléfono (o `Offline` en las herramientas del navegador).
2. Registrar **Llegué** y **Entregar** (con firma y foto; sin OTP). → La app lo acepta, muestra "1 operación pendiente", avanza la siguiente parada localmente.
3. Recargar la app. → Abre sin conexión mostrando la ruta guardada y la cola de pendientes.
4. Desactivar el modo avión. → La cola se sincroniza en orden; la entrega queda registrada con `fuera_de_linea = 1`, `otp_validado = 0` y la hora real (`ocurrido_at`).
5. Forzar el reenvío de la misma operación (mismo `uuid`). → `duplicado: true`, sin registros nuevos.
6. Provocar un rechazo `4xx` en una operación (por ejemplo, parada ya resuelta). → Queda visible como error, no bloquea las siguientes.
7. Revisar los puntos GPS enviados durante el corte. → Llegaron en lote con sus `capturado_at` originales.

## E2E-04. Reenrutamiento dinámico y cancelaciones

1. En la ruta 1 en curso, **cancelar** el paquete 9 (parada 5) desde `hub/paquete_detalle.php`. → La parada pasa a `omitida`, la ruta se recalcula, sin huecos de `orden`, ETA actualizados.
2. Crear un paquete **urgente**, clasificarlo y **agregarlo** a la ruta 1 desde `ruta_detalle.php`. → Se inserta en la posición más barata, pasa a `en_transito`, genera la notificación `en_camino` y recalcula los ETA.
3. **Omitir** la parada pendiente del paquete 8. → Incidencia `otro` sin sumar intento y **sin** notificación de falla.
4. `Recalcular` con `evitar` (círculo sobre una parada pendiente). → Esa parada se mueve al final.
5. Intentar **cancelar** la ruta 1 (en curso) y **reasignarla**. → `ESTADO_INVALIDO` en ambos.
6. Cancelar la ruta 2 (planificada). → Paquetes 11 y 12 vuelven a `en_clasificacion`.
7. Generar rutas con un paquete de 2000 kg. → Aparece en `sin_asignar` con `excede_capacidad`.

## E2E-05. Webhooks y API de entrada (idempotencia)

1. `curl` de un pedido Shopify de ejemplo a `/webhooks/shopify.php` (en `dev`). → Evento `procesado`; paquete `creado` con guía, zona y token.
2. Reenviar **exactamente** el mismo evento. → `duplicado: true`; no hay segundo paquete.
3. Enviar un pedido con teléfono inválido. → Evento `error`; aparece en `admin/webhooks.php`; corregir la causa y **Reprocesar** → `procesado`.
4. Enviar el evento de cancelación del pedido del paso 1. → Paquete `cancelado`.
5. Enviar la cancelación de un pedido cuyo paquete está `en_transito` (paquete 7). → El paquete no cambia; evento `error` explicando el motivo.
6. `POST integraciones/paquetes_entrada.php` con la API key y un lote de 3 (uno inválido). → Resultado por elemento; reenviar el lote → los válidos salen `duplicado`.
7. Sin `X-API-Key` o con una incorrecta. → `401`.
8. Con `app.entorno = prod`, webhook con firma inválida. → `401`, sin efectos.

## E2E-06. KPIs

1. Con el seed limpio, abrir `gerencia/dashboard.php` (gerente). → Despachados 5, Entregados 1, OTIF 20,0 %, FTDR 50,0 %, Incidencias 1, Tiempo de parada 5:00 (valores de M6, sección 5).
2. Completar el E2E-01 y volver a mirar. → Los números cambian de forma coherente con lo ocurrido.
3. Registrar una entrega **tardía** (cambiar la `ventana_fin` del paquete a una hora anterior antes de entregar). → OTIF baja; FTDR no cambia.
4. Entregar un paquete en su **segundo** intento (E2E-02). → FTDR no lo cuenta como éxito de primer intento.
5. Filtrar por conductor y por zona. → Las zonas suman el total (salvo paquetes sin zona).
6. Mapa de calor de entregas e incidencias. → Puntos en las ubicaciones esperadas.
7. Exportar CSV y abrirlo en Excel. → Acentos correctos y mismas cifras que la pantalla.
8. Entrar como despachador al dashboard. → `SIN_PERMISO`.

## E2E-07. Proveedores reales (opcional, al final)

| # | Paso | Resultado esperado |
|---|---|---|
| 1 | Configurar Twilio en `config.php`, `modo_simulado = 0`, "Probar" | Llega un SMS/WhatsApp de prueba |
| 2 | Disparar `en_camino` real | Mensaje recibido con enlace que abre el portal |
| 3 | Apagar la red del servidor / clave inválida | La notificación reintenta 1→5→15 min y pasa al siguiente canal |
| 4 | Cambiar `mapas_proveedor` a un proveedor real | Geocodificación y rutas reales; la carpeta `storage/cache/geocode/` se llena |
| 5 | Configurar SMTP | Llega el correo con asunto y cuerpo |
| 6 | Revisar `storage/logs/integraciones.log` | Sin claves ni tokens |

## E2E-08. Seguridad

| # | Prueba | Resultado esperado |
|---|---|---|
| 1 | Login con `' OR 1=1 --` | Sin acceso; consulta intacta |
| 2 | Buscar paquetes con `' OR 1=1 --` en `q` | Sin resultados extra, sin error SQL |
| 3 | Crear un paquete con `<script>alert(1)</script>` en el nombre | Se muestra como texto, no se ejecuta |
| 4 | 6 logins fallidos seguidos | Cuenta bloqueada 15 min |
| 5 | `POST` sin CSRF en cualquier endpoint | `CSRF_INVALIDO` |
| 6 | Conductor 1 pide la ruta 2 o la parada de otro conductor | `SIN_PERMISO` |
| 7 | Conductor 1 pide `archivos/ver.php` de una entrega ajena | `SIN_PERMISO` |
| 8 | Portal con token inventado, o con `paquete_id` en vez de token | `404`; no acepta `paquete_id` |
| 9 | 70 consultas en un minuto al portal | `LIMITE` (429) |
| 10 | Revisar el JSON de `tracking/estado.php` | Sin teléfono, correo, nombre de conductor, placa ni coordenadas exactas |
| 11 | Buscar secretos en el repositorio (`git grep -i "token\|secret\|password"`) | Sin claves reales |
| 12 | Intentar abrir `/config/config.php`, `/app/`, `/storage/` por URL | No accesible |
| 13 | Subir un `.php` renombrado a `.png` como foto | Rechazado por tipo real |
| 14 | Revisar cabeceras de seguridad de una página y de una API | Presentes |

## E2E-09. Rendimiento básico (referencia, no bloqueante)

- `paquetes/listar.php` con 10 000 paquetes de prueba: < 1 s.
- `gps/enviar.php` con 50 puntos: < 500 ms.
- `kpi/resumen.php` con 10 000 paquetes: < 1 s.
- Monitor con 10 rutas en curso refrescando cada 5 s: sin errores y sin acumulación de peticiones.
