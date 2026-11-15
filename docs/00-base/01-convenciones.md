# 01. Convenciones del proyecto

Todos los programadores siguen estas reglas sin excepción. Si una regla estorba, se propone el cambio aquí, no se ignora en el código.

## 1. Idioma y nombres

- Código, tablas, columnas, claves JSON, funciones y archivos: **español, `snake_case`, sin tildes ni ñ** (`codigo_postal`, `paquete_cambiar_estado`, `ultima_posicion`).
- Textos que ve el usuario (pantallas, mensajes de error, notificaciones): español con tildes y ñ correctas.
- Tablas en plural (`paquetes`), columnas en singular. Clave foránea: `<entidad_singular>_id` (`paquete_id`, `conductor_id`).
- Booleanos: `TINYINT(1)` con nombre positivo (`activo`, `completa`), nunca `no_activo`.
- Fechas y horas: columnas `*_at` tipo `DATETIME` en **UTC**. Solo fecha: columna `fecha` o `fecha_*` tipo `DATE`.
- JavaScript: variables y funciones en `camelCase`; archivos en `snake_case.js`.
- Constantes PHP: `MAYUSCULAS_CON_GUION_BAJO`.

## 2. PHP

- Versión mínima 8.1. Todo archivo empieza con `<?php` y **no** lleva `?>` de cierre. Codificación UTF-8 sin BOM. Sangría de 4 espacios.
- Estilo procedural con funciones. Una función = una tarea. Las funciones públicas de cada módulo van en `app/services/<nombre>_service.php` con un bloque PHPDoc que indique parámetros, retorno y excepciones.
- Prefijo por área para evitar choques de nombres: `paquete_*`, `incidencia_*`, `hub_*` (M3); `zona_*`, `ruta_*`, `parada_*`, `conductor_*`, `vehiculo_*` (M1); `gps_*`, `eta_*`, `geocerca_*` (M2); `otp_*`, `entrega_*`, `archivo_*` (M4); `token_*`, `tracking_*` (M5); `kpi_*` (M6); `notificar`, `geocodificar`, `ruta_matriz`, `ruta_geometria`, `integracion_*`, `webhook_*` (M7); `auth_*`, `usuario_*`, `csrf_*`, `audit`, `rate_limit_*` (M8).
- **Nunca** concatenar variables en una consulta SQL. Toda consulta pasa por `db_all`, `db_one`, `db_val`, `db_exec` o `db_insert` con parámetros.
- **Nunca** leer `$_GET`, `$_POST` ni `php://input` directamente en los endpoints: `api_run` ya entrega los datos en `$in`, y se validan con `validar()`.
- Todo texto que se imprima en HTML pasa por `e()` (alias de `htmlspecialchars` con `ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8).
- Los errores esperados se lanzan con `lanzar_error(...)`; no se hace `die()` ni `echo` de errores. Los errores inesperados los captura `api_run` y responde `ERROR_INTERNO` sin mostrar detalles.
- `display_errors = Off` fuera de desarrollo. Los errores se registran con `log_app()` en `storage/logs/app.log`.
- Una consulta que modifica más de una tabla va dentro de `db_tx(function() { ... })`.

## 3. SQL

- Solo se cambia el esquema con un archivo nuevo en `database/migraciones/NNN_descripcion.sql` (por ejemplo `001_agregar_campo_x.sql`) y anotándolo en `database/migraciones/REGISTRO.md` (número, fecha, quién y qué cambia). Después de la Fase 0 no se edita `schema.sql` directamente; el dueño del módulo actualiza `schema.sql` en el mismo pull request que agrega la migración.
- Cada tabla pertenece a un módulo (ver `03-base-de-datos.md`). **Solo el dueño escribe en su tabla**, y lo hace mediante sus servicios. Los demás módulos pueden leerla con `SELECT`, pero para modificarla llaman al servicio del dueño.
- No se borran filas de tablas de negocio (se usa `activo` o un estado). Excepciones: tablas de unión (`zona_codigos_postales`, `zona_conductor`).
- Tablas de solo inserción (nunca `UPDATE` ni `DELETE`): `historial_estados`, `puntos_gps`, `log_auditoria`, `entregas`.

## 4. JavaScript y HTML

- JavaScript moderno (ES6+), sin jQuery. Las llamadas al servidor usan **siempre** el helper `api()` de `public/assets/js/api.js` (lo entrega Carlos en la Fase 0): agrega el encabezado CSRF, convierte la respuesta, maneja `401` redirigiendo a `login.php` y muestra los errores con el mismo componente de mensajes.
- Nada de estilos en línea ni `<script>` con lógica larga dentro de las páginas PHP: la lógica va en `public/assets/js/<modulo>/<pagina>.js`.
- Todo dato que llega del servidor y se inserta en el DOM se inserta con `textContent`, nunca con `innerHTML`, salvo HTML generado por plantillas propias con datos escapados.
- Librerías: se guardan **localmente** en `public/assets/vendor/` con la versión fija indicada en el README. No se usan CDN, para que todos tengan las mismas versiones y funcione el modo offline.

## 5. Git

- Repositorio único. Ramas: `main` (estable, solo recibe de `develop` al cerrar un hito), `develop` (integración) y `feature/m<N>-<descripcion>` por tarea (por ejemplo `feature/m3-etiqueta-guia`).
- Se trabaja siempre en una rama `feature/...` y se integra a `develop` con pull request revisado por **otra persona** (no quien lo escribió), según la rotación de revisores del `README.md` (sección 3). Nadie hace `push` directo a `develop` ni a `main`.
- Mensajes de commit: `[M3] Agrega validación de transiciones de estado` (módulo entre corchetes, verbo en infinitivo o presente, máximo 72 caracteres).
- `config/config.php`, `storage/*` (salvo `.gitkeep`) y `public/assets/vendor/*/node_modules` van en `.gitignore`. **Nunca** se suben claves, contraseñas ni tokens.
- Antes de abrir un pull request: `git pull` de `develop`, importar `schema.sql` + `seed.sql` desde cero y comprobar que lo propio sigue funcionando.

## 6. Revisión de código (checklist del revisor)

1. ¿Usa solo consultas parametrizadas?
2. ¿Valida entradas con `validar()` y comprueba el rol en `api_run`?
3. ¿Respeta el formato de respuesta y los códigos de error del contrato?
4. ¿Cambia estados solo a través de los servicios del dueño (`paquete_cambiar_estado`, etc.)?
5. ¿Escapa la salida y no usa `innerHTML` con datos externos?
6. ¿Registra auditoría en las acciones importantes (ver catálogo en M8)?
7. ¿Maneja fechas en UTC?
8. ¿Hay errores o avisos de PHP en `storage/logs/app.log` al usar la pantalla?

## 7. Cambios al contrato

Un cambio en una tabla, endpoint, función de servicio, estado o formato que afecte a otro módulo se hace así:
1. Se abre un pull request que modifica el documento correspondiente de `docs/` y, si aplica, el esquema.
2. Lo aprueban los dueños de los módulos afectados.
3. Se avisa al grupo antes de integrarlo.

Mientras no esté aprobado, el código sigue el contrato vigente.
