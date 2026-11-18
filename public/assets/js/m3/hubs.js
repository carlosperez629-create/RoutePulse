'use strict';
/** hub/hubs.php: CRUD de HUBs con ubicación en mapa (solo admin escribe). */
(function () {
    const { el, $, get, post, limpiar } = window.RP;
    const escribe = $('#m3-hubs').dataset.escribe === '1';
    const cuerpo = $('#tabla-cuerpo');
    const colspan = escribe ? 6 : 5;
    let editando = null, mapa = null, marcador = null, modal = null;

    async function cargar() {
        limpiar(cuerpo).append(el('tr', {}, el('td', { attrs: { colspan: String(colspan) }, className: 'text-center text-muted py-4', text: 'Cargando…' })));
        try {
            const r = await get('hubs/listar.php');
            limpiar(cuerpo);
            if (!r.data.length) cuerpo.append(RP.filaVacia(colspan));
            r.data.forEach((h) => {
                const tr = el('tr', {},
                    el('td', { className: 'fw-semibold', text: h.nombre }),
                    el('td', { text: RP.dato(h.direccion) }),
                    el('td', { className: 'small', text: h.lat.toFixed(5) + ', ' + h.lng.toFixed(5) }),
                    el('td', { text: String(h.paquetes_activos) }),
                    el('td', {}, el('span', { className: 'badge ' + (h.activo ? 'text-bg-success' : 'text-bg-secondary'), text: h.activo ? 'Activo' : 'Inactivo' })));
                if (escribe) tr.append(el('td', { className: 'text-end' }, el('button', { className: 'btn btn-sm btn-outline-primary', text: 'Editar', attrs: { type: 'button' }, on: { click: () => abrir(h) } })));
                cuerpo.append(tr);
            });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(colspan, 'No se pudo cargar la lista.')); }
    }

    if (escribe) {
        const form = $('#form-hub');
        modal = new bootstrap.Modal($('#modal-hub'));

        function poner(lat, lng) {
            if (!marcador) {
                marcador = L.marker([lat, lng], { draggable: true }).addTo(mapa);
                marcador.on('dragend', () => { const p = marcador.getLatLng(); $('#h-lat').value = p.lat.toFixed(7); $('#h-lng').value = p.lng.toFixed(7); });
            } else marcador.setLatLng([lat, lng]);
            $('#h-lat').value = Number(lat).toFixed(7);
            $('#h-lng').value = Number(lng).toFixed(7);
        }
        $('#modal-hub').addEventListener('shown.bs.modal', () => {
            const lat = parseFloat($('#h-lat').value), lng = parseFloat($('#h-lng').value);
            const centro = !isNaN(lat) && !isNaN(lng) ? [lat, lng] : [4.6097, -74.0817];
            if (!mapa) {
                mapa = RP.mapa('mapa-hub', centro[0], centro[1], 14);
                mapa.on('click', (ev) => poner(ev.latlng.lat, ev.latlng.lng));
            }
            mapa.invalidateSize();
            mapa.setView(centro, 14);
            if (marcador) { mapa.removeLayer(marcador); marcador = null; }
            if (!isNaN(lat) && !isNaN(lng)) poner(lat, lng);
        });
        ['h-lat', 'h-lng'].forEach((id) => $('#' + id).addEventListener('change', () => {
            const lat = parseFloat($('#h-lat').value), lng = parseFloat($('#h-lng').value);
            if (mapa && !isNaN(lat) && !isNaN(lng)) { poner(lat, lng); mapa.setView([lat, lng]); }
        }));

        function abrir(h) {
            editando = h;
            RP.marcarErrores(form, {});
            $('#modal-hub-titulo').textContent = h ? 'Editar HUB' : 'Nuevo HUB';
            $('#h-nombre').value = h ? h.nombre : '';
            $('#h-direccion').value = h && h.direccion ? h.direccion : '';
            $('#h-lat').value = h ? h.lat : '';
            $('#h-lng').value = h ? h.lng : '';
            $('#h-activo').checked = h ? h.activo : true;
            modal.show();
        }
        $('#btn-nuevo').addEventListener('click', () => abrir(null));

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            RP.marcarErrores(form, {});
            if (!form.checkValidity()) { form.reportValidity(); return; }
            const datos = { nombre: $('#h-nombre').value.trim(), direccion: $('#h-direccion').value.trim() || null, lat: $('#h-lat').value, lng: $('#h-lng').value, activo: $('#h-activo').checked };
            if (editando) datos.hub_id = editando.id;
            $('#h-guardar').disabled = true;
            try {
                await post(editando ? 'hubs/actualizar.php' : 'hubs/crear.php', datos);
                RP.exito(editando ? 'HUB actualizado' : 'HUB creado');
                modal.hide();
                cargar();
            } catch (e) {
                if (e && e.campos) RP.marcarErrores(form, e.campos);
            }
            $('#h-guardar').disabled = false;
        });
    }
    cargar();
})();
