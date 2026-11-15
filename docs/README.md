# RoutePulse: paquete de especificación para desarrollo en paralelo

Versión 1.0. Este paquete define TODO lo que los 4 programadores necesitan para empezar a programar bajo los mismos criterios: base de datos, convenciones, contrato de API, estados, permisos, servicios internos y la especificación detallada de los 8 módulos.

Regla de oro: **si algo no está en estos documentos, se pregunta y se agrega aquí antes de programarlo**. Nadie inventa nombres de tablas, campos, rutas ni formatos por su cuenta.

## 1. Tecnologías (fijas)

PHP 8.1+ (procedural, sin framework), MySQL 8 / MariaDB 10.5+ con MySQLi, HTML5, CSS con Bootstrap 5.3, AJAX con `fetch`.
Librerías JavaScript permitidas (necesarias para mapas, gráficos y modo offline): Leaflet 1.9 + Leaflet.draw + Leaflet.heat, Chart.js 4, qrcode.js, JsBarcode, signature_pad. En PHP: solo PHPMailer 6.9 (copiado a `app/lib/`). No se usa Composer ni frameworks.

## 2. Contenido del paquete

| Ruta | Qué contiene |
|---|---|
| `instrucciones-ia/` | Un archivo `.txt` por integrante (`IA-CARLOS`, `IA-JUAN`, `IA-GUANDA`, `IA-KAROLINE`) para pegarle a la IA que usen: explica el proyecto, lo que le toca a cada uno y cómo empezar. Empezar por `LEEME.txt` |
| `ESTRUCTURA-CARPETAS.txt` | Árbol de carpetas del proyecto en texto plano, con el responsable de cada carpeta |
| `00-base/01-convenciones.md` | Nombres, estilo de código, Git, revisión de código |
| `00-base/02-estructura-proyecto.md` | Árbol de carpetas, pantallas y URLs, quién es dueño de cada carpeta |
| `00-base/03-base-de-datos.md` | Dueño de cada tabla, relaciones y reglas de datos |
| `00-base/04-estados-y-reglas.md` | Estados de paquete, ruta, parada; transiciones; disparadores de notificación; formatos de códigos |
| `00-base/05-contrato-api.md` | Formato de requests y respuestas, errores, CSRF, paginación y catálogo completo de endpoints |
| `00-base/06-roles-y-permisos.md` | Matriz de permisos por rol |
| `00-base/07-servicios-internos.md` | Funciones del núcleo y funciones que cada módulo ofrece a los demás (firmas exactas) |
| `00-base/08-configuracion-y-entorno.md` | Instalación local, `config.php`, cron, despliegue, seguridad de producción |
| `00-base/09-guia-interfaz.md` | Reglas de interfaz: Bootstrap, colores de estado, formatos, mensajes |
| `modulos/M1…M8` | Especificación detallada de cada módulo |
| `modulos/GUIA-KAROLINE.md` | Guía paso a paso de las tareas de Karoline, de lo simple a lo complejo |
| `99-integracion/` | Dependencias, orden de integración, pruebas de punta a punta, definición de terminado |
| `database/schema.sql` | Esquema completo, **23 tablas** (fuente de verdad; ver sección 8) |
| `database/seed.sql` | Datos de prueba comunes (usuarios, flota, zonas, paquetes en todos los estados, una ruta en curso) |

## 3. Reparto de módulos según el perfil de cada integrante

Criterios usados: (1) los módulos de los que dependen todos los demás van a quien tiene más experiencia y se hacen primero; (2) quien hace un módulo hace también los que están pegados a él (comparten tablas, estados o código); (3) la persona principiante empieza por módulos de **bajo riesgo** (solo lectura o pantallas con contrato ya definido), donde un error no bloquea a nadie, y avanza de lo simple a lo complejo.

