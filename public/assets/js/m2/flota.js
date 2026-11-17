/**
 * flota.js - Monitor de flota en vivo (M2).
 * Pantalla: public/monitor/flota.php. Datos: GET api/gps/flota.php cada monitor_refresh_s (5 s).
 * Usa mapa_util.js (mapaCrear, marcadorVehiculo, marcadorParada, dibujarPolyline).
 *
 * Todo dato que viene del servidor se inserta con textContent (nunca innerHTML).
 * Las peticiones usan fetch en una sola función (obtenerJson): el refresco automático no debe
 * mostrar un aviso rojo cada 5 s si se cae la red. Cuando api.js esté disponible se cambia solo ahí.
 */
(function () {
    'use strict';

    var contenedor = document.getElementById('monitor');
    if (!contenedor) { return; }

    // ---------- Configuración que entrega la página ----------
    var refrescoMs = Math.max(2, parseInt(contenedor.getAttribute('data-refresh'), 10) || 5) * 1000;
    var zonaHoraria = contenedor.getAttribute('data-zona-horaria') || 'UTC';
    var DETALLE_CADA_N_CICLOS = 3;   // la ruta seleccionada se vuelve a pedir cada 3 refrescos (15 s)

    var TEXTO_SENAL = { en_linea: 'En línea', demorado: 'Demorado', sin_senal: 'Sin señal' };
    var CLASE_SENAL = { en_linea: 'bg-success', demorado: 'bg-warning text-dark', sin_senal: 'bg-secondary' };
    var TEXTO_TIPO_VEHICULO = { furgon: 'Furgón', motocicleta: 'Motocicleta', vehiculo_electrico: 'Vehículo eléctrico', bicicleta: 'Bicicleta' };

    // ---------- Elementos ----------
    var panel = document.getElementById('monitor-panel');
    var filtroZona = document.getElementById('filtro-zona');
    var filtroSenal = document.getElementById('filtro-senal');
    var botonCentrar = document.getElementById('btn-centrar');
    var botonPantalla = document.getElementById('btn-pantalla-completa');
    var indicador = document.getElementById('monitor-estado');

    // ---------- Estado ----------
    var rutas = [];
    var marcadores = {};          // rutaId -> { marcador, senal }
    var seleccionada = null;      // rutaId
    var capaDetalle = null;       // L.layerGroup con la ruta sugerida y las paradas
    var ciclosDesdeDetalle = 0;
    var enCurso = false;
    var primeraCarga = true;
    var temporizador = null;

    // ---------- Mapa ----------
    var opcionesMapa = {};
    var hubLat = parseFloat(contenedor.getAttribute('data-hub-lat'));
    var hubLng = parseFloat(contenedor.getAttribute('data-hub-lng'));
    if (isFinite(hubLat) && isFinite(hubLng)) {
        opcionesMapa.hub = { lat: hubLat, lng: hubLng, nombre: contenedor.getAttribute('data-hub-nombre') || 'HUB' };
        opcionesMapa.centro = [hubLat, hubLng];
    }
    var mapa = mapaCrear('mapa', opcionesMapa);
    capaDetalle = L.layerGroup().addTo(mapa);

    // ---------- Utilidades ----------
    function crear(etiqueta, clase, texto) {
        var el = document.createElement(etiqueta);
        if (clase) { el.className = clase; }
        if (texto !== undefined && texto !== null) { el.textContent = texto; }
        return el;
    }

    function formatoHora(iso) {
        if (!iso) { return '—'; }
        try {
            return new Intl.DateTimeFormat('es', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: zonaHoraria }).format(new Date(iso));
        } catch (e) {
            return new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', hour12: false });
        }
    }

    function formatoAntiguedad(segundos) {
        if (segundos === null || segundos === undefined) { return 'sin posición'; }
        if (segundos < 60) { return 'hace ' + segundos + ' s'; }
        if (segundos < 3600) { return 'hace ' + Math.floor(segundos / 60) + ' min'; }
        return 'hace ' + Math.floor(segundos / 3600) + ' h';
    }

    function formatoDistancia(metros) {
        if (metros === null || metros === undefined) { return '—'; }
        if (metros < 1000) { return metros + ' m'; }
        return (metros / 1000).toLocaleString('es', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' km';
    }

    function mostrarEstado(texto, esError) {
        indicador.textContent = texto;
        indicador.classList.toggle('text-danger', !!esError);
        indicador.classList.toggle('text-muted', !esError);
    }

    // ---------- Red ----------
    function obtenerJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (res) {
            if (res.status === 401) {
                window.location.href = '../login.php?expirada=1';   // sesión vencida (09-guia-interfaz §4.7)
                return new Promise(function () { /* la página se está yendo */ });
            }
            return res.json().catch(function () { return null; }).then(function (json) {
                if (!json || json.ok !== true) {
                    var mensaje = json && json.error ? json.error.mensaje : 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.';
                    throw new Error(mensaje);
                }
                return json.data;
            });
        });
    }

    function cargarZonas() {
        return obtenerJson('../api/zonas/listar.php?por_pagina=100').then(function (zonas) {
            (Array.isArray(zonas) ? zonas : []).forEach(function (z) {
                var opcion = crear('option', '', z.nombre);
                opcion.value = String(z.id);
                filtroZona.appendChild(opcion);
            });
        }).catch(function () { /* M1 aún no disponible: se completa con las zonas que aparezcan en la flota */ });
    }

    function completarZonasDesdeFlota(lista) {
        var existentes = {};
        Array.prototype.forEach.call(filtroZona.options, function (o) { existentes[o.value] = true; });
        lista.forEach(function (item) {
            var id = item.ruta.zona_id;
            if (id !== null && !existentes[String(id)]) {
                var opcion = crear('option', '', 'Zona ' + id);
                opcion.value = String(id);
                filtroZona.appendChild(opcion);
                existentes[String(id)] = true;
            }
        });
    }

    // ---------- Marcadores ----------
    // Leaflet interpreta un tooltip en texto como HTML: se le pasa un nodo con textContent.
    function textoMarcador(item) {
        var partes = [item.conductor.nombre];
        if (item.vehiculo) { partes.push(item.vehiculo.placa); }
        return crear('span', '', partes.join(' · '));
    }

    function actualizarMarcadores(lista) {
        var vigentes = {};
        lista.forEach(function (item) {
            var id = item.ruta.id;
            var pos = item.posicion;
            if (!pos) { return; }                       // sin posición: se lista en el panel, pero no hay marcador
            vigentes[id] = true;

            var registro = marcadores[id];
            if (!registro) {
                var m = marcadorVehiculo(pos.lat, pos.lng, item.estado_senal);
                m.on('click', function () { seleccionar(id, false); });
                m.bindTooltip(textoMarcador(item), { direction: 'top', offset: [0, -14] });
                m.addTo(mapa);
                registro = marcadores[id] = { marcador: m, senal: item.estado_senal };
            } else {
                registro.marcador.setLatLng([pos.lat, pos.lng]);
                if (registro.senal !== item.estado_senal) {
                    registro.marcador.setIcon(marcadorVehiculo(pos.lat, pos.lng, item.estado_senal).options.icon);
                    registro.senal = item.estado_senal;
                }
                registro.marcador.setTooltipContent(textoMarcador(item));
            }
        });
        Object.keys(marcadores).forEach(function (id) {
            if (!vigentes[id]) {
                mapa.removeLayer(marcadores[id].marcador);
                delete marcadores[id];
            }
        });
    }

    function ajustarFlota() {
        var puntos = [];
        Object.keys(marcadores).forEach(function (id) { puntos.push(marcadores[id].marcador.getLatLng()); });
        if (puntos.length) {
            mapa.fitBounds(L.latLngBounds(puntos), { padding: [40, 40], maxZoom: 16 });
        }
    }

    // ---------- Panel lateral ----------
    function itemRuta(item) {
        var el = crear('div', 'list-group-item list-group-item-action monitor-item' + (item.ruta.id === seleccionada ? ' active' : ''));
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.setAttribute('aria-pressed', item.ruta.id === seleccionada ? 'true' : 'false');

        var cabecera = crear('div', 'd-flex justify-content-between align-items-start gap-2');
        var titulo = crear('div');
        titulo.appendChild(crear('div', 'fw-semibold', item.conductor.nombre));
        var detalle = item.ruta.codigo;
        if (item.vehiculo) {
            detalle += ' · ' + item.vehiculo.placa + ' (' + (TEXTO_TIPO_VEHICULO[item.vehiculo.tipo] || item.vehiculo.tipo) + ')';
        }
        titulo.appendChild(crear('div', 'small text-muted', detalle));
        cabecera.appendChild(titulo);
        cabecera.appendChild(crear('span', 'badge ' + (CLASE_SENAL[item.estado_senal] || 'bg-secondary'), TEXTO_SENAL[item.estado_senal] || item.estado_senal));
        el.appendChild(cabecera);

        // Progreso resueltas / total
        var total = item.ruta.total_paradas;
        var resueltas = item.ruta.resueltas;
        var porcentaje = total > 0 ? Math.round((resueltas / total) * 100) : 0;
        var progreso = crear('div', 'mt-2');
        progreso.appendChild(crear('div', 'small', 'Paradas: ' + resueltas + ' de ' + total));
        var barra = crear('div', 'progress');
        barra.style.height = '6px';
        barra.setAttribute('role', 'progressbar');
        barra.setAttribute('aria-label', 'Progreso de la ruta');
        barra.setAttribute('aria-valuenow', String(porcentaje));
        barra.setAttribute('aria-valuemin', '0');
        barra.setAttribute('aria-valuemax', '100');
        var relleno = crear('div', 'progress-bar bg-success');
        relleno.style.width = porcentaje + '%';
        barra.appendChild(relleno);
        progreso.appendChild(barra);
        el.appendChild(progreso);

        // Próxima parada
        var proxima = item.proxima_parada;
        var lineaProxima = crear('div', 'small mt-2');
        if (proxima) {
            var etiqueta = proxima.estado === 'llegado' ? 'En la parada ' : 'Próxima: ';
            lineaProxima.textContent = etiqueta + proxima.codigo_guia + ' · ETA ' + formatoHora(proxima.eta) + ' · ' + formatoDistancia(proxima.distancia_m);
        } else {
            lineaProxima.textContent = 'Sin paradas pendientes';
        }
        el.appendChild(lineaProxima);

        // Batería y antigüedad del último punto
        var pos = item.posicion;
        var lineaPie = crear('div', 'small text-muted d-flex justify-content-between align-items-center mt-1');
        var texto = pos
            ? 'Batería ' + (pos.bateria_pct !== null ? pos.bateria_pct + ' %' : '—') + ' · ' + formatoAntiguedad(pos.antiguedad_s)
            : 'Sin posición todavía';
        lineaPie.appendChild(crear('span', '', texto));
        var enlace = crear('a', 'btn btn-link btn-sm p-0', 'Ver ruta');
        enlace.href = '../despacho/ruta_detalle.php?ruta_id=' + encodeURIComponent(item.ruta.id);
        enlace.addEventListener('click', function (ev) { ev.stopPropagation(); });
        lineaPie.appendChild(enlace);
        el.appendChild(lineaPie);

        function activar() { seleccionar(item.ruta.id, true); }
        el.addEventListener('click', activar);
        el.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); activar(); }
        });
        return el;
    }

    function pintarPanel(lista) {
        panel.textContent = '';
        if (!lista.length) {
            panel.appendChild(crear('div', 'p-3 text-muted', 'No hay rutas en curso con estos filtros.'));
            return;
        }
        lista.forEach(function (item) { panel.appendChild(itemRuta(item)); });
    }

    // ---------- Selección y detalle de una ruta ----------
    function dibujarDetalle(ruta) {
        capaDetalle.clearLayers();

        var linea = ruta.polyline_sugerida;
        if (typeof linea === 'string') {
            try { linea = JSON.parse(linea); } catch (e) { linea = null; }
        }
        if (Array.isArray(linea) && linea.length > 1) {
            dibujarPolyline(capaDetalle, linea, 'sugerida');   // la polyline se agrega al grupo (L.LayerGroup)
        }
        (ruta.paradas || []).forEach(function (parada) {
            marcadorParada({
                lat: parada.lat, lng: parada.lng, orden: parada.orden, estado: parada.estado,
                codigo_guia: parada.paquete ? parada.paquete.codigo_guia : ''
            }).addTo(capaDetalle);
        });
    }

    function cargarDetalle(id) {
        ciclosDesdeDetalle = 0;
        return obtenerJson('../api/rutas/ver.php?ruta_id=' + encodeURIComponent(id)).then(function (ruta) {
            if (id === seleccionada) { dibujarDetalle(ruta); }
        }).catch(function () {
            // M1 aún no disponible o sin permiso: el monitor sigue funcionando sin el detalle.
            if (id === seleccionada) { capaDetalle.clearLayers(); }
        });
    }

    function seleccionar(id, centrar) {
        seleccionada = id;
        pintarPanel(rutas);
        var registro = marcadores[id];
        if (centrar && registro) { mapa.setView(registro.marcador.getLatLng(), Math.max(mapa.getZoom(), 15)); }
        cargarDetalle(id);
    }

    // ---------- Refresco ----------
    function consulta() {
        var parametros = new URLSearchParams();
        if (filtroZona.value) { parametros.set('zona_id', filtroZona.value); }
        if (filtroSenal.value) { parametros.set('estado_senal', filtroSenal.value); }
        var texto = parametros.toString();
        return '../api/gps/flota.php' + (texto ? '?' + texto : '');
    }

    function refrescar() {
        if (enCurso) { return Promise.resolve(); }
        enCurso = true;
        return obtenerJson(consulta()).then(function (lista) {
            rutas = Array.isArray(lista) ? lista : [];
            completarZonasDesdeFlota(rutas);
            if (seleccionada !== null && !rutas.some(function (r) { return r.ruta.id === seleccionada; })) {
                seleccionada = null;
                capaDetalle.clearLayers();
            }
            actualizarMarcadores(rutas);
            pintarPanel(rutas);
            if (primeraCarga) { primeraCarga = false; ajustarFlota(); }

            if (seleccionada !== null) {
                ciclosDesdeDetalle += 1;
                if (ciclosDesdeDetalle >= DETALLE_CADA_N_CICLOS) { cargarDetalle(seleccionada); }
            }
            mostrarEstado('Actualizado ' + formatoHora(new Date().toISOString()), false);
        }).catch(function () {
            mostrarEstado('Sin conexión con el servidor. Reintentando…', true);
        }).then(function () { enCurso = false; });
    }

    function iniciarRefresco() {
        detenerRefresco();
        temporizador = setInterval(function () {
            if (!document.hidden) { refrescar(); }   // en pestaña oculta se pausa
        }, refrescoMs);
    }

    function detenerRefresco() {
        if (temporizador) { clearInterval(temporizador); temporizador = null; }
    }

    // ---------- Eventos de la barra ----------
    function alCambiarFiltro() {
        primeraCarga = true;   // al filtrar se vuelve a encuadrar la flota
        refrescar();
    }
    filtroZona.addEventListener('change', alCambiarFiltro);
    filtroSenal.addEventListener('change', alCambiarFiltro);
    botonCentrar.addEventListener('click', ajustarFlota);

    function elementoPantallaCompleta() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }
    botonPantalla.addEventListener('click', function () {
        if (elementoPantallaCompleta()) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else if (contenedor.requestFullscreen || contenedor.webkitRequestFullscreen) {
            (contenedor.requestFullscreen || contenedor.webkitRequestFullscreen).call(contenedor);
        }
    });
    function alCambiarPantallaCompleta() {
        botonPantalla.textContent = elementoPantallaCompleta() ? 'Salir de pantalla completa' : 'Pantalla completa';
        setTimeout(function () { mapa.invalidateSize(); }, 150);
    }
    document.addEventListener('fullscreenchange', alCambiarPantallaCompleta);
    document.addEventListener('webkitfullscreenchange', alCambiarPantallaCompleta);
    window.addEventListener('resize', function () { mapa.invalidateSize(); });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refrescar(); }   // al volver a la pestaña se actualiza de inmediato
    });

    // ---------- Arranque ----------
    cargarZonas().then(refrescar).then(iniciarRefresco);
})();
