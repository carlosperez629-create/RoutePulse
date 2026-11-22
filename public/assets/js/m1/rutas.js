'use strict';
/** despacho/rutas.php: lista de rutas y asistente "Generar rutas". */
(function () {
    const { el, $, get, post, limpiar, badge } = window.RP;
    const escribe = $('#m1-rutas').dataset.escribe === '1';
    const cuerpo = $('#tabla-cuerpo');
    let page = 1;

    const hoyLocal = () => { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); };
    const aIso = (v) => (v ? new Date(v).toISOString().replace('.000Z', 'Z') : null);
    const MOTIVOS = { excede_capacidad: 'Excede la capacidad de los vehículos', sin_conductor_disponible: 'No hay conductor disponible', sin_coordenadas: 'Sin coordenadas' };

    async function cargar() {
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: '8' }, className: 'text-center text-muted py-4', text: 'Cargando…' })));
        try {
            const r = await get('rutas/listar.php', { fecha: $('#f-fecha').value, estado: $('#f-estado').value, conductor_id: $('#f-conductor').value, zona_id: $('#f-zona').value, page, por_pagina: 25 });
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(8));
            r.data.forEach((x) => cuerpo.append(el('tr', {},
                el('td', {}, el('a', { className: 'fw-semibold text-decoration-none', text: x.codigo, attrs: { href: 'ruta_detalle.php?ruta_id=' + x.id } })),
                el('td', { text: RP.soloFecha(x.fecha) }),
                el('td', {}, badge(x.estado, RP.ESTADOS_RUTA)),
                el('td', { className: 'small' }, el('div', { text: x.conductor_nombre || 'Sin conductor' }), el('div', { className: 'text-muted', text: x.vehiculo_placa || 'Sin vehículo' })),
                el('td', { className: 'small', text: x.zona_nombre || 'Mixta' }),
                el('td', { text: x.resueltas + ' / ' + x.total_paquetes }),
                el('td', { className: 'small', text: x.carga_kg + ' kg' }),
                el('td', { className: 'small', text: RP.km(x.distancia_km_estimada) + ' · ' + RP.duracion(x.duracion_min_estimada) }))));
            RP.paginador($('#paginador'), r.meta, (p) => { page = p; cargar(); });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(8, 'No se pudo cargar la lista.')); }
    }

    async function catalogos() {
        try {
            (await get('zonas/listar.php')).data.forEach((z) => {
                $('#f-zona').append(el('option', { text: z.nombre, attrs: { value: z.id } }));
                if (escribe) $('#g-zona').append(el('option', { text: z.nombre, attrs: { value: z.id } }));
            });
            if (escribe) {
                (await get('conductores/listar.php', { por_pagina: 100 })).data.forEach((c) => $('#f-conductor').append(el('option', { text: c.usuario.nombre, attrs: { value: c.id } })));
                (await get('hubs/listar.php', { activo: 1 })).data.forEach((h) => $('#g-hub').append(el('option', { text: h.nombre, attrs: { value: h.id } })));
            } else {
                $('#f-conductor').closest('div[class^="col-"]').classList.add('d-none'); // el gerente no consulta la lista de conductores
            }
        } catch (e) { /* los filtros siguen funcionando */ }
    }

    ['#f-fecha', '#f-estado', '#f-conductor', '#f-zona'].forEach((s) => $(s).addEventListener('change', () => { page = 1; cargar(); }));
    $('#filtros').addEventListener('submit', (ev) => ev.preventDefault());
    $('#btn-limpiar').addEventListener('click', () => { $('#filtros').reset(); page = 1; cargar(); });

    if (escribe) {
        const modal = new bootstrap.Modal($('#modal-generar'));
        const form = $('#form-generar');

        async function conductoresDisponibles() {
            const fecha = $('#g-fecha').value;
            const cont = limpiar($('#g-conductores'));
            if (!fecha) { cont.append(el('span', { className: 'text-muted small', text: 'Elige una fecha…' })); return; }
            try {
                const r = await get('conductores/listar.php', { disponibles_para_fecha: fecha, por_pagina: 100 });
                if (!r.data.length) cont.append(el('span', { className: 'text-muted small', text: 'No hay conductores disponibles para esa fecha.' }));
                r.data.forEach((c) => {
                    const id = 'gc-' + c.id;
                    cont.append(el('div', { className: 'form-check' },
                        el('input', { className: 'form-check-input', attrs: { type: 'checkbox', id, value: c.id } }),
                        el('label', { className: 'form-check-label', attrs: { for: id }, text: c.usuario.nombre + (c.vehiculo ? ' · ' + c.vehiculo.placa + ' (' + c.vehiculo.capacidad_kg + ' kg)' : ' · sin vehículo') })));
                });
            } catch (e) { cont.append(el('span', { className: 'text-danger small', text: 'No se pudo cargar la lista.' })); }
        }
        $('#g-fecha').addEventListener('change', conductoresDisponibles);

        $('#btn-generar').addEventListener('click', () => {
            $('#gen-formulario').classList.remove('d-none');
            $('#gen-resultado').classList.add('d-none');
            $('#g-enviar').classList.remove('d-none');
            $('#g-cerrar').textContent = 'Cancelar';
            $('#g-fecha').value = hoyLocal();
            $('#g-hora').value = '';
            RP.marcarErrores(form, {});
            conductoresDisponibles();
            modal.show();
        });

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            RP.marcarErrores(form, {});
            if (!form.checkValidity()) { form.reportValidity(); return; }
            const datos = { fecha: $('#g-fecha').value, hub_id: parseInt($('#g-hub').value, 10) };
            if ($('#g-zona').value) datos.zona_id = parseInt($('#g-zona').value, 10);
            if ($('#g-hora').value) datos.hora_inicio = aIso($('#g-hora').value);
            const ids = Array.from(document.querySelectorAll('#g-conductores input:checked')).map((c) => parseInt(c.value, 10));
            if (ids.length) datos.conductor_ids = ids;
            const boton = $('#g-enviar');
            boton.disabled = true;
            boton.textContent = 'Generando…';
            try {
                const r = await post('rutas/generar.php', datos);
                const res = limpiar($('#gen-resultado'));
                res.append(el('div', { className: 'alert alert-' + (r.data.rutas.length ? 'success' : 'warning') + ' py-2', text: r.data.rutas.length ? 'Se crearon ' + r.data.rutas.length + ' ruta(s) en borrador.' : 'No se creó ninguna ruta.' }));
                if (r.data.rutas.length) {
                    res.append(el('ul', { className: 'list-group mb-3' }, r.data.rutas.map((x) => el('li', { className: 'list-group-item d-flex justify-content-between align-items-center' },
                        el('a', { text: x.codigo, className: 'fw-semibold', attrs: { href: 'ruta_detalle.php?ruta_id=' + x.id } }),
                        el('span', { className: 'small text-muted', text: (x.conductor ? x.conductor.nombre : '—') + ' · ' + x.resumen.total_paquetes + ' paquete(s) · ' + RP.km(x.resumen.distancia_km_estimada) })))));
                }
                if (r.data.sin_asignar.length) {
                    res.append(el('div', { className: 'alert alert-warning py-2 mb-0' }, el('div', { className: 'fw-semibold', text: 'Paquetes sin asignar (' + r.data.sin_asignar.length + ')' }),
                        el('ul', { className: 'small mb-0' }, r.data.sin_asignar.map((s) => el('li', {}, el('a', { text: s.codigo_guia, attrs: { href: '../hub/paquete_detalle.php?id=' + s.paquete_id } }), ' — ' + (MOTIVOS[s.motivo] || s.motivo))))));
                }
                $('#gen-formulario').classList.add('d-none');
                res.classList.remove('d-none');
                boton.classList.add('d-none');
                $('#g-cerrar').textContent = 'Cerrar';
                cargar();
            } catch (e) {
                if (e && e.campos) RP.marcarErrores(form, e.campos);
            }
            boton.disabled = false;
            boton.textContent = 'Generar';
        });
    }

    catalogos();
    cargar();
})();
