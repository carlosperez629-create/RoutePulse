'use strict';
/** hub/paquetes.php: tabla paginada de paquetes con filtros. */
(function () {
    const { el, $, $$, get, post, limpiar, badge } = window.RP;
    const raiz = $('#m3-paquetes');
    const escribe = raiz.dataset.escribe === '1';
    const cuerpo = $('#tabla-cuerpo');
    const estado = { page: 1, orden: 'created_at_desc', estados: [], seleccion: new Set() };

    // --- Filtro de estados (casillas dentro de un menú) ---
    const menu = $('#f-estado-menu');
    Object.keys(RP.ESTADOS_PAQUETE).forEach((k) => {
        const id = 'f-est-' + k;
        menu.append(el('div', { className: 'form-check' },
            el('input', { className: 'form-check-input', attrs: { type: 'checkbox', id, value: k } }),
            el('label', { className: 'form-check-label', attrs: { for: id }, text: RP.ESTADOS_PAQUETE[k] })));
    });
    menu.addEventListener('change', () => {
        estado.estados = $$('input:checked', menu).map((c) => c.value);
        $('#f-estado-btn').textContent = estado.estados.length ? estado.estados.map((e) => RP.ESTADOS_PAQUETE[e]).join(', ') : 'Todos';
        estado.page = 1;
        cargar();
    });

    function filtros() {
        return {
            q: $('#f-q').value.trim(), estado: estado.estados, hub_id: $('#f-hub').value, zona_id: $('#f-zona').value,
            prioridad: $('#f-prioridad').value, desde: $('#f-desde').value, hasta: $('#f-hasta').value,
            con_incidencia_abierta: $('#f-incidencia').checked ? 1 : '', orden: estado.orden, page: estado.page, por_pagina: 25,
        };
    }

    function actualizarSeleccion() {
        if (!escribe) return;
        $('#num-sel').textContent = String(estado.seleccion.size);
        $('#btn-imprimir').disabled = estado.seleccion.size === 0;
    }

    function fila(p) {
        const tr = el('tr', { className: p.incidencia_abierta ? 'rp-fila-incidencia' : '' });
        if (escribe) {
            const chk = el('input', { className: 'form-check-input', attrs: { type: 'checkbox', 'aria-label': 'Seleccionar ' + p.codigo_guia } });
            chk.checked = estado.seleccion.has(p.id);
            chk.addEventListener('change', () => { chk.checked ? estado.seleccion.add(p.id) : estado.seleccion.delete(p.id); actualizarSeleccion(); });
            tr.append(el('td', {}, chk));
        }
        tr.append(
            el('td', {}, el('a', { className: 'fw-semibold text-decoration-none', text: p.codigo_guia, attrs: { href: 'paquete_detalle.php?id=' + p.id } }),
                el('div', { className: 'small text-muted', text: RP.fecha(p.created_at) })),
            el('td', {}, el('div', { text: p.destinatario_nombre }), el('div', { className: 'small text-muted', text: p.destinatario_telefono })),
            el('td', { className: 'small', text: p.direccion_entrega + (p.ciudad ? ', ' + p.ciudad : '') }),
            el('td', { className: 'small' }, el('div', { text: p.zona_nombre || 'Sin zona' }), el('div', { className: 'text-muted', text: p.hub_nombre || 'Sin HUB' })),
            el('td', {}, p.prioridad === 'urgente' ? el('span', { className: 'badge text-bg-danger', text: 'URGENTE' }) : el('span', { className: 'text-muted small', text: 'Normal' })),
            el('td', {}, badge(p.estado), p.incidencia_abierta ? el('i', { className: 'bi bi-exclamation-triangle-fill text-danger ms-1', attrs: { title: 'Incidencia abierta' } }) : null),
            el('td', { className: 'small', text: p.ventana_fin ? 'hasta ' + RP.fecha(p.ventana_fin) : '—' }));
        return tr;
    }

    async function cargar() {
        const colspan = escribe ? 8 : 7;
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: String(colspan) }, className: 'text-center text-muted py-4' },
            el('div', { className: 'spinner-border spinner-border-sm me-2', attrs: { role: 'status' } }), 'Cargando…')));
        try {
            const r = await get('paquetes/listar.php', filtros());
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(colspan));
            r.data.forEach((p) => cuerpo.append(fila(p)));
            RP.paginador($('#paginador'), r.meta, (pag) => { estado.page = pag; cargar(); });
            if (escribe) $('#sel-todos').checked = false;
        } catch (e) {
            limpiar(cuerpo).append(RP.filaVacia(colspan, 'No se pudo cargar la lista.'));
        }
    }

    // --- Catálogos de los selectores ---
    async function catalogos() {
        try {
            const hubs = await get('hubs/listar.php');
            hubs.data.forEach((h) => $('#f-hub').append(el('option', { text: h.nombre, attrs: { value: h.id } })));
            const zonas = await get('zonas/listar.php');
            zonas.data.forEach((z) => $('#f-zona').append(el('option', { text: z.nombre, attrs: { value: z.id } })));
        } catch (e) { /* los filtros siguen funcionando sin catálogos */ }
    }

    // --- Eventos ---
    const recargar = RP.debounce(() => { estado.page = 1; cargar(); }, 400);
    $('#f-q').addEventListener('input', recargar);
    ['#f-hub', '#f-zona', '#f-prioridad', '#f-desde', '#f-hasta', '#f-incidencia'].forEach((s) => $(s).addEventListener('change', () => { estado.page = 1; cargar(); }));
    $('#filtros').addEventListener('submit', (ev) => ev.preventDefault());
    $('#btn-limpiar').addEventListener('click', () => {
        $('#filtros').reset();
        $$('input', menu).forEach((c) => { c.checked = false; });
        estado.estados = [];
        $('#f-estado-btn').textContent = 'Todos';
        estado.page = 1;
        cargar();
    });
    $$('[data-orden]').forEach((b) => b.addEventListener('click', () => {
        const campo = b.dataset.orden;
        estado.orden = estado.orden === campo + '_asc' ? campo + '_desc' : campo + '_asc';
        estado.page = 1;
        cargar();
    }));

    if (escribe) {
        $('#sel-todos').addEventListener('change', (ev) => {
            $$('tbody input[type=checkbox]', cuerpo).forEach((c) => { c.checked = ev.target.checked; c.dispatchEvent(new Event('change')); });
        });
        $('#btn-imprimir').addEventListener('click', () => {
            const ids = Array.from(estado.seleccion).slice(0, 100);
            window.open('etiqueta.php?ids=' + ids.join(','), '_blank');
        });

        $('#form-csv').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const archivo = $('#csv-archivo').files[0];
            if (!archivo) return;
            const boton = $('#csv-enviar');
            boton.disabled = true;
            const fd = new FormData();
            fd.append('archivo', archivo);
            const salida = limpiar($('#csv-resultado'));
            try {
                const r = await post('paquetes/importar_csv.php', fd);
                salida.append(el('div', { className: 'alert alert-success py-2 mb-2', text: 'Paquetes creados: ' + r.data.creados }));
                if (r.data.errores.length) {
                    const lista = el('ul', { className: 'small mb-0' });
                    r.data.errores.slice(0, 50).forEach((e) => lista.append(el('li', { text: 'Fila ' + e.fila + ': ' + e.mensaje })));
                    salida.append(el('div', { className: 'alert alert-warning py-2' }, el('div', { className: 'fw-semibold', text: 'Filas con errores: ' + r.data.errores.length }), lista));
                }
                cargar();
            } catch (e) { /* api() ya mostró el error */ }
            boton.disabled = false;
        });
    }

    catalogos();
    cargar();
})();
