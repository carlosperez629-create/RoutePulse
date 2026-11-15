# 03. Base de datos

La fuente de verdad es `database/schema.sql`. Este documento explica quién es dueño de cada tabla, cómo se relacionan y qué reglas de datos se cumplen siempre.

## 1. Cómo importar

1. Crear una base de datos vacía `routepulse` (utf8mb4, `utf8mb4_unicode_ci`).
2. Importar `database/schema.sql` y luego `database/seed.sql`.
3. Todo el equipo prueba contra los mismos datos del seed. Para reiniciar: borrar la base, crearla y volver a importar los dos archivos.

## 2. Propiedad de las tablas

El esquema tiene **23 tablas** (ver la sección 8 del `README.md` para lo que se quitó y se fusionó respecto al diseño inicial).

"Escribe" significa quién ejecuta `INSERT`/`UPDATE`. Siempre se hace **a través del servicio del dueño**; los demás módulos llaman a la función del dueño y no escriben directo.

| Tabla | Dueño | Quién la modifica (vía servicio del dueño) | Quién la lee |
|---|---|---|---|
| `usuarios` (el rol es un `ENUM`) | M8 | M8 (y M1 al crear conductores, vía `usuario_crear`) | Todos |
| `configuracion` | M8 | M8 | Todos (con `config_get`) |
| `log_auditoria` | M8 | Todos mediante `audit()` | M8 (consulta) |
| `archivos` | M4 | M4 | M3, despacho y gerencia al ver pruebas de entrega |
| `integraciones` | M7 | M7 | M7 |
| `webhook_eventos`, `notificaciones`, `plantillas_notificacion` | M7 | M7 (`notificar()` la usan todos) | M7, despachador |
| `hubs` | M3 | M3 | M1, M3, M6 |
| `paquetes`, `historial_estados`, `incidencias` | M3 | M3; el estado solo cambia con `paquete_cambiar_estado()`. **Excepción acordada:** las columnas `otp_*` de `paquetes` las escribe solo M4 (`otp_service.php`); las columnas `token_*` las escribe solo M3 y las lee M5 | Todos |
| `zonas`, `zona_codigos_postales`, `zona_conductor`, `vehiculos`, `conductores` | M1 | M1 | M2, M3, M4, M6 |
| `rutas`, `paradas` | M1 | M1; M2 escribe `paradas.eta` con `eta_recalcular_ruta()`; M4 cambia estado/llegada/salida con `parada_actualizar_estado()` | Todos |
| `puntos_gps`, `ultima_posicion`, `eventos_geocerca` | M2 | M2 | M5 (solo vía `gps_ultima_posicion`), M6 |
| `entregas` | M4 | M4 | M6, M3 |

Datos que **no** tienen tabla propia (ver README, sección 8):
- Token de seguimiento: columnas `token_seguimiento` y `token_expira_at` de `paquetes`.
- OTP de entrega: columnas `otp_*` de `paquetes` (un solo OTP vigente por paquete).
- Límite de peticiones: archivos en `storage/cache/rate_limit/`.
- Caché de geocodificación: archivos en `storage/cache/geocode/`.
- Bitácora de llamadas a proveedores: `storage/logs/integraciones.log`.
- Registro de migraciones de esquema: `database/migraciones/REGISTRO.md`.
- KPIs: se calculan en vivo, sin tabla de caché.

## 3. Relaciones principales

```
usuarios 1─0..1 conductores N─0..1 vehiculos
                                  │
zonas N─N conductores (zona_conductor)         zonas 1─N zona_codigos_postales

hubs 1─N paquetes N─0..1 zonas            integraciones 1─N paquetes (origen)
paquetes 1─N historial_estados            paquetes 1─N incidencias
paquetes 1─N notificaciones

hubs 1─N rutas N─1 conductores / vehiculos / zonas
rutas 1─N paradas N─1 paquetes
rutas 1─N puntos_gps N─1 conductores        conductores 1─1 ultima_posicion
paradas 1─N eventos_geocerca
paradas 1─N entregas N─1 paquetes           entregas N─0..2 archivos (firma, foto)
```

Un paquete puede tener varias paradas a lo largo del tiempo (una por intento de entrega, en rutas distintas). Dentro de una misma ruta, un paquete tiene como máximo una parada.

## 4. Reglas de datos que siempre se cumplen

1. **Zona horaria**: la conexión ejecuta `SET time_zone = '+00:00'`. Todo `DATETIME` está en UTC. La conversión a hora local es solo de presentación.
2. **Estado del paquete**: `paquetes.estado` solo cambia con `paquete_cambiar_estado()`, que además inserta una fila en `historial_estados`. Nadie hace `UPDATE paquetes SET estado=...` directamente.
3. **Historial inmutable**: `historial_estados`, `puntos_gps`, `log_auditoria` y `entregas` solo reciben `INSERT`. Una corrección es una fila nueva, nunca una edición.
4. **Un solo estado vigente por parada**: en una ruta hay a lo sumo una parada en `en_camino` (la de menor `orden` entre las no resueltas).
5. **Capacidad**: la suma de `peso_kg` y `volumen_m3` de los paquetes de una ruta no supera `capacidad_kg` ni `capacidad_m3` del vehículo.
6. **Un conductor, una ruta activa**: un conductor no puede tener dos rutas en estado `borrador`, `planificada` o `en_curso` para la misma fecha.
7. **Idempotencia**: `entregas.uuid_cliente`, `webhook_eventos(integracion_id, evento_externo_id)`, `paquetes(integracion_id, origen_externo_id)` y `notificaciones.clave_dedup` impiden duplicados al reintentar. Reintentar nunca debe crear un segundo registro.
8. **Borrado**: no se borran paquetes, rutas, usuarios ni entregas. Se cancelan o se desactivan (`activo = 0`).
9. **Archivos**: las firmas y fotos se guardan en `storage/uploads/` y en `archivos.ruta_relativa`. Nunca como BLOB en MySQL ni dentro de `public/`.
10. **Coordenadas**: `DECIMAL(10,7)`; latitud entre -90 y 90, longitud entre -180 y 180.
11. **Teléfonos**: se guardan en formato internacional sin espacios (`+573001234567`); validación `^\+?[0-9]{7,15}$`.
12. **Dinero**: `DECIMAL(12,2)`; la moneda en `moneda` (ISO 4217, 3 letras).

## 5. Índices y rendimiento

Los índices ya están definidos en `schema.sql`. Si una consulta tarda más de 300 ms con 10 000 paquetes de prueba, se agrega un índice mediante migración. Las listas siempre se paginan (máximo 100 filas por página).

## 6. Usuario de base de datos

- Desarrollo: un usuario con todos los permisos sobre la base `routepulse`.
- Producción: un usuario para la aplicación **sin** `UPDATE` ni `DELETE` sobre `puntos_gps`, `historial_estados`, `log_auditoria` y `entregas` (garantiza la inmutabilidad), y un segundo usuario de mantenimiento, usado solo por `cron/limpiar_gps.php`, con `DELETE` sobre `puntos_gps`.
- `ultima_posicion` necesita `UPDATE` (se reescribe en cada envío).
