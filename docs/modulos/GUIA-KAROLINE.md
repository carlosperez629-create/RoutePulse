# Guía de trabajo de Karoline (paso a paso)

Karoline hace tres piezas: las **pantallas de administración de M8**, el **portal del cliente (M5)** y el **dashboard (M6)**. Están ordenadas de lo más simple a lo más complejo, y ninguna está en el camino crítico: un retraso o un error aquí no bloquea el trabajo de los demás.

Cada etapa dice qué se construye, qué se aprende y cómo saber que quedó bien. Se avanza a la siguiente cuando la anterior pasó su revisión.

## Reglas de trabajo

1. **Pull requests pequeños**: una pantalla o un endpoint por vez. Revisa Carlos (y Juan las consultas SQL de M6).
2. **45 minutos**: si llevas ese tiempo atascada en lo mismo, pregunta. No es una molestia, está previsto.
3. **No se editan** los archivos de `app/core/`, ni tablas de otros módulos. Si algo del núcleo no funciona como dice la documentación, se avisa a Carlos.
4. **No se copia código sin entenderlo.** Si algo se toma de un ejemplo, se puede explicar línea por línea en la revisión.
5. **Seguridad básica, siempre** (los revisores la verifican en cada pull request):
   - Toda consulta SQL con los helpers `db_all`, `db_one`, `db_val`, `db_exec` y parámetros; nunca variables pegadas dentro del texto SQL.
   - Todo texto que viene de la base de datos y se imprime en HTML pasa por `e()` (PHP) o se inserta con `textContent` (JavaScript).
   - Todo dato recibido se valida con `validar()`.
6. **Si el endpoint de otra persona aún no existe**, trabaja con una respuesta de ejemplo copiada del documento del módulo (un archivo `.json` local) y cambia a la API real cuando esté lista. El formato ya está definido, así que el cambio es mínimo.

---

## Etapa 0. Entorno y lectura (antes de programar)

**Qué hacer**
1. Instalar XAMPP, clonar el repositorio, crear `config/config.php`, importar `schema.sql` y `seed.sql`, e iniciar sesión (`08-configuracion-y-entorno.md`, secciones 1 y 2).
2. Leer, en este orden: `README.md`, `00-base/01-convenciones.md`, `00-base/05-contrato-api.md` (secciones 1 a 4) y `00-base/09-guia-interfaz.md`.
3. Cuando Carlos termine la Fase 0, recorrer con él un ejemplo completo: la pantalla de login y `auth/me.php`. Seguir el camino **pantalla → JavaScript → `api()` → `api_run` → servicio → base de datos → respuesta**.
4. Abrir la base de datos (phpMyAdmin) y mirar las tablas `usuarios`, `paquetes`, `rutas`, `paradas` y `entregas` con los datos del seed.

**Cómo saber que quedó bien:** puede iniciar sesión con los 5 usuarios, ve los datos del seed en phpMyAdmin y puede explicar con sus palabras qué hace `api_run`.

---

## Etapa 1. Pantallas de administración de M8

Carlos hace los endpoints (`usuarios/*`, `auditoria/*`, `configuracion/*`) y Karoline las pantallas que los usan. Es el mejor lugar para aprender el patrón que se repetirá en todo el proyecto.

| Tarea | Pantalla | Qué se construye |
|---|---|---|
| 1.1 | `admin/usuarios.php` | Tabla paginada con los usuarios (nombre, correo, rol, activo, último acceso, bloqueado) usando `GET usuarios/listar.php` |
| 1.2 | `admin/usuarios.php` | Ventana (modal) para **crear** un usuario con `POST usuarios/crear.php`. Mostrar los errores de `campos` debajo de cada campo; mostrar la contraseña temporal una sola vez |
| 1.3 | `admin/usuarios.php` | Editar, activar/desactivar, desbloquear y resetear contraseña, con confirmación en las acciones delicadas |
| 1.4 | `admin/configuracion.php` | Tabla de parámetros agrupados por módulo, con edición en línea y mensaje de error si el valor no es válido |
| 1.5 | `admin/auditoria.php` | Tabla con filtros (usuario, acción, entidad, fechas) y panel que muestra el detalle JSON |

**Qué se aprende:** `render_header` y `render_footer`, el helper `api()`, tablas paginadas con el `meta` de la API, formularios con validación de servidor, mensajes de éxito y error, y a leer el contrato de un endpoint.

