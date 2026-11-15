# M1. Despacho y optimización de rutas

Responsable: **Juan** (también hace M3). Apoyo: Carlos (servicios de mapas de M7) y Guanda (ETA y GPS de M2). Servicios: `zona_service.php`, `ruta_service.php`, `conductor_service.php`. Carpetas: `public/api/{vehiculos,conductores,zonas,rutas}/`, `public/despacho/`.

## 1. Propósito y alcance

**Hace:** administra la flota (vehículos, conductores) y las zonas de cobertura; agrupa y ordena paquetes en rutas respetando horarios, capacidad y tipo de vehículo; permite al despachador editar y confirmar rutas; reenruta sobre la marcha (cancelaciones, urgentes, bloqueos); controla el ciclo de vida de rutas y paradas.

**No hace:** no calcula el ETA (M2), no recibe GPS (M2), no registra entregas (M4), no cambia estados de paquete directamente (usa `paquete_cambiar_estado`), no envía notificaciones, no llama a proveedores de mapas (usa `ruta_matriz` y `ruta_geometria` de M7).

## 2. Dependencias

| Necesita de | Para |
|---|---|
| M3 `paquetes_para_ruta`, `paquete_cambiar_estado`, `hub_obtener` | Paquetes candidatos y cambios de estado |
| M7 `ruta_matriz`, `ruta_geometria` | Distancias, tiempos y trazado |
| M8 `usuario_crear`, `audit`, `api_run` | Crear usuario del conductor, auditoría, permisos |
| M2 `eta_recalcular_ruta`, `gps_ultima_posicion`, `gps_distancia_recorrida_km` | ETA de las paradas, posición para reenrutar, km reales |

| Provee a | Qué |
|---|---|
| M3 | `zona_resolver`, `parada_cancelar_por_paquete` |
| M4 | `ruta_activa_de_conductor`, `ruta_iniciar`, `ruta_finalizar`, `parada_actualizar_estado`, `conductor_obtener_por_usuario` |
| M2 | `ruta_obtener`, `parada_siguiente` |
| M5, M6 | Datos de rutas, paradas, zonas, vehículos (lectura) |

## 3. Pantallas

| Página | Rol | Contenido |
|---|---|---|
| `despacho/vehiculos.php` | A, D | Tabla de vehículos; modal crear/editar (placa, tipo, marca/modelo, capacidades, costo por km, activo) |
| `despacho/conductores.php` | A, D | Tabla de conductores; modal crear (datos del usuario + licencia + vehículo habitual), editar estado y vehículo |
| `despacho/zonas.php` | A, D | Mapa Leaflet con polígonos de colores; dibujar/editar polígono (Leaflet.draw); lista de códigos postales; asignar conductores a la zona |
| `despacho/rutas.php` | A, D, G(L) | Lista de rutas con filtros (fecha, estado, conductor, zona); botón "Generar rutas" (asistente: fecha, HUB, zona opcional, conductores opcionales, hora de salida) |
| `despacho/ruta_detalle.php` | A, D, G(L) | Mapa con la ruta sugerida y las paradas numeradas; lista de paradas con drag & drop; resumen (carga, km, duración, paradas en riesgo de horario); acciones según estado (Confirmar, Reasignar, Agregar paquete, Quitar, Omitir parada, Recalcular, Cancelar) |

## 4. Tablas propias

`vehiculos`, `conductores`, `zonas`, `zona_codigos_postales`, `zona_conductor`, `rutas`, `paradas`. Definiciones en `schema.sql`.

## 5. Endpoints

Formato general en `05-contrato-api.md`. Todos requieren los roles del catálogo.

### Vehículos
- `GET vehiculos/listar.php`: filtros `activo`, `tipo`, `q`. Retorna lista paginada.
- `POST vehiculos/crear.php`: `placa` (req, única, ≤20), `tipo` (req, enum), `marca_modelo?`, `capacidad_kg` (req, >0), `capacidad_m3` (req, >0), `costo_por_km?` (≥0). `201`.
- `POST vehiculos/actualizar.php`: `vehiculo_id` + cualquier campo anterior + `activo`. No se puede desactivar un vehículo con ruta `planificada`/`en_curso` (`CONFLICTO`).

