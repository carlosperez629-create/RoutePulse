# M6. Dashboard de analítica y control de operaciones

Responsable: **Karoline**, con acompañamiento de Juan (consultas SQL sobre paquetes, rutas y paradas). Guía paso a paso en `GUIA-KAROLINE.md`. Servicio: `kpi_service.php`. Carpetas: `public/api/kpi/`, `public/gerencia/`, `public/assets/js/m6/`.

## 1. Propósito y alcance

**Hace:** calcula y muestra los indicadores clave del servicio: OTIF, FTDR, tiempo de parada (dwell time), costos por kilómetro y por entrega, tasa de incidencias; muestra rankings por conductor y por zona; dibuja el mapa de calor de entregas e incidencias; exporta reportes.

**No hace:** no modifica datos de otros módulos (es de **solo lectura** sobre ellos), no monitorea la flota en vivo (eso es el monitor de M2).

## 2. Dependencias

| Necesita de (lectura de tablas) | Para |
|---|---|
| `paradas`, `rutas` (M1), `entregas` (M4), `paquetes`, `incidencias` (M3), `vehiculos` (M1), `zonas` (M1), `conductores` (M1), `usuarios` (M8), `puntos_gps` (solo vía `rutas.distancia_km_real`) | Todos los indicadores |
| M8 `api_run`, `audit` | Permisos |
| M2 `mapa_util.js` | Mapa Leaflet |

Provee a: nadie (módulo final de la cadena).

Como solo lee, M6 puede desarrollarse desde el primer día **con el seed**, y refinarse cuando M1/M4 generen datos reales.

## 3. Pantallas

| Página | Rol | Contenido |
|---|---|---|
| `gerencia/dashboard.php` | A, G | Filtros: rango de fechas (por defecto últimos 7 días), zona, conductor, tipo de vehículo. Tarjetas: **OTIF %**, **FTDR %**, **Tiempo de parada** promedio, **Entregados / Despachados**, **Incidencias** (cantidad y tasa), **Costo por km**, **Costo por entrega**. Gráfico de líneas (OTIF y FTDR por día), gráfico de barras (entregas vs incidencias por día), tabla de ranking de conductores, tabla de zonas con su tasa de rechazo |
| `gerencia/mapa_calor.php` | A, G | Mapa Leaflet con capa de calor (Leaflet.heat). Selector: "Entregas" / "Incidencias". Mismos filtros de fecha, zona y conductor. Leyenda de intensidad |
| `gerencia/reportes.php` | A, G | Listado de reportes disponibles (Resumen, Ranking de conductores, Por zona, Detalle de entregas); botón **Exportar CSV**; botón **Imprimir / guardar PDF** (CSS de impresión sobre la vista) |

## 4. Tablas propias

No tiene tablas propias: todos los cálculos se hacen **en vivo** con consultas SQL indexadas sobre las tablas de los demás módulos. Si más adelante el rendimiento lo exige, se puede agregar una tabla de caché mediante una migración acordada por el grupo.

## 5. Definiciones exactas de los indicadores

Estas definiciones son el contrato: dos personas que las calculen deben obtener el mismo número.

**Periodo**: `desde` y `hasta` (fechas inclusivas) aplicados a `rutas.fecha`. Una ruta cuenta si su estado es `en_curso` o `completada`.

**Despachados** = cantidad de paquetes **distintos** con al menos una parada en una ruta del periodo y filtros. (Un paquete reprogramado y reenviado cuenta una vez por periodo.)

**Entregados** = paquetes distintos con una fila en `entregas` con `resultado = 'entregado'` cuyo intento pertenece a una ruta del periodo.

**OTIF %** = (`entregas` con `resultado = 'entregado'` **y** `completa = 1` **y** (`paquetes.ventana_fin` es `NULL` **o** `entregas.ocurrido_at <= paquetes.ventana_fin`)) ÷ **Despachados** × 100. Un decimal; si no hay despachados → `null`.

**FTDR %** = (`entregas` con `intento_num = 1` y `resultado = 'entregado'`) ÷ (`entregas` con `intento_num = 1`, cualquier resultado) × 100.

**Tiempo de parada (dwell time)** = promedio de `TIMESTAMPDIFF(SECOND, llegada_real, salida_real)` de las paradas del periodo con ambos datos y valor ≤ `dwell_max_s` (7200 s, para descartar paradas olvidadas abiertas). Se devuelve en segundos y como `mm:ss`.

**Incidencias** = cantidad de `incidencias` creadas en el periodo con paquetes de las rutas filtradas. **Tasa de incidencias %** = incidencias ÷ Despachados × 100.

**Km reales** = `SUM(rutas.distancia_km_real)` de las rutas `completada` del periodo.

**Costo operativo** = `SUM(rutas.distancia_km_real × vehiculos.costo_por_km)`. **Costo por km** = costo operativo ÷ km reales (promedio ponderado). **Costo por entrega** = costo operativo ÷ Entregados.

**Nivel de servicio global** = OTIF %.

**Tasa de rechazo por zona %** = (paradas `fallida` de paquetes de la zona) ÷ (paradas resueltas `entregada` + `fallida` de la zona) × 100.

