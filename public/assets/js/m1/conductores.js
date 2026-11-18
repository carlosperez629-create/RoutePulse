'use strict';
/** despacho/conductores.php: alta de conductores (usuario + licencia + vehículo) y edición de estado y vehículo. */
(function () {
    const { el, $, get, post, limpiar, badge } = window.RP;
    const cuerpo = $('#tabla-cuerpo');
    let page = 1, editando = null;
    const modal = new bootstrap.Modal($('#modal-conductor'));
    const form = $('#form-conductor');

    async function catalogos() {
        try {
            (await get('zonas/listar.php')).data.forEach((z) => $('#f-zona').append(el('option', { text: z.nombre, attrs: { value: z.id } })));
            (await get('vehiculos/listar.php', { activo: 1, por_pagina: 100 })).data
                .forEach((v) => $('#c-vehiculo').append(el('option', { text: v.placa + ' · ' + (RP.TIPOS_VEHICULO[v.tipo] || v.tipo), attrs: { value: v.id } })));
        } catch (e) { /* los selectores quedan vacíos */ }
    }

    async function cargar() {
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: '7' }, className: 'text-center text-muted py-4', text: 'Cargando…' })));
        try {
            const r = await get('conductores/listar.php', { estado: $('#f-estado').value, zona_id: $('#f-zona').value, page, por_pagina: 25 });
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(7));
            r.data.forEach((c) => cuerpo.append(el('tr', {},
                el('td', { className: 'fw-semibold', text: c.usuario.nombre }),
                el('td', { className: 'small' }, el('div', { text: c.usuario.email }), el('div', { className: 'text-muted', text: RP.dato(c.usuario.telefono) })),
                el('td', { text: RP.dato(c.licencia) }),
                el('td', { className: 'small', text: c.vehiculo ? c.vehiculo.placa + ' · ' + (RP.TIPOS_VEHICULO[c.vehiculo.tipo] || '') : 'Sin vehículo' }),
                el('td', { className: 'small', text: c.zonas.length ? c.zonas.map((z) => z.nombre).join(', ') : '—' }),
                el('td', {}, badge(c.estado, { disponible: 'Disponible', en_ruta: 'En ruta', inactivo: 'Inactivo' })),
                el('td', { className: 'text-end' }, el('button', { className: 'btn btn-sm btn-outline-primary', text: 'Editar', attrs: { type: 'button' }, on: { click: () => abrir(c) } })))));
            RP.paginador($('#paginador'), r.meta, (p) => { page = p; cargar(); });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(7, 'No se pudo cargar la lista.')); }
    }

    function abrir(c) {
        editando = c;
        RP.marcarErrores(form, {});
        $('#modal-conductor-titulo').textContent = c ? 'Editar conductor' : 'Nuevo conductor';
        $('#bloque-usuario').classList.toggle('d-none', !!c);
        $('#bloque-estado').classList.toggle('d-none', !c);
        ['c-nombre', 'c-email', 'c-telefono', 'c-password'].forEach((id) => { $('#' + id).value = ''; });
        $('#c-licencia').value = c && c.licencia ? c.licencia : '';
        $('#c-vehiculo').value = c && c.vehiculo_id ? c.vehiculo_id : '';
        if (c) {
            const enRuta = c.estado === 'en_ruta';
            $('#c-estado').value = c.estado === 'inactivo' ? 'inactivo' : 'disponible';
            $('#c-estado').disabled = enRuta;
            $('#c-estado-ayuda').classList.toggle('d-none', !enRuta);
        }
        modal.show();
    }
    $('#btn-nuevo').addEventListener('click', () => abrir(null));

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        RP.marcarErrores(form, {});
        const falta = {};
        if (!editando) {
            if (!$('#c-nombre').value.trim()) falta.nombre = 'El nombre es obligatorio.';
            if (!$('#c-email').value.trim()) falta.email = 'El correo es obligatorio.';
            if (!$('#c-telefono').value.trim()) falta.telefono = 'El teléfono es obligatorio.';
        }
        if (Object.keys(falta).length) { RP.marcarErrores(form, falta); return; }
        const datos = { licencia: $('#c-licencia').value.trim() || null, vehiculo_id: $('#c-vehiculo').value ? parseInt($('#c-vehiculo').value, 10) : null };
        if (editando) {
            datos.conductor_id = editando.id;
            if (!$('#c-estado').disabled) datos.estado = $('#c-estado').value;
        } else {
            datos.nombre = $('#c-nombre').value.trim();
            datos.email = $('#c-email').value.trim();
            datos.telefono = $('#c-telefono').value.trim();
            if ($('#c-password').value) datos.password = $('#c-password').value;
        }
        $('#c-guardar').disabled = true;
        try {
            const r = await post(editando ? 'conductores/actualizar.php' : 'conductores/crear.php', datos);
            modal.hide();
            cargar();
            if (!editando && r.data.password_temporal) {
                await RP.confirmar('Contraseña temporal', 'Entrega esta contraseña al conductor. No se volverá a mostrar: ' + r.data.password_temporal, 'Entendido', false);
            } else {
                RP.exito(editando ? 'Conductor actualizado' : 'Conductor creado');
            }
        } catch (e) {
            if (e && e.campos) RP.marcarErrores(form, e.campos);
        }
        $('#c-guardar').disabled = false;
    });

    ['#f-estado', '#f-zona'].forEach((s) => $(s).addEventListener('change', () => { page = 1; cargar(); }));
    $('#filtros').addEventListener('submit', (ev) => ev.preventDefault());
    catalogos();
    cargar();
})();