### Conductores
- `GET conductores/listar.php`: filtros `estado`, `zona_id`, `disponibles_para_fecha`. Cada conductor incluye `usuario` (nombre, email, teléfono), `vehiculo` y `zonas`.
- `POST conductores/crear.php`: `nombre`, `email`, `telefono`, `password?` (si falta se genera una temporal y se retorna **una sola vez** en `password_temporal`), `licencia?`, `vehiculo_id?`. Crea el usuario con `usuario_crear` (rol `conductor`) y la fila en `conductores`. `201`.
- `POST conductores/actualizar.php`: `conductor_id`, `licencia?`, `vehiculo_id?` (puede ser `null`), `estado?` (`disponible`/`inactivo`; `en_ruta` lo cambia solo el sistema).

### Zonas
- `GET zonas/listar.php`: incluye `poligono` (GeoJSON objeto), `codigos_postales[]`, `conductores[]`.
- `POST zonas/crear.php`: `nombre` (req, único), `descripcion?`, `color?` (`#RRGGBB`), `poligono?` (GeoJSON `Polygon` válido: anillo cerrado, ≥4 puntos, lng/lat en rango), `codigos_postales?` (lista). `201`.
- `POST zonas/actualizar.php`: `zona_id` + campos + `activa`. Al cambiar el polígono **no** se recalculan zonas de paquetes ya creados.
- `POST zonas/asignar_conductores.php`: `zona_id`, `conductor_ids` (lista; reemplaza la asignación actual).
- `GET zonas/resolver.php?lat=&lng=&codigo_postal=`: retorna `{zona_id, nombre}` o `null`.

### Rutas
- `POST rutas/generar.php`: `fecha?` (por defecto hoy UTC), `hub_id` (req), `zona_id?`, `paquete_ids?`, `conductor_ids?`, `hora_inicio?` (ISO; por defecto ahora + 30 min). Retorna `{rutas: [...], sin_asignar: [{paquete_id, codigo_guia, motivo}], sin_coordenadas: [paquete_id]}`. Motivos: `excede_capacidad`, `sin_conductor_disponible`, `sin_coordenadas`. `201`.
- `GET rutas/listar.php`: filtros `fecha`, `desde`, `hasta`, `estado`, `conductor_id`, `zona_id`, `hub_id`.
- `GET rutas/ver.php?ruta_id=`: ruta con `paradas[]` (cada una: `id`, `orden`, `estado`, `eta`, `llegada_real`, `salida_real`, `lat`, `lng`, `en_riesgo` (bool), `paquete{id, codigo_guia, destinatario_nombre, direccion_entrega, peso_kg, prioridad, ventana_inicio, ventana_fin}`), `conductor`, `vehiculo`, `polyline_sugerida`, `resumen{total_paquetes, resueltas, carga_kg, carga_m3, capacidad_kg, capacidad_m3, distancia_km_estimada, duracion_min_estimada}`. El conductor solo ve su ruta (`SIN_PERMISO` si no).
- `POST rutas/confirmar.php`: `ruta_id`, `conductor_id?`, `vehiculo_id?` (si no se enviaron, deben estar ya asignados). Valida todo de nuevo (ver 7.5). Retorna la ruta.
- `POST rutas/reordenar.php`: `ruta_id`, `orden` (lista de `parada_id` en el nuevo orden; debe contener **todas** las paradas no resueltas, y las resueltas conservan su posición). Recalcula ETA y polyline. Permitido en `borrador`, `planificada`, `en_curso`.
- `POST rutas/agregar_paquete.php`: `ruta_id`, `paquete_id`, `posicion?` (si falta, inserción más barata). En `en_curso`, el paquete debe ser `en_clasificacion` y pasa a `asignado_ruta` y luego `en_transito` (con notificación). Verifica capacidad.
- `POST rutas/quitar_paquete.php`: `ruta_id`, `paquete_id`. Solo `borrador`/`planificada`; en `planificada` el paquete vuelve a `en_clasificacion`.
- `POST rutas/omitir_parada.php`: `ruta_id`, `parada_id`, `motivo?`. Solo `en_curso` y paradas `pendiente`/`en_camino`. Marca `omitida`, crea incidencia `otro` sin sumar intento (`incidencia_crear(..., cuentaIntento: false)`), y recalcula.
- `POST rutas/reasignar.php`: `ruta_id`, `conductor_id?`, `vehiculo_id?`. En `planificada`: valida capacidad y disponibilidad. En `en_curso`: **no permitido** (`ESTADO_INVALIDO`).
- `POST rutas/recalcular.php`: `ruta_id`, `motivo` (req), `evitar?` (lista de `{lat, lng, radio_m}`). Retorna la ruta actualizada y `cambios[]` (paradas que cambiaron de posición).
- `POST rutas/cancelar.php`: `ruta_id`, `motivo?`. Solo `borrador`/`planificada`; paquetes `asignado_ruta` vuelven a `en_clasificacion`.