Filtros: `zona_id` (se aplica a `paquetes.zona_id`), `conductor_id` (a `rutas.conductor_id`), `vehiculo_tipo` (a `vehiculos.tipo`). Rango máximo: 366 días (`VALIDACION` si se excede).

### Ejemplo de verificación con el seed (para comprobar el cálculo)

Con el periodo que contiene hoy (todas las rutas del seed son de hoy) y sin filtros: ruta 1 `en_curso` con 5 paquetes (6, 10, 7, 8, 9) → **Despachados = 5**; entregas: una exitosa (paquete 6, completa, intento 1, antes de su `ventana_fin`) y una fallida (paquete 10, intento 1) → **Entregados = 1**, **OTIF = 1/5 = 20,0 %**, **FTDR = 1/2 = 50,0 %**, **Incidencias = 1**, **Tasa de incidencias = 20,0 %**, **Tiempo de parada**: parada 1 = 300 s, parada 2 = 300 s → 5:00. La ruta 2 aún es `planificada`, así que no cuenta. Los km y costos son `null`/0 porque ninguna ruta está `completada`.

## 6. Endpoints (roles A, G)

Todos `GET`, con filtros comunes `desde`, `hasta`, `zona_id?`, `conductor_id?`, `vehiculo_tipo?`.

- `kpi/resumen.php` → `{ "periodo": {"desde","hasta"}, "despachados", "entregados", "otif_pct", "ftdr_pct", "dwell_seg", "dwell_texto", "incidencias", "tasa_incidencias_pct", "km_reales", "costo_operativo", "costo_por_km", "costo_por_entrega", "nivel_servicio_pct" }`.
- `kpi/serie.php` → `[{ "fecha", "despachados", "entregados", "otif_pct", "ftdr_pct", "incidencias" }]` una entrada por día del rango (con ceros o `null` los días sin datos).
- `kpi/ranking_conductores.php` → por conductor: `conductor_id`, `nombre`, `despachados`, `entregados`, `otif_pct`, `ftdr_pct`, `dwell_seg`, `km_reales`, `costo_operativo`. Orden por defecto: `otif_pct` descendente.
- `kpi/por_zona.php` → por zona: `zona_id`, `nombre`, `despachados`, `entregados`, `incidencias`, `tasa_rechazo_pct`, `otif_pct`.
- `kpi/mapa_calor.php?tipo=entregas|incidencias` → `[[lat, lng, peso], ...]` (máximo 5000). `entregas`: ubicación (`lat`,`lng`) de cada entrega exitosa; `incidencias`: ubicación de cada entrega fallida (si no tiene, la del paquete). Se agrupan redondeando a 4 decimales (~11 m) y sumando el peso.
- `kpi/exportar.php?reporte=resumen|conductores|zonas|entregas` → archivo CSV: UTF-8 con BOM, separador coma, comillas dobles, fechas en `zona_horaria_ui`, `Content-Disposition: attachment`. `entregas` es el detalle fila por fila (guía, conductor, resultado, fecha, a tiempo, completa, OTP validado). Máximo 20 000 filas.

## 7. Reglas de implementación

- **Solo lectura**: M6 nunca hace `INSERT/UPDATE/DELETE` en tablas ajenas.
- Consultas con parámetros y con `GROUP BY` en SQL; no se cargan todas las filas en PHP para calcular.
- Cada endpoint de KPI debe responder en menos de 1 s con 10 000 paquetes de prueba; si no, agregar índices con una migración.
- Los porcentajes se calculan en el servidor y se redondean a 1 decimal; la pantalla solo formatea.
- Gráficos con Chart.js (local). Mapa de calor con Leaflet.heat.
- Exportaciones y vistas auditan `kpi_exportar` (quién y qué reporte).
- Zona horaria: los límites de día (`desde`, `hasta`) se interpretan en `zona_horaria_ui` y se convierten a UTC para consultar.

## 8. Criterios de aceptación

- [ ] Con el seed, `kpi/resumen.php` devuelve los valores del ejemplo de la sección 5 (Despachados 5, Entregados 1, OTIF 20,0 %, FTDR 50,0 %, Incidencias 1, Tiempo de parada 5:00).
- [ ] Si se registra una entrega tardía (después de `ventana_fin`) o incompleta, el OTIF baja; si se entrega al segundo intento, FTDR no la cuenta como éxito de primer intento.
- [ ] El filtro por conductor, zona y tipo de vehículo cambia los números de forma coherente (la suma de las zonas coincide con el total sin filtro, salvo paquetes sin zona).
- [ ] `serie.php` devuelve una fila por cada día del rango, también los días sin datos.
- [ ] El ranking ordena por OTIF y muestra a los 2 conductores del seed.
- [ ] El mapa de calor muestra los puntos de entregas y, al cambiar a incidencias, el punto del paquete 10.
- [ ] El CSV se abre bien en Excel (acentos correctos) y tiene las mismas cifras que la pantalla.
- [ ] El gerente puede entrar al dashboard; el despachador y el conductor reciben `SIN_PERMISO`.
- [ ] Un rango mayor a 366 días responde `VALIDACION`; fechas invertidas (`desde > hasta`) también.
- [ ] No hay ninguna consulta construida concatenando parámetros.
- [ ] La pantalla se ve bien en escritorio y tableta (las tarjetas se reacomodan).