| Integrante | Nivel | Módulos | Por qué |
|---|---|---|---|
| **Carlos** | Intermedio | Núcleo (`app/core`) + **M8** Seguridad y usuarios (servicios y endpoints) + **M7** Integraciones | Es la base de la que dependen todos. El núcleo y M8 etapa A se hacen primero; M7 arranca en modo simulado y los proveedores reales se dejan para el final |
| **Juan** | Intermedio | **M3** Trazabilidad y HUBs + **M1** Despacho y rutas | Comparten paquetes, paradas y estados. M3 (`paquete_cambiar_estado`) debe quedar listo primero porque M1 y M4 lo usan; M1 tiene el algoritmo de rutas |
| **Guanda** | Intermedio | **M2** Rastreo GPS + **M4** App del conductor | Comparten el envío de ubicación (`gps_tracker.js`) y la parada actual. M4 incluye el modo offline, la parte más exigente del lado del navegador |
| **Karoline** | Principiante | **M5** Portal del cliente + **M6** Dashboard + pantallas de administración de **M8** | Pantallas con el contrato de API ya definido, de solo lectura o de bajo riesgo: aprende el patrón `api()`, validación y consultas SQL con acompañamiento. Sus módulos no están en el camino crítico |

Carga: Carlos tiene 3 áreas pero muy concentradas al inicio (núcleo y M8) y con M7 real al final; Juan y Guanda tienen 2 módulos cada uno; Karoline tiene 3 piezas pequeñas ordenadas de más simple a más compleja.

### Orden de trabajo de cada persona

- **Carlos**: núcleo (Fase 0) → M8 etapa A → M7 modo simulado (`notificar`, `geocodificar`, `ruta_matriz`, `ruta_geometria`) → M8 etapa B → M7 webhooks y cola de notificaciones → proveedores reales.
- **Juan**: `paquete_crear` y `paquete_cambiar_estado` (M3) → resto de M3 (escaneo, etiqueta, incidencias) → catálogos y zonas de M1 → `ruta_generar` y `ruta_confirmar` → edición, reenrutamiento y cancelaciones.
- **Guanda**: `mapa_util.js` y `gps_tracker.js` → `gps/enviar` y monitor de flota (M2) → M4 sin offline (mi ruta, iniciar, llegada, entregar, fallar, finalizar) → ETA y geocerca → modo offline (PWA) → playback.
- **Karoline**: entorno y lectura → pantallas de administración de M8 → M5 Portal → M6 Dashboard. Detalle en `modulos/GUIA-KAROLINE.md`.

### Revisión de código (rotación)

Todo pull request lo revisa otra persona, según esta tabla:

| Quien programa | Quien revisa |
|---|---|
| Karoline | Carlos (y Juan, además, las consultas SQL de M6) |
| Carlos | Guanda |
| Juan | Carlos |
| Guanda | Juan |

### Acompañamiento a Karoline

- **Carlos**: sesión corta al inicio (30 a 45 minutos) para explicar el patrón de un endpoint (`api_run`, `validar`), el helper `api()` y las pantallas con `render_header`; responde dudas de M8.
- **Juan**: apoya las consultas SQL de M6 (conoce las tablas de paquetes, rutas y paradas).
- **Guanda**: apoya el mapa (Leaflet con `mapa_util.js`) de M5 y M6.
- Regla de ayuda: si pasan 45 minutos atascada en lo mismo, se pregunta. Los pull requests de Karoline son pequeños (una pantalla o un endpoint por vez) para revisarse rápido.

## 4. Plan de trabajo en fases

**Fase 0: base común (la hace Carlos, los demás la revisan).** Entregables, en este orden:
1. Repositorio creado con la estructura de `02-estructura-proyecto.md`.
2. `schema.sql` y `seed.sql` importados sin errores en las 4 máquinas.
3. `app/core/*` con las funciones exactas de `07-servicios-internos.md` (sección A): conexión, `api_run`, validación, sesión, CSRF, respuestas, logger.
4. Login funcionando con los usuarios del seed y `layout` compartido (cabecera, menú por rol, pie).
5. Cada persona publica en su archivo de `app/services/` las **firmas** de sus funciones del contrato, con cuerpos mínimos que devuelvan datos coherentes con el seed (así nadie queda bloqueado esperando a otro).

