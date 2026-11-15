# 99-01. Dependencias entre módulos y orden de integración

## 1. Matriz de dependencias

Fila = módulo que **necesita**; columna = módulo del que depende. `●` = depende de sus servicios o endpoints; `○` = solo lee sus tablas.

| Necesita ↓ / de → | M1 | M2 | M3 | M4 | M5 | M6 | M7 | M8 |
|---|---|---|---|---|---|---|---|---|
| **M1** Despacho | | ● | ● | | | | ● | ● |
| **M2** Rastreo | ● | | | | | | ● | ● |
| **M3** Trazabilidad | ● | | | | ● | | ● | ● |
| **M4** App conductor | ● | ● | ● | | | | ● | ● |
| **M5** Portal cliente | ○ | ● | ● | | | | | ● |
| **M6** Dashboard | ○ | ○ | ○ | ○ | | | | ● |
| **M7** Integraciones | | | ● | | ● | | | ● |
| **M8** Seguridad | | | | | | | | |

Observaciones:
- M8 no depende de nadie; todos dependen de M8.
- Hay una dependencia circular **por diseño** entre M3 y M1 (M3 llama `zona_resolver`/`parada_cancelar_por_paquete`; M1 llama `paquete_cambiar_estado`) y entre M3 y M5/M7. Se rompe con las **firmas publicadas desde el Hito 1**: nadie espera a que el otro termine, solo a que la firma exista.
- M6 solo lee tablas: puede desarrollarse con el seed desde el primer día.

## 2. Cómo trabajar en paralelo sin bloquearse

1. **Hito 1 (primeros días de la Fase 1)**: cada dueño sube a su archivo de `app/services/` las firmas de `07-servicios-internos.md` con cuerpo mínimo (retorna datos coherentes con el seed). Así todos pueden llamar a todos.
2. Cada uno desarrolla su UI y sus endpoints contra el **seed** y los servicios mínimos de los demás.
3. M7 entrega primero el **modo simulado** (`notificar`, `geocodificar`, `ruta_matriz`, `ruta_geometria`) y M3 entrega primero `paquete_cambiar_estado` completo: son los dos servicios que más módulos usan.
4. M2 entrega primero `mapa_util.js` y `gps_tracker.js` (los usan M1, M4, M5, M6).
5. Los endpoints se prueban con la colección de pruebas manuales del documento de pruebas (99-02) y el seed.

## 3. Orden de integración recomendado

Cada paso termina con sus pruebas E2E correspondientes (99-02). No se pasa al siguiente con errores abiertos del anterior.

| Paso | Qué se integra | Responsables | Pruebas |
|---|---|---|---|
| 1 | **Núcleo + M8 (etapa A)**: base, login, menú por rol | Carlos + revisión de todos | E2E-00 |
| 2 | **M3 completo + M7 simulado**: crear paquete → guía, geocodificación simulada, zona, token; escaneo en HUB; transiciones; notificaciones simuladas | Juan y Carlos | E2E-01 (parte 1), E2E-05 |
| 3 | **M1**: zonas, flota, generar/confirmar rutas con paquetes reales de M3 | Juan | E2E-01 (parte 2), E2E-04 |
| 4 | **M4 + M2**: iniciar ruta, GPS, geocercas, ETA, entrega con pruebas, fallos, offline | Guanda (+ Juan y Carlos) | E2E-01 (parte 3), E2E-02, E2E-03 |
| 5 | **M5**: portal con datos reales de ruta, ETA y posición; reprogramación | Karoline (+ Guanda y Juan) | E2E-01 (parte 4), E2E-02 |
| 6 | **M6**: KPIs con datos generados por los pasos anteriores; coherencia con el seed | Karoline (+ Juan) | E2E-06 |
| 7 | **M7 real** (opcional): proveedores reales una por una | Carlos | E2E-07 |
| 8 | **M8 etapa B** (si no se completó antes) y revisión de seguridad completa | Carlos + todos | E2E-08 |

## 4. Puntos de contacto críticos (revisar en parejas)

| Punto | Quiénes | Qué verificar |
|---|---|---|
| `paquete_cambiar_estado` llamado desde M1 (`ruta_confirmar`, `ruta_iniciar`) y M4 (entregas) | Juan + Guanda | Las transiciones y las notificaciones se generan una sola vez |
| `parada_actualizar_estado` ↔ siguiente parada `en_camino` | Juan + Guanda | Nunca hay dos paradas `en_camino`; el orden se respeta |
| `gps_registrar_puntos` → `geocerca_evaluar` → `notificar` | Guanda + Carlos | Una sola notificación de proximidad por parada |
| `eta_recalcular_ruta` ↔ `ruta_matriz` | Guanda + Carlos | Funciona con el proveedor simulado y con el de respaldo |
| `tracking_estado_publico` ↔ `gps_ultima_posicion` / `eta_de_parada` | Karoline + Guanda | El mapa del cliente solo aparece con la regla de las 3 paradas |
| `incidencia_crear` / `incidencia_resolver` ↔ portal (reprogramar) ↔ M1 (volver a una ruta) | Juan + Karoline + Guanda | Un paquete reprogramado vuelve a `ingresado_hub` y puede entrar a otra ruta |
| Webhook → `paquete_crear` → geocodificar → zona | Carlos + Juan | El paquete llega a `creado` con zona y token |
| `ruta_finalizar` → `gps_distancia_recorrida_km` → KPIs | Juan + Guanda + Karoline | `distancia_km_real` y costos coherentes |

## 5. Resolución de conflictos

Si dos módulos no coinciden en un formato o comportamiento:
1. Manda el documento (`07`, `05`, `04`). Si el documento es ambiguo, se aclara **en el documento** por pull request.
2. Los dos dueños acuerdan el cambio y lo aprueba un tercero.
3. Se actualizan las pruebas E2E afectadas.
