/*
 * Utilidades compartidas por las pantallas de Karoline (M8 administración y M6 dashboard).
 * Expone el objeto global `RP`. Todo texto del servidor se inserta con textContent (nunca innerHTML).
 *
 * PUNTO ÚNICO DE CONTACTO CON api() (lo entrega Carlos en assets/js/api.js):
 *   RP.llamar(ruta, { metodo, params, cuerpo }) hace `await api(url, { method, body })` y normaliza
 *   el resultado a `{ data, meta }`. Se asume que api():
 *     - recibe la URL completa (con la ruta base del proyecto) y un objeto { method, body },
 *     - serializa `body` a JSON, agrega X-CSRF-Token, muestra el toast de error y redirige en 401,
 *     - devuelve la respuesta `{ ok, data, meta }` (o solo `data`) y lanza un error en caso de fallo.
 *   Si api.js final usa otra firma, solo hay que ajustar `llamar()` en este archivo.
 */
(() => {
    'use strict';

    // Ruta base del proyecto, deducida de la ubicación de este mismo script (…/assets/js/pantalla_util.js).
    const actual = document.currentScript;
    let BASE = '';
    if (actual && actual.src) {
        BASE = new URL(actual.src, window.location.href).pathname.replace(/\/assets\/js\/[^/]*$/, '');
    }

    // ------------------------------------------------------------- API

    async function llamar(ruta, { metodo = 'GET', params = null, cuerpo = null } = {}) {
        let url = BASE + '/api/' + ruta;
        if (params) {
            const q = new URLSearchParams();
            Object.entries(params).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') {
                    q.append(k, v);
                }
            });
            const texto = q.toString();
            if (texto) {
                url += '?' + texto;
            }
        }
        const opciones = { method: metodo };
        if (cuerpo !== null) {
            opciones.body = cuerpo;
        }
        const r = await window.api(url, opciones);
        if (r && typeof r === 'object' && !Array.isArray(r) && 'data' in r) {
            return { data: r.data, meta: r.meta || null };
        }
        return { data: r, meta: null };
    }

    /** Errores por campo de una respuesta VALIDACION (`campos`), o null si no los hay. */
    function errorCampos(e) {
        if (!e) {
            return null;
        }
        const c = e.campos || (e.error && e.error.campos) || null;
        return c && Object.keys(c).length ? c : null;
    }

    function errorMensaje(e) {
        return (e && (e.mensaje || (e.error && e.error.mensaje) || e.message)) || 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.';
    }

    // ------------------------------------------------------------- DOM

    /** crear('button', { class: 'btn', type: 'button', onclick: fn }, 'Texto', otroNodo) */
    function crear(etiqueta, atributos = {}, ...hijos) {
        const nodo = document.createElement(etiqueta);
        Object.entries(atributos || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) {
                return;
            }
            if (k === 'class') {
                nodo.className = v;
            } else if (k.startsWith('on') && typeof v === 'function') {
                nodo.addEventListener(k.slice(2), v);
            } else if (k === 'texto') {
                nodo.textContent = v;
            } else {
                nodo.setAttribute(k, v === true ? '' : v);
            }
        });
        hijos.flat().forEach((h) => {
            if (h === null || h === undefined || h === false) {
                return;
            }
            nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
        });
        return nodo;
    }

    function vaciar(nodo) {
        nodo.replaceChildren();
        return nodo;
    }

    function spinner(texto = 'Cargando…') {
        return crear('div', { class: 'text-center text-muted py-4', role: 'status' },
            crear('span', { class: 'spinner-border spinner-border-sm me-2', 'aria-hidden': 'true' }), texto);
    }

    const guion = '—';

    /** Fecha ISO UTC → `dd/mm/aaaa HH:mm` en la zona de la interfaz (ui.formatoFecha de Carlos). */
    function fecha(iso) {
        if (!iso) {
            return guion;
        }
        if (window.ui && typeof window.ui.formatoFecha === 'function') {
            return window.ui.formatoFecha(iso) || guion;
        }
        const f = new Date(iso);
        if (Number.isNaN(f.getTime())) {
            return guion;
        }
        const p = (n) => String(n).padStart(2, '0');
        return p(f.getDate()) + '/' + p(f.getMonth() + 1) + '/' + f.getFullYear() + ' ' + p(f.getHours()) + ':' + p(f.getMinutes());
    }

    /** 12,4 → «12,4» con coma decimal; null → «—». */
    function numero(valor, decimales = 1) {
        if (valor === null || valor === undefined || Number.isNaN(Number(valor))) {
            return guion;
        }
        return Number(valor).toLocaleString('es', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
    }

    function porcentaje(valor) {
        return valor === null || valor === undefined ? guion : numero(valor, 1) + ' %';
    }

    function dinero(valor, moneda = 'USD', decimales = 2) {
        if (valor === null || valor === undefined) {
            return guion;
        }
        if (window.ui && typeof window.ui.formatoMoneda === 'function' && decimales === 2) {
            return window.ui.formatoMoneda(valor, moneda);
        }
        return numero(valor, decimales) + ' ' + moneda;
    }

    function debounce(fn, ms = 400) {
        let t = null;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), ms);
        };
    }

    // ------------------------------------------------------------- avisos y confirmaciones

    function contenedorToasts() {
        let c = document.getElementById('rp-toasts');
        if (!c) {
            c = crear('div', { id: 'rp-toasts', class: 'toast-container position-fixed top-0 end-0 p-3' });
            document.body.append(c);
        }
        return c;
    }

    function aviso(mensaje, tipo = 'success') {
        const cuerpo = crear('div', { class: 'toast-body' }, mensaje);
        const boton = crear('button', { type: 'button', class: 'btn-close btn-close-white me-2 m-auto', 'data-bs-dismiss': 'toast', 'aria-label': 'Cerrar' });
        const t = crear('div', { class: 'toast align-items-center text-bg-' + tipo + ' border-0', role: 'status', 'aria-live': 'polite' },
            crear('div', { class: 'd-flex' }, cuerpo, boton));
        contenedorToasts().append(t);
        if (window.bootstrap) {
            const inst = new bootstrap.Toast(t, { delay: 4000 });
            t.addEventListener('hidden.bs.toast', () => t.remove());
            inst.show();
        } else {
            t.classList.add('show');
            setTimeout(() => t.remove(), 4000);
        }
    }

    const exito = (mensaje) => aviso(mensaje, 'success');

    /** Ventana de confirmación. Devuelve una promesa con true (confirmó) o false. */
    function confirmar({ titulo = 'Confirmar', mensaje = '', textoBoton = 'Confirmar', peligro = false } = {}) {
        return new Promise((resolver) => {
            const ok = crear('button', { type: 'button', class: 'btn ' + (peligro ? 'btn-danger' : 'btn-primary') }, textoBoton);
            const cancelar = crear('button', { type: 'button', class: 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal' }, 'Cancelar');
            const modal = crear('div', { class: 'modal fade', tabindex: '-1', 'aria-hidden': 'true' },
                crear('div', { class: 'modal-dialog modal-dialog-centered' },
                    crear('div', { class: 'modal-content' },
                        crear('div', { class: 'modal-header' },
                            crear('h2', { class: 'modal-title fs-5' }, titulo),
                            crear('button', { type: 'button', class: 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': 'Cerrar' })),
                        crear('div', { class: 'modal-body' }, mensaje),
                        crear('div', { class: 'modal-footer' }, cancelar, ok))));
            document.body.append(modal);
            const inst = new bootstrap.Modal(modal);
            let respuesta = false;
            ok.addEventListener('click', () => {
                respuesta = true;
                inst.hide();
            });
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
                resolver(respuesta);
            });
            inst.show();
        });
    }

    // ------------------------------------------------------------- formularios

    function limpiarCampos(form) {
        form.querySelectorAll('.is-invalid').forEach((c) => c.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback[data-campo]').forEach((c) => { c.textContent = ''; });
    }

    /** Marca cada campo con is-invalid y muestra el mensaje debajo (div.invalid-feedback[data-campo]). */
    function marcarCampos(form, campos) {
        Object.entries(campos || {}).forEach(([nombre, mensaje]) => {
            const input = form.elements[nombre];
            const fb = form.querySelector('.invalid-feedback[data-campo="' + nombre + '"]');
            if (input) {
                input.classList.add('is-invalid');
            }
            if (fb) {
                fb.textContent = mensaje;
            }
        });
    }

    // ------------------------------------------------------------- paginación

    /**
     * Dibuja «Anterior · Página X de Y · Siguiente» con el `meta` de la API.
     * Si no llega `meta`, habilita «Siguiente» cuando la página vino llena.
     */
    function paginador(contenedor, { meta, page, porPagina, cantidad, alCambiar }) {
        vaciar(contenedor);
        const total = meta ? meta.total : null;
        const paginas = meta ? Math.max(1, meta.paginas) : null;
        const hayMas = meta ? page < paginas : cantidad >= porPagina;

        const anterior = crear('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', disabled: page <= 1, onclick: () => alCambiar(page - 1) }, '« Anterior');
        const siguiente = crear('button', { type: 'button', class: 'btn btn-outline-secondary btn-sm', disabled: !hayMas, onclick: () => alCambiar(page + 1) }, 'Siguiente »');
        const texto = paginas
            ? 'Página ' + page + ' de ' + paginas + (total !== null ? ' · ' + total + ' registros' : '')
            : 'Página ' + page;
        contenedor.append(crear('div', { class: 'd-flex justify-content-between align-items-center gap-2' },
            anterior, crear('span', { class: 'small text-muted' }, texto), siguiente));
    }

    window.RP = {
        base: BASE, llamar, errorCampos, errorMensaje, crear, vaciar, spinner, guion, fecha, numero, porcentaje,
        dinero, debounce, aviso, exito, confirmar, limpiarCampos, marcarCampos, paginador,
    };
})();
