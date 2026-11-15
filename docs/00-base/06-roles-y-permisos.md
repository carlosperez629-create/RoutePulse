# 06. Roles y permisos (RBAC)

## 1. Roles

| Código | Nombre | Cómo entra | Alcance |
|---|---|---|---|
| `admin` | Administrador | Login | Usuarios, configuración e integraciones; además lectura de todo lo operativo |
| `despachador` | Despachador / Monitor | Login | Opera la flota, paquetes, rutas, incidencias y HUB |
| `gerente` | Gerente de Logística | Login | Solo lectura: reportes, KPIs, costos |
| `conductor` | Conductor / Delivery | Login (móvil) | Únicamente su ruta y sus entregas |
| `destinatario` | Destinatario final | Sin login, enlace con token | Únicamente el seguimiento de su paquete |

`destinatario` es un perfil del RBAC pero **no existe en la base de datos**: no tiene usuario ni login (el `ENUM` `usuarios.rol` solo tiene `admin`, `despachador`, `gerente` y `conductor`). Su acceso es por el token del paquete.

## 2. Matriz de permisos por funcionalidad

C = crear, L = leer, E = editar, X = ejecutar acción, — = sin acceso, (propio) = solo sus propios datos.

| Funcionalidad | admin | despachador | gerente | conductor | destinatario |
|---|---|---|---|---|---|
| Usuarios y roles | C L E | — | — | — | — |
| Configuración del sistema | L E | — | — | — | — |
| Auditoría | L | — | L | — | — |
| Integraciones (claves, activar, probar) | L E X | — | — | — | — |
| Log de integraciones y webhooks | L X | — | — | — | — |
| Notificaciones (cola) | L X | L X | — | — | — |
| HUBs | C L E | L | L | — | — |
| Paquetes | C L E X | C L E X | L | L (propios de su ruta) | L (propio, por token) |
| Escaneo en HUB / clasificación | X | X | — | — | — |
| Etiquetas / guías | X | X | — | — | — |
| Incidencias | C L X | C L X | L | C (al fallar una entrega) | Reprogramar (propio, por token) |
| Vehículos y conductores | C L E | C L E | L | — | — |
| Zonas | C L E | C L E | L | — | — |
| Generar / confirmar / editar rutas | X | X | — | — | — |
| Reenrutar (recalcular, omitir parada, reasignar) | X | X | — | — | — |
| Ver ruta | L | L | L | L (propia) | — |
| Monitor de flota en vivo | L | L | L | — | — |
| Playback de recorridos | L | L | L | — | — |
| Enviar GPS | — | — | — | X (propia ruta) | — |
| Iniciar / finalizar ruta | — | — | — | X (propia) | — |
| Entregar / fallar / solicitar OTP | — | — | — | X (propia parada) | — |
| Ver firmas y fotos de entrega | L | L | L | L (propias) | — |
| Dashboard y KPIs | L X | — | L X | — | — |
| Portal de seguimiento | — | — | — | — | L (token) |

El despachador no accede al dashboard gerencial; si el grupo quiere cambiarlo, es una decisión de diseño (ver README, sección 5).

## 3. Cómo se aplica

1. **En el endpoint**: `api_run('POST', ['admin','despachador'], ...)` rechaza con `SIN_PERMISO` (403) a cualquier otro rol, y con `NO_AUTENTICADO` (401) si no hay sesión.
2. **En la pantalla**: `auth_requerir_pagina([...])` redirige al login o muestra "Sin permiso". Ocultar un botón en la interfaz **no** es seguridad; siempre se valida también en el servidor.
3. **Propiedad del recurso** (en el servicio): un conductor solo puede operar sobre una ruta cuyo `conductor_id` coincide con el suyo; si no, `SIN_PERMISO`. Se usa `auth_conductor_id()` para obtenerlo de la sesión, nunca de un parámetro enviado por el cliente.
4. **Portal por token**: el token identifica un paquete; nunca se acepta un `paquete_id` en los endpoints públicos.
5. **Mínimo privilegio en datos**: los endpoints públicos devuelven solo los campos listados en M5 (sin teléfonos, sin direcciones completas, sin nombres de conductores).

## 4. Menú por rol

Lo calcula `auth/me.php` y lo dibuja `navbar.php`.

| Rol | Opciones |
|---|---|
| admin | Usuarios, Auditoría, Configuración, Integraciones, Notificaciones, Paquetes, Rutas, Monitor, Dashboard |
| despachador | Monitor de flota, Rutas, Paquetes, Escaneo HUB, Incidencias, Conductores, Vehículos, Zonas, Notificaciones |
| gerente | Dashboard, Mapa de calor, Reportes, Playback, Auditoría |
| conductor | Mi ruta (una sola pantalla con las paradas) |
