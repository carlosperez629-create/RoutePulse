# M2. Rastreo GPS y telemetría

Responsable: **Guanda** (también hace M4). Apoyo: Carlos (M7: `notificar` y `ruta_matriz`). Servicio: `gps_service.php`. Carpetas: `public/api/gps/`, `public/monitor/`, `public/assets/js/gps_tracker.js`, `public/assets/js/mapa_util.js`, `cron/recalcular_eta.php`, `cron/limpiar_gps.php`.

## 1. Propósito y alcance

**Hace:** recibe la ubicación del conductor mediante AJAX; guarda el recorrido de forma inmutable; muestra la flota en vivo al despachador; dispara geocercas de proximidad; calcula y mantiene el ETA de cada parada; permite reproducir el recorrido real frente al sugerido.

**No hace:** no ordena rutas (M1), no envía mensajes (llama `notificar` de M7), no tiene WebSockets ni MQTT (ver límites), no rastrea con la app cerrada.

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M1 `ruta_obtener`, `parada_siguiente` | Paradas y su orden |
| M7 `notificar`, `ruta_matriz` | Aviso de proximidad; tiempos de viaje del ETA |
| M8 `api_run`, `audit`, `rate_limit_check` | Permisos y límites |

| Provee a | Qué |
|---|---|
| M4 | `assets/js/gps_tracker.js` y el endpoint `gps/enviar.php` |
| M1 | `eta_recalcular_ruta`, `gps_ultima_posicion`, `gps_distancia_recorrida_km` |
| M5 | `gps_ultima_posicion`, `eta_de_parada` |
| M6 | `puntos_gps`, `eventos_geocerca` (lectura) |
| Todos | `mapa_util.js` (utilidades de mapa Leaflet) |

## 3. Pantallas

| Página | Rol | Contenido |
|---|---|---|
| `monitor/flota.php` | A, D, G(L) | **Pantalla completa**. Mapa con un marcador por vehículo en ruta (color según señal), panel lateral con la lista de rutas en curso: conductor, vehículo, progreso (resueltas/total), próxima parada y su ETA, nivel de batería, antigüedad del último punto. Clic en un vehículo → centra el mapa, muestra su ruta sugerida y sus paradas. Botón "Pantalla completa". Refresco cada `monitor_refresh_s` (5 s) con `setInterval`, pausado si la pestaña está oculta. Filtro por zona y por estado de señal. Acceso directo a `despacho/ruta_detalle.php` y a las incidencias abiertas |
| `monitor/playback.php` | A, D, G | Selector de ruta (por fecha); mapa con la polyline **real** (azul) sobre la **sugerida** (gris discontinua), paradas con su hora de llegada real, control de tiempo (slider y botones reproducir/pausa/velocidad ×1 ×4 ×16) con un marcador que recorre el trayecto, y tarjetas con: distancia real, distancia sugerida, desviación %, duración real, tiempo en paradas, eventos de geocerca |

## 4. Tablas propias

`puntos_gps`, `ultima_posicion`, `eventos_geocerca`. Escribe además `paradas.eta` y `paradas.eta_actualizado_at`.

## 5. Endpoints

### `POST gps/enviar.php` (rol C)
Entrada JSON:
```json
{ "ruta_id": 1,
  "puntos": [ { "lat": 4.6331, "lng": -74.0801, "velocidad_kmh": 28.5, "precision_m": 8, "rumbo": 90, "bateria_pct": 80, "capturado_at": "2026-11-13T15:04:05.120Z" } ] }
```
Reglas:
- La ruta debe ser del conductor de la sesión y estar `en_curso` (si no, `SIN_PERMISO` / `ESTADO_INVALIDO`).
- Máximo 200 puntos por llamada (el modo offline envía lotes). Más → `VALIDACION`.
- Cada punto se valida (`lat`/`lng` en rango; `capturado_at` no más de 60 s en el futuro ni más de 24 h en el pasado). Los inválidos se rechazan **individualmente** y se informan; los válidos se guardan.
- Duplicados (misma ruta y mismo `capturado_at`) se ignoran sin error.
- Puntos con `precision_m > gps_precision_max_m` (200) se guardan pero **no** actualizan `ultima_posicion`, ni geocerca, ni ETA.
- Se procesan en orden de `capturado_at`. Cadena de integridad: `hash_registro = sha256(hash_anterior_de_la_ruta . '|' . ruta_id . '|' . lat . '|' . lng . '|' . capturado_at)`; el primero usa 64 ceros. Para evitar condiciones de carrera se bloquea la fila de la ruta (`SELECT ... FOR UPDATE`) durante la inserción. La cadena sigue el orden de inserción (`id`), no el de captura.
- Después de insertar: actualizar `ultima_posicion` con el punto más reciente **válido**, ejecutar `geocerca_evaluar` y `eta_recalcular_ruta`.
- Rate limit: 120 llamadas por minuto por conductor.

