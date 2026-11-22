'use strict';
/** hub/paquete_detalle.php: datos, línea de tiempo, incidencias, ruta/parada, entregas y acciones. */
(function () {
    const { el, $, get, post, limpiar, badge } = window.RP;
    const raiz = $('#m3-detalle');
    const escribe = raiz.dataset.escribe === '1';
    const id = RP.param('id'), guia = RP.param('guia');
    const EDITABLES = ['creado', 'ingresado_hub', 'en_clasificacion'];
    const CANCELABLES = ['creado', 'ingresado_hub', 'en_clasificacion', 'asignado_ruta'];

    function campo(etiqueta, valor) {
        return el('div', { className: 'col-sm-6 mb-2' }, el('div', { className: 'small text-muted', text: etiqueta }), el('div', { text: RP.dato(valor) }));
    }
    function tarjeta(titulo, ...hijos) {
        return el('div', { className: 'card mb-3' }, el('div', { className: 'card-header fw-semibold', text: titulo }), el('div', { className: 'card-body' }, hijos));
    }

    function pintar(p) {
        document.title = p.codigo_guia + ' · RoutePulse';
        $('#d-guia').textContent = p.codigo_guia;
        limpiar($('#d-estado')).append(badge(p.estado));

        // --- Acciones según estado ---
        const acc = limpiar($('#acciones'));
        acc.append(el('a', { className: 'btn btn-outline-secondary btn-sm', text: 'Volver', attrs: { href: 'paquetes.php' } }));
        if (escribe) {
            acc.append(el('a', { className: 'btn btn-outline-dark btn-sm', attrs: { href: 'etiqueta.php?ids=' + p.id, target: '_blank' } }, el('i', { className: 'bi bi-printer me-1' }), 'Imprimir etiqueta'));
            if (EDITABLES.includes(p.estado)) acc.append(el('a', { className: 'btn btn-outline-primary btn-sm', attrs: { href: 'paquete_nuevo.php?id=' + p.id } }, el('i', { className: 'bi bi-pencil me-1' }), 'Editar'));
            if (p.estado === 'en_transito') acc.append(el('button', { className: 'btn btn-outline-warning btn-sm', attrs: { type: 'button' }, on: { click: () => registrarIncidencia(p) } }, el('i', { className: 'bi bi-exclamation-triangle me-1' }), 'Registrar incidencia'));
            if (CANCELABLES.includes(p.estado)) acc.append(el('button', { className: 'btn btn-outline-danger btn-sm', attrs: { type: 'button' }, on: { click: () => cancelar(p) } }, el('i', { className: 'bi bi-x-circle me-1' }), 'Cancelar paquete'));
        }

        const c = limpiar($('#contenido'));
        const columnaIzq = el('div', { className: 'col-lg-7' });
        const columnaDer = el('div', { className: 'col-lg-5' });

        columnaIzq.append(
            tarjeta('Destinatario y entrega', el('div', { className: 'row' },
                campo('Destinatario', p.destinatario_nombre), campo('Teléfono', p.destinatario_telefono), campo('Correo', p.destinatario_email),
                campo('Dirección', p.direccion_entrega), campo('Referencia', p.referencia), campo('Ciudad / CP', [p.ciudad, p.codigo_postal].filter(Boolean).join(' · ') || null),
                campo('Zona', p.zona_nombre || 'Sin zona'), campo('HUB', p.hub_nombre || 'Sin HUB'),
                campo('Coordenadas', p.lat !== null ? p.lat.toFixed(6) + ', ' + p.lng.toFixed(6) + (p.geocodificado ? ' (geocodificado)' : '') : 'Sin coordenadas'))),
            tarjeta('Envío', el('div', { className: 'row' },
                campo('Remitente', p.remitente_nombre), campo('Teléfono remitente', p.remitente_telefono),
                campo('Peso', p.peso_kg + ' kg'), campo('Volumen', p.volumen_m3 + ' m³'),
                campo('Cobro contra entrega', p.cobro_contra_entrega > 0 ? RP.dinero(p.cobro_contra_entrega, p.moneda) : 'No aplica'),
                campo('Prioridad', p.prioridad === 'urgente' ? 'URGENTE' : 'Normal'),
                campo('Ventana horaria', p.ventana_inicio || p.ventana_fin ? RP.fecha(p.ventana_inicio) + ' → ' + RP.fecha(p.ventana_fin) : null),
                campo('Intentos de entrega', p.intentos + ' de ' + p.max_intentos),
                campo('Reprogramado para', p.fecha_programada ? RP.soloFecha(p.fecha_programada) : null), campo('Notas', p.notas), campo('Creado', RP.fecha(p.created_at)))));

        // Enlace de seguimiento copiable
        const enlace = el('input', { className: 'form-control form-control-sm', attrs: { readonly: 'readonly', value: p.enlace_seguimiento, 'aria-label': 'Enlace de seguimiento' } });
        const copiar = el('button', { className: 'btn btn-outline-secondary btn-sm', attrs: { type: 'button' }, text: 'Copiar', on: { click: async () => {
            try { await navigator.clipboard.writeText(p.enlace_seguimiento); } catch (e) { enlace.select(); document.execCommand('copy'); }
            RP.exito('Enlace copiado');
        } } });
        columnaDer.append(tarjeta('Enlace de seguimiento', el('div', { className: 'input-group input-group-sm' }, enlace, copiar),
            p.token_expira_at ? el('div', { className: 'small text-muted mt-1', text: 'Vence: ' + RP.fecha(p.token_expira_at) }) : null));

        // Ruta y parada actual
        const pa = p.parada_actual;
        columnaDer.append(tarjeta('Ruta y parada', pa
            ? el('div', {},
                el('div', {}, 'Ruta ', el('a', { text: pa.ruta_codigo, attrs: { href: '../despacho/ruta_detalle.php?ruta_id=' + pa.ruta_id } })),
                el('div', { className: 'small mt-1' }, 'Parada #' + pa.orden + ' ', badge(pa.estado, RP.ESTADOS_PARADA)),
                el('div', { className: 'small text-muted mt-1', text: 'ETA: ' + RP.fecha(pa.eta) }))
            : el('div', { className: 'text-muted', text: 'Todavía no está en ninguna ruta.' })));

        // Línea de tiempo
        const lista = el('ol', { className: 'rp-linea-tiempo' });
        p.historial.forEach((h) => lista.append(el('li', { className: 'rp-lt-' + h.estado_nuevo },
            el('div', { className: 'fw-semibold', text: RP.ESTADOS_PAQUETE[h.estado_nuevo] || h.estado_nuevo }),
            el('div', { className: 'small text-muted', text: RP.fecha(h.created_at) + ' · ' + h.usuario_nombre }),
            h.motivo ? el('div', { className: 'small', text: h.motivo }) : null)));
        columnaDer.append(tarjeta('Línea de tiempo', lista));

        // Incidencias y entregas
        if (p.incidencias.length) {
            const tabla = el('table', { className: 'table table-sm mb-0' }, el('tbody', {}, p.incidencias.map((i) => el('tr', {},
                el('td', { text: i.tipo_texto }), el('td', { className: 'small', text: i.descripcion || '—' }),
                el('td', {}, badge(i.estado, { abierta: 'Abierta', reprogramada: 'Reprogramada', devuelta: 'Devuelta', cancelada: 'Cancelada' })),
                el('td', { className: 'small text-muted', text: RP.fecha(i.created_at) })))));
            columnaIzq.append(tarjeta('Incidencias', tabla));
        }
        if (p.entregas.length) {
            const tabla = el('table', { className: 'table table-sm mb-0' }, el('tbody', {}, p.entregas.map((e) => el('tr', {},
                el('td', { text: e.resultado === 'entregado' ? 'Entregado' : 'Fallida' }),
                el('td', { className: 'small', text: e.receptor_nombre || (e.motivo_fallo ? RP.TIPOS_INCIDENCIA[e.motivo_fallo] : '—') }),
                el('td', { className: 'small text-muted', text: RP.fecha(e.ocurrido_at) })))));
            columnaIzq.append(tarjeta('Entregas', tabla));
        }
        c.append(el('div', { className: 'row' }, columnaIzq, columnaDer));
    }

    async function cargar() {
        try {
            const r = await get('paquetes/ver.php', id ? { id } : { guia });
            pintar(r.data);
        } catch (e) {
            limpiar($('#contenido')).append(el('div', { className: 'alert alert-danger', text: 'No encontramos lo que buscas.' }));
        }
    }

    async function cancelar(p) {
        const v = await RP.formularioModal('Cancelar paquete ' + p.codigo_guia, [
            { nombre: 'motivo', etiqueta: 'Motivo de la cancelación', tipo: 'textarea', requerido: true, max: 255 },
        ], 'Cancelar paquete');
        if (!v) return;
        const ok = await RP.confirmar('Cancelar paquete', '¿Seguro que quieres cancelar el paquete ' + p.codigo_guia + '? Esta acción no se puede deshacer.', 'Sí, cancelar');
        if (!ok) return;
        try {
            await post('paquetes/cambiar_estado.php', { paquete_id: p.id, estado_nuevo: 'cancelado', motivo: v.motivo });
            RP.exito('Paquete cancelado');
            cargar();
        } catch (e) { /* api() ya mostró el error */ }
    }

    async function registrarIncidencia(p) {
        const v = await RP.formularioModal('Registrar incidencia en ' + p.codigo_guia, [
            { nombre: 'tipo', etiqueta: 'Tipo', tipo: 'select', opciones: Object.keys(RP.TIPOS_INCIDENCIA).map((k) => ({ valor: k, texto: RP.TIPOS_INCIDENCIA[k] })) },
            { nombre: 'descripcion', etiqueta: 'Descripción', tipo: 'textarea', max: 500 },
        ], 'Registrar');
        if (!v) return;
        try {
            await post('incidencias/crear.php', { paquete_id: p.id, tipo: v.tipo, descripcion: v.descripcion || null });
            RP.exito('Incidencia registrada');
            cargar();
        } catch (e) { /* api() ya mostró el error */ }
    }

    cargar();
})();
