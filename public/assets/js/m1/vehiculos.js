'use strict';
/** despacho/vehiculos.php: catálogo de vehículos. */
(function () {
    const { el, $, get, post, limpiar } = window.RP;
    const cuerpo = $('#tabla-cuerpo');
    let page = 1, editando = null;
    const modal = new bootstrap.Modal($('#modal-vehiculo'));
    const form = $('#form-vehiculo');

    Object.keys(RP.TIPOS_VEHICULO).forEach((k) => {
        $('#f-tipo').append(el('option', { text: RP.TIPOS_VEHICULO[k], attrs: { value: k } }));
        $('#v-tipo').append(el('option', { text: RP.TIPOS_VEHICULO[k], attrs: { value: k } }));
    });

    async function cargar() {
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: '7' }, className: 'text-center text-muted py-4', text: 'Cargando…' })));
        try {
            const r = await get('vehiculos/listar.php', { q: $('#f-q').value.trim(), tipo: $('#f-tipo').value, activo: $('#f-activo').value, page, por_pagina: 25 });
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(7));
            r.data.forEach((v) => cuerpo.append(el('tr', {},
                el('td', { className: 'fw-semibold', text: v.placa }),
                el('td', { text: RP.TIPOS_VEHICULO[v.tipo] || v.tipo }),
                el('td', { text: RP.dato(v.marca_modelo) }),
                el('td', { className: 'small', text: v.capacidad_kg + ' kg · ' + v.capacidad_m3 + ' m³' }),
                el('td', { text: RP.dinero(v.costo_por_km) }),
                el('td', {}, el('span', { className: 'badge ' + (v.activo ? 'text-bg-success' : 'text-bg-secondary'), text: v.activo ? 'Activo' : 'Inactivo' })),
                el('td', { className: 'text-end' }, el('button', { className: 'btn btn-sm btn-outline-primary', text: 'Editar', attrs: { type: 'button' }, on: { click: () => abrir(v) } })))));
            RP.paginador($('#paginador'), r.meta, (p) => { page = p; cargar(); });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(7, 'No se pudo cargar la lista.')); }
    }

    function abrir(v) {
        editando = v;
        RP.marcarErrores(form, {});
        $('#modal-vehiculo-titulo').textContent = v ? 'Editar vehículo' : 'Nuevo vehículo';
        $('#v-placa').value = v ? v.placa : '';
        $('#v-tipo').value = v ? v.tipo : 'furgon';
        $('#v-modelo').value = v && v.marca_modelo ? v.marca_modelo : '';
        $('#v-kg').value = v ? v.capacidad_kg : '';
        $('#v-m3').value = v ? v.capacidad_m3 : '';
        $('#v-costo').value = v ? v.costo_por_km : 0;
        $('#v-activo').checked = v ? v.activo : true;
        modal.show();
    }
    $('#btn-nuevo').addEventListener('click', () => abrir(null));

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        RP.marcarErrores(form, {});
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const datos = {
            placa: $('#v-placa').value.trim().toUpperCase(), tipo: $('#v-tipo').value, marca_modelo: $('#v-modelo').value.trim() || null,
            capacidad_kg: $('#v-kg').value, capacidad_m3: $('#v-m3').value, costo_por_km: $('#v-costo').value || 0,
        };
        if (editando) { datos.vehiculo_id = editando.id; datos.activo = $('#v-activo').checked; }
        $('#v-guardar').disabled = true;
        try {
            await post(editando ? 'vehiculos/actualizar.php' : 'vehiculos/crear.php', datos);
            RP.exito(editando ? 'Vehículo actualizado' : 'Vehículo creado');
            modal.hide();
            cargar();
        } catch (e) {
            if (e && e.campos) RP.marcarErrores(form, e.campos);
        }
        $('#v-guardar').disabled = false;
    });

    const recargar = RP.debounce(() => { page = 1; cargar(); }, 400);
    $('#f-q').addEventListener('input', recargar);
    ['#f-tipo', '#f-activo'].forEach((s) => $(s).addEventListener('change', () => { page = 1; cargar(); }));
    $('#filtros').addEventListener('submit', (ev) => ev.preventDefault());
    cargar();
})();
