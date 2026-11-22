'use strict';
/**
 * M3/M1 (Juan). Utilidades comunes de las pantallas de hub/ y despacho/.
 * Se apoya en api() y ui() del núcleo (assets/js/api.js y ui.js), que ya cargan antes en cada página.
 */
(function () {
    const RP = (window.RP = window.RP || {});

    RP.ESTADOS_PAQUETE = {
        creado: 'Creado', ingresado_hub: 'Ingresado en HUB', en_clasificacion: 'En clasificación',
        asignado_ruta: 'Asignado a ruta', en_transito: 'En tránsito', entregado: 'Entregado',
        incidencia: 'Incidencia', cancelado: 'Cancelado', devuelto: 'Devuelto',
    };
    RP.ESTADOS_RUTA = {
        borrador: 'Borrador', planificada: 'Planificada', en_curso: 'En curso', completada: 'Completada', cancelada: 'Cancelada',
    };
    RP.ESTADOS_PARADA = {
        pendiente: 'Pendiente', en_camino: 'En camino', llegado: 'Llegado', entregada: 'Entregada', fallida: 'Fallida', omitida: 'Omitida',
    };
    RP.TIPOS_INCIDENCIA = {
        direccion_no_encontrada: 'Dirección no encontrada', cliente_ausente: 'Cliente ausente',
        cobro_no_realizado: 'Cobro no realizado', rechazado_por_cliente: 'Rechazado por el cliente',
        paquete_danado: 'Paquete dañado', zona_insegura: 'Zona insegura', otro: 'Otro',
    };
    RP.ESTADOS_INCIDENCIA = { abierta: 'Abierta', reprogramada: 'Reprogramada', devuelta: 'Devuelta', cancelada: 'Cancelada' };
    RP.TIPOS_VEHICULO = { furgon: 'Furgón', motocicleta: 'Motocicleta', vehiculo_electrico: 'Vehículo eléctrico', bicicleta: 'Bicicleta' };
    RP.ESTADOS_CONDUCTOR = { disponible: 'Disponible', en_ruta: 'En ruta', inactivo: 'Inactivo' };

    /** Crea un elemento. props: {className, text, attrs:{}, on:{evento:fn}, dataset:{}}. Los hijos pueden ser nodos o textos. */
    RP.el = function (tag, props, ...hijos) {
        const nodo = document.createElement(tag);
        const p = props || {};
        if (p.className) nodo.className = p.className;
        if (p.text !== undefined && p.text !== null) nodo.textContent = p.text;
        if (p.attrs) Object.keys(p.attrs).forEach((k) => { if (p.attrs[k] !== null && p.attrs[k] !== undefined) nodo.setAttribute(k, p.attrs[k]); });
        if (p.dataset) Object.keys(p.dataset).forEach((k) => { nodo.dataset[k] = p.dataset[k]; });
        if (p.on) Object.keys(p.on).forEach((k) => nodo.addEventListener(k, p.on[k]));
        hijos.flat().forEach((h) => {
            if (h === null || h === undefined || h === false) return;
            nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
        });
        return nodo;
    };

    RP.limpiar = function (nodo) { while (nodo.firstChild) nodo.removeChild(nodo.firstChild); return nodo; };
    RP.$ = (sel, raiz) => (raiz || document).querySelector(sel);
    RP.$$ = (sel, raiz) => Array.from((raiz || document).querySelectorAll(sel));

    /** Insignia con el color del estado (clases estado-<estado> de assets/css/estados.css). */
    RP.badge = function (estado, textos) {
        const t = textos || RP.ESTADOS_PAQUETE;
        return RP.el('span', { className: 'badge rp-estado estado-' + estado, text: t[estado] || estado });
    };

    RP.fecha = function (iso) {
        if (!iso) return '—';
        if (window.ui && typeof window.ui.formatoFecha === 'function') return window.ui.formatoFecha(iso);
        const d = new Date(iso);
        return isNaN(d) ? '—' : d.toLocaleString('es-CO', { hour12: false });
    };
    RP.soloFecha = function (iso) {
        if (!iso) return '—';
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '—';
    };
    RP.dinero = function (v, moneda) {
        if (v === null || v === undefined) return '—';
        if (window.ui && typeof window.ui.formatoMoneda === 'function') return window.ui.formatoMoneda(v, moneda);
        return (moneda || '') + ' ' + Number(v).toFixed(2);
    };
    RP.km = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(1).replace('.', ',') + ' km');
    RP.duracion = function (min) {
        if (min === null || min === undefined) return '—';
        const h = Math.floor(min / 60), m = Math.round(min % 60);
        return h > 0 ? h + ' h ' + m + ' min' : m + ' min';
    };
    RP.dato = (v) => (v === null || v === undefined || v === '' ? '—' : String(v));

    /**
     * Llama a la API con api() del núcleo (agrega CSRF, muestra el aviso rojo ante errores y lanza ApiError).
     * Devuelve {data, meta}. Un FormData se envía como multipart con api.subir().
     * opciones: las de api() (por ejemplo {silencioso: true} para no mostrar el aviso de error).
     * Ante un error lanza ApiError: e.campos = {campo: mensaje} (VALIDACION), e.codigo, e.message.
     */
    RP.llamar = async function (metodo, ruta, datos, opciones) {
        const o = Object.assign({ completa: true }, opciones || {});
        let r;
        if (typeof FormData !== 'undefined' && datos instanceof FormData) r = await window.api.subir(ruta, datos, o);
        else r = await window.api(ruta, Object.assign(o, { metodo: metodo, datos: datos || null }));
        return { data: r.data, meta: r.meta || null };
    };
    RP.get = (ruta, datos, opciones) => RP.llamar('GET', ruta, datos, opciones);
    RP.post = (ruta, datos, opciones) => RP.llamar('POST', ruta, datos, opciones);

    /** Aviso breve (usa ui.toast de Carlos si existe; si no, un toast Bootstrap propio). */
    RP.aviso = function (mensaje, tipo) {
        const t = tipo || 'success';
        if (window.ui && typeof window.ui.toast === 'function') { window.ui.toast(mensaje, t); return; }
        let cont = document.getElementById('rp-toasts');
        if (!cont) {
            cont = RP.el('div', { className: 'toast-container position-fixed top-0 end-0 p-3', attrs: { id: 'rp-toasts' } });
            document.body.append(cont);
        }
        const fondo = t === 'danger' || t === 'error' ? 'text-bg-danger' : t === 'warning' ? 'text-bg-warning' : 'text-bg-success';
        const toast = RP.el('div', { className: 'toast align-items-center border-0 ' + fondo, attrs: { role: 'alert' } },
            RP.el('div', { className: 'd-flex' },
                RP.el('div', { className: 'toast-body', text: mensaje }),
                RP.el('button', { className: 'btn-close btn-close-white me-2 m-auto', attrs: { type: 'button', 'data-bs-dismiss': 'toast', 'aria-label': 'Cerrar' } })));
        cont.append(toast);
        const inst = new bootstrap.Toast(toast, { delay: 3500 });
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
        inst.show();
    };
    RP.exito = (m) => RP.aviso(m, 'success');
    RP.advertencia = (m) => RP.aviso(m, 'warning');

    /** Spinner dentro de un contenedor mientras se espera la respuesta. */
    RP.cargando = function (cont) {
        RP.limpiar(cont).append(RP.el('div', { className: 'text-center text-muted py-4' },
            RP.el('div', { className: 'spinner-border spinner-border-sm me-2', attrs: { role: 'status' } }), 'Cargando…'));
    };

    /** Ventana de confirmación para acciones destructivas. Devuelve Promise<boolean>. */
    RP.confirmar = function (titulo, mensaje, textoBoton, peligro) {
        return new Promise((resolver) => {
            let decidido = false;
            const modal = RP.el('div', { className: 'modal fade', attrs: { tabindex: '-1' } },
                RP.el('div', { className: 'modal-dialog modal-dialog-centered' },
                    RP.el('div', { className: 'modal-content' },
                        RP.el('div', { className: 'modal-header' },
                            RP.el('h5', { className: 'modal-title', text: titulo }),
                            RP.el('button', { className: 'btn-close', attrs: { type: 'button', 'data-bs-dismiss': 'modal', 'aria-label': 'Cerrar' } })),
                        RP.el('div', { className: 'modal-body', text: mensaje }),
                        RP.el('div', { className: 'modal-footer' },
                            RP.el('button', { className: 'btn btn-outline-secondary', text: 'Volver', attrs: { type: 'button', 'data-bs-dismiss': 'modal' } }),
                            RP.el('button', {
                                className: 'btn ' + (peligro === false ? 'btn-primary' : 'btn-danger'), text: textoBoton || 'Confirmar', attrs: { type: 'button' },
                                on: { click: () => { decidido = true; inst.hide(); } },
                            })))));
            document.body.append(modal);
            const inst = new bootstrap.Modal(modal);
            modal.addEventListener('hidden.bs.modal', () => { modal.remove(); resolver(decidido); });
            inst.show();
        });
    };

    /** Modal con un formulario simple (campos: [{nombre, etiqueta, tipo, opciones, requerido, valor}]). Devuelve Promise<objeto|null>. */
    RP.formularioModal = function (titulo, campos, textoBoton) {
        return new Promise((resolver) => {
            let resultado = null;
            const form = RP.el('form', { attrs: { novalidate: 'novalidate' } });
            campos.forEach((c) => {
                const id = 'rpf_' + c.nombre;
                let ctl;
                if (c.tipo === 'select') {
                    ctl = RP.el('select', { className: 'form-select', attrs: { id, name: c.nombre } },
                        (c.opciones || []).map((o) => RP.el('option', { text: o.texto, attrs: { value: o.valor } })));
                } else if (c.tipo === 'textarea') {
                    ctl = RP.el('textarea', { className: 'form-control', attrs: { id, name: c.nombre, rows: '3', maxlength: c.max || null } });
                } else {
                    ctl = RP.el('input', { className: 'form-control', attrs: { id, name: c.nombre, type: c.tipo || 'text', maxlength: c.max || null, min: c.min || null } });
                }
                if (c.valor !== undefined && c.valor !== null) ctl.value = c.valor;
                if (c.requerido) ctl.required = true;
                form.append(RP.el('div', { className: 'mb-3' },
                    RP.el('label', { className: 'form-label', attrs: { for: id }, text: c.etiqueta + (c.requerido ? ' *' : '') }), ctl,
                    c.ayuda ? RP.el('div', { className: 'form-text', text: c.ayuda }) : null));
            });
            const modal = RP.el('div', { className: 'modal fade', attrs: { tabindex: '-1' } },
                RP.el('div', { className: 'modal-dialog modal-dialog-centered' },
                    RP.el('div', { className: 'modal-content' },
                        RP.el('div', { className: 'modal-header' },
                            RP.el('h5', { className: 'modal-title', text: titulo }),
                            RP.el('button', { className: 'btn-close', attrs: { type: 'button', 'data-bs-dismiss': 'modal', 'aria-label': 'Cerrar' } })),
                        RP.el('div', { className: 'modal-body' }, form),
                        RP.el('div', { className: 'modal-footer' },
                            RP.el('button', { className: 'btn btn-outline-secondary', text: 'Cancelar', attrs: { type: 'button', 'data-bs-dismiss': 'modal' } }),
                            RP.el('button', {
                                className: 'btn btn-primary', text: textoBoton || 'Guardar', attrs: { type: 'button' },
                                on: {
                                    click: () => {
                                        if (!form.reportValidity()) return;
                                        resultado = {};
                                        campos.forEach((c) => { resultado[c.nombre] = form.elements[c.nombre].value.trim(); });
                                        inst.hide();
                                    },
                                },
                            })))));
            document.body.append(modal);
            const inst = new bootstrap.Modal(modal);
            modal.addEventListener('hidden.bs.modal', () => { modal.remove(); resolver(resultado); });
            inst.show();
        });
    };

    /** Marca campos inválidos de un formulario con los errores de VALIDACION ({campo: mensaje}). */
    RP.marcarErrores = function (form, campos) {
        RP.$$('.is-invalid', form).forEach((n) => n.classList.remove('is-invalid'));
        RP.$$('.invalid-feedback.rp-fb', form).forEach((n) => n.remove());
        Object.keys(campos || {}).forEach((nombre) => {
            const ctl = form.elements[nombre];
            if (!ctl) return;
            ctl.classList.add('is-invalid');
            ctl.insertAdjacentElement('afterend', RP.el('div', { className: 'invalid-feedback rp-fb', text: campos[nombre] }));
        });
    };

    RP.debounce = function (fn, ms) {
        let t = null;
        return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms || 400); };
    };

    /** Paginador Bootstrap a partir del meta de la API. */
    RP.paginador = function (cont, meta, alCambiar) {
        RP.limpiar(cont);
        if (!meta || meta.paginas <= 1) return;
        const ul = RP.el('ul', { className: 'pagination pagination-sm mb-0 justify-content-center' });
        const item = (texto, pagina, deshabilitado, activo) => ul.append(RP.el('li', { className: 'page-item' + (deshabilitado ? ' disabled' : '') + (activo ? ' active' : '') },
            RP.el('button', { className: 'page-link', text: texto, attrs: { type: 'button' }, on: { click: () => alCambiar(pagina) } })));
        item('«', meta.page - 1, meta.page <= 1);
        const ini = Math.max(1, meta.page - 2), fin = Math.min(meta.paginas, meta.page + 2);
        for (let p = ini; p <= fin; p++) item(String(p), p, false, p === meta.page);
        item('»', meta.page + 1, meta.page >= meta.paginas);
        cont.append(ul, RP.el('div', { className: 'text-center small text-muted mt-1', text: meta.total + ' resultado(s)' }));
    };

    /** Lee el parámetro de la URL. */
    RP.param = (nombre) => new URLSearchParams(window.location.search).get(nombre);

    /** Fila de tabla vacía. */
    RP.filaVacia = (cols, texto) => RP.el('tr', {}, RP.el('td', { className: 'text-center text-muted py-4', attrs: { colspan: String(cols) }, text: texto || 'No hay resultados' }));

    /** Mapa Leaflet con teselas de OpenStreetMap (atribución visible). */
    RP.mapa = function (id, lat, lng, zoom) {
        const mapa = L.map(id).setView([lat, lng], zoom || 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(mapa);
        return mapa;
    };
})();