**Hito 0** = los 4 pueden iniciar sesión, llamar a `GET /api/auth/me.php` y ver el menú de su rol.

**Fase 1: módulos en paralelo.** Cada persona implementa sus módulos contra el seed. Las integraciones externas están en modo simulado (ver M7), así que nadie necesita claves de API.

**Fase 2: integración por parejas.** Orden en `99-integracion/01-dependencias-y-orden.md`.

**Fase 3: pruebas de punta a punta** con `99-integracion/02-pruebas-e2e.md` y corrección de errores.

**Fase 4: despliegue** según `08-configuracion-y-entorno.md`.

## 5. Decisiones de diseño tomadas en esta especificación

Estas decisiones completan lo que el documento original no dejaba definido. Cámbienlas **ahora, antes de programar**, si el grupo no está de acuerdo; después de la Fase 0 cualquier cambio exige acuerdo de los 4.

1. Se agrega el rol `admin` (gestiona usuarios, integraciones y configuración). El documento original solo menciona 4 perfiles; el destinatario no tiene login y entra por enlace con token.
2. No hay rol "operador de HUB": el despachador hace el ingreso y la clasificación de paquetes en el HUB.
3. Tiempo real = polling con AJAX (cada 5 s en el monitor, cada 10 s en el portal). No hay WebSockets ni MQTT porque PHP con Apache no los mantiene.
4. La optimización de rutas es heurística propia (vecino más cercano + 2-opt), no un solver VRP exacto.
5. La app del conductor es una PWA (web móvil instalable) con Service Worker e IndexedDB para el modo offline. Solo rastrea la ubicación con la página abierta en primer plano.
6. Todas las fechas se guardan en UTC y se muestran en la zona horaria configurada (`zona_horaria_ui`).
7. El OTP es obligatorio al entregar con conexión (`otp_obligatorio=1`); sin conexión se permite entregar sin OTP, marcado como `otp_validado=0`.
8. "Menos de 3 paradas" del portal se define como: el cliente ve el vehículo en vivo cuando hay a lo sumo 3 paradas sin resolver antes de la suya (`portal_paradas_en_vivo`).
9. Las integraciones externas (Twilio, WhatsApp, SMTP, mapas, e-commerce) arrancan en **modo simulado**. El paso a proveedores reales se hace al final, solo con cambiar `modo_simulado` y poner claves en `config.php`.
10. Las coordenadas del seed son de un centro ficticio de pruebas; el grupo puede cambiarlas por las de su ciudad (instrucciones en el encabezado de `seed.sql`).
11. La base de datos tiene **23 tablas** (el diseño inicial tenía 31). Se quitaron 5 tablas de apoyo y se fusionaron 3 en otras; el detalle está en la sección 8.

## 6. Glosario

- **HUB**: centro de distribución donde se recibe y clasifica la mercancía.
- **Parada**: un punto de entrega dentro de una ruta (un paquete = una parada).
- **VRP**: problema de ruteo de vehículos.
- **ETA**: hora estimada de llegada.
- **Geocerca**: radio alrededor de un punto que dispara un evento al entrar.
- **ePoD**: prueba de entrega electrónica (firma, foto geoetiquetada, OTP).
- **OTP**: código de un solo uso enviado al receptor.
- **OTIF**: entregas a tiempo y completas sobre el total despachado.
- **FTDR**: porcentaje de entregas exitosas al primer intento.
- **Dwell time**: tiempo que el conductor permanece en cada parada.
- **RBAC**: control de acceso por roles.

## 7. Usuarios de prueba (clave de todos: `Dev12345!`)

