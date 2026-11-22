# RoutePulse

**Sistema de seguimiento en tiempo real y control de envíos (última milla).**

RoutePulse es una plataforma web que funciona como torre de control de la distribución de última milla y la logística urbana. Centraliza el recorrido completo de un envío: llega el pedido, se clasifica en un HUB, se asigna a una ruta optimizada, el conductor lo entrega con prueba digital (firma, foto geoetiquetada y código OTP) y el cliente lo sigue con un enlace único y recibe avisos por WhatsApp, SMS o correo. La gerencia consulta un dashboard con indicadores (OTIF, FTDR, tiempo de parada y costos).

Proyecto del **Grupo No. 4**.

---

## Tecnologías

| Área | Tecnología |
|---|---|
| Backend | PHP 8.1+ (procedural, sin framework y sin Composer) |
| Base de datos | MySQL 8 / MariaDB 10.5+ con MySQLi (consultas preparadas) |
| Frontend | HTML5, CSS con Bootstrap 5.3, AJAX con `fetch` |
| Mapas y gráficos | Leaflet (+ Leaflet.draw y Leaflet.heat), Chart.js |
| Etiquetas y firma | qrcode.js, JsBarcode (GS1-128), signature_pad |
| Correo | PHPMailer |

Las librerías están copiadas en el repositorio (no se usan CDN): las de JavaScript y CSS en `public/assets/vendor/` y PHPMailer en `app/lib/phpmailer/`.

## Equipo y módulos

| Integrante | Módulos |
|---|---|
| **Carlos** | Núcleo (`app/core`) + M8 Seguridad y usuarios + M7 Integraciones |
| **Juan** | M3 Trazabilidad y HUBs + M1 Despacho y rutas |
| **Guanda** | M2 Rastreo GPS + M4 App del conductor |
| **Karoline** | M5 Portal del cliente + M6 Dashboard + pantallas de administración de M8 |

Los 8 módulos: M1 Despacho y optimización de rutas · M2 Rastreo GPS y telemetría · M3 Trazabilidad de envíos y control de HUBs · M4 App del conductor (PWA) · M5 Portal del cliente (tracking) · M6 Dashboard de analítica · M7 Integraciones (APIs y webhooks) · M8 Seguridad y usuarios (RBAC).

## Estructura del repositorio

```
routepulse/
├─ public/        DocumentRoot: pantallas, endpoints (api/), webhooks y assets
├─ app/           núcleo (core/), servicios (services/), vistas y librerías PHP (lib/)
├─ config/        config.example.php (plantilla). config.php NO se sube
├─ cron/          scripts programados
├─ database/      schema.sql, seed.sql y migraciones/
├─ storage/       uploads, logs y cache (NO se sube su contenido)
├─ docs/          especificación completa del proyecto
├─ tests/         notas y resultados de pruebas
├─ .gitignore
└─ README.md
```

El árbol completo, con el responsable de cada carpeta, está en `docs/ESTRUCTURA-CARPETAS.txt`.

## Documentación

Toda la especificación está en `docs/`. **Leer antes de programar**; si algo no está ahí, se pregunta al grupo y se agrega a la documentación.

| Documento | Contenido |
|---|---|
| `docs/README.md` | Índice de la especificación, reparto, fases y decisiones de diseño |
| `docs/00-base/` | Convenciones, estructura, base de datos, estados, contrato de la API, permisos, servicios entre módulos, entorno e interfaz |
| `docs/modulos/` | Especificación de los módulos M1 a M8 y `GUIA-KAROLINE.md` |
| `docs/99-integracion/` | Dependencias, orden de integración, pruebas de punta a punta y definición de terminado |
| `docs/instrucciones-ia/` | Un archivo por integrante para la IA que use |

## Instalación local

**Requisitos:** PHP 8.1+ (extensiones `mysqli`, `mbstring`, `curl`, `json`, `openssl`, `fileinfo`), MySQL o MariaDB, Apache con `mod_rewrite` (se recomienda XAMPP) y Git.

