'use strict';
/** despacho/zonas.php: mapa con polígonos de zonas, dibujo con Leaflet.draw, códigos postales y conductores. */
(function () {
    const { el, $, get, post, limpiar } = window.RP;
    const form = $('#form-zona');
    let mapa, zonas = [], conductores = [], capasZonas = {}, editable, seleccion = null; // seleccion: {id|null}
    let poligono = null, poligonoCambio = false;

    function iniciarMapa() {
        mapa = RP.mapa('mapa-zonas', 10.5571993, -71.6490662, 11);
        editable = new L.FeatureGroup().addTo(mapa);
        const control = new L.Control.Draw({
            position: 'topleft',
            draw: { polygon: { allowIntersection: false, showArea: false }, polyline: false, rectangle: false, circle: false, marker: false, circlemarker: false },
            edit: { featureGroup: editable },
        });
        mapa.addControl(control);
        mapa.on(L.Draw.Event.CREATED, (ev) => {
            if (!seleccion) { RP.advertencia('Primero crea o selecciona una zona.'); return; }
            editable.clearLayers();
            editable.addLayer(ev.layer);
            poligono = ev.layer.toGeoJSON().geometry;
            poligonoCambio = true;
            estadoPoligono();
        });
        mapa.on(L.Draw.Event.EDITED, () => {
            const capas = editable.getLayers();
            if (capas.length) { poligono = capas[0].toGeoJSON().geometry; poligonoCambio = true; estadoPoligono(); }
        });
        mapa.on(L.Draw.Event.DELETED, () => {
            if (!editable.getLayers().length) { poligono = null; poligonoCambio = true; estadoPoligono(); }
        });
    }

    function estadoPoligono() {
        $('#estado-poligono').textContent = poligono ? 'Polígono definido (' + (poligono.coordinates[0].length - 1) + ' vértices).' : 'Sin polígono: solo se resolverá por código postal.';
    }

    function dibujarZonas() {
        Object.values(capasZonas).forEach((c) => mapa.removeLayer(c));
        capasZonas = {};
        editable.clearLayers();
        zonas.forEach((z) => {
            if (!z.poligono) return;
            const esSel = seleccion && seleccion.id === z.id;
            const capa = L.geoJSON(z.poligono, { style: { color: z.color, weight: esSel ? 4 : 2, fillOpacity: z.activa ? 0.2 : 0.05, dashArray: z.activa ? null : '6' } });
            capa.bindTooltip(z.nombre, { sticky: true });
            if (esSel) {
                capa.eachLayer((l) => editable.addLayer(l));
            } else {
                capa.on('click', () => seleccionar(z.id));
                capa.addTo(mapa);
                capasZonas[z.id] = capa;
            }
        });
    }

    function pintarLista() {
        const ul = limpiar($('#lista-zonas'));
        if (!zonas.length) ul.append(el('li', { className: 'list-group-item text-muted', text: 'Aún no hay zonas.' }));
        zonas.forEach((z) => ul.append(el('li', { className: 'list-group-item list-group-item-action d-flex align-items-center gap-2' + (seleccion && seleccion.id === z.id ? ' active' : ''), attrs: { role: 'button' }, on: { click: () => seleccionar(z.id) } },
            el('span', { className: 'rp-zona-color', attrs: { style: 'background:' + z.color } }),
            el('div', { className: 'flex-grow-1' }, el('div', { className: 'fw-semibold', text: z.nombre + (z.activa ? '' : ' (inactiva)') }),
                el('div', { className: 'small opacity-75', text: z.codigos_postales.length + ' CP · ' + z.conductores.length + ' conductor(es)' })))));
    }

    function pintarConductores(ids) {
        const cont = limpiar($('#z-conductores'));
        if (!conductores.length) cont.append(el('div', { className: 'text-muted small', text: 'No hay conductores registrados.' }));
        conductores.forEach((c) => {
            const id = 'zc-' + c.id;
            const chk = el('input', { className: 'form-check-input', attrs: { type: 'checkbox', id, value: c.id } });
            chk.checked = ids.includes(c.id);
            cont.append(el('div', { className: 'form-check' }, chk, el('label', { className: 'form-check-label', attrs: { for: id }, text: c.usuario.nombre })));
        });
    }

    function seleccionar(id) {
        seleccion = { id };
        const z = zonas.find((x) => x.id === id);
        RP.marcarErrores(form, {});
        $('#titulo-form').textContent = 'Editar zona';
        $('#z-nombre').value = z.nombre;
        $('#z-descripcion').value = z.descripcion || '';
        $('#z-color').value = z.color;
        $('#z-activa').checked = z.activa;
        $('#z-cp').value = z.codigos_postales.join(', ');
        poligono = z.poligono;
        poligonoCambio = false;
        pintarConductores(z.conductores.map((c) => c.id));
        $('#z-guardar').disabled = false;
        estadoPoligono();
        pintarLista();
        dibujarZonas();
        if (z.poligono) mapa.fitBounds(L.geoJSON(z.poligono).getBounds(), { maxZoom: 14 });
    }

    function nueva() {
        seleccion = { id: null };
        RP.marcarErrores(form, {});
        $('#titulo-form').textContent = 'Nueva zona';
        form.reset();
        $('#z-color').value = '#0d6efd';
        $('#z-activa').checked = true;
        poligono = null;
        poligonoCambio = false;
        pintarConductores([]);
        $('#z-guardar').disabled = false;
        estadoPoligono();
        pintarLista();
        dibujarZonas();
        $('#z-nombre').focus();
    }

    function cancelar() {
        seleccion = null;
        poligono = null;
        poligonoCambio = false;
        form.reset();
        $('#titulo-form').textContent = 'Selecciona o crea una zona';
        $('#z-guardar').disabled = true;
        $('#estado-poligono').textContent = '';
        limpiar($('#z-conductores'));
        pintarLista();
        dibujarZonas();
    }

    async function cargar() {
        try {
            zonas = (await get('zonas/listar.php')).data;
            conductores = (await get('conductores/listar.php', { por_pagina: 100 })).data;
        } catch (e) { zonas = zonas || []; }
        pintarLista();
        dibujarZonas();
        const todas = zonas.filter((z) => z.poligono);
        if (!seleccion && todas.length) {
            const grupo = L.featureGroup(todas.map((z) => L.geoJSON(z.poligono)));
            mapa.fitBounds(grupo.getBounds(), { maxZoom: 13 });
        }
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        RP.marcarErrores(form, {});
        if (!seleccion) return;
        if (!$('#z-nombre').value.trim()) { RP.marcarErrores(form, { nombre: 'El nombre es obligatorio.' }); return; }
        const datos = {
            nombre: $('#z-nombre').value.trim(), descripcion: $('#z-descripcion').value.trim() || null, color: $('#z-color').value,
            codigos_postales: $('#z-cp').value.split(',').map((s) => s.trim()).filter(Boolean),
        };
        if (seleccion.id === null) { if (poligono) datos.poligono = poligono; }
        else { datos.zona_id = seleccion.id; datos.activa = $('#z-activa').checked; if (poligonoCambio) datos.poligono = poligono; }
        $('#z-guardar').disabled = true;
        try {
            const r = await post(seleccion.id === null ? 'zonas/crear.php' : 'zonas/actualizar.php', datos);
            const zid = r.data.id;
            const ids = Array.from(document.querySelectorAll('#z-conductores input:checked')).map((c) => parseInt(c.value, 10));
            await post('zonas/asignar_conductores.php', { zona_id: zid, conductor_ids: ids });
            RP.exito('Zona guardada');
            seleccion = { id: zid };
            await cargar();
            seleccionar(zid);
        } catch (e) {
            if (e && e.campos) RP.marcarErrores(form, e.campos);
        }
        $('#z-guardar').disabled = false;
    });
    $('#btn-nueva').addEventListener('click', nueva);
    $('#btn-cancelar').addEventListener('click', cancelar);

    iniciarMapa();
    cargar();
})();
