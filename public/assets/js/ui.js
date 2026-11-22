/*
 * RoutePulse - ui.js
 * Utilidades de interfaz compartidas por todas las pantallas
 * (docs/00-base/09-guia-interfaz.md): avisos, confirmaciones, formatos de fecha,
 * dinero y números, insignias de estado, errores de formulario y paginador.
 *
 * Nunca se inserta texto externo con innerHTML: se usa textContent (ui.crear).
 */
(function (global) {
    'use strict';

    var TEXTOS_ESTADO = {
        // Paquete
        creado: 'Creado', ingresado_hub: 'Ingresado en HUB', en_clasificacion: 'En clasificación',
        asignado_ruta: 'Asignado a ruta', en_transito: 'En tránsito', entregado: 'Entregado',
        incidencia: 'Incidencia', cancelado: 'Cancelado', devuelto: 'Devuelto',
        // Ruta
        borrador: 'Borrador', planificada: 'Planificada', en_curso: 'En curso',
        completada: 'Completada', cancelada: 'Cancelada',
        // Parada
        pendiente: 'Pendiente', en_camino: 'En camino', llegado: 'Llegó',
        entregada: 'Entregada', fallida: 'Fallida', omitida: 'Omitida'
    };

    var TEXTOS_INCIDENCIA = {
        direccion_no_encontrada: 'Dirección no encontrada', cliente_ausente: 'Cliente ausente',
        cobro_no_realizado: 'Cobro no realizado', rechazado_por_cliente: 'Rechazado por el cliente',
        paquete_danado: 'Paquete dañado', zona_insegura: 'Zona insegura', otro: 'Otro'
    };

    function leerMeta(nombre) {
        var el = document.querySelector('meta[name="' + nombre + '"]');
        return el ? (el.getAttribute('content') || '') : '';
    }

    function esNumero(valor) {
        return valor !== null && valor !== undefined && valor !== '' && isFinite(Number(valor));
    }

    // ------------------------------------------------------------------ DOM seguro

    /**
     * Crea un elemento. Los hijos de tipo texto se insertan con textContent.
     * ui.crear('span', { className: 'badge', title: 'x' }, 'Hola', otroElemento)
     */
    function crear(etiqueta, atributos) {
        var el = document.createElement(etiqueta);
        var propiedades = atributos || {};
        Object.keys(propiedades).forEach(function (clave) {
            var valor = propiedades[clave];
            if (valor === null || valor === undefined || valor === false) {
                return;
            }
            if (clave === 'className') {
                el.className = valor;
            } else if (clave === 'texto') {
                el.textContent = String(valor);
            } else if (clave.indexOf('on') === 0 && typeof valor === 'function') {
                el.addEventListener(clave.slice(2).toLowerCase(), valor);
            } else {
                el.setAttribute(clave, valor === true ? '' : String(valor));
            }
        });
        for (var i = 2; i < arguments.length; i++) {
            var hijo = arguments[i];
            if (hijo === null || hijo === undefined || hijo === false) {
                continue;
            }
            el.appendChild(typeof hijo === 'object' && hijo.nodeType ? hijo : document.createTextNode(String(hijo)));
        }
        return el;
    }

    // ------------------------------------------------------------------ Avisos y confirmaciones

    /** Aviso breve. tipo: 'success' | 'danger' | 'warning' | 'info'. */
    function toast(mensaje, tipo) {
        var contenedor = document.getElementById('rp-toasts');
        var clase = 'text-bg-' + (['success', 'danger', 'warning', 'info'].indexOf(tipo) >= 0 ? tipo : 'success');
        if (!contenedor || !global.bootstrap) {
            if (global.console) { global.console.warn('[RoutePulse] ' + mensaje); }
            return;
        }
        var cuerpo = crear('div', { className: 'toast-body', texto: mensaje });
        var cerrar = crear('button', { type: 'button', className: 'btn-close btn-close-white me-2 m-auto', 'data-bs-dismiss': 'toast', 'aria-label': 'Cerrar' });
        var el = crear('div', { className: 'toast align-items-center border-0 ' + clase, role: 'alert', 'aria-live': 'assertive', 'aria-atomic': 'true' },
            crear('div', { className: 'd-flex' }, cuerpo, cerrar));
        contenedor.appendChild(el);
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        global.bootstrap.Toast.getOrCreateInstance(el, { delay: 5000 }).show();
    }

    /**
     * Ventana de confirmación. Devuelve una promesa que resuelve true (aceptó) o false.
     * Obligatoria antes de acciones destructivas (cancelar, omitir, devolver...).
     */
    function confirmar(mensaje, opciones) {
        var o = Object.assign({ titulo: 'Confirmar', textoOk: 'Aceptar', peligro: false }, opciones || {});
        var el = document.getElementById('rp-confirmar');
        if (!el || !global.bootstrap) {
            return Promise.resolve(global.confirm(mensaje));
        }
        return new Promise(function (resolver) {
            var ok = el.querySelector('[data-rp="ok"]');
            el.querySelector('[data-rp="titulo"]').textContent = o.titulo;
            el.querySelector('[data-rp="texto"]').textContent = mensaje;
            ok.className = 'btn ' + (o.peligro ? 'btn-danger' : 'btn-primary');
            ok.textContent = o.textoOk;

            var modal = global.bootstrap.Modal.getOrCreateInstance(el);
            var resuelto = false;

            function terminar(valor) {
                if (resuelto) { return; }
                resuelto = true;
                ok.removeEventListener('click', alAceptar);
                el.removeEventListener('hidden.bs.modal', alOcultar);
                resolver(valor);
            }
            function alAceptar() { terminar(true); modal.hide(); }
            function alOcultar() { terminar(false); }

            ok.addEventListener('click', alAceptar);
            el.addEventListener('hidden.bs.modal', alOcultar);
            modal.show();
        });
    }

    // ------------------------------------------------------------------ Formatos

    /** "dd/mm/aaaa HH:mm" en la zona horaria configurada. null → "—". Con soloFecha: "dd/mm/aaaa". */
    function formatoFecha(valor, opciones) {
        if (!valor) { return '—'; }
        var soloFecha = !!(opciones && opciones.soloFecha);

        // Una fecha sin hora (columna DATE) no se desplaza por zona horaria.
        var simple = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(valor));
        if (simple) { return simple[3] + '/' + simple[2] + '/' + simple[1]; }

        var fecha = new Date(valor);
        if (isNaN(fecha.getTime())) { return '—'; }

        var formato = { timeZone: leerMeta('rp-zona-horaria') || 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' };
        if (!soloFecha) {
            formato.hour = '2-digit';
            formato.minute = '2-digit';
            formato.hourCycle = 'h23';
        }
        var partes;
        try {
            partes = new Intl.DateTimeFormat('es', formato).formatToParts(fecha);
        } catch (e) {
            formato.timeZone = 'UTC';
            partes = new Intl.DateTimeFormat('es', formato).formatToParts(fecha);
        }
        var p = {};
        partes.forEach(function (parte) { p[parte.type] = parte.value; });
        return p.day + '/' + p.month + '/' + p.year + (p.hour !== undefined ? ' ' + p.hour + ':' + p.minute : '');
    }

    /** Importe con el símbolo de la moneda (por defecto USD), formato español. null → "—". */
    function formatoMoneda(valor, moneda) {
        if (!esNumero(valor)) { return '—'; }
        try {
            return new Intl.NumberFormat('es', { style: 'currency', currency: moneda || 'USD' }).format(Number(valor));
        } catch (e) {
            return Number(valor).toFixed(2) + ' ' + (moneda || '');
        }
    }

    /** Número con coma decimal. formatoNumero(12.4) → "12,4". */
    function formatoNumero(valor, decimales) {
        if (!esNumero(valor)) { return '—'; }
        var d = decimales === undefined ? 1 : decimales;
        return new Intl.NumberFormat('es', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(valor));
    }

    function formatoKm(valor) {
        return esNumero(valor) ? formatoNumero(valor, 1) + ' km' : '—';
    }

    function formatoPorcentaje(valor) {
        return esNumero(valor) ? formatoNumero(valor, 1) + ' %' : '—';
    }

    /** Minutos → "1 h 35 min". */
    function formatoDuracionMin(minutos) {
        if (!esNumero(minutos)) { return '—'; }
        var total = Math.round(Number(minutos));
        var h = Math.floor(total / 60);
        var m = total % 60;
        return h > 0 ? h + ' h ' + m + ' min' : m + ' min';
    }

    /** Segundos → "mm:ss" (por ejemplo, tiempo de parada). */
    function formatoDuracionSeg(segundos) {
        if (!esNumero(segundos)) { return '—'; }
        var total = Math.round(Number(segundos));
        var m = Math.floor(total / 60);
        var s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ------------------------------------------------------------------ Estados

    function estadoTexto(estado) {
        return TEXTOS_ESTADO[estado] || String(estado || '—');
    }

    function motivoTexto(tipo) {
        return TEXTOS_INCIDENCIA[tipo] || String(tipo || '—');
    }

    /** Insignia con el color del estado (clase estado-<estado>, definida en estados.css). */
    function badgeEstado(estado) {
        return crear('span', { className: 'badge estado-' + String(estado || '').replace(/[^a-z_]/g, '') }, estadoTexto(estado));
    }

    // ------------------------------------------------------------------ Formularios

    /** Quita las marcas de error de un formulario. */
    function limpiarErrores(formulario) {
        if (!formulario) { return; }
        formulario.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        formulario.querySelectorAll('[data-rp-error]').forEach(function (el) { el.remove(); });
    }

    /** Marca cada campo con su mensaje de error. campos = { nombre_del_campo: 'mensaje' } */
    function marcarErrores(formulario, campos) {
        if (!formulario || !campos) { return; }
        limpiarErrores(formulario);
        Object.keys(campos).forEach(function (nombre) {
            var elementos = formulario.elements[nombre];
            var campo = elementos && elementos.length !== undefined && !elementos.tagName ? elementos[0] : elementos;
            if (!campo || !campo.classList) { return; }
            campo.classList.add('is-invalid');
            var aviso = crear('div', { className: 'invalid-feedback', 'data-rp-error': '1', texto: campos[nombre] });
            campo.insertAdjacentElement('afterend', aviso);
        });
    }

    /** Deshabilita un botón mientras se procesa (evita el doble clic). */
    function bloquear(boton, activo, textoCargando) {
        if (!boton) { return; }
        if (activo) {
            boton.dataset.rpTexto = boton.textContent;
            boton.disabled = true;
            boton.textContent = textoCargando || 'Procesando…';
        } else {
            boton.disabled = false;
            if (boton.dataset.rpTexto !== undefined) {
                boton.textContent = boton.dataset.rpTexto;
                delete boton.dataset.rpTexto;
            }
        }
    }

    /** Retrasa la ejecución (buscadores): ui.debounce(fn, 400). */
    function debounce(fn, ms) {
        var temporizador = null;
        return function () {
            var contexto = this;
            var args = arguments;
            clearTimeout(temporizador);
            temporizador = setTimeout(function () { fn.apply(contexto, args); }, ms === undefined ? 400 : ms);
        };
    }

    /** Indicador de carga (spinner de Bootstrap). */
    function spinner(texto) {
        return crear('div', { className: 'text-center text-muted py-4', role: 'status' },
            crear('span', { className: 'spinner-border spinner-border-sm me-2', 'aria-hidden': 'true' }),
            texto || 'Cargando…');
    }

    /**
     * Paginador de Bootstrap a partir del "meta" de la API.
     * ui.paginador(contenedor, meta, function (pagina) { cargar(pagina); })
     */
    function paginador(contenedor, meta, alCambiar) {
        contenedor.textContent = '';
        if (!meta || !meta.paginas || meta.paginas <= 1) { return; }

        var actual = meta.page;
        var ultima = meta.paginas;
        var lista = crear('ul', { className: 'pagination pagination-sm justify-content-center mb-0' });

        function item(etiqueta, pagina, deshabilitado, activo) {
            var enlace = crear('button', { type: 'button', className: 'page-link', texto: etiqueta });
            if (!deshabilitado && !activo) {
                enlace.addEventListener('click', function () { alCambiar(pagina); });
            }
            lista.appendChild(crear('li', { className: 'page-item' + (deshabilitado ? ' disabled' : '') + (activo ? ' active' : '') }, enlace));
        }

        item('«', actual - 1, actual <= 1, false);
        var desde = Math.max(1, actual - 2);
        var hasta = Math.min(ultima, actual + 2);
        if (desde > 1) { item('1', 1, false, false); }
        if (desde > 2) { item('…', 0, true, false); }
        for (var p = desde; p <= hasta; p++) { item(String(p), p, false, p === actual); }
        if (hasta < ultima - 1) { item('…', 0, true, false); }
        if (hasta < ultima) { item(String(ultima), ultima, false, false); }
        item('»', actual + 1, actual >= ultima, false);

        contenedor.appendChild(crear('nav', { 'aria-label': 'Paginación' }, lista));
        contenedor.appendChild(crear('div', { className: 'text-center text-muted small mt-1', texto: 'Total: ' + meta.total }));
    }

    // ------------------------------------------------------------------ Salir

    function configurarSalir() {
        document.querySelectorAll('[data-accion="logout"]').forEach(function (boton) {
            boton.addEventListener('click', async function () {
                try {
                    await global.api.post('auth/logout.php', {}, { silencioso: true, noRedirigir: true });
                } catch (e) {
                    // Aunque falle, se va al login (la sesión puede haber caducado ya).
                }
                global.location.href = global.api.urlBase() + '/login.php';
            });
        });
    }

    global.ui = {
        crear: crear, toast: toast, confirmar: confirmar,
        formatoFecha: formatoFecha, formatoMoneda: formatoMoneda, formatoNumero: formatoNumero,
        formatoKm: formatoKm, formatoPorcentaje: formatoPorcentaje,
        formatoDuracionMin: formatoDuracionMin, formatoDuracionSeg: formatoDuracionSeg,
        estadoTexto: estadoTexto, motivoTexto: motivoTexto, badgeEstado: badgeEstado,
        marcarErrores: marcarErrores, limpiarErrores: limpiarErrores, bloquear: bloquear,
        debounce: debounce, spinner: spinner, paginador: paginador
    };

    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', configurarSalir);
    }
})(typeof window !== 'undefined' ? window : globalThis);