1. Clonar el repositorio en `htdocs/routepulse` (o configurar un VirtualHost cuyo `DocumentRoot` sea `routepulse/public`).
2. Copiar `config/config.example.php` a `config/config.php` y ajustar la conexión a la base de datos y `app.url`.
3. Crear la base de datos `routepulse` (`utf8mb4`) e importar, en este orden, `database/schema.sql` y `database/seed.sql`.
4. Verificar que existen `storage/uploads`, `storage/logs` y `storage/cache` con permiso de escritura.
5. Abrir `login.php` e iniciar sesión.

Para probar la app del conductor en un teléfono hace falta HTTPS (el navegador solo da GPS y cámara en contextos seguros). Detalles en `docs/00-base/08-configuracion-y-entorno.md`.

### Roles, credenciales e interfaces

Todos los roles con login entran por `login.php`. El rol `destinatario` no tiene usuario ni contraseña: accede solo con el enlace único de su paquete.

**Credenciales de prueba**

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador (`admin`) | `admin@routepulse.test` | `Prueba12345` |
| Despachador (`despachador`) | `despachador@routepulse.test` | `Dev12345!` |
| Gerente (`gerente`) | `gerente@routepulse.test` | `Dev12345!` |
| Conductor ruta 1 (`conductor`) | `conductor1@routepulse.test` | `Dev12345!` |
| Conductor ruta 2 (`conductor`) | `conductor2@routepulse.test` | `Dev12345!` |
| Destinatario (`destinatario`) | Sin login | Sin contraseña; usar `/t/00000000000000000000000000000007` |

> En el ZIP de repositorio todas las cuentas de prueba usan `Dev12345!` porque vienen de `seed.sql`. En el ZIP privado, el administrador puede tener otra contraseña: el hash no se puede revertir. Las demás cuentas de prueba conservan `Dev12345!`.

**Qué hace cada rol y a qué interfaces accede**

Todas las rutas son relativas a `app.url`.

| Rol | Qué hace | Interfaces principales |
|---|---|---|
| `admin` | Administra usuarios, roles, configuración e integraciones; tiene lectura y control de toda la operación. | `admin/usuarios.php`, `admin/auditoria.php`, `admin/configuracion.php`, `admin/integraciones.php`, `admin/notificaciones.php`, `hub/paquetes.php`, `hub/paquete_nuevo.php`, `hub/paquete_detalle.php`, `hub/escaneo.php`, `hub/etiqueta.php`, `hub/incidencias.php`, `hub/hubs.php`, `despacho/rutas.php`, `despacho/ruta_detalle.php`, `despacho/conductores.php`, `despacho/vehiculos.php`, `despacho/zonas.php`, `monitor/flota.php`, `monitor/playback.php`, `gerencia/dashboard.php`, `gerencia/mapa_calor.php`, `gerencia/reportes.php`, `cambiar_password.php` |
| `despachador` | Opera el HUB y la última milla: paquetes, clasificación, rutas, incidencias, conductores, vehículos, zonas, notificaciones y monitor en vivo. | `monitor/flota.php`, `monitor/playback.php`, `despacho/rutas.php`, `despacho/ruta_detalle.php`, `despacho/conductores.php`, `despacho/vehiculos.php`, `despacho/zonas.php`, `hub/paquetes.php`, `hub/paquete_nuevo.php`, `hub/paquete_detalle.php`, `hub/escaneo.php`, `hub/etiqueta.php`, `hub/incidencias.php`, `hub/hubs.php` (solo lectura), `admin/notificaciones.php`, `cambiar_password.php` |
| `gerente` | Consulta indicadores, reportes, costos, mapa de calor, playback, monitor y auditoría; no edita la operación. | `gerencia/dashboard.php`, `gerencia/mapa_calor.php`, `gerencia/reportes.php`, `monitor/flota.php`, `monitor/playback.php`, `admin/auditoria.php`, `hub/paquetes.php`, `hub/paquete_detalle.php`, `hub/incidencias.php`, `hub/hubs.php`, `despacho/rutas.php`, `despacho/ruta_detalle.php`, `cambiar_password.php`; `admin/notificaciones.php` solo por URL directa |
| `conductor` | Usa la PWA móvil para ver solo su ruta, iniciar/finalizar, llegar a paradas, solicitar OTP, registrar entrega o fallo, subir firma/foto y enviar GPS. | `conductor/` (Mi ruta), `conductor/parada.php`, `cambiar_password.php` |
| `destinatario` | Consulta el estado de su propio paquete y puede reprogramar la entrega; no ve datos internos ni de otros paquetes. | Portal público `/t/<token>` |