Respuesta: `{ "aceptados": 12, "rechazados": [ { "indice": 3, "motivo": "capturado_at fuera de rango" } ], "eventos": [ { "tipo": "entrada", "parada_id": 3 } ] }`.

### `GET gps/flota.php` (roles A, D, G)
Filtros: `zona_id`, `estado_senal`. Retorna una entrada por ruta `en_curso`:
```json
{ "ruta": { "id": 1, "codigo": "R-261113-01", "total_paradas": 5, "resueltas": 2, "zona_id": 1 },
  "conductor": { "id": 1, "nombre": "Carlos Conductor", "telefono": "+58..." },
  "vehiculo": { "id": 2, "placa": "MOT-001", "tipo": "motocicleta" },
  "posicion": { "lat": 4.633, "lng": -74.08, "velocidad_kmh": 28, "rumbo": 90, "bateria_pct": 80, "capturado_at": "...", "antiguedad_s": 45 },
  "estado_senal": "en_linea",
  "proxima_parada": { "id": 3, "paquete_id": 7, "codigo_guia": "RP-261113-U3V4W5", "eta": "...", "distancia_m": 1250 } }
```
`estado_senal`: `en_linea` (antigüedad < `gps_demorado_s`), `demorado` (< `gps_sin_senal_s`), `sin_senal` (resto, o sin posición).

### `GET gps/recorrido.php?ruta_id=&desde=&hasta=&verificar=` (A, D, G)
Retorna `{ "real": [[lat,lng,"capturado_at"], ...], "sugerida": [[lat,lng], ...], "paradas": [...con llegada_real y salida_real], "estadisticas": { "distancia_real_km", "distancia_sugerida_km", "desviacion_pct", "duracion_real_min", "tiempo_en_paradas_min" }, "integridad_ok": true }`. Los puntos reales se simplifican (Douglas-Peucker, tolerancia 5 m) si pasan de 3000. `verificar=1` recorre la cadena de hashes y devuelve `integridad_ok` (omitirlo devuelve `null`). `desviacion_pct = (real − sugerida) / sugerida × 100`.

### `GET gps/eventos.php?ruta_id=` (A, D)
Lista de `eventos_geocerca` de la ruta con datos de la parada.

### `GET gps/eta.php?ruta_id=` (A, D, G, C propio)
`[{ "parada_id", "orden", "estado", "eta": "ISO", "minutos": 12, "en_riesgo": false }]`. `en_riesgo` = ETA posterior a `ventana_fin` del paquete.

## 6. Cliente: `assets/js/gps_tracker.js` (lo usa M4)

Interfaz fija (M4 solo la consume):

```
GpsTracker.iniciar({ rutaId, intervaloS, alCambiarEstado })   // empieza a rastrear
GpsTracker.detener()
GpsTracker.estado()   // { activo, ultimoEnvio, pendientes, permiso: 'concedido'|'denegado'|'pendiente', ultimaPrecision }
```

Comportamiento:
- Usa `navigator.geolocation.watchPosition` (alta precisión) y toma una muestra cada `intervaloS` (por defecto `gps_intervalo_s` = 10 s; el valor viene de `mi_ruta.php`).
- Acumula los puntos y los envía a `gps/enviar.php` por lotes. Si no hay conexión o el servidor responde 5xx, los guarda en **IndexedDB** (almacén `puntos_pendientes`) y los reenvía en orden al recuperar la conexión, en lotes de hasta 100.
- Mantiene la pantalla encendida con la Screen Wake Lock API si está disponible.
- Avisa (`alCambiarEstado`) cuando se deniega el permiso de ubicación o cuando pasan más de 60 s sin poder enviar.
- Se detiene cuando la ruta termina.

## 7. Reglas de negocio y algoritmos

### 7.1 Geocerca de proximidad (`geocerca_evaluar`)

Solo se evalúa la parada `en_camino` de la ruta (la próxima del conductor). Con cada punto válido: `distancia = geo_distancia_m(punto, parada)`. Se crea el evento `entrada` (una sola vez por parada, protegido por la clave única `parada_id, tipo`) cuando se cumple **el primero** de estos criterios:
1. `distancia <= geocerca_radio_m` (500 m), o
2. el ETA de la parada es ≤ `proximidad_eta_min` (10 min).