| Rol | Correo |
|---|---|
| admin | admin@routepulse.test |
| despachador | despachador@routepulse.test |
| gerente | gerente@routepulse.test |
| conductor (ruta 1, en curso) | conductor1@routepulse.test |
| conductor (ruta 2, planificada) | conductor2@routepulse.test |

Enlace de seguimiento de prueba: `/t/00000000000000000000000000000007` (paquete 7, en tránsito).

## 8. Base de datos: 23 tablas

El esquema final (`database/schema.sql`, versión 1.1) tiene **23 tablas**. El diseño inicial tenía 31; se redujo **antes de empezar a programar**, quitando 5 tablas de apoyo y fusionando 3 en otras, sin perder ninguna función pedida en el documento del proyecto. `seed.sql` ya está ajustado a las 23 tablas.

### Las 23 tablas, por módulo dueño

| Módulo | Tablas | Cantidad |
|---|---|---|
| M8 Seguridad | `usuarios`, `log_auditoria`, `configuracion` | 3 |
| M1 Despacho | `zonas`, `zona_codigos_postales`, `vehiculos`, `conductores`, `zona_conductor`, `rutas`, `paradas` | 7 |
| M7 Integraciones | `integraciones`, `webhook_eventos`, `plantillas_notificacion`, `notificaciones` | 4 |
| M3 Trazabilidad | `hubs`, `paquetes`, `historial_estados`, `incidencias` | 4 |
| M4 App del conductor | `archivos`, `entregas` | 2 |
| M2 Rastreo GPS | `puntos_gps`, `ultima_posicion`, `eventos_geocerca` | 3 |
| M5 Portal, M6 Dashboard | (sin tablas propias) | 0 |
| **Total** | | **23** |

### Tablas quitadas (5) y qué las reemplaza

| Tabla quitada | Reemplazo |
|---|---|
| `kpi_diario` | Los KPIs se calculan en vivo con SQL (M6). Si hiciera falta una caché, se agrega por migración |
| `schema_migraciones` | Registro en archivo: `database/migraciones/REGISTRO.md` |
| `geocache` | Caché en archivos: `storage/cache/geocode/` |
| `log_api` | Bitácora en archivo: `storage/logs/integraciones.log` (una línea JSON por llamada, pantalla `admin/log_api.php`) |
| `rate_limit` | Contadores en archivos: `storage/cache/rate_limit/` (el bloqueo de cuenta del login sigue en `usuarios`) |

### Tablas fusionadas (3)

| Tabla fusionada | Dónde quedó |
|---|---|
| `tokens_seguimiento` | Columnas `token_seguimiento` (única) y `token_expira_at` de `paquetes`. Las escribe solo M3 y las lee M5 |
| `otp_codigos` | Columnas `otp_hash`, `otp_expira_at`, `otp_intentos`, `otp_solicitudes` y `otp_ventana_at` de `paquetes`. Las escribe solo M4. Un solo OTP vigente por paquete |
| `roles` | `usuarios.rol` es un `ENUM` (`admin`, `despachador`, `gerente`, `conductor`). El destinatario no tiene usuario: entra por el token |

### Qué se pierde con esta reducción (aceptado)

- No queda historial de los OTP anteriores de un paquete (solo el vigente).
- La bitácora de llamadas a proveedores es un archivo: se consulta por las últimas líneas, sin filtros por fecha.
- El nombre y la descripción de los roles ya no están en la base de datos (están en `00-base/06-roles-y-permisos.md`).
- Los límites de peticiones y la caché de geocodificación necesitan que `storage/cache/` sea escribible (ya es requisito del proyecto).

Regla: **no se agregan tablas nuevas sin acuerdo de los 4** (cambio de contrato, ver `00-base/01-convenciones.md`, sección 7). Los documentos de `00-base/` y `modulos/` ya reflejan este esquema.