> Los usuarios y contraseñas de prueba son solo para desarrollo. En producción no se importa `seed.sql` o se cambian las cuentas. El administrador no puede quitarse a sí mismo el rol `admin` ni desactivarse.

## Base de datos

El esquema tiene **23 tablas** (`database/schema.sql` es la fuente de verdad). Cada tabla tiene un módulo dueño y solo ese módulo escribe en ella, mediante su servicio. Los cambios posteriores al esquema se hacen con un archivo numerado en `database/migraciones/` y se anotan en `database/migraciones/REGISTRO.md`. No se agregan tablas sin acuerdo de los 4.

## Flujo de trabajo con Git

**Ramas**

| Rama | Uso |
|---|---|
| `main` | Versión estable. **Integración final de hitos.** |
| `carlos` | Integración individual de módulos asignados (M7, M8, core). |
| `karoline` | Integración individual de módulos asignados (M5, M6, admin M8). |
| `juan` | Integración individual de módulos asignados (M3, M1). |
| `guanda` | Integración individual de módulos asignados (M2, M4). |
| `feature/m<N>-<descripcion>` | Una rama por tarea, por ejemplo `feature/m3-paquete-crear` |

**Ciclo de cada tarea**

```bash
git checkout juan                            # rama personal (ejemplo Juan para M3)
git pull origin juan                         # traer lo último
git checkout -b feature/m3-paquete-crear     # rama nueva
# ... programar y probar ...
git add .
git commit -m "[M3] Agrega paquete_crear con guía única"
git push -u origin feature/m3-paquete-crear
```

Después, en GitHub se abre un **pull request hacia su rama personal** (`carlos`, `karoline`, `juan`, `guanda`), lo revisa otra persona y, al aprobarse, se hace *merge* y se borra la rama. Todos actualizan con `git pull origin` de su rama personal (`carlos`, `karoline`, `juan`, `guanda`).

**Revisión de código (rotación)**

| Quien programa | Quien revisa |
|---|---|
| Karoline | Carlos (y Juan las consultas SQL de M6) |
| Carlos | Guanda |
| Juan | Carlos |
| Guanda | Juan |

**Reglas**

- Nadie sube directo a `main` ni a las ramas personales (`carlos`, `karoline`, `juan`, `guanda`).
- Una tarea = una rama = un pull request pequeño.
- Hacer `git pull origin` de tu rama personal (`carlos`, `karoline`, `juan`, `guanda`) y de `main` antes de empezar a trabajar cada día.
- Mensajes de commit: `[M3] Mensaje corto` (módulo entre corchetes).
- Nunca se suben claves, contraseñas ni `config/config.php`.
- Los archivos de `app/core/` y `database/schema.sql` son de Carlos; los cambios se piden por pull request.
- Un cambio de base de datos lleva su migración y el `schema.sql` actualizado **en el mismo pull request**.

## Convenciones clave

- Código, tablas, columnas, claves JSON y funciones en **español `snake_case`**, sin tildes ni ñ. Textos para el usuario en español con tildes.
- Fechas en **UTC** en la base de datos y en la API; la pantalla las convierte a la zona horaria configurada.
- SQL **siempre con consultas preparadas** (`db_all`, `db_one`, `db_val`, `db_exec`, `db_insert`); nunca concatenar variables.
- Los endpoints usan `api_run(...)` y responden `{ok, data}` o `{ok, error}`.
- El estado de un paquete solo cambia con `paquete_cambiar_estado()`.
- Los datos externos se insertan en el DOM con `textContent`, nunca con `innerHTML`.

El detalle completo está en `docs/00-base/01-convenciones.md`.

## Definición de terminado

Un módulo está terminado cuando cumple el checklist de `docs/99-integracion/03-definicion-de-terminado.md`, sus criterios de aceptación están probados con los datos del seed y su pull request fue aprobado.