Al crear el evento: guardar `distancia_m` y `radio_m`, llamar `notificar($paqueteId, 'proximidad', ['eta_min' => max(1, minutos_al_eta)], "{$paqueteId}:proximidad:{$paradaId}")` y marcar `notificado = 1`. El evento `salida` es opcional (fase 2).

### 7.2 Cálculo del ETA (`eta_recalcular_ruta`)

Para una ruta `en_curso`, con la posición actual `P` (la de `ultima_posicion`, o el HUB si no hay):
1. `tiempo_parada_prom` = promedio de (`salida_real − llegada_real`) de las paradas ya resueltas de esta ruta (últimas 5, excluyendo las mayores a `dwell_max_s`). Si no hay, `eta_parada_default_min`.
2. Para las paradas no resueltas en orden: `eta[k] = eta[k−1] + t_viaje(k−1 → k) + tiempo_parada_prom`, con `eta[0]` = ahora + `t_viaje(P → parada_en_camino)`. Si la parada actual está `llegado`, se suma el tiempo ya transcurrido en la parada (mínimo 0) y se parte de ahí.
3. `t_viaje` sale de `ruta_matriz` (una sola llamada con `P` y las paradas restantes; si el proveedor no responde, la velocidad es la `velocidad_kmh` media de los últimos 5 minutos si es mayor a 5, y si no `eta_velocidad_media_kmh`).
4. Se guarda en `paradas.eta` y `eta_actualizado_at`. No se recalcula más de una vez cada `eta_recalculo_min_s` (30 s) por ruta, salvo `$forzar`.
5. `cron/recalcular_eta.php` cada minuto recalcula las rutas en curso que llevan más de 2 minutos sin recalcular (cubre rutas sin puntos nuevos).

### 7.3 Inmutabilidad

`puntos_gps` solo recibe `INSERT`. `cron/limpiar_gps.php` es la única excepción (usuario de mantenimiento, ver `03-base-de-datos.md`).

### 7.4 Distancia recorrida

`gps_distancia_recorrida_km(ruta)`: suma de `geo_distancia_m` entre puntos consecutivos (por `capturado_at`), ignorando puntos con precisión mala y saltos con velocidad implícita > 150 km/h. Se usa al finalizar la ruta.

### 7.5 Utilidades de mapa (`mapa_util.js`)

Interfaz fija descrita en `09-guia-interfaz.md`, sección 5. M2 la entrega en la **primera semana** porque la usan M1, M5 y M6.

## 8. Datos de prueba (seed)

La ruta 1 tiene 6 puntos GPS, `ultima_posicion` del conductor 1 y 2 eventos de geocerca. Para probar la flota sin teléfono, usar un script de simulación (`tests/simular_gps.md` describe los pasos: llamar a `gps/enviar.php` con una secuencia de coordenadas entre el HUB y las paradas con un retardo de 2 s).

## 9. Criterios de aceptación

- [ ] `gps/enviar.php` guarda un lote de 50 puntos en menos de 500 ms y actualiza `ultima_posicion`.
- [ ] Un punto inválido se rechaza sin descartar los demás del lote; un duplicado se ignora.
- [ ] Un conductor no puede enviar puntos a la ruta de otro conductor ni a una ruta que no esté `en_curso`.
- [ ] El monitor muestra la ruta 1 con su vehículo y se refresca solo; el vehículo pasa a `demorado` y luego a `sin_senal` si se dejan de enviar puntos.
- [ ] Al acercarse a menos de 500 m de la parada `en_camino` se crea **un solo** evento y **una sola** notificación `proximidad`, aunque lleguen más puntos.
- [ ] El ETA de las paradas cambia cuando el vehículo se mueve o cuando se completa una parada.
- [ ] El playback reproduce el recorrido y muestra distancia real, sugerida y desviación.
- [ ] `verificar=1` devuelve `integridad_ok: true` y devuelve `false` si se altera un hash a mano en la BD.
- [ ] Los puntos con precisión peor que 200 m no mueven el marcador ni disparan eventos.
- [ ] `gps_tracker.js` guarda puntos sin conexión y los reenvía en orden al reconectar.

## 10. Límites conocidos

- Tiempo real por **polling** (5 s en el monitor), no por WebSockets ni MQTT. Con muchos vehículos aumenta la carga del servidor.
- El rastreo solo funciona mientras la página de la app del conductor está abierta en primer plano; los navegadores móviles detienen la geolocalización en segundo plano.
- El tráfico actual solo se refleja si el proveedor de mapas configurado lo ofrece (Google); con OSRM gratuito el ETA no considera tráfico en vivo.
