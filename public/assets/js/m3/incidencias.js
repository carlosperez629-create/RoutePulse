'use strict';
/** hub/incidencias.php: tabla de incidencias y acción "Resolver" (reprogramar, devolver, cancelar). */
(function () {
    const { el, $, get, post, limpiar, badge } = window.RP;
    const escribe = $('#m3-incidencias').dataset.escribe === '1';
    const cuerpo = $('#tabla-cuerpo');
    const colspan = escribe ? 8 : 7;
    let page = 1, actual = null, modal = null;

    Object.keys(RP.TIPOS_INCIDENCIA).forEach((k) => $('#f-tipo').append(el('option', { text: RP.TIPOS_INCIDENCIA[k], attrs: { value: k } })));

    async function cargar() {
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: String(colspan) }, className: 'text-center text-muted py-4', text: 'Cargando…' })));
        try {
            const r = await get('incidencias/listar.php', { estado: $('#f-estado').value, tipo: $('#f-tipo').value, desde: $('#f-desde').value, hasta: $('#f-hasta').value, page, por_pagina: 25 });
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(colspan));
            r.data.forEach((i) => {
                const tr = el('tr', {},
                    el('td', {}, el('a', { className: 'fw-semibold text-decoration-none', text: i.codigo_guia, attrs: { href: 'paquete_detalle.php?id=' + i.paquete_id } })),
                    el('td', { text: i.destinatario_nombre }),
                    el('td', { text: i.tipo_texto }),
                    el('td', { className: 'small', text: i.descripcion || '—' }),
                    el('td', {}, badge(i.estado, RP.ESTADOS_INCIDENCIA)),
                    el('td', { text: i.intentos + ' / ' + i.max_intentos }),
                    el('td', { className: 'small', text: RP.fecha(i.created_at) }));
                if (escribe) {
                    tr.append(el('td', { className: 'text-end' }, i.estado === 'abierta'
                        ? el('button', { className: 'btn btn-sm btn-outline-primary', text: 'Resolver', attrs: { type: 'button' }, on: { click: () => abrir(i) } })
                        : (i.reprogramada_para ? el('span', { className: 'small text-muted', text: 'Para ' + RP.soloFecha(i.reprogramada_para) }) : null)));
                }
                cuerpo.append(tr);
            });
            RP.paginador($('#paginador'), r.meta, (p) => { page = p; cargar(); });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(colspan, 'No se pudo cargar la lista.')); }
    }

    ['#f-estado', '#f-tipo', '#f-desde', '#f-hasta'].forEach((s) => $(s).addEventListener('change', () => { page = 1; cargar(); }));
    $('#filtros').addEventListener('submit', (ev) => ev.preventDefault());

    if (escribe) {
        modal = new bootstrap.Modal($('#modal-resolver'));
        const aIso = (v) => (v ? new Date(v).toISOString().replace('.000Z', 'Z') : null);
        const alternar = () => $('#bloque-reprogramar').classList.toggle('d-none', $('#r-accion').value !== 'reprogramar');
        $('#r-accion').addEventListener('change', alternar);

        window.abrirResolver = null;
        function abrir(i) {
            actual = i;
            $('#resolver-resumen').textContent = i.codigo_guia + ' · ' + i.tipo_texto + ' · intento ' + i.intentos + ' de ' + i.max_intentos;
            const sinIntentos = i.intentos >= i.max_intentos;
            const opReprogramar = $('#r-accion').querySelector('option[value=reprogramar]');
            opReprogramar.disabled = sinIntentos;
            $('#r-sin-intentos').classList.toggle('d-none', !sinIntentos);
            $('#r-accion').value = sinIntentos ? 'devolver' : 'reprogramar';
            const hoy = new Date();
            const p = (n) => String(n).padStart(2, '0');
            $('#r-fecha').min = hoy.getFullYear() + '-' + p(hoy.getMonth() + 1) + '-' + p(hoy.getDate());
            $('#r-fecha').value = '';
            $('#r-vi').value = '';
            $('#r-vf').value = '';
            RP.marcarErrores($('#form-resolver'), {});
            alternar();
            modal.show();
        }
        window.abrirResolver = abrir;

        $('#form-resolver').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const accion = $('#r-accion').value;
            if (accion === 'reprogramar' && !$('#r-fecha').value) { RP.marcarErrores($('#form-resolver'), { fecha: 'Indica la nueva fecha.' }); return; }
            if (accion !== 'reprogramar') {
                const textos = { devolver: 'devolver al remitente', cancelar: 'cancelar' };
                const ok = await RP.confirmar('Confirmar', '¿Seguro que quieres ' + textos[accion] + ' el paquete ' + actual.codigo_guia + '?', 'Sí, continuar');
                if (!ok) return;
            }
            const datos = { incidencia_id: actual.id, accion };
            if (accion === 'reprogramar') {
                datos.fecha = $('#r-fecha').value;
                datos.ventana_inicio = aIso($('#r-vi').value);
                datos.ventana_fin = aIso($('#r-vf').value);
            }
            $('#r-enviar').disabled = true;
            try {
                await post('incidencias/resolver.php', datos);
                RP.exito('Incidencia resuelta');
                modal.hide();
                cargar();
            } catch (e) {
                if (e && e.campos) RP.marcarErrores($('#form-resolver'), e.campos);
            }
            $('#r-enviar').disabled = false;
        });
    }
    cargar();
})();
