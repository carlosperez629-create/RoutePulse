# 09. Guía de interfaz

Para que las pantallas de los 8 módulos se vean y se comporten como una sola aplicación.

## 1. Base visual

- Bootstrap 5.3 local (`assets/vendor/bootstrap`). Un solo archivo propio `assets/css/app.css` para ajustes globales; cada módulo puede tener `assets/css/<modulo>.css` solo para lo específico.
- Plantilla común: `render_header()` dibuja la barra superior (logo "RoutePulse", menú por rol, nombre del usuario, botón "Salir") y `render_footer()` carga los scripts. Nadie dibuja su propia cabecera.
- Contenedor: `container-fluid` en pantallas de mapa o tablas anchas; `container` en formularios.
- Idioma de la interfaz: español. Tuteo en notificaciones al cliente ("Tu paquete…"), trato neutro en la interfaz interna.
- Íconos: Bootstrap Icons (local).

## 2. Colores de estado (clase `estado-<estado>` en `assets/css/estados.css`)

El mismo estado usa el mismo color en **todas** las pantallas (insignias, líneas de tiempo, marcadores de mapa).

| Estado de paquete | Color | Estado de ruta / parada | Color |
|---|---|---|---|
| `creado` | `#6c757d` gris | `borrador` | `#6c757d` |
| `ingresado_hub` | `#0dcaf0` celeste | `planificada` | `#0d6efd` |
| `en_clasificacion` | `#0d6efd` azul | `en_curso` | `#fd7e14` |
| `asignado_ruta` | `#ffc107` amarillo | `completada` | `#198754` |
| `en_transito` | `#fd7e14` naranja | `cancelada` | `#343a40` |
| `entregado` | `#198754` verde | `pendiente` (parada) | `#6c757d` |
| `incidencia` | `#dc3545` rojo | `en_camino` (parada) | `#fd7e14` |
| `cancelado` | `#343a40` gris oscuro | `llegado` (parada) | `#0dcaf0` |
| `devuelto` | `#6f42c1` morado | `entregada` / `fallida` / `omitida` | `#198754` / `#dc3545` / `#6c757d` |

El texto de cada estado en pantalla es el de la columna "Texto en pantalla" de `04-estados-y-reglas.md`. Nunca se muestra el valor interno (`en_clasificacion`) al usuario.

Los marcadores de mapa: vehículo en línea (verde), demorado (amarillo), sin señal (gris); parada (color del estado de la parada); HUB (azul oscuro).

## 3. Fechas, números y formatos

- Las fechas se muestran como `dd/mm/aaaa HH:mm` (24 h) en la zona `zona_horaria_ui`. Función JS compartida `ui.formatoFecha(iso)` en `ui.js`.
- Distancias: `12,4 km` (coma decimal), duraciones: `1 h 35 min`. Dinero: con símbolo y 2 decimales (`ui.formatoMoneda(valor, moneda)`).
- Porcentajes con un decimal (`87,5 %`).
- Las tablas muestran "—" cuando un dato es `null`.

## 4. Comportamiento común

1. **Carga**: mientras llega la respuesta se muestra un indicador (spinner) en el contenedor; los botones de envío se deshabilitan durante la petición para evitar doble clic.
2. **Errores**: `api()` muestra el `error.mensaje` en un aviso (toast) rojo. Los errores de `VALIDACION` marcan cada campo con `is-invalid` y muestran `campos[nombre]` debajo del campo.
3. **Éxitos**: aviso verde breve ("Paquete creado").
4. **Acciones destructivas o irreversibles** (cancelar paquete, cancelar ruta, omitir parada, devolver paquete): ventana de confirmación con el nombre del objeto.
5. **Tablas**: paginadas con el `meta` de la API, columnas ordenables solo donde el endpoint permita `orden`, buscador con retraso de 400 ms (debounce), y mensaje "No hay resultados" cuando está vacía.
6. **Formularios**: se validan en el navegador (HTML5 + mensajes) **y** en el servidor. El servidor manda.
7. **Sesión expirada** (`NO_AUTENTICADO`): `api()` redirige a `login.php?expirada=1`.
8. **Accesibilidad mínima**: etiquetas `<label>` asociadas a cada campo, contraste suficiente, botones con texto o `aria-label`, navegación con teclado en formularios.

## 5. Mapas

- Leaflet con teselas de OpenStreetMap (con la atribución visible). Centro inicial: el HUB por defecto (`hubs.id = 1` en el seed).
- Zoom por defecto 13; el monitor ajusta los límites (`fitBounds`) a la flota.
- Un solo archivo JS compartido de utilidades de mapa (`assets/js/mapa_util.js`, lo escribe M2 y lo usan M1, M5 y M6): `mapaCrear(idContenedor, opciones)`, `marcadorVehiculo(lat, lng, estadoSenal)`, `marcadorParada(parada)`, `dibujarPolyline(mapa, puntos, estilo)`.

## 6. Pantallas móviles

- El **conductor** y el **portal del cliente** son *mobile first*: se diseñan para 360 px de ancho, botones de al menos 44 px de alto, texto de 16 px o más, y todo usable con una mano.
- El resto de pantallas se diseñan para escritorio y deben seguir siendo utilizables en tableta (768 px).

## 7. Textos estándar de la interfaz

| Situación | Texto |
|---|---|
| Sin permiso | "No tienes permiso para ver esta página." |
| No encontrado | "No encontramos lo que buscas." |
| Error inesperado | "Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador." |
| Sin conexión (conductor) | "Sin conexión. Tus entregas se guardan y se enviarán al recuperar la señal." |
| Enlace de seguimiento inválido | "Este enlace de seguimiento no es válido o ya venció." |