**Cómo saber que quedó bien:** cada pantalla cumple los puntos de `09-guia-interfaz.md` (carga, errores, confirmaciones, formatos de fecha) y un conductor que abre la URL recibe "Sin permiso".

---

## Etapa 2. M5. Portal del cliente

Documento: `modulos/M5-portal-cliente.md`. Es una sola página pública para celular, más dos endpoints y dos funciones PHP.

| Tarea | Qué se construye |
|---|---|
| 2.1 | Maquetar `t.php` en Bootstrap, pensada para 360 px, usando como datos un JSON de ejemplo copiado de la sección 5 de M5. Cabecera, estado grande, mensaje según estado, línea de tiempo |
| 2.2 | Funciones `tracking_url` y `token_seguimiento_generar` (son cortas) |
| 2.3 | `tracking_estado_publico` y `GET tracking/estado.php`: buscar el token → obtener el paquete → armar **solo los campos permitidos** (nombre abreviado, dirección recortada, historial sin datos internos). Probar con los tokens del seed (`/t/…0007`, `/t/…0010`, `/t/…0006`) |
| 2.4 | Conectar la página al endpoint real y mostrar los mensajes de cada estado (tabla de textos de `04-estados-y-reglas.md`) |
| 2.5 | Mapa del "último tramo" con Leaflet (con apoyo de Guanda y `mapa_util.js`) y refresco automático cada 10 s |
| 2.6 | Reprogramar entrega: formulario y `POST tracking/reprogramar.php` con sus validaciones de fecha y franja |
| 2.7 | Límite de consultas por IP y cabeceras `no-store` / `noindex` |

**Qué se aprende:** páginas públicas sin sesión, seguridad por token, no exponer datos sensibles, `setInterval` y actualización sin recargar, mapas.

**Cómo saber que quedó bien:** se cumplen todos los criterios de aceptación de M5, y el revisor mira el JSON completo de `estado.php` para confirmar que no aparece ningún dato sensible.

---

## Etapa 3. M6. Dashboard

Documento: `modulos/M6-dashboard-analitica.md`. Es de **solo lectura**: no puede dañar los datos de nadie. La clave es calcular bien los indicadores; las definiciones exactas están en la sección 5 de M6.

| Tarea | Qué se construye | Cómo verificar |
|---|---|---|
| 3.1 | Consultas SQL de **Despachados** y **Entregados**, escritas y probadas directamente en phpMyAdmin con el seed | Despachados = 5, Entregados = 1 |
| 3.2 | Consultas de **OTIF**, **FTDR** y **Tiempo de parada**, una por una | OTIF 20,0 %, FTDR 50,0 %, Tiempo de parada 5:00 |
| 3.3 | Servicio `kpi_calcular` y `GET kpi/resumen.php`; en la pantalla, las tarjetas de KPIs con el filtro de fechas | Los mismos valores del seed en pantalla |
| 3.4 | `GET kpi/serie.php` y gráfico de líneas con Chart.js | Una fila por cada día del rango, también los días sin datos |
| 3.5 | `kpi/ranking_conductores.php` y `kpi/por_zona.php` con sus tablas | Aparecen los 2 conductores y las 2 zonas del seed |
| 3.6 | Filtros por zona, conductor y tipo de vehículo | Las zonas suman el total (salvo paquetes sin zona) |
| 3.7 | Mapa de calor (`kpi/mapa_calor.php` + Leaflet.heat) | Puntos de entregas; al cambiar a incidencias, el paquete 10 |
| 3.8 | Exportar CSV (`kpi/exportar.php`) | Se abre bien en Excel, con acentos y las mismas cifras |

**Método:** cada indicador se escribe primero como consulta SQL suelta, se compara con el valor esperado del seed, y **solo entonces** se pasa a PHP. Juan revisa las consultas.

**Qué se aprende:** `JOIN`, `GROUP BY`, `COUNT`, `AVG`, `TIMESTAMPDIFF`, filtros con parámetros, gráficos y exportación de datos.

---

## Cómo se sabe que cada etapa terminó

- Pasa el checklist común de `99-integracion/03-definicion-de-terminado.md` y el checklist de su módulo.
- Los criterios de aceptación de su documento están marcados y probados con el seed.
- Su pull request fue revisado y aprobado.

Si en algún momento una etapa se está volviendo demasiado grande, se avisa al grupo: se puede partir en tareas más pequeñas o repartir una parte (por ejemplo, la exportación a CSV o el mapa de calor de M6) sin afectar el resto del proyecto.
