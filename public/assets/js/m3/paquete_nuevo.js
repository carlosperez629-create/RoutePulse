'use strict';
/** hub/paquete_nuevo.php: crea un paquete, o lo edita si llega ?id=. Incluye "Ubicar en mapa" con marcador arrastrable. */
(function () {
    const { el, $, get, post, limpiar } = window.RP;
    const form = $('#form-paquete');
    const editId = RP.param('id');
    let mapa = null, marcador = null, hubCentro = [4.6097, -74.0817];
    let original = null; // datos al abrir en modo edición (para saber si cambió la dirección)

    // --- Fechas: los datetime-local se envían como ISO UTC ---
    const aIso = (v) => (v ? new Date(v).toISOString().replace('.000Z', 'Z') : null);
    const deIso = (iso) => {
        if (!iso) return '';
        const d = new Date(iso);
        const p = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    };

    // --- Mapa ---
    function iniciarMapa() {
        mapa = RP.mapa('mapa', hubCentro[0], hubCentro[1], 13);
        mapa.on('click', (ev) => ponerMarcador(ev.latlng.lat, ev.latlng.lng, true));
    }
    function ponerMarcador(lat, lng, resolverZona) {
        if (!marcador) {
            marcador = L.marker([lat, lng], { draggable: true }).addTo(mapa);
            marcador.on('dragend', () => {
                const p = marcador.getLatLng();
                $('#lat').value = p.lat.toFixed(7);
                $('#lng').value = p.lng.toFixed(7);
                resolverZonaActual();
            });
        } else {
            marcador.setLatLng([lat, lng]);
        }
        $('#lat').value = Number(lat).toFixed(7);
        $('#lng').value = Number(lng).toFixed(7);
        mapa.setView([lat, lng], Math.max(mapa.getZoom(), 15));
        if (resolverZona) resolverZonaActual();
    }
    async function resolverZonaActual() {
        const lat = parseFloat($('#lat').value), lng = parseFloat($('#lng').value);
        const badge = $('#zona-resuelta');
        if (isNaN(lat) || isNaN(lng)) { badge.className = 'badge text-bg-secondary'; badge.textContent = 'Zona: sin resolver'; return; }
        try {
            const r = await get('zonas/resolver.php', { lat, lng, codigo_postal: $('#codigo_postal').value.trim() });
            if (r.data && r.data.nombre) { badge.className = 'badge text-bg-success'; badge.textContent = 'Zona: ' + r.data.nombre; }
            else { badge.className = 'badge text-bg-warning'; badge.textContent = 'Zona: sin zona'; }
        } catch (e) { badge.className = 'badge text-bg-secondary'; badge.textContent = 'Zona: no disponible'; }
    }

    $('#btn-ubicar').addEventListener('click', async () => {
        const direccion = $('#direccion_entrega').value.trim();
        if (!direccion) { RP.marcarErrores(form, { direccion_entrega: 'Escribe la dirección para ubicarla.' }); return; }
        const boton = $('#btn-ubicar');
        boton.disabled = true;
        try {
            const r = await get('paquetes/geocodificar.php', { direccion, ciudad: $('#ciudad').value.trim(), codigo_postal: $('#codigo_postal').value.trim() });
            if (r.data.lat === null) {
                RP.advertencia('No se pudo ubicar la dirección. Marca el punto en el mapa con un clic.');
            } else {
                ponerMarcador(r.data.lat, r.data.lng, false);
                const badge = $('#zona-resuelta');
                badge.className = r.data.zona_nombre ? 'badge text-bg-success' : 'badge text-bg-warning';
                badge.textContent = 'Zona: ' + (r.data.zona_nombre || 'sin zona');
            }
        } catch (e) { /* api() ya mostró el error */ }
        boton.disabled = false;
    });
    ['lat', 'lng'].forEach((id) => $('#' + id).addEventListener('change', () => {
        const lat = parseFloat($('#lat').value), lng = parseFloat($('#lng').value);
        if (!isNaN(lat) && !isNaN(lng)) ponerMarcador(lat, lng, true);
    }));

    // --- Catálogo de HUBs ---
    async function cargarHubs() {
        try {
            const r = await get('hubs/listar.php', { activo: 1 });
            r.data.forEach((h) => $('#hub_id').append(el('option', { text: h.nombre, attrs: { value: h.id } })));
            if (r.data.length) hubCentro = [r.data[0].lat, r.data[0].lng];
        } catch (e) { /* sin HUBs: el formulario sigue funcionando */ }
    }

    // --- Datos del formulario ---
    function datos() {
        const v = (id) => $('#' + id).value.trim();
        const d = {
            remitente_nombre: v('remitente_nombre'), remitente_telefono: v('remitente_telefono'),
            destinatario_nombre: v('destinatario_nombre'), destinatario_telefono: v('destinatario_telefono'),
            destinatario_email: v('destinatario_email'), direccion_entrega: v('direccion_entrega'), referencia: v('referencia'),
            ciudad: v('ciudad'), codigo_postal: v('codigo_postal'), peso_kg: v('peso_kg'), volumen_m3: v('volumen_m3'),
            cobro_contra_entrega: v('cobro_contra_entrega'), moneda: v('moneda').toUpperCase(), prioridad: v('prioridad'),
            ventana_inicio: aIso(v('ventana_inicio')), ventana_fin: aIso(v('ventana_fin')), hub_id: v('hub_id') || null, notas: v('notas'),
        };
        let enviarCoords = v('lat') !== '' && v('lng') !== '';
        if (editId && original && enviarCoords) {
            // Si cambió la dirección y no movieron el marcador, no se envían las coordenadas viejas: el servidor re-geocodifica.
            const cambioDireccion = v('direccion_entrega') !== original.direccion || v('ciudad') !== original.ciudad;
            const mismoPunto = Number(v('lat')) === Number(original.lat) && Number(v('lng')) === Number(original.lng);
            if (cambioDireccion && mismoPunto) enviarCoords = false;
        }
        if (enviarCoords) { d.lat = v('lat'); d.lng = v('lng'); }
        Object.keys(d).forEach((k) => { if (d[k] === '') d[k] = null; });
        if (editId) {
            // Al editar, los campos vacíos se envían como null para poder limpiarlos.
            d.paquete_id = parseInt(editId, 10);
        }
        return d;
    }

    function mostrarAdvertencias(adv) {
        const textos = { sin_coordenadas: 'No se pudo ubicar la dirección: corrígela con el mapa para poder rutearlo.', sin_zona: 'El punto no pertenece a ninguna zona de cobertura.' };
        return (adv || []).map((a) => textos[a] || a);
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        RP.marcarErrores(form, {});
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const boton = $('#btn-guardar');
        boton.disabled = true;
        try {
            const r = await post(editId ? 'paquetes/actualizar.php' : 'paquetes/crear.php', datos());
            const p = r.data;
            RP.exito(editId ? 'Paquete actualizado' : 'Paquete creado');
            const salida = limpiar($('#resultado'));
            const adv = mostrarAdvertencias(p.advertencias);
            salida.append(el('div', { className: 'alert alert-success' },
                el('div', { className: 'fw-semibold', text: (editId ? 'Cambios guardados. Guía ' : 'Paquete creado. Guía ') + p.codigo_guia }),
                adv.length ? el('ul', { className: 'mb-2 mt-1 small' }, adv.map((t) => el('li', { text: t }))) : null,
                el('div', { className: 'd-flex flex-wrap gap-2 mt-2' },
                    el('a', { className: 'btn btn-sm btn-primary', text: 'Ver detalle', attrs: { href: 'paquete_detalle.php?id=' + p.id } }),
                    el('a', { className: 'btn btn-sm btn-outline-dark', text: 'Imprimir etiqueta', attrs: { href: 'etiqueta.php?ids=' + p.id, target: '_blank' } }),
                    editId ? null : el('button', { className: 'btn btn-sm btn-outline-secondary', text: 'Crear otro', attrs: { type: 'button' }, on: { click: () => window.location.reload() } }))));
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (!editId) boton.disabled = true; else boton.disabled = false;
            return;
        } catch (e) {
            if (e && e.campos) RP.marcarErrores(form, e.campos);
        }
        boton.disabled = false;
    });

    // --- Modo edición ---
    async function cargarEdicion() {
        $('#titulo-pantalla').textContent = 'Editar paquete';
        $('#texto-guardar').textContent = 'Guardar cambios';
        try {
            const r = await get('paquetes/ver.php', { id: editId });
            const p = r.data;
            ['remitente_nombre', 'remitente_telefono', 'destinatario_nombre', 'destinatario_telefono', 'destinatario_email', 'direccion_entrega',
                'referencia', 'ciudad', 'codigo_postal', 'peso_kg', 'volumen_m3', 'cobro_contra_entrega', 'moneda', 'prioridad', 'notas']
                .forEach((c) => { $('#' + c).value = p[c] === null || p[c] === undefined ? '' : p[c]; });
            $('#ventana_inicio').value = deIso(p.ventana_inicio);
            $('#ventana_fin').value = deIso(p.ventana_fin);
            $('#hub_id').value = p.hub_id || '';
            if (!['creado', 'ingresado_hub', 'en_clasificacion'].includes(p.estado)) {
                limpiar($('#resultado')).append(el('div', { className: 'alert alert-warning', text: 'Este paquete está «' + RP.ESTADOS_PAQUETE[p.estado] + '» y ya no se puede editar. Quítalo de la ruta o cancélalo.' }));
                $('#btn-guardar').disabled = true;
            }
            original = { direccion: p.direccion_entrega, ciudad: p.ciudad || '', lat: p.lat, lng: p.lng };
            if (p.lat !== null && p.lng !== null) { ponerMarcador(p.lat, p.lng, true); }
        } catch (e) {
            limpiar($('#resultado')).append(el('div', { className: 'alert alert-danger', text: 'No encontramos lo que buscas.' }));
            $('#btn-guardar').disabled = true;
        }
    }

    (async function init() {
        await cargarHubs();
        iniciarMapa();
        if (editId) await cargarEdicion();
    })();
})();
