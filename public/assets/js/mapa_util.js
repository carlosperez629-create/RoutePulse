/**
 * mapa_util.js - Utilidades de mapa Leaflet compartidas (M2).
 * La usan M1 (zonas, ruta_detalle), M2 (monitor), M5 (portal) y M6 (mapa de calor).
 *
 * Interfaz fija (09-guia-interfaz.md, sección 5):
 *   mapaCrear(idContenedor, opciones)          -> L.Map
 *   marcadorVehiculo(lat, lng, estadoSenal)    -> L.Marker  (no lo agrega al mapa)
 *   marcadorParada(parada)                     -> L.Marker  (no lo agrega al mapa)
 *   dibujarPolyline(mapa, puntos, estilo)      -> L.Polyline (ya agregada al mapa)
 *
 * Requiere Leaflet 1.9 cargado antes (public/assets/vendor/leaflet).
 * No usa imágenes de marcadores (L.divIcon), así que no depende de rutas de vendor.
 * Todo texto externo se inserta con textContent, nunca con innerHTML.
 */
(function (global) {
    'use strict';

    // HUB por defecto (hubs.id = 1 en el seed). Sobrescribible con opciones.centro.
    var CENTRO_DEFECTO = [10.5571993, -71.6490662];
    var ZOOM_DEFECTO = 13;

    // Colores de 09-guia-interfaz.md, sección 2.
    var COLORES_SENAL = {
        en_linea: '#198754',   // verde
        demorado: '#ffc107',   // amarillo
        sin_senal: '#6c757d'   // gris
    };
    var COLORES_PARADA = {
        pendiente: '#6c757d',
        en_camino: '#fd7e14',
        llegado: '#0dcaf0',
        entregada: '#198754',
        fallida: '#dc3545',
        omitida: '#6c757d'
    };
    var COLOR_HUB = '#0a2540';   // azul oscuro

    var TEXTO_ESTADO_PARADA = {
        pendiente: 'Pendiente',
        en_camino: 'En camino',
        llegado: 'Llegó',
        entregada: 'Entregada',
        fallida: 'Fallida',
        omitida: 'Omitida'
    };

    var ESTILOS_LINEA = {
        defecto: { color: '#0d6efd', weight: 4, opacity: 0.9 },
        real: { color: '#0d6efd', weight: 4, opacity: 0.9 },                       // recorrido real (azul)
        sugerida: { color: '#6c757d', weight: 3, opacity: 0.8, dashArray: '8 8' }  // ruta sugerida (gris discontinua)
    };

    var cssInyectado = false;

    function exigirLeaflet() {
        if (typeof global.L === 'undefined') {
            throw new Error('mapa_util.js necesita Leaflet cargado antes.');
        }
    }

    // Estilos de los marcadores. Se inyectan una sola vez (CSP permite style-src 'self' 'unsafe-inline').
    function inyectarCss() {
        if (cssInyectado) { return; }
        cssInyectado = true;
        var css = [
            '.mu-icono{background:transparent;border:0;}',
            '.mu-vehiculo{width:26px;height:26px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.35);}',
            '.mu-vehiculo.mu-en_linea{background:' + COLORES_SENAL.en_linea + ';}',
            '.mu-vehiculo.mu-demorado{background:' + COLORES_SENAL.demorado + ';}',
            '.mu-vehiculo.mu-sin_senal{background:' + COLORES_SENAL.sin_senal + ';}',
            '.mu-parada{width:28px;height:28px;border-radius:50%;border:2px solid #fff;color:#fff;font:600 13px/24px system-ui,sans-serif;text-align:center;box-shadow:0 0 0 1px rgba(0,0,0,.35);}',
            '.mu-parada.mu-pendiente,.mu-parada.mu-omitida{background:' + COLORES_PARADA.pendiente + ';}',
            '.mu-parada.mu-en_camino{background:' + COLORES_PARADA.en_camino + ';}',
            '.mu-parada.mu-llegado{background:' + COLORES_PARADA.llegado + ';color:#000;}',
            '.mu-parada.mu-entregada{background:' + COLORES_PARADA.entregada + ';}',
            '.mu-parada.mu-fallida{background:' + COLORES_PARADA.fallida + ';}',
            '.mu-hub{width:30px;height:30px;border-radius:6px;background:' + COLOR_HUB + ';border:2px solid #fff;color:#fff;font:700 13px/26px system-ui,sans-serif;text-align:center;box-shadow:0 0 0 1px rgba(0,0,0,.35);}'
        ].join('\n');
        var estilo = document.createElement('style');
        estilo.setAttribute('data-origen', 'mapa_util');
        estilo.textContent = css;
        document.head.appendChild(estilo);
    }

    function numeroValido(valor) {
        return typeof valor === 'number' && isFinite(valor);
    }

    function claseEstado(mapaColores, estado, porDefecto) {
        return Object.prototype.hasOwnProperty.call(mapaColores, estado) ? estado : porDefecto;
    }

    /**
     * Crea el mapa con teselas de OpenStreetMap (con atribución visible).
     * @param {string} idContenedor  id del elemento que contendrá el mapa.
     * @param {Object} [opciones]
     *   centro: [lat, lng]            (por defecto el HUB 1)
     *   zoom: number                  (por defecto 13)
     *   hub: {lat, lng, nombre?}      si viene, dibuja el marcador del HUB
     *   zoomMaximo: number            (por defecto 19)
     * @returns {L.Map}
     */
    function mapaCrear(idContenedor, opciones) {
        exigirLeaflet();
        inyectarCss();
        opciones = opciones || {};

        var contenedor = document.getElementById(idContenedor);
        if (!contenedor) {
            throw new Error('mapaCrear: no existe el contenedor "' + idContenedor + '".');
        }

        var centro = Array.isArray(opciones.centro) && numeroValido(opciones.centro[0]) && numeroValido(opciones.centro[1])
            ? opciones.centro
            : CENTRO_DEFECTO;
        var zoom = numeroValido(opciones.zoom) ? opciones.zoom : ZOOM_DEFECTO;
        var zoomMaximo = numeroValido(opciones.zoomMaximo) ? opciones.zoomMaximo : 19;

        var mapa = global.L.map(contenedor, { center: centro, zoom: zoom, maxZoom: zoomMaximo });

        global.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: zoomMaximo,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(mapa);

        if (opciones.hub && numeroValido(opciones.hub.lat) && numeroValido(opciones.hub.lng)) {
            var marcadorHub = global.L.marker([opciones.hub.lat, opciones.hub.lng], {
                icon: global.L.divIcon({
                    className: 'mu-icono',
                    html: '<div class="mu-hub">H</div>',   // HTML fijo, sin datos externos
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                }),
                title: opciones.hub.nombre ? String(opciones.hub.nombre) : 'HUB'
            });
            marcadorHub.addTo(mapa);
        }

        return mapa;
    }

    /**
     * Marcador de vehículo. El color depende de la señal:
     * en_linea (verde), demorado (amarillo), sin_senal (gris).
     * Para cambiar la señal de un marcador existente: marcador.setIcon(marcadorVehiculo(...).options.icon).
     * @returns {L.Marker} sin agregar al mapa (el llamador hace .addTo(mapa)).
     */
    function marcadorVehiculo(lat, lng, estadoSenal) {
        exigirLeaflet();
        inyectarCss();
        var senal = claseEstado(COLORES_SENAL, estadoSenal, 'sin_senal');
        return global.L.marker([lat, lng], {
            icon: global.L.divIcon({
                className: 'mu-icono',
                html: '<div class="mu-vehiculo mu-' + senal + '"></div>',   // clase tomada de una lista cerrada
                iconSize: [26, 26],
                iconAnchor: [13, 13]
            }),
            zIndexOffset: 1000   // el vehículo queda por encima de las paradas
        });
    }

    /**
     * Marcador numerado de una parada, con el color de su estado.
     * @param {Object} parada  {lat, lng, orden, estado, codigo_guia?, paquete?{codigo_guia}, eta?}
     * @returns {L.Marker} sin agregar al mapa.
     */
    function marcadorParada(parada) {
        exigirLeaflet();
        inyectarCss();
        parada = parada || {};
        var estado = claseEstado(COLORES_PARADA, parada.estado, 'pendiente');
        var orden = numeroValido(Number(parada.orden)) ? String(parseInt(parada.orden, 10)) : '';

        var marcador = global.L.marker([parada.lat, parada.lng], {
            icon: global.L.divIcon({
                className: 'mu-icono',
                html: '<div class="mu-parada mu-' + estado + '"></div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            })
        });

        // El número se agrega con textContent al elemento real una vez dibujado el marcador.
        marcador.on('add', function () {
            var elemento = marcador.getElement();
            var circulo = elemento ? elemento.querySelector('.mu-parada') : null;
            if (circulo) { circulo.textContent = orden; }
        });

        // Tooltip construido con nodos de texto (nunca innerHTML con datos del servidor).
        var guia = parada.codigo_guia || (parada.paquete && parada.paquete.codigo_guia) || '';
        var caja = document.createElement('div');
        var linea1 = document.createElement('strong');
        linea1.textContent = (orden ? 'Parada ' + orden : 'Parada') + (guia ? ' · ' + guia : '');
        caja.appendChild(linea1);
        caja.appendChild(document.createElement('br'));
        caja.appendChild(document.createTextNode(TEXTO_ESTADO_PARADA[estado]));
        marcador.bindTooltip(caja, { direction: 'top', offset: [0, -12] });

        return marcador;
    }

    /**
     * Dibuja una polyline y la agrega al mapa.
     * @param {L.Map} mapa
     * @param {Array} puntos  [[lat, lng], ...] (admite un tercer elemento, p. ej. capturado_at, que se ignora)
     * @param {Object|string} [estilo]  opciones de L.polyline, o 'real' / 'sugerida'.
     * @returns {L.Polyline}
     */
    function dibujarPolyline(mapa, puntos, estilo) {
        exigirLeaflet();
        var base = typeof estilo === 'string'
            ? (ESTILOS_LINEA[estilo] || ESTILOS_LINEA.defecto)
            : global.L.Util.extend({}, ESTILOS_LINEA.defecto, estilo || {});

        var coordenadas = (Array.isArray(puntos) ? puntos : [])
            .filter(function (p) { return Array.isArray(p) && numeroValido(Number(p[0])) && numeroValido(Number(p[1])); })
            .map(function (p) { return [Number(p[0]), Number(p[1])]; });

        return global.L.polyline(coordenadas, base).addTo(mapa);
    }

    // Interfaz global (la de 09-guia-interfaz.md) y espacio de nombres con las constantes.
    global.mapaCrear = mapaCrear;
    global.marcadorVehiculo = marcadorVehiculo;
    global.marcadorParada = marcadorParada;
    global.dibujarPolyline = dibujarPolyline;
    global.MapaUtil = {
        CENTRO_DEFECTO: CENTRO_DEFECTO,
        COLORES_SENAL: COLORES_SENAL,
        COLORES_PARADA: COLORES_PARADA,
        COLOR_HUB: COLOR_HUB
    };
})(window);