## 6. Servicios que expone

Las firmas están en `07-servicios-internos.md`, sección D. M1 las implementa todas.

## 7. Reglas de negocio y algoritmos

### 7.1 Generación de rutas (`ruta_generar`)

1. **Candidatos**: `paquetes_para_ruta(hub, zona, fecha, paquete_ids)`. Los sin coordenadas se informan en `sin_coordenadas` y quedan fuera.
2. **Conductores disponibles**: `conductores.estado != 'inactivo'`, con vehículo activo asignado (o indicado), y sin otra ruta `borrador`/`planificada`/`en_curso` para esa fecha. Si se pasan `conductor_ids`, solo esos.
3. **Agrupación por zona**: se separan los paquetes por `zona_id`. Si una zona tiene conductores asignados (`zona_conductor`), sus paquetes solo van a esos conductores. Los paquetes sin zona y las zonas sin conductores asignados se reparten entre los conductores restantes.
4. **Reparto**, por cada grupo: ordenar candidatos por (`urgente` primero, `ventana_fin` ascendente). Mientras queden paquetes y conductores: tomar el conductor con mayor capacidad libre y llenar su ruta con el paquete más cercano (haversine al último punto, partiendo del HUB) que quepa en peso, volumen y `ruta_max_paradas`. Un paquete que no cabe en **ningún** vehículo disponible va a `sin_asignar` con `excede_capacidad`; los que sobran por falta de conductores, con `sin_conductor_disponible`.
5. **Ordenamiento de paradas** (por ruta): matriz de tiempos con `ruta_matriz([hub, ...paradas])`; orden inicial por vecino más cercano desde el HUB; mejora con **2-opt** (máximo 200 iteraciones o 2 s); luego pasada de ventanas: simular llegadas (salida = `hora_inicio`, tiempo de parada = `eta_parada_default_min`) y, si una parada llega después de su `ventana_fin`, intentar adelantarla intercambiándola con paradas anteriores (máximo 50 intentos). Las que sigan fuera de ventana se marcan `en_riesgo`.
6. **Tipo de vehículo**: se respeta solo mediante capacidades (`capacidad_kg`, `capacidad_m3`). Una motocicleta o bicicleta tiene capacidades pequeñas en el catálogo, así que los paquetes grandes no le llegan.
7. **Resultado**: se crean rutas `borrador` con sus paradas (`pendiente`, `eta` calculado, `lat`/`lng` copiadas del paquete) y `polyline_sugerida` (de `ruta_geometria`, o línea recta si es `null`). No se cambia ningún estado de paquete hasta confirmar.
8. **Códigos**: `R-AAMMDD-NN`. Todo en una transacción; auditar `ruta_generar`.

### 7.2 Confirmar (`ruta_confirmar`)

Dentro de `db_tx`: la ruta está en `borrador`; tiene conductor y vehículo activos; el conductor no tiene otra ruta activa ese día; todos los paquetes siguen `en_clasificacion` (si alguno ya no, se devuelve `CONFLICTO` con la lista y no se confirma); capacidad respetada. Luego cada paquete pasa a `asignado_ruta` con `paquete_cambiar_estado`, y la ruta a `planificada`.

### 7.3 Iniciar (`ruta_iniciar`, llamada por M4)

Valida que la ruta sea del conductor y esté `planificada`. En `db_tx`: ruta `en_curso` con `inicio_real`; todos los paquetes a `en_transito` (genera la notificación 1 de cada uno); primera parada `en_camino`; `conductores.estado = 'en_ruta'`. Después, fuera de la transacción, `eta_recalcular_ruta($rutaId, true)`.

### 7.4 Reenrutamiento dinámico (`ruta_recalcular`)

