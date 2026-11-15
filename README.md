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

### Usuarios de prueba (clave de todos: `Dev12345!`)

| Rol | Correo |
|---|---|
| Administrador | admin@routepulse.test |
| Despachador | despachador@routepulse.test |
| Gerente | gerente@routepulse.test |
| Conductor (ruta 1 en curso) | conductor1@routepulse.test |
| Conductor (ruta 2 planificada) | conductor2@routepulse.test |

Enlace de seguimiento de prueba: `/t/00000000000000000000000000000007`.

> Los usuarios y la clave de prueba son solo para desarrollo. En producción no se importa `seed.sql` o se cambian las cuentas.

## Base de datos

El esquema tiene **23 tablas** (`database/schema.sql` es la fuente de verdad). Cada tabla tiene un módulo dueño y solo ese módulo escribe en ella, mediante su servicio. Los cambios posteriores al esquema se hacen con un archivo numerado en `database/migraciones/` y se anotan en `database/migraciones/REGISTRO.md`. No se agregan tablas sin acuerdo de los 4.

## Flujo de trabajo con Git

**Ramas**

| Rama | Uso |
|---|---|
| `main` | Versión estable e integración del trabajo de todos. Es la única rama compartida |
| `carlos` | Rama personal de Carlos (núcleo, M8 Seguridad y usuarios, M7 Integraciones) |
| `karoline` | Rama personal de Karoline (M5 Portal del cliente, M6 Dashboard, administración de M8) |
| `juan` | Rama personal de Juan (M3 Trazabilidad y HUBs, M1 Despacho y rutas) |
| `guanda` | Rama personal de Guanda (M2 Rastreo GPS, M4 App del conductor) |

Cada integrante programa y prueba **únicamente en su rama personal**. No se crean ramas por tarea ni se sube directo a `main`.

**Ciclo de cada tarea**

```bash
git checkout carlos                          # ubicarse en la rama personal
git pull origin carlos                       # traer lo último de la rama propia
# ... programar y probar ...
git add .
git commit -m "[M3] Agrega paquete_crear con guía única"
git push origin carlos

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
