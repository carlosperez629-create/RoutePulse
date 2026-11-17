/**
 * playback.js - Playback de recorridos (M2). Pantalla: public/monitor/playback.php.
 * Datos: GET api/gps/recorrido.php (real, sugerida, paradas, estadísticas, eventos, integridad).
 * Usa mapa_util.js. Todo texto del servidor se inserta con textContent (nunca innerHTML).
 * Las peticiones usan fetch en una sola función (obtenerJson); cuando api.js esté disponible se cambia solo ahí.
 */
(function () {
    'use strict';

    var raiz = document.getElementById('playback');
    if (!raiz) { return; }

    var zonaHoraria = raiz.getAttribute('data-zona-horaria') || 'UTC';
    var TEXTO_ESTADO_RUTA = { en_curso: 'En curso', completada: 'Completada' };

    // ---------- Elementos ----------
    var campoFecha = document.getElementById('pb-fecha');
    var selectorRuta = document.getElementById('pb-ruta');
    var botonVerificar = document.getElementById('pb-verificar');
    var insigniaIntegridad = document.getElementById('pb-integridad');
    var aviso = document.getElementById('pb-aviso');
    var botonPlay = document.getElementById('pb-play');
    var botonesVelocidad = raiz.querySelectorAll('[data-velocidad]');
    var deslizador = document.getElementById('pb-slider');
    var reloj = document.getElementById('pb-reloj');
    var listaEventos = document.getElementById('pb-lista-eventos');
    var tarjetas = {
        distReal: document.getElementById('pb-dist-real'),
        distSug: document.getElementById('pb-dist-sug'),
        desv: document.getElementById('pb-desv'),
        duracion: document.getElementById('pb-duracion'),
        paradas: document.getElementById('pb-paradas'),
        eventos: document.getElementById('pb-eventos')
    };

    // ---------- Mapa y estado ----------
    var mapa = mapaCrear('mapa-playback', {});
    var capa = L.layerGroup().addTo(mapa);
    var marcadorVehiculoPlayback = null;

    var estado = {
        rutaId: null,
        puntos: [],          // [{lat, lng, t}] con t en segundos (epoch)
        t0: 0, t1: 0, tActual: 0,
        velocidad: 1,
        reproduciendo: false,
        ultimoCuadro: 0,
        cuadro: null
    };

    // ---------- Utilidades de formato ----------
    function crear(etiqueta, clase, texto) {
        var el = document.createElement(etiqueta);
        if (clase) { el.className = clase; }
        if (texto !== undefined && texto !== null) { el.textContent = texto; }
        return el;
    }

    function formatoHora(milisegundos, conSegundos) {
        var opciones = { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: zonaHoraria };
        if (conSegundos) { opciones.second = '2-digit'; }
        try {
            return new Intl.DateTimeFormat('es', opciones).format(new Date(milisegundos));
        } catch (e) {
            delete opciones.timeZone;
            return new Intl.DateTimeFormat('es', opciones).format(new Date(milisegundos));
        }
    }

    function formatoDecimal(valor, decimales) {
        return valor.toLocaleString('es', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
    }

    function formatoKm(valor) {
        return valor === null || valor === undefined ? '—' : formatoDecimal(valor, 1) + ' km';
    }

    function formatoDuracion(minutos) {
        if (minutos === null || minutos === undefined) { return '—'; }
        var total = Math.round(minutos);
        var horas = Math.floor(total / 60);
        var resto = total % 60;
        return horas > 0 ? horas + ' h ' + resto + ' min' : resto + ' min';
    }

    function formatoPorcentaje(valor) {
        if (valor === null || valor === undefined) { return '—'; }
        return (valor > 0 ? '+' : '') + formatoDecimal(valor, 1) + ' %';
    }

    function mostrarAviso(texto, esError) {
        aviso.textContent = texto || '';
        aviso.classList.toggle('text-danger', !!esError);
        aviso.classList.toggle('text-muted', !esError);
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

    // ---------- Selección de ruta ----------
    function cargarRutas(fecha) {
        selectorRuta.textContent = '';
        selectorRuta.appendChild(crear('option', '', 'Cargando rutas…'));
        return obtenerJson('../api/rutas/listar.php?fecha=' + encodeURIComponent(fecha) + '&estado=en_curso,completada&por_pagina=100')
            .then(function (rutas) {
                selectorRuta.textContent = '';
                rutas = Array.isArray(rutas) ? rutas : [];
                if (!rutas.length) {
                    selectorRuta.appendChild(crear('option', '', 'No hay rutas con recorrido en esta fecha'));
                    return null;
                }
                rutas.forEach(function (r) {
                    var texto = r.codigo + ' · ' + (TEXTO_ESTADO_RUTA[r.estado] || r.estado);
                    if (r.conductor && r.conductor.nombre) { texto += ' · ' + r.conductor.nombre; }
                    var opcion = crear('option', '', texto);
                    opcion.value = String(r.id);
                    selectorRuta.appendChild(opcion);
                });
                return rutas[0].id;
            })
            .catch(function () {
                selectorRuta.textContent = '';
                selectorRuta.appendChild(crear('option', '', 'No se pudo cargar la lista de rutas'));
                mostrarAviso('Puedes abrir esta pantalla con ?ruta_id=N para ver una ruta directamente.', false);
                return null;
            });
    }

    // ---------- Reproducción ----------
    function posicionEn(t) {
        var p = estado.puntos;
        var n = p.length;
        if (!n) { return null; }
        if (t <= p[0].t) { return [p[0].lat, p[0].lng]; }
        if (t >= p[n - 1].t) { return [p[n - 1].lat, p[n - 1].lng]; }
        var bajo = 0;
        var alto = n - 1;
        while (alto - bajo > 1) {
            var medio = (bajo + alto) >> 1;
            if (p[medio].t <= t) { bajo = medio; } else { alto = medio; }
        }
        var a = p[bajo];
        var b = p[alto];
        var f = (t - a.t) / (b.t - a.t);
        return [a.lat + (b.lat - a.lat) * f, a.lng + (b.lng - a.lng) * f];
    }

    function actualizarVista() {
        var pos = posicionEn(estado.tActual);
        if (pos && marcadorVehiculoPlayback) {
            marcadorVehiculoPlayback.setLatLng(pos);
            if (!mapa.getBounds().contains(pos)) { mapa.panTo(pos); }
        }
        deslizador.value = String(Math.round(estado.tActual - estado.t0));
        reloj.textContent = estado.puntos.length ? formatoHora(estado.tActual * 1000, true) : '—';
    }

    function pausar() {
        estado.reproduciendo = false;
        if (estado.cuadro) { cancelAnimationFrame(estado.cuadro); estado.cuadro = null; }
        botonPlay.textContent = 'Reproducir';
        botonPlay.setAttribute('aria-label', 'Reproducir');
    }

    function paso(ahora) {
        if (!estado.reproduciendo) { return; }
        var segundos = Math.min(1, (ahora - estado.ultimoCuadro) / 1000);   // tope: evita saltos al volver de otra pestaña
        estado.ultimoCuadro = ahora;
        estado.tActual += segundos * estado.velocidad;
        if (estado.tActual >= estado.t1) {
            estado.tActual = estado.t1;
            actualizarVista();
            pausar();
            return;
        }
        actualizarVista();
        estado.cuadro = requestAnimationFrame(paso);
    }

    function reproducir() {
        if (estado.puntos.length < 2) { return; }
        if (estado.tActual >= estado.t1) { estado.tActual = estado.t0; }
        estado.reproduciendo = true;
        estado.ultimoCuadro = performance.now();
        botonPlay.textContent = 'Pausar';
        botonPlay.setAttribute('aria-label', 'Pausar');
        estado.cuadro = requestAnimationFrame(paso);
    }

    botonPlay.addEventListener('click', function () {
        if (estado.reproduciendo) { pausar(); } else { reproducir(); }
    });

    Array.prototype.forEach.call(botonesVelocidad, function (boton) {
        boton.addEventListener('click', function () {
            estado.velocidad = parseInt(boton.getAttribute('data-velocidad'), 10) || 1;
            Array.prototype.forEach.call(botonesVelocidad, function (b) {
                var activo = b === boton;
                b.classList.toggle('active', activo);
                b.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
        });
    });
    botonesVelocidad[0].classList.add('active');

    deslizador.addEventListener('input', function () {
        estado.tActual = estado.t0 + (parseFloat(deslizador.value) || 0);
        actualizarVista();
    });

    // ---------- Pintado ----------
    function pintarIntegridad(valor) {
        insigniaIntegridad.className = 'badge';
        if (valor === true) {
            insigniaIntegridad.classList.add('text-bg-success');
            insigniaIntegridad.textContent = 'Cadena íntegra';
        } else if (valor === false) {
            insigniaIntegridad.classList.add('text-bg-danger');
            insigniaIntegridad.textContent = 'Cadena alterada';
        } else {
            insigniaIntegridad.classList.add('text-bg-light');
            insigniaIntegridad.textContent = 'Sin verificar';
        }
    }

    function tooltipParada(parada) {
        var caja = crear('div');
        caja.appendChild(crear('strong', '', 'Parada ' + parada.orden + ' · ' + parada.codigo_guia));
        caja.appendChild(document.createElement('br'));
        var llegada = parada.llegada_real ? formatoHora(Date.parse(parada.llegada_real), false) : '—';
        var salida = parada.salida_real ? formatoHora(Date.parse(parada.salida_real), false) : '—';
        caja.appendChild(document.createTextNode('Llegada: ' + llegada + ' · Salida: ' + salida));
        return caja;
    }

    function pintarEventos(eventos) {
        listaEventos.textContent = '';
        (eventos || []).forEach(function (e) {
            var hora = e.creado_at ? formatoHora(Date.parse(e.creado_at), false) : '—';
            var texto = hora + ' · ' + (e.tipo === 'entrada' ? 'Entró a la geocerca' : 'Salió de la geocerca')
                + ' de la parada ' + e.parada.orden + ' (' + e.paquete.codigo_guia + ') a '
                + formatoDecimal(e.distancia_m, 0) + ' m' + (e.notificado ? ' · cliente avisado' : '');
            listaEventos.appendChild(crear('li', '', texto));
        });
    }

    function pintar(d) {
        pausar();
        capa.clearLayers();
        marcadorVehiculoPlayback = null;

        if (Array.isArray(d.sugerida) && d.sugerida.length > 1) { dibujarPolyline(capa, d.sugerida, 'sugerida'); }
        if (Array.isArray(d.real) && d.real.length > 1) { dibujarPolyline(capa, d.real, 'real'); }

        var limites = [];
        (d.sugerida || []).forEach(function (p) { limites.push([p[0], p[1]]); });
        (d.real || []).forEach(function (p) { limites.push([p[0], p[1]]); });
        (d.paradas || []).forEach(function (parada) {
            var m = marcadorParada(parada);
            m.setTooltipContent(tooltipParada(parada));
            m.addTo(capa);
            limites.push([parada.lat, parada.lng]);
        });
        if (limites.length) { mapa.fitBounds(L.latLngBounds(limites), { padding: [30, 30] }); }

        // Puntos reales para la reproducción.
        estado.puntos = (d.real || []).map(function (p) { return { lat: p[0], lng: p[1], t: Date.parse(p[2]) / 1000 }; });
        var hayRecorrido = estado.puntos.length >= 2 && estado.puntos[estado.puntos.length - 1].t > estado.puntos[0].t;
        if (estado.puntos.length) {
            estado.t0 = estado.puntos[0].t;
            estado.t1 = estado.puntos[estado.puntos.length - 1].t;
            estado.tActual = estado.t0;
            marcadorVehiculoPlayback = marcadorVehiculo(estado.puntos[0].lat, estado.puntos[0].lng, 'en_linea');
            marcadorVehiculoPlayback.addTo(capa);
        }
        deslizador.min = '0';
        deslizador.max = String(hayRecorrido ? Math.round(estado.t1 - estado.t0) : 0);
        deslizador.disabled = !hayRecorrido;
        botonPlay.disabled = !hayRecorrido;
        actualizarVista();
        mostrarAviso(hayRecorrido ? '' : 'Esta ruta todavía no tiene puntos GPS para reproducir.', false);

        // Tarjetas.
        var est = d.estadisticas || {};
        tarjetas.distReal.textContent = formatoKm(est.distancia_real_km);
        tarjetas.distSug.textContent = formatoKm(est.distancia_sugerida_km);
        tarjetas.desv.textContent = formatoPorcentaje(est.desviacion_pct);
        tarjetas.duracion.textContent = formatoDuracion(est.duracion_real_min);
        tarjetas.paradas.textContent = formatoDuracion(est.tiempo_en_paradas_min);
        tarjetas.eventos.textContent = String((d.eventos || []).length);
        pintarEventos(d.eventos);
        pintarIntegridad(null);
        botonVerificar.disabled = false;
    }

    function cargarRecorrido(rutaId) {
        estado.rutaId = rutaId;
        mostrarAviso('Cargando recorrido…', false);
        botonVerificar.disabled = true;
        return obtenerJson('../api/gps/recorrido.php?ruta_id=' + encodeURIComponent(rutaId)).then(function (d) {
            if (rutaId !== estado.rutaId) { return; }   // se eligió otra ruta mientras cargaba
            pintar(d);
        }).catch(function (error) {
            mostrarAviso(error.message, true);
        });
    }

    function verificarIntegridad() {
        if (estado.rutaId === null) { return; }
        botonVerificar.disabled = true;
        mostrarAviso('Verificando la cadena de hashes…', false);
        obtenerJson('../api/gps/recorrido.php?ruta_id=' + encodeURIComponent(estado.rutaId) + '&verificar=1').then(function (d) {
            pintarIntegridad(d.integridad_ok);
            mostrarAviso('', false);
        }).catch(function (error) {
            mostrarAviso(error.message, true);
        }).then(function () { botonVerificar.disabled = false; });
    }

    // ---------- Eventos y arranque ----------
    botonVerificar.addEventListener('click', verificarIntegridad);

    selectorRuta.addEventListener('change', function () {
        if (selectorRuta.value) { cargarRecorrido(parseInt(selectorRuta.value, 10)); }
    });

    campoFecha.addEventListener('change', function () {
        if (!campoFecha.value) { return; }
        cargarRutas(campoFecha.value).then(function (primera) {
            if (primera !== null) { cargarRecorrido(primera); }
        });
    });

    pintarIntegridad(null);
    var rutaDirecta = parseInt(new URLSearchParams(window.location.search).get('ruta_id'), 10);
    if (rutaDirecta > 0) {
        // Abierta desde otra pantalla (o a mano): se carga esa ruta; la lista de la fecha sigue disponible.
        cargarRutas(campoFecha.value).then(function () {
            var opcion = crear('option', '', 'Ruta ' + rutaDirecta + ' (de la dirección)');
            opcion.value = String(rutaDirecta);
            selectorRuta.insertBefore(opcion, selectorRuta.firstChild);
            selectorRuta.value = String(rutaDirecta);
            cargarRecorrido(rutaDirecta);
        });
    } else {
        cargarRutas(campoFecha.value).then(function (primera) {
            if (primera !== null) { cargarRecorrido(primera); }
        });
    }
})();