Solo rutas `planificada` o `en_curso`. Las paradas resueltas (`entregada`, `fallida`, `omitida`) y la parada en estado `llegado` no se tocan. Las restantes se reordenan:
- Punto de partida: `gps_ultima_posicion(conductor)` si la ruta está en curso y hay posición reciente (< 5 min); si no, la última parada resuelta o el HUB.
- Mismo algoritmo de 7.1 (vecino más cercano + 2-opt + ventanas) sobre las paradas restantes.
- `evitar`: las paradas cuyo punto cae dentro de algún círculo `{lat, lng, radio_m}` se mueven al final de la ruta (se visitan después). Es una simplificación: no se calculan desvíos por calle.
- Se actualizan `orden` y `polyline_sugerida`, se marca `en_camino` la primera pendiente, y se llama `eta_recalcular_ruta($rutaId, true)`.
- **Disparadores automáticos** (además del botón): `parada_cancelar_por_paquete` (paquete cancelado en ruta), `agregar_paquete` (paquete urgente) y `omitir_parada`.
- Se audita con el `motivo`.

### 7.5 Validaciones comunes

- Capacidad: `SUM(peso_kg) <= vehiculos.capacidad_kg` y `SUM(volumen_m3) <= capacidad_m3`; violación → `CONFLICTO` "La carga excede la capacidad del vehículo".
- Máximo de paradas: `ruta_max_paradas`.
- Un paquete solo en una ruta activa a la vez.
- Placa única; polígono GeoJSON válido; `color` con formato `#RRGGBB`.

### 7.6 Asignación de conductores por sector

`zona_resolver(lat, lng, codigo_postal)` asigna `zona_id` al paquete al crearlo (lo llama M3). Primero por polígono (la primera zona activa que contenga el punto, ordenadas por `id`); si no hay polígono que contenga el punto, por `zona_codigos_postales`. La asignación del conductor ocurre al generar rutas (7.1, paso 3).

## 8. Datos de prueba (seed)

Hay 2 zonas, 3 vehículos, 2 conductores, la ruta 1 `en_curso` (conductor 1, 5 paradas: 1 entregada, 1 fallida, 1 en camino, 2 pendientes) y la ruta 2 `planificada` (conductor 2, 2 paradas). Los paquetes 3 y 4 están `en_clasificacion` y sirven para probar `ruta_generar` (el 3 en Zona Norte, el 4 en Zona Sur). Para trabajar sin esperar a otros módulos, usar `ruta_generar` con los servicios de M7 en modo simulado.

## 9. Criterios de aceptación

- [ ] CRUD de vehículos, conductores y zonas funciona; el conductor creado puede iniciar sesión.
- [ ] Una zona se dibuja en el mapa, se guarda como GeoJSON y se vuelve a mostrar igual.
- [ ] `zona_resolver` devuelve la zona correcta para los paquetes del seed (por polígono y por código postal).
- [ ] `rutas/generar` con los paquetes 3 y 4 crea 2 rutas `borrador` (una por zona), ordenadas, con ETA y sin cambiar el estado de los paquetes.
- [ ] Un paquete que excede toda capacidad aparece en `sin_asignar` con su motivo.
- [ ] Confirmar una ruta pasa sus paquetes a `asignado_ruta` y deja historial; no se puede confirmar sin conductor ni vehículo.
- [ ] Reordenar paradas con drag & drop persiste el nuevo orden y actualiza los ETA.
- [ ] Agregar un paquete urgente a la ruta 1 (en curso) lo inserta en la posición más barata, lo pasa a `en_transito` y dispara la notificación 1.
- [ ] Cancelar un paquete que está en la ruta 2 saca su parada y no deja huecos en `orden`.
- [ ] `omitir_parada` crea una incidencia `otro` sin sumar intento ni enviar la notificación de fallo.
- [ ] `ruta_recalcular` con `evitar` mueve al final las paradas dentro del círculo.
- [ ] No se puede cancelar una ruta `en_curso` ni reasignar su conductor.
- [ ] Un conductor que pide `rutas/ver.php` de otra ruta recibe `SIN_PERMISO`.
- [ ] Todas las acciones auditan (`ruta_generar`, `ruta_confirmar`, `ruta_recalcular`, `ruta_cancelar`).

## 10. Límites conocidos (documentar en la entrega)

- La optimización es heurística: no garantiza el óptimo global y pierde calidad con más de ~40 paradas por ruta.
- El reenrutamiento por "bloqueo de tráfico" es manual (el despachador marca el área); no se detecta tráfico en tiempo real.
- Sin proveedor real de mapas, las distancias son estimaciones en línea recta.
