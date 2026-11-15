# 08. Configuración, entorno local y despliegue

## 1. Requisitos

- PHP 8.1 o superior con las extensiones: `mysqli`, `mbstring`, `curl`, `json`, `openssl`, `fileinfo`, `gd` (opcional, para redimensionar fotos).
- MySQL 8 o MariaDB 10.5+.
- Apache con `mod_rewrite` (o Nginx con la regla equivalente). Paquete recomendado para desarrollo: **XAMPP** (Windows/Mac/Linux).
- Git y un editor. Navegador moderno (Chrome/Edge/Firefox; Safari para pruebas de la PWA en iPhone).

## 2. Puesta en marcha local (los 4 igual)

1. Clonar el repositorio en `htdocs/routepulse` (o configurar un `VirtualHost` cuyo `DocumentRoot` sea `routepulse/public`).
2. Copiar `config/config.example.php` a `config/config.php` y ajustar la conexión a la base de datos y `app.url`.
3. Crear la base `routepulse` (utf8mb4) e importar `database/schema.sql` y luego `database/seed.sql`.
4. Crear las carpetas `storage/uploads`, `storage/logs`, `storage/cache` con permiso de escritura para el servidor web.
5. Abrir `http://localhost/routepulse/public/login.php` (o la URL del VirtualHost) e iniciar sesión con `admin@routepulse.test` / `Dev12345!`.
6. Verificar que `storage/logs/app.log` queda vacío (sin avisos de PHP) después de navegar.

### Probar la app del conductor en un teléfono

El navegador solo entrega la ubicación y la cámara en **contextos seguros** (HTTPS o `localhost`). Un teléfono que abre `http://192.168.x.x` **no** podrá usar el GPS. Opciones:
- Usar un túnel HTTPS temporal (por ejemplo, `cloudflared tunnel` o `ngrok`) apuntando al servidor local.
- O probar en el escritorio con las herramientas de desarrollo del navegador (simular ubicación).
- O subir el proyecto a un hosting con certificado SSL (ver sección 6).

## 3. `config/config.php`

Arreglo PHP devuelto con `return [...]`. La estructura está en `config/config.example.php`. Claves:

| Clave | Uso | Obligatoria |
|---|---|---|
| `app.entorno` | `dev` o `prod`. En `prod` se ocultan errores y se exige firma en webhooks | Sí |
| `app.url` | URL pública sin barra final (se usa en los enlaces de seguimiento) | Sí |
| `app.clave_secreta` | Cadena aleatoria larga para firmar el OTP (HMAC) | Sí |
| `db.host`, `db.nombre`, `db.usuario`, `db.clave`, `db.puerto` | Conexión MySQLi | Sí |
| `storage.ruta` | Ruta absoluta a `storage/` | Sí |
| `twilio.sid`, `twilio.token`, `twilio.from_sms`, `twilio.from_whatsapp` | Mensajería real | Solo producción |
| `meta_whatsapp.token`, `meta_whatsapp.phone_id` | WhatsApp Business API | Opcional |
| `smtp.host`, `smtp.puerto`, `smtp.usuario`, `smtp.clave`, `smtp.desde` | Correo | Solo producción |
| `google_maps.api_key` | Si `mapas_proveedor = google` | Opcional |
| `osrm.base_url`, `nominatim.base_url`, `nominatim.contacto` | Rutas y geocodificación gratuitas (el contacto va en el `User-Agent`) | Opcional |
| `webhook_secretos.shopify`, `.woocommerce`, `.magento`, `.mercadolibre` | Secretos de firma de cada proveedor | Solo producción |

`config.php` **no se versiona**. Si alguien necesita una clave nueva, se agrega la clave vacía a `config.example.php` y se avisa por el grupo.

## 4. Modo simulado de las integraciones

Por defecto todas las integraciones tienen `modo_simulado = 1` (tabla `integraciones`). En este modo:
- `notificar()` marca el mensaje como `enviado` de inmediato, con `proveedor_mensaje_id = 'SIM-...'`, y lo escribe en `storage/logs/notificaciones_simuladas.log`.
- `geocodificar()` devuelve coordenadas deterministas calculadas a partir del texto de la dirección, dentro de ±0.03° del HUB.
- `ruta_matriz()` y `ruta_geometria()` usan distancias haversine × 1.3 y `eta_velocidad_media_kmh`.
- Los webhooks de e-commerce aceptan pedidos sin verificar la firma **solo si** `app.entorno = dev`.

Gracias a esto, los 4 trabajan sin claves de ningún proveedor.

## 5. Tareas programadas

En desarrollo se ejecutan a mano (`php cron/procesar_notificaciones.php`) cuando se necesite. En producción:

```
* * * * *    php /ruta/routepulse/cron/procesar_notificaciones.php
*/5 * * * *  php /ruta/routepulse/cron/reintentar_webhooks.php
* * * * *    php /ruta/routepulse/cron/recalcular_eta.php
15 3 * * *   php /ruta/routepulse/cron/limpiar_gps.php
```

En hosting compartido se configuran en el panel ("Tareas Cron"). Si el plan no permite cron cada minuto, M7 y M2 deben funcionar igual de forma degradada (la cola se procesa con retraso).

## 6. Despliegue (prototipo en la web)

Requisitos del hosting o VPS: PHP 8.1+, MySQL/MariaDB, HTTPS (certificado SSL, imprescindible para GPS y cámara), acceso a cron, `mod_rewrite`.

Pasos:
1. Crear la base de datos y un usuario propio para la aplicación. Importar `schema.sql` (en producción **no** se importa `seed.sql`, salvo en una instancia de demostración).
2. Subir los archivos por Git, SFTP o el administrador de archivos del hosting. `public/` es el `DocumentRoot` (o su contenido va a `public_html`, ver `02-estructura-proyecto.md`).
3. Crear `config/config.php` en el servidor con `app.entorno = prod`, la URL real y credenciales reales.
4. Crear `storage/` fuera de la carpeta pública con permiso de escritura solo para el usuario del servidor web.
5. Crear el usuario administrador real y cambiar las claves de las cuentas de prueba (o no importar el seed).
6. Activar HTTPS y forzar la redirección de HTTP a HTTPS.
7. Configurar los cron de la sección 5.
8. Cambiar las integraciones a `modo_simulado = 0` una por una, con sus claves en `config.php`, usando "Probar" en la pantalla de integraciones.
9. Configurar copias de seguridad automáticas de la base de datos y de `storage/uploads`.

## 7. Lista de seguridad para producción

- [ ] `app.entorno = prod`, `display_errors = Off`, `expose_php = Off`.
- [ ] HTTPS obligatorio; cookie de sesión con `Secure`.
- [ ] `config/`, `app/`, `database/`, `cron/`, `storage/` fuera del alcance web (o bloqueados con `.htaccess` `Require all denied`).
- [ ] Usuario de BD con permisos mínimos (ver `03-base-de-datos.md`, sección 6).
- [ ] Contraseñas de las cuentas de prueba cambiadas o eliminadas.
- [ ] `app.clave_secreta` generada con `bin2hex(random_bytes(32))`.
- [ ] Secretos de webhooks y API keys configurados y `modo_simulado = 0` solo donde corresponde.
- [ ] Copias de seguridad probadas (restaurar al menos una vez).
- [ ] Revisados los cabezales de seguridad (ver M8) con una herramienta de análisis.
- [ ] Límite de subida de PHP (`upload_max_filesize`, `post_max_size`) de al menos 12 MB.
