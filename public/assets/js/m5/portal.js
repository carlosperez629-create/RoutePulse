/*
 * M5 - Portal del cliente: lógica de la página pública t.php.
 * - Consulta api/tracking/estado.php con el token (sin sesión) y dibuja el estado, el mapa y el historial.
 * - Se refresca solo: cada `intervalo_refresco_s` en tránsito/incidencia, cada 60 s con la pestaña oculta
 *   o en estados previos a la ruta, y se detiene en estados terminales.
 * - Reprogramación: formulario modal que llama a api/tracking/reprogramar.php.
 * Todo texto que viene del servidor se inserta con textContent (nunca innerHTML).
 */
(() => {
    'use strict';

    const raiz = document.getElementById('portal');
    if (!raiz) {
        return;
    }

    const TERMINALES = ['entregado', 'cancelado', 'devuelto'];
    const ACTIVOS = ['en_transito', 'incidencia'];
    const REFRESCO_LENTO_S = 60;
    const REFRESCO_ERROR_S = 30;

    const cfg = {
        token: raiz.dataset.token || '',
        tokenValido: raiz.dataset.tokenValido === '1',
        base: raiz.dataset.base || '',
        zona: raiz.dataset.zona || undefined,
        paradasEnVivo: parseInt(raiz.dataset.paradasEnVivo, 10) || 3,
        diasMax: parseInt(raiz.dataset.diasMax, 10) || 7,
    };

    const $ = (id) => document.getElementById(id);
    const el = {
        guia: $('portal-guia'),
        aviso: $('portal-aviso'),
        cargando: $('portal-cargando'),
        invalido: $('portal-invalido'),
        invalidoTexto: $('portal-invalido-texto'),
        conexion: $('portal-conexion'),
        contenido: $('portal-contenido'),
        estado: $('portal-estado'),
        mensaje: $('portal-mensaje'),
        eta: $('portal-eta'),
        btnReprogramar: $('portal-btn-reprogramar'),
        sinReprogramar: $('portal-sin-reprogramar'),
        mapaTarjeta: $('portal-mapa-tarjeta'),
        mapaDiv: $('portal-mapa'),
        paradas: $('portal-paradas'),
        sinUbicacion: $('portal-sin-ubicacion'),
        mapaPronto: $('portal-mapa-pronto'),
        historial: $('portal-historial'),
        destinatario: $('portal-destinatario'),
        direccion: $('portal-direccion'),
        modal: $('portal-modal'),
        form: $('portal-form'),
        formEnviar: $('portal-form-enviar'),
        formError: $('portal-modal-error'),
    };

    let temporizador = null;
    let ultimo = null;           // última respuesta válida
    let cargandoAhora = false;
    let mapa = null;
    let marcadorVehiculo = null;
    let marcadorDestino = null;
    let circuloDestino = null;
    let modalBs = null;

    // ---------------------------------------------------------------- formatos

    function formatear(iso, opciones) {
        if (!iso) {
            return '';
        }
        const fecha = new Date(iso);
        if (Number.isNaN(fecha.getTime())) {
            return '';
        }
        try {
            return new Intl.DateTimeFormat('es', { ...opciones, timeZone: cfg.zona }).format(fecha);
        } catch (e) {
            return new Intl.DateTimeFormat('es', opciones).format(fecha);   // zona horaria desconocida
        }
    }

    const fechaCorta = (iso) => formatear(iso, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true });
    const soloHora = (iso) => formatear(iso, { hour: 'numeric', minute: '2-digit', hour12: true });

    // ---------------------------------------------------------------- vistas

    function mostrarSolo(que) {
        el.cargando.classList.toggle('d-none', que !== 'cargando');
        el.invalido.classList.toggle('d-none', que !== 'invalido');
        el.contenido.classList.toggle('d-none', que !== 'contenido');
    }

    function mostrarInvalido(texto) {
        detener();
        if (texto) {
            el.invalidoTexto.textContent = texto;
        }
        mostrarSolo('invalido');
    }

    function avisoConexion(texto) {
        el.conexion.textContent = texto || '';
        el.conexion.classList.toggle('d-none', !texto);
    }

    function mensajeSegunEstado(d) {
        switch (d.estado) {
            case 'en_transito':
                return 'Tu paquete va en camino.';
            case 'entregado': {
                let texto = 'Entregado el ' + (d.entrega && d.entrega.fecha ? fechaCorta(d.entrega.fecha) : 'día de hoy') + '.';
                if (d.entrega && d.entrega.recibio) {
                    texto += ' Recibió: ' + d.entrega.recibio + '.';
                }
                return texto;
            }
            case 'incidencia': {
                const motivo = d.incidencia && d.incidencia.motivo_texto ? d.incidencia.motivo_texto : 'Otro';
                return 'No pudimos entregar tu paquete (' + motivo + ').';
            }
            case 'cancelado':
                return 'Este envío fue cancelado.';
            case 'devuelto':
                return 'Este envío fue devuelto al remitente.';
            default:
                return 'Estamos preparando tu paquete.';
        }
    }

    function dibujarHistorial(historial) {
        el.historial.replaceChildren();
        // El hito más reciente va arriba.
        [...historial].reverse().forEach((h) => {
            const li = document.createElement('li');
            const punto = document.createElement('span');
            punto.className = 'portal-punto estado-' + h.estado;
            punto.setAttribute('aria-hidden', 'true');
            const hito = document.createElement('span');
            hito.className = 'hito';
            hito.textContent = h.estado_texto;
            const fecha = document.createElement('span');
            fecha.className = 'fecha';
            fecha.textContent = fechaCorta(h.fecha);
            li.append(punto, hito, fecha);
            el.historial.append(li);
        });
    }

    function textoParadas(n) {
        if (n === 0) {
            return 'Tu paquete es la siguiente entrega.';
        }
        return 'Tu repartidor está a ' + n + (n === 1 ? ' parada.' : ' paradas.');
    }

    // ---------------------------------------------------------------- mapa

    function asegurarMapa() {
        if (mapa || typeof window.L === 'undefined') {
            return mapa;
        }
        mapa = L.map(el.mapaDiv, { zoomControl: true, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; Colaboradores de OpenStreetMap',
        }).addTo(mapa);
        return mapa;
    }

    function actualizarMapa(d) {
        if (!asegurarMapa()) {
            return;
        }
        const veh = [d.vehiculo.lat, d.vehiculo.lng];
        const dest = [d.destino.lat, d.destino.lng];

        if (!marcadorVehiculo) {
            marcadorVehiculo = L.marker(veh, {
                icon: L.divIcon({ className: '', html: '<div class="portal-punto-vehiculo"></div>', iconSize: [22, 22], iconAnchor: [11, 11] }),
                title: 'Tu repartidor',
            }).addTo(mapa);
            marcadorDestino = L.circleMarker(dest, { radius: 8, color: '#fff', weight: 2, fillColor: '#dc3545', fillOpacity: 1 }).addTo(mapa);
            marcadorDestino.bindTooltip('Tu entrega (aproximado)');
            circuloDestino = L.circle(dest, { radius: 110, color: '#dc3545', weight: 1, fillOpacity: 0.08 }).addTo(mapa);
            mapa.fitBounds(L.latLngBounds([veh, dest]).pad(0.4), { maxZoom: 17 });
        } else {
            marcadorVehiculo.setLatLng(veh);
            marcadorDestino.setLatLng(dest);
            circuloDestino.setLatLng(dest);
        }
    }

    function mostrarMapa(visible) {
        el.mapaTarjeta.classList.toggle('d-none', !visible);
        if (visible && mapa) {
            mapa.invalidateSize();
        }
    }

    // ---------------------------------------------------------------- dibujo principal

    function dibujar(d) {
        el.guia.textContent = d.codigo_guia;
        el.estado.className = 'portal-estado estado-' + d.estado;
        el.estado.textContent = d.estado_texto;
        el.mensaje.textContent = mensajeSegunEstado(d);

        if (d.estado === 'en_transito' && d.eta) {
            const hora = soloHora(d.eta);
            el.eta.textContent = 'Llega aproximadamente a las ' + hora + (hora.endsWith('.') ? '' : '.');
            el.eta.classList.remove('d-none');
        } else {
            el.eta.classList.add('d-none');
        }

        el.btnReprogramar.classList.toggle('d-none', !d.puede_reprogramar);
        el.sinReprogramar.classList.toggle('d-none', !(d.estado === 'incidencia' && !d.puede_reprogramar));

        // Mapa en vivo del último tramo: solo cuando el servidor lo autoriza.
        const enTransito = d.estado === 'en_transito';
        const cerca = enTransito && d.paradas_antes !== null && d.paradas_antes <= cfg.paradasEnVivo;
        if (d.mostrar_mapa && d.vehiculo && d.destino) {
            el.paradas.textContent = textoParadas(d.paradas_antes);
            mostrarMapa(true);
            actualizarMapa(d);
        } else {
            mostrarMapa(false);
        }
        el.sinUbicacion.classList.toggle('d-none', !(cerca && !d.mostrar_mapa));
        el.mapaPronto.classList.toggle('d-none', !(enTransito && d.paradas_antes !== null && !cerca));

        dibujarHistorial(d.historial || []);
        el.destinatario.textContent = d.destinatario;
        el.direccion.textContent = d.direccion_resumen;
        mostrarSolo('contenido');
    }

    // ---------------------------------------------------------------- comunicación

    async function pedir(url, opciones) {
        const resp = await fetch(url, { cache: 'no-store', credentials: 'omit', ...opciones });
        let json = null;
        try {
            json = await resp.json();
        } catch (e) {
            json = null;
        }
        return { http: resp.status, json };
    }

    async function cargar() {
        if (cargandoAhora) {
            return;
        }
        cargandoAhora = true;
        clearTimeout(temporizador);
        let proximoSeg = REFRESCO_ERROR_S;
        try {
            const { http, json } = await pedir(
                cfg.base + '/api/tracking/estado.php?t=' + encodeURIComponent(cfg.token),
                { headers: { Accept: 'application/json' } }
            );
            if (json && json.ok) {
                avisoConexion('');
                ultimo = json.data;
                dibujar(ultimo);
                proximoSeg = siguienteIntervalo(ultimo);
            } else if (http === 404) {
                mostrarInvalido(json && json.error ? json.error.mensaje : '');
                return;
            } else if (http === 429) {
                avisoConexion('Demasiadas consultas. Esperaremos un momento antes de actualizar.');
            } else {
                avisoConexion('No pudimos actualizar. Reintentaremos en unos segundos.');
            }
        } catch (e) {
            avisoConexion('Sin conexión. Reintentaremos en unos segundos.');
        } finally {
            cargandoAhora = false;
        }
        programar(proximoSeg);
    }

    /** Segundos hasta la próxima consulta, o null si el estado es terminal. */
    function siguienteIntervalo(d) {
        if (TERMINALES.includes(d.estado)) {
            return null;
        }
        if (ACTIVOS.includes(d.estado) && !document.hidden) {
            return Math.max(3, d.intervalo_refresco_s || 10);
        }
        return REFRESCO_LENTO_S;
    }

    function programar(segundos) {
        clearTimeout(temporizador);
        if (segundos === null || segundos === undefined) {
            return;
        }
        temporizador = setTimeout(cargar, segundos * 1000);
    }

    function detener() {
        clearTimeout(temporizador);
        temporizador = null;
    }

    document.addEventListener('visibilitychange', () => {
        if (!ultimo || TERMINALES.includes(ultimo.estado)) {
            return;
        }
        if (document.hidden) {
            programar(REFRESCO_LENTO_S);
        } else {
            cargar();   // al volver a la pestaña se actualiza de inmediato
        }
    });

    // ---------------------------------------------------------------- reprogramar

    function limpiarErroresForm() {
        el.formError.classList.add('d-none');
        el.formError.textContent = '';
        el.form.querySelectorAll('.is-invalid').forEach((c) => c.classList.remove('is-invalid'));
        el.form.querySelectorAll('.invalid-feedback').forEach((c) => { c.textContent = ''; });
    }

    function marcarCampos(campos) {
        Object.entries(campos || {}).forEach(([nombre, mensaje]) => {
            const input = el.form.elements[nombre];
            const feedback = el.form.querySelector('.invalid-feedback[data-campo="' + nombre + '"]');
            if (input) {
                input.classList.add('is-invalid');
            }
            if (feedback) {
                feedback.textContent = mensaje;
            }
        });
    }

    function fechaLocalISO(desplazamientoDias) {
        const f = new Date();
        f.setDate(f.getDate() + desplazamientoDias);
        const mm = String(f.getMonth() + 1).padStart(2, '0');
        const dd = String(f.getDate()).padStart(2, '0');
        return f.getFullYear() + '-' + mm + '-' + dd;
    }

    function abrirModal() {
        limpiarErroresForm();
        el.form.elements.fecha.min = fechaLocalISO(1);
        el.form.elements.fecha.max = fechaLocalISO(cfg.diasMax);
        el.form.elements.fecha.value = fechaLocalISO(1);
        if (window.bootstrap) {
            modalBs = modalBs || new bootstrap.Modal(el.modal);
            modalBs.show();
        }
    }

    function textoFechaProgramada(yyyyMmDd) {
        const f = new Date(yyyyMmDd + 'T12:00:00Z');
        return new Intl.DateTimeFormat('es', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(f);
    }

    async function enviarReprogramacion(ev) {
        ev.preventDefault();
        limpiarErroresForm();
        if (!el.form.elements.fecha.value) {
            marcarCampos({ fecha: 'Elige una fecha.' });
            return;
        }
        el.formEnviar.disabled = true;
        try {
            const { json } = await pedir(cfg.base + '/api/tracking/reprogramar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    t: cfg.token,
                    fecha: el.form.elements.fecha.value,
                    franja: el.form.elements.franja.value,
                    nota: el.form.elements.nota.value.trim() || null,
                }),
            });
            if (json && json.ok) {
                if (modalBs) {
                    modalBs.hide();
                }
                el.aviso.textContent = 'Listo: reprogramamos tu entrega para el ' + textoFechaProgramada(json.data.fecha_programada) + '.';
                el.aviso.classList.remove('d-none');
                await cargar();
            } else if (json && json.error) {
                if (json.error.campos && Object.keys(json.error.campos).length) {
                    marcarCampos(json.error.campos);
                } else {
                    el.formError.textContent = json.error.mensaje;
                    el.formError.classList.remove('d-none');
                }
                if (json.error.codigo === 'ESTADO_INVALIDO') {
                    cargar();   // el estado cambió: se vuelve a consultar
                }
            } else {
                el.formError.textContent = 'Ocurrió un problema. Intenta de nuevo.';
                el.formError.classList.remove('d-none');
            }
        } catch (e) {
            el.formError.textContent = 'Sin conexión. Intenta de nuevo.';
            el.formError.classList.remove('d-none');
        } finally {
            el.formEnviar.disabled = false;
        }
    }

    el.btnReprogramar.addEventListener('click', abrirModal);
    el.form.addEventListener('submit', enviarReprogramacion);

    // ---------------------------------------------------------------- inicio

    if (!cfg.tokenValido) {
        mostrarInvalido();
    } else {
        cargar();
    }
})();
