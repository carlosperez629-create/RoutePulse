# 02. Estructura del proyecto

## 1. Árbol de carpetas

```
routepulse/
├─ public/                         ← DocumentRoot del servidor web (lo único accesible por URL)
│  ├─ index.php                    redirige según el rol (M8)
│  ├─ login.php                    pantalla de acceso (M8)
│  ├─ cambiar_password.php         (M8)
│  ├─ t.php                        portal de seguimiento del cliente (M5); /t/{token} se reescribe aquí
│  ├─ .htaccess                    reescritura /t/{token} → t.php?t={token}; cabeceras de seguridad
│  ├─ api/
│  │  ├─ auth/ usuarios/ auditoria/ configuracion/        (M8)
│  │  ├─ paquetes/ incidencias/ hubs/                      (M3)
│  │  ├─ vehiculos/ conductores/ zonas/ rutas/             (M1)
│  │  ├─ gps/                                              (M2)
│  │  ├─ conductor/ archivos/                              (M4)
│  │  ├─ tracking/                                         (M5)
│  │  ├─ kpi/                                              (M6)
│  │  └─ integraciones/ notificaciones/                    (M7)
│  ├─ webhooks/                    shopify.php, woocommerce.php, magento.php, mercadolibre.php (M7)
│  ├─ admin/                       usuarios.php, auditoria.php, configuracion.php (M8, pantallas de Karoline)
│  │                               integraciones.php, notificaciones.php, webhooks.php, log_api.php (M7)
│  ├─ hub/                         paquetes.php, paquete_nuevo.php, paquete_detalle.php, escaneo.php,
│  │                               etiqueta.php, incidencias.php, hubs.php (M3)
│  ├─ despacho/                    vehiculos.php, conductores.php, zonas.php, rutas.php, ruta_detalle.php (M1)
│  ├─ monitor/                     flota.php, playback.php (M2)
│  ├─ conductor/                   index.php, parada.php, manifest.json, sw.js, offline.html (M4)
│  ├─ gerencia/                    dashboard.php, mapa_calor.php, reportes.php (M6)
│  └─ assets/
│     ├─ css/                      app.css, estados.css (colores de estado), <modulo>.css
│     ├─ js/                       api.js, ui.js (núcleo); gps_tracker.js (M2); m1/ m2/ ... por módulo
│     ├─ img/
│     └─ vendor/                   bootstrap, leaflet, leaflet-draw, leaflet-heat, chartjs, qrcodejs, jsbarcode, signature_pad
├─ app/
│  ├─ core/                        núcleo compartido (Carlos, Fase 0)
│  │  ├─ bootstrap.php  db.php  response.php  input.php  auth.php  csrf.php
│  │  ├─ config.php  logger.php  geo.php  layout.php  errores.php
│  ├─ services/                    funciones públicas de cada módulo (el contrato de 07-servicios-internos.md)
│  │  ├─ paquete_service.php        (M3)     ├─ zona_service.php, ruta_service.php, conductor_service.php (M1)
│  │  ├─ gps_service.php            (M2)     ├─ entrega_service.php, otp_service.php, archivo_service.php (M4)
│  │  ├─ tracking_service.php       (M5)     ├─ kpi_service.php (M6)
│  │  ├─ notificacion_service.php, integracion_service.php, geo_proveedores_service.php (M7)
│  │  └─ usuario_service.php, auditoria_service.php, rate_limit_service.php (M8)
│  ├─ views/partials/              header.php, navbar.php, footer.php, mensajes.php
│  └─ lib/                         librerías de terceros en PHP (phpmailer/)
├─ config/
│  ├─ config.example.php           plantilla versionada
│  └─ config.php                   NO versionado (claves y credenciales locales)
├─ cron/                           scripts de línea de comandos (ver abajo)
├─ database/                       schema.sql, seed.sql, migraciones/
├─ storage/                        NO accesible por URL, NO versionado
│  ├─ uploads/                     firmas y fotos (M4)
│  ├─ logs/                        app.log, notificaciones_simuladas.log, integraciones.log
│  └─ cache/                       rate_limit/ (contadores), geocode/ (caché de geocodificación)
├─ docs/                           esta especificación
└─ tests/                          casos de prueba manuales (copiar de 99-integracion/02-pruebas-e2e.md)
```

**Hosting compartido:** si el hosting solo ofrece una carpeta pública (`public_html`), el contenido de `public/` va dentro de `public_html/` y `app/`, `config/`, `cron/`, `database/` y `storage/` se colocan un nivel por encima, fuera del alcance web. Las rutas relativas de `bootstrap.php` se calculan desde `__DIR__`, así que no cambian.

## 2. Cómo es un endpoint

Todos los archivos de `public/api/**` siguen esta forma (ver detalle en `05-contrato-api.md` y `07-servicios-internos.md`):

1. `require` del `bootstrap.php`.
2. Una sola llamada a `api_run(<método>, <roles permitidos>, <función manejadora>)`.
3. La función manejadora valida con `validar()`, llama a servicios y **retorna los datos**; no imprime nada.

Los endpoints son delgados: la lógica de negocio vive en `app/services/`.

## 3. Cómo es una pantalla

Cada pantalla `public/<carpeta>/<pagina>.php`:
1. Hace `require` del `bootstrap.php`, llama `auth_requerir_pagina([...roles])` y `render_header('Título', 'módulo')`.
2. Imprime solo la estructura HTML (Bootstrap) y los contenedores vacíos; los datos se cargan por AJAX desde `assets/js/<modulo>/<pagina>.js`.
3. Termina con `render_footer([...scripts])`.

## 4. Mapa de pantallas por rol (menú)

| Rol | Pantallas visibles |
|---|---|
| admin | Todas las de `admin/`, y lectura de `hub/`, `despacho/`, `monitor/`, `gerencia/` |
| despachador | `monitor/flota.php`, `monitor/playback.php`, `despacho/*`, `hub/*` |
| gerente | `gerencia/*`, `monitor/playback.php` (solo lectura) |
| conductor | `conductor/*` únicamente |
| destinatario | `t/{token}` únicamente (sin sesión) |

Redirección después del login: admin → `/admin/usuarios.php`; despachador → `/monitor/flota.php`; gerente → `/gerencia/dashboard.php`; conductor → `/conductor/`.

## 5. Scripts programados (`cron/`)

| Script | Dueño | Frecuencia | Qué hace |
|---|---|---|---|
| `procesar_notificaciones.php` | M7 | cada minuto | Envía notificaciones pendientes y reintenta las fallidas |
| `reintentar_webhooks.php` | M7 | cada 5 min | Reprocesa eventos de webhook en estado `error` (máx. 3 intentos) |
| `recalcular_eta.php` | M2 | cada minuto | Recalcula el ETA de rutas en curso sin puntos GPS recientes |
| `limpiar_gps.php` | M2 | diario | Borra puntos GPS más viejos que `gps_retencion_dias` (única excepción a "solo inserción", con usuario de BD de mantenimiento) |

Los scripts de `cron/` se ejecutan por línea de comandos (`php cron/archivo.php`) y se niegan a ejecutarse desde el navegador.

## 6. Propiedad de carpetas

Cada persona solo modifica archivos de sus módulos. Los archivos de `app/core/`, `public/assets/js/api.js`, `ui.js` y `app/views/partials/` son de Carlos; cualquier cambio ahí se propone por pull request. Las pantallas `admin/usuarios.php`, `admin/auditoria.php` y `admin/configuracion.php` son de Karoline (los endpoints que usan son de Carlos).
