# 99-03. Definición de terminado (DoD)

Un módulo está **terminado** solo cuando cumple todo lo siguiente. Quien lo programó marca la lista; **otra persona** la verifica antes de integrar a `develop`.

## 1. Checklist común (los 8 módulos)

**Contrato**
- [ ] Todos los endpoints del catálogo (`05-contrato-api.md`) y de la especificación del módulo existen, con los nombres, métodos y roles indicados.
- [ ] Las respuestas usan el formato `{ok, data, meta}` / `{ok, error}` y los códigos de error del contrato.
- [ ] Las funciones de `07-servicios-internos.md` del módulo existen con las firmas exactas y hacen lo descrito.
- [ ] Se usan los estados, tipos y formatos de `04-estados-y-reglas.md` sin variantes.
- [ ] Solo se escribe en tablas propias (las demás, mediante servicios del dueño).

**Calidad de datos**
- [ ] Todas las entradas pasan por `validar()`; los errores devuelven `campos`.
- [ ] Todas las consultas están parametrizadas (`git grep` no encuentra concatenación de variables en SQL).
- [ ] Las fechas se guardan en UTC y se envían en ISO 8601.
- [ ] Las operaciones multitabla usan `db_tx`.
- [ ] No hay borrados físicos de datos de negocio.

**Seguridad**
- [ ] Rol validado en `api_run` y propiedad del recurso validada en el servicio.
- [ ] Salida HTML escapada; sin `innerHTML` con datos externos.
- [ ] Sin claves, tokens ni contraseñas en el código ni en los logs.
- [ ] Acciones importantes auditadas con los nombres del catálogo de M8.

**Interfaz**
- [ ] Usa `render_header`/`render_footer`, Bootstrap, los colores de estado y los formatos de `09-guia-interfaz.md`.
- [ ] Indicadores de carga, mensajes de error de la API y confirmación en acciones destructivas.
- [ ] Probado en escritorio (y en 360 px si es conductor o portal).

**Pruebas y limpieza**
- [ ] Todos los criterios de aceptación de su documento de módulo están marcados y probados con el seed.
- [ ] Las pruebas E2E que le corresponden pasan.
- [ ] `storage/logs/app.log` sin avisos ni errores al usar todas sus pantallas.
- [ ] Sin código muerto, sin `var_dump`/`console.log` olvidados, sin archivos temporales.
- [ ] Cambios de esquema (si los hubo) en una migración numerada y reflejados en `schema.sql`.
- [ ] Pull request revisado y aprobado por otra persona.

## 2. Checklist específico por módulo

### M1. Despacho y rutas
- [ ] `ruta_generar` respeta capacidad, tipo de vehículo, zonas y horarios; informa `sin_asignar` y `sin_coordenadas`.
- [ ] Las transiciones de ruta y parada siguen `04-estados-y-reglas.md`; nunca hay dos paradas `en_camino`.
- [ ] Drag & drop de paradas persiste y recalcula ETA.
- [ ] Reenrutamiento dinámico por cancelación, urgente, omisión y `evitar`.

### M2. Rastreo GPS
- [ ] `gps_tracker.js` y `mapa_util.js` entregados y documentados con sus interfaces.
- [ ] Una sola notificación de proximidad por parada.
- [ ] Cadena de hash verificable; `puntos_gps` solo recibe `INSERT` desde la app.
- [ ] Monitor refresca solo, con estado de señal correcto.

### M3. Trazabilidad
- [ ] `paquete_cambiar_estado` rechaza toda transición no permitida y no duplica historial.
- [ ] Etiqueta imprimible 100×150 mm con QR y GS1-128 correctos.
- [ ] Incidencias y reintentos con el límite `max_intentos`.

### M4. App del conductor
- [ ] PWA instalable, funciona sin conexión, cola idempotente y en orden.
- [ ] OTP: generación, vigencia, intentos y reglas online/offline.
- [ ] Firma, foto geoetiquetada y archivos guardados fuera de `public/`.

### M5. Portal del cliente
- [ ] Respuesta pública sin datos sensibles (revisada campo por campo).
- [ ] Mapa solo con la regla de las 3 paradas; refresco sin recargar.
- [ ] Reprogramación con las reglas de fecha y franja; rate limit activo.

### M6. Dashboard
- [ ] Los KPIs coinciden con el ejemplo de la sección 5 de M6 usando el seed.
- [ ] Solo lectura sobre tablas ajenas; filtros coherentes.
- [ ] CSV correcto en Excel; mapa de calor funcionando.

### M7. Integraciones
- [ ] Modo simulado completo; el paso a modo real no cambia el código de los demás módulos.
- [ ] Webhooks idempotentes, firmados y registrados; API de entrada con API key.
- [ ] Cola de notificaciones con reintentos y respaldo por canal; `notificar` nunca lanza excepciones.

### M8. Seguridad y usuarios
- [ ] Núcleo de la Fase 0 completo (checklist de M8, sección 7).
- [ ] Bloqueo, rate limit, CSRF, sesiones y cabeceras de seguridad verificados.
- [ ] Todas las acciones del catálogo de auditoría llegan a `log_auditoria`.

## 3. Definición de terminado del proyecto (entrega final)

- [ ] E2E-00 a E2E-06 y E2E-08 pasan (E2E-07 y E2E-09 son opcionales).
- [ ] El proyecto se instala desde cero siguiendo `08-configuracion-y-entorno.md` en una máquina limpia.
- [ ] Desplegado en el hosting con HTTPS, probado con un teléfono real (GPS, cámara y modo offline).
- [ ] Cron configurados y funcionando.
- [ ] Documentados los **límites conocidos** (secciones finales de M1, M2, M4 y README) y entregados al docente o cliente.
- [ ] Cuentas de prueba cambiadas o eliminadas en el entorno de producción.
- [ ] Repositorio limpio: sin claves, `main` actualizado con la versión entregada y etiqueta de versión (`v1.0`).
