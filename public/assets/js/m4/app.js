/*
 * app.js - Hoja de ruta del conductor (M4, public/conductor/index.php).
 * Carga mi_ruta.php (o la copia de IndexedDB), dibuja la ruta, arranca el GPS de M2 y
 * muestra la cola offline. No usa innerHTML: todo el texto va con ui.crear/textContent.
 */
(function (global) {
    'use strict';

    var contenedor = document.getElementById('conductor-app');
    if (!contenedor) {
        return;
    }

    var ABIERTAS = ['pendiente', 'en_camino', 'llegado'];
    var estado = {
        ruta: null,
        sinDatos: false,
        cargando: false,
        gps: null,
        recargaTimer: null
    };

    function el(id) { return document.getElementById(id); }

    function paradaActual(ruta) {
        if (!ruta || !Array.isArray(ruta.paradas)) { return null; }
        var actual = null;
        ruta.paradas.forEach(function (p) {
            if (!actual && (p.estado === 'en_camino' || p.estado === 'llegado')) {
                actual = p;
            }
        });
        return actual;
    }

    function abiertas(ruta) {
        return (ruta && ruta.paradas ? ruta.paradas : []).filter(function (p) {
            return ABIERTAS.indexOf(p.estado) >= 0;
        }).length;
    }

    // ---------------------------------------------------------------- Render

    function actualizarConexion() {
        var badge = el('estado-conexion');
        if (!badge) { return; }
        if (global.navigator.onLine) {
            badge.textContent = 'En línea';
            badge.className = 'badge bg-success';
        } else {
            badge.textContent = 'Sin conexión';
            badge.className = 'badge bg-secondary';
        }
    }

    function crearNavegacion(parada) {
        var grupo = ui.crear('div', { className: 'd-flex flex-wrap gap-2' });
        [['Waze', 'waze'], ['Google Maps', 'google'], ['Apple Maps', 'apple']].forEach(function (par) {
            var url = parada.navegacion ? parada.navegacion[par[1]] : null;
            if (!url) { return; }
            grupo.appendChild(ui.crear('a', {
                className: 'btn btn-outline-primary btn-sm',
                href: url,
                target: '_blank',
                rel: 'noopener'
            }, par[0]));
        });
        var telefono = parada.paquete ? parada.paquete.destinatario_telefono : null;
        if (telefono) {
            var numero = String(telefono).replace(/[^+0-9]/g, '');
            if (numero) {
                grupo.appendChild(ui.crear('a', { className: 'btn btn-outline-secondary btn-sm', href: 'tel:' + numero },
                    ui.crear('i', { className: 'bi bi-telephone', 'aria-hidden': 'true' }), ' Llamar'));
            }
        }
        return grupo;
    }

    function datosParada(parada) {
        var p = parada.paquete || {};
        var partes = [];
        if (p.direccion_entrega) { partes.push(p.direccion_entrega); }
        if (p.referencia) { partes.push('Ref.: ' + p.referencia); }
        return partes.join(' · ');
    }

    function ventana(parada) {
        var p = parada.paquete || {};
        if (!p.ventana_inicio && !p.ventana_fin) { return null; }
        return 'Ventana ' + (p.ventana_inicio ? M4.hora(p.ventana_inicio) : '—') + '–' + (p.ventana_fin ? M4.hora(p.ventana_fin) : '—');
    }

    function renderParadaActual(ruta) {
        var cont = el('parada-actual');
        cont.textContent = '';
        var actual = paradaActual(ruta);
        if (!actual) { return; }

        var p = actual.paquete || {};
        var tarjeta = ui.crear('section', { className: 'card border-success rp-m4-parada-actual' });
        var cuerpo = ui.crear('div', { className: 'card-body' });

        cuerpo.appendChild(ui.crear('div', { className: 'd-flex justify-content-between align-items-start gap-2' },
            ui.crear('div', null,
                ui.crear('div', { className: 'text-muted small', texto: 'Parada ' + actual.orden + ' · Parada actual' }),
                ui.crear('h2', { className: 'h5 mb-0', texto: p.destinatario_nombre || 'Destinatario' })),
            ui.badgeEstado(actual.estado)));

        if (datosParada(actual)) {
            cuerpo.appendChild(ui.crear('p', { className: 'mb-1 mt-2', texto: datosParada(actual) }));
        }
        var detalles = [];
        if (ventana(actual)) { detalles.push(ventana(actual)); }
        if (actual.eta) { detalles.push('ETA ' + M4.hora(actual.eta)); }
        if (p.cobro_contra_entrega !== null && Number(p.cobro_contra_entrega) > 0) {
            detalles.push('Cobrar ' + ui.formatoMoneda(p.cobro_contra_entrega, p.moneda));
        }
        if (Number(p.peso_kg) > 0) {
            detalles.push(ui.formatoNumero(p.peso_kg, 1) + ' kg');
        }
        if (detalles.length) {
            cuerpo.appendChild(ui.crear('ul', { className: 'list-unstyled small text-muted mb-2 mt-2' },
                detalles.map(function (texto) { return ui.crear('li', { texto: texto }); })));
        }
        if (p.notas) {
            cuerpo.appendChild(ui.crear('div', { className: 'alert alert-light border small py-2', texto: p.notas }));
        }

        cuerpo.appendChild(crearNavegacion(actual));

        var acciones = ui.crear('div', { className: 'd-grid gap-2 mt-3' });
        if (actual.estado === 'en_camino') {
            var boton = ui.crear('button', { type: 'button', className: 'btn btn-primary btn-touch' },
                ui.crear('i', { className: 'bi bi-geo-alt-fill', 'aria-hidden': 'true' }), ' Llegué');
            boton.addEventListener('click', function () { enviarLlegada(actual, boton); });
            acciones.appendChild(boton);
        } else if (actual.estado === 'llegado') {
            acciones.appendChild(ui.crear('a', {
                className: 'btn btn-success btn-touch',
                href: 'parada.php?id=' + encodeURIComponent(actual.id)
            }, ui.crear('i', { className: 'bi bi-check2-circle', 'aria-hidden': 'true' }), ' Entregar'));
            acciones.appendChild(ui.crear('a', {
                className: 'btn btn-outline-danger btn-touch',
                href: 'parada.php?id=' + encodeURIComponent(actual.id) + '#fallo'
            }, ui.crear('i', { className: 'bi bi-x-octagon', 'aria-hidden': 'true' }), ' No se pudo entregar'));
        }
        cuerpo.appendChild(acciones);

        tarjeta.appendChild(cuerpo);
        cont.appendChild(tarjeta);
    }

    function renderLista(ruta) {
        var cont = el('lista-paradas');
        cont.textContent = '';
        if (!ruta || !Array.isArray(ruta.paradas)) { return; }

        var actual = paradaActual(ruta);
        var restantes = ruta.paradas.filter(function (p) { return !actual || Number(p.id) !== Number(actual.id); });

        if (!restantes.length) {
            cont.appendChild(ui.crear('div', { className: 'list-group-item text-muted', texto: 'No quedan otras paradas.' }));
            return;
        }

        restantes.forEach(function (parada) {
            var p = parada.paquete || {};
            var item = ui.crear('a', {
                className: 'list-group-item list-group-item-action rp-m4-parada',
                href: 'parada.php?id=' + encodeURIComponent(parada.id)
            });
            var fila = ui.crear('div', { className: 'd-flex gap-3 align-items-start' });
            fila.appendChild(ui.crear('span', { className: 'rp-m4-orden', texto: String(parada.orden) }));

            var cuerpo = ui.crear('div', { className: 'flex-grow-1 min-w-0' });
            cuerpo.appendChild(ui.crear('div', { className: 'd-flex justify-content-between align-items-start gap-2' },
                ui.crear('strong', { className: 'text-truncate', texto: p.destinatario_nombre || 'Destinatario' }),
                ui.badgeEstado(parada.estado)));
            if (p.direccion_entrega) {
                cuerpo.appendChild(ui.crear('div', { className: 'small text-muted text-truncate', texto: p.direccion_entrega }));
            }
            var meta = [];
            if (parada.eta) { meta.push('ETA ' + M4.hora(parada.eta)); }
            if (ventana(parada)) { meta.push(ventana(parada)); }
            if (p.cobro_contra_entrega !== null && Number(p.cobro_contra_entrega) > 0) {
                meta.push('Cobro ' + ui.formatoMoneda(p.cobro_contra_entrega, p.moneda));
            }
            if (meta.length) {
                cuerpo.appendChild(ui.crear('div', { className: 'small text-muted', texto: meta.join(' · ') }));
            }
            fila.appendChild(cuerpo);
            item.appendChild(fila);
            cont.appendChild(item);
        });
    }

    function renderErrores(lista) {
        var cont = el('cola-errores');
        cont.textContent = '';
        if (!lista || !lista.length) { return; }

        var tarjeta = ui.crear('section', { className: 'card border-danger' });
        var cuerpo = ui.crear('div', { className: 'card-body' });
        cuerpo.appendChild(ui.crear('h2', { className: 'h6 text-danger' },
            ui.crear('i', { className: 'bi bi-exclamation-triangle', 'aria-hidden': 'true' }), ' Operaciones con error'));
        cuerpo.appendChild(ui.crear('p', { className: 'small text-muted',
            texto: 'El servidor rechazó estas operaciones. Corrige el problema y reintenta; no se envían solas.' }));

        lista.forEach(function (op) {
            var campos = op.campos || {};
            var referencia = 'Parada ' + (campos.parada_id !== undefined ? campos.parada_id : '—') +
                (campos.motivo_fallo ? ' · ' + ui.motivoTexto(campos.motivo_fallo) : '');
            var fila = ui.crear('div', { className: 'border-top pt-2 mt-2' },
                ui.crear('div', { className: 'small fw-semibold', texto: referencia }),
                ui.crear('div', { className: 'small text-danger', texto: op.ultimo_error || 'Error al sincronizar.' }));
            var boton = ui.crear('button', { type: 'button', className: 'btn btn-sm btn-outline-danger mt-2' },
                ui.crear('i', { className: 'bi bi-arrow-repeat', 'aria-hidden': 'true' }), ' Reintentar');
            boton.addEventListener('click', function () {
                ui.bloquear(boton, true, 'Reintentando…');
                M4Cola.reintentar(op.id).catch(function () {
                    ui.bloquear(boton, false);
                });
            });
            fila.appendChild(boton);
            cuerpo.appendChild(fila);
        });

        tarjeta.appendChild(cuerpo);
        cont.appendChild(tarjeta);
    }

    function render() {
        var ruta = estado.ruta;
        var cab = ruta && ruta.ruta ? ruta.ruta : null;
        var total = cab ? Number(cab.total || 0) : 0;
        var resueltas = cab ? Number(cab.resueltas || 0) : 0;
        var pct = total > 0 ? Math.round(resueltas / total * 100) : 0;

        el('ruta-codigo').textContent = cab ? cab.codigo : 'Sin ruta';
        el('vehiculo').textContent = ruta && ruta.vehiculo
            ? String(ruta.vehiculo.placa || '') + (ruta.vehiculo.tipo ? ' · ' + ruta.vehiculo.tipo : '')
            : '—';
        el('progreso').textContent = resueltas + '/' + total;
        el('barra-progreso').style.width = pct + '%';
        el('progreso-barra').setAttribute('aria-valuenow', String(pct));

        var enCurso = cab && cab.estado === 'en_curso';
        var planificada = cab && cab.estado === 'planificada';
        el('btn-iniciar').classList.toggle('d-none', !planificada);
        el('btn-finalizar').classList.toggle('d-none', !(enCurso && abiertas(ruta) === 0));

        var aviso = el('aviso-ruta');
        if (estado.sinDatos) {
            aviso.textContent = 'Sin conexión y sin ruta guardada en este teléfono. Conéctate y vuelve a abrir la app.';
            aviso.className = 'alert alert-warning mt-3 mb-0';
        } else if (!ruta) {
            aviso.textContent = 'No tienes una ruta activa para hoy.';
            aviso.className = 'alert alert-info mt-3 mb-0';
        } else if (enCurso && abiertas(ruta) === 0) {
            aviso.textContent = 'Todas las paradas están resueltas. Puedes finalizar la ruta.';
            aviso.className = 'alert alert-success mt-3 mb-0';
        } else {
            aviso.textContent = '';
            aviso.className = 'alert mt-3 mb-0 d-none';
        }

        renderParadaActual(ruta);
        renderLista(ruta);
        actualizarConexion();
        actualizarContadores();
    }

    // ---------------------------------------------------------------- Estado y contadores

    function actualizarGps(info) {
        estado.gps = info || estado.gps;
        var g = estado.gps || {};
        var estadoTexto = 'Inactivo';
        if (g.activo) {
            if (g.permiso === 'concedido') { estadoTexto = 'Activo'; }
            else if (g.permiso === 'denegado') { estadoTexto = 'Permiso denegado'; }
            else { estadoTexto = 'Iniciando…'; }
        } else if (g.permiso === 'denegado') {
            estadoTexto = 'Permiso denegado';
        }
        if (el('gps-estado')) { el('gps-estado').textContent = estadoTexto; }
        if (el('gps-precision')) {
            el('gps-precision').textContent = g.ultimaPrecision !== null && g.ultimaPrecision !== undefined
                ? Math.round(g.ultimaPrecision) + ' m'
                : '—';
        }
        if (el('gps-pendientes')) { el('gps-pendientes').textContent = String(g.pendientes || 0); }
        actualizarContadores();
    }

    function actualizarContadores() {
        if (!el('pendientes')) { return; }
        Promise.all([M4Cola.contar(), M4Cola.errores()]).then(function (resultado) {
            var operaciones = resultado[0];
            var errores = resultado[1];
            var gps = estado.gps && estado.gps.pendientes ? estado.gps.pendientes : 0;
            var total = operaciones + gps;
            el('pendientes').textContent = total + (total === 1 ? ' pendiente' : ' pendientes');
            if (el('ultima-sincronizacion')) {
                var opSync = M4Cola.ultimaSincronizacion();
                var gpsSync = estado.gps && estado.gps.ultimoEnvio ? estado.gps.ultimoEnvio : null;
                var ultima = null;
                if (opSync && gpsSync) {
                    ultima = new Date(opSync) > new Date(gpsSync) ? opSync : gpsSync;
                } else {
                    ultima = gpsSync || opSync;
                }
                el('ultima-sincronizacion').textContent = ultima ? M4.horaRelativa(ultima) : '—';
            }
            renderErrores(errores);
        }).catch(function () { /* IndexedDB no disponible */ });
    }

    // ---------------------------------------------------------------- Acciones

    function enviarLlegada(parada, boton) {
        ui.bloquear(boton, true, 'Guardando…');
        M4.posicion(5000).then(function (pos) {
            var campos = { parada_id: Number(parada.id), ocurrido_at: M4.horaIso() };
            if (pos) {
                campos.lat = pos.lat;
                campos.lng = pos.lng;
                campos.precision_m = pos.precision_m;
            }
            return M4Cola.encolar({
                endpoint: 'conductor/llegada.php',
                tipo: 'json',
                campos: campos,
                parada_id: Number(parada.id),
                efecto: 'llegado'
            });
        }).then(function () {
            return M4.avanzarRutaLocal(parada.id, 'llegado');
        }).then(function (ruta) {
            if (ruta) { estado.ruta = ruta; }
            render();
            ui.toast(global.navigator.onLine ? 'Llegada guardada.' : 'Llegada guardada sin conexión.', 'success');
        }).catch(function (error) {
            ui.bloquear(boton, false);
            ui.toast((error && (error.mensaje || error.message)) || 'No se pudo guardar la llegada.', 'danger');
        });
    }

    function iniciarRuta() {
        if (!estado.ruta || !estado.ruta.ruta) { return; }
        var boton = el('btn-iniciar');
        var rutaId = Number(estado.ruta.ruta.id);
        ui.bloquear(boton, true, 'Iniciando…');
        api.post('conductor/iniciar_ruta.php', { ruta_id: rutaId }).then(function () {
            ui.toast('Ruta iniciada. El GPS ya está enviando tu ubicación.', 'success');
            return cargarRuta(true);
        }).catch(function () {
            ui.bloquear(boton, false);
        });
    }

    function finalizarRuta() {
        if (!estado.ruta || !estado.ruta.ruta) { return; }
        var rutaId = Number(estado.ruta.ruta.id);
        ui.confirmar('¿Finalizar la ruta? No podrás registrar más entregas en ella.', {
            titulo: 'Finalizar ruta', textoOk: 'Finalizar', peligro: true
        }).then(function (aceptado) {
            if (!aceptado) { return; }
            var boton = el('btn-finalizar');
            ui.bloquear(boton, true, 'Finalizando…');

            M4Cola.sincronizar().then(function () {
                return M4Cola.pendientes();
            }).then(function (pendientes) {
                if (pendientes.length > 0) {
                    throw new Error('Aún hay ' + pendientes.length + ' operación(es) sin sincronizar. Conéctate y espera a que terminen.');
                }
                return (global.GpsTracker ? global.GpsTracker.enviarPendientes() : Promise.resolve());
            }).then(function () {
                return api.post('conductor/finalizar_ruta.php', { ruta_id: rutaId });
            }).then(function () {
                if (global.GpsTracker) { global.GpsTracker.detener(); }
                ui.toast('Ruta finalizada.', 'success');
                return cargarRuta(true);
            }).catch(function (error) {
                ui.bloquear(boton, false);
                ui.toast((error && (error.mensaje || error.message)) || 'No se pudo finalizar la ruta.', 'danger');
            });
        });
    }

    // ---------------------------------------------------------------- Carga de la ruta

    function configurarGps() {
        if (!global.GpsTracker) { return; }
        if (estado.ruta && estado.ruta.ruta && estado.ruta.ruta.estado === 'en_curso') {
            var config = estado.ruta.config || {};
            global.GpsTracker.iniciar({
                rutaId: Number(estado.ruta.ruta.id),
                intervaloS: Number(config.gps_intervalo_s) || 10,
                alCambiarEstado: actualizarGps
            }).catch(function () { /* el GPS no impide usar la app */ });
        } else {
            global.GpsTracker.detener();
            actualizarGps(global.GpsTracker.estado());
        }
    }

    function cargarRuta(permitirRed) {
        if (estado.cargando) { return Promise.resolve(); }
        estado.cargando = true;

        return M4.obtenerRuta(!!permitirRed).then(function (ruta) {
            estado.cargando = false;
            estado.sinDatos = ruta === undefined;
            estado.ruta = ruta === undefined ? null : ruta;
            render();
            configurarGps();
        }).catch(function (error) {
            estado.cargando = false;
            if (error && error.codigo === 'NO_AUTENTICADO') { return; }
            estado.sinDatos = true;
            estado.ruta = null;
            render();
            ui.toast('No se pudo cargar la ruta. Revisa tu conexión.', 'danger');
        });
    }

    function programarRecarga() {
        global.clearTimeout(estado.recargaTimer);
        estado.recargaTimer = global.setTimeout(function () {
            cargarRuta(true);
        }, 800);
    }

    // ---------------------------------------------------------------- Eventos

    el('btn-iniciar').addEventListener('click', iniciarRuta);
    el('btn-finalizar').addEventListener('click', finalizarRuta);
    el('btn-recargar').addEventListener('click', function () {
        cargarRuta(true).then(function () { ui.toast('Ruta actualizada.', 'success'); });
    });

    global.addEventListener('online', function () {
        actualizarConexion();
        M4Cola.sincronizar().then(programarRecarga).catch(function () { /* se reintenta */ });
    });
    global.addEventListener('offline', actualizarConexion);
    document.addEventListener('m4:cola', function () {
        global.clearTimeout(estado.recargaTimer);
        estado.recargaTimer = global.setTimeout(actualizarContadores, 300);
    });
    document.addEventListener('m4:operacion-sincronizada', programarRecarga);
    document.addEventListener('m4:operacion-error', function (evento) {
        var detalle = evento.detail || {};
        var error = detalle.error || {};
        ui.toast('No se pudo sincronizar: ' + ((error.mensaje || error.message) || 'error del servidor'), 'danger');
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && global.navigator.onLine) {
            cargarRuta(true);
            M4Cola.sincronizar().catch(function () { /* se reintenta */ });
        }
    });

    // ---------------------------------------------------------------- Arranque

    M4Cola.iniciarAuto();
    M4.registrarServiceWorker();
    M4.iniciarBateria(function (porcentaje) {
        if (el('bateria')) { el('bateria').textContent = porcentaje + ' %'; }
    });
    actualizarConexion();
    cargarRuta(true);

    global.setInterval(function () {
        if (document.visibilityState === 'visible') {
            actualizarContadores();
            if (global.GpsTracker) { actualizarGps(global.GpsTracker.estado()); }
        }
    }, 10000);
})(window);
