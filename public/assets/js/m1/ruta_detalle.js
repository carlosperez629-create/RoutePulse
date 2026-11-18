'use strict';
/** despacho/ruta_detalle.php: mapa, paradas con arrastrar y soltar, resumen y acciones según el estado de la ruta. */
(function () {
    const { el, $, get, post, limpiar, badge } = window.RP;
    const escribe = $('#m1-ruta').dataset.escribe === '1';
    const rutaId = parseInt(RP.param('ruta_id'), 10);
    let ruta = null, mapa = null, capaMapa = null, modoPunto = false, despuesDeAsignar = false;
    const modales = {};

    const abierta = (p) => p.estado === 'pendiente' || p.estado === 'en_camino';

    // ---------------- Mapa ----------------
    function icono(num, clase) {
        return L.divIcon({ className: '', html: el('div', { className: 'rp-marcador-num ' + (clase || ''), text: String(num) }), iconSize: [26, 26], iconAnchor: [13, 13] });
    }
    function dibujarMapa() {
        if (!mapa) {
            mapa = RP.mapa('mapa-ruta', ruta.hub_lat, ruta.hub_lng, 12);
            mapa.on('click', (ev) => {
                if (!modoPunto) return;
                modoPunto = false;
                $('#rc-lat').value = ev.latlng.lat.toFixed(6);
                $('#rc-lng').value = ev.latlng.lng.toFixed(6);
                mapa.getContainer().style.cursor = '';
                modales.recalcular.show();
            });
        }
        if (capaMapa) mapa.removeLayer(capaMapa);
        capaMapa = L.featureGroup().addTo(mapa);
        L.marker([ruta.hub_lat, ruta.hub_lng], { icon: L.divIcon({ className: '', html: el('div', { className: 'rp-marcador-hub', text: ruta.hub_nombre }), iconAnchor: [0, 12] }) }).addTo(capaMapa);
        if (ruta.polyline_sugerida && ruta.polyline_sugerida.length > 1) L.polyline(ruta.polyline_sugerida, { color: '#0d6efd', weight: 4, opacity: 0.7 }).addTo(capaMapa);
        ruta.paradas.forEach((p) => {
            const clase = p.estado === 'omitida' || p.estado === 'entregada' || p.estado === 'fallida' ? 'resuelta' : (p.en_riesgo ? 'riesgo' : '');
            const m = L.marker([p.lat, p.lng], { icon: icono(p.orden, clase) }).addTo(capaMapa);
            m.bindPopup(el('div', {}, el('strong', { text: '#' + p.orden + ' ' + p.paquete.codigo_guia }), el('div', { text: p.paquete.destinatario_nombre }), el('div', { className: 'small', text: p.paquete.direccion_entrega }), el('div', { className: 'small', text: 'ETA: ' + RP.fecha(p.eta) })));
            m.on('click', () => { const fila = document.getElementById('parada-' + p.id); if (fila) fila.scrollIntoView({ block: 'center', behavior: 'smooth' }); });
        });
        if (capaMapa.getBounds().isValid()) mapa.fitBounds(capaMapa.getBounds().pad(0.15));
    }

    // ---------------- Paradas ----------------
    function filaParada(p) {
        const editable = escribe && abierta(p) && ['borrador', 'planificada', 'en_curso'].includes(ruta.estado);
        const fila = el('li', { className: 'list-group-item d-flex gap-2 align-items-start rp-parada' + (editable ? '' : ' rp-parada-fija') + (p.en_riesgo ? ' rp-parada-riesgo' : ''), attrs: { id: 'parada-' + p.id, draggable: editable ? 'true' : 'false' }, dataset: { id: p.id } },
            el('span', { className: 'rp-parada-num estado-' + p.estado, text: String(p.orden) }),
            el('div', { className: 'flex-grow-1 min-w-0' },
                el('div', { className: 'd-flex flex-wrap gap-2 align-items-center' },
                    el('a', { className: 'fw-semibold text-decoration-none', text: p.paquete.codigo_guia, attrs: { href: '../hub/paquete_detalle.php?id=' + p.paquete_id } }),
                    badge(p.estado, RP.ESTADOS_PARADA),
                    p.paquete.prioridad === 'urgente' ? el('span', { className: 'badge text-bg-danger', text: 'URGENTE' }) : null,
                    p.en_riesgo ? el('span', { className: 'badge text-bg-warning', text: 'En riesgo de horario' }) : null),
                el('div', { text: p.paquete.destinatario_nombre }),
                el('div', { className: 'small text-muted', text: p.paquete.direccion_entrega }),
                el('div', { className: 'small', text: 'ETA ' + RP.fecha(p.eta) + (p.paquete.ventana_fin ? ' · ventana hasta ' + RP.fecha(p.paquete.ventana_fin) : '') + (p.llegada_real ? ' · llegó ' + RP.fecha(p.llegada_real) : '') })));
        if (escribe && abierta(p)) {
            const botones = el('div', { className: 'd-flex flex-column gap-1' });
            if (['borrador', 'planificada'].includes(ruta.estado)) botones.append(el('button', { className: 'btn btn-sm btn-outline-danger', text: 'Quitar', attrs: { type: 'button' }, on: { click: () => quitar(p) } }));
            if (ruta.estado === 'en_curso') botones.append(el('button', { className: 'btn btn-sm btn-outline-warning', text: 'Omitir', attrs: { type: 'button' }, on: { click: () => omitir(p) } }));
            fila.append(botones);
        }
        if (editable) conectarArrastre(fila);
        return fila;
    }

    let arrastrada = null;
    function conectarArrastre(fila) {
        fila.addEventListener('dragstart', (ev) => { arrastrada = fila; fila.classList.add('rp-arrastrando'); ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', fila.dataset.id); });
        fila.addEventListener('dragend', () => { fila.classList.remove('rp-arrastrando'); document.querySelectorAll('.rp-sobre').forEach((n) => n.classList.remove('rp-sobre')); arrastrada = null; });
        fila.addEventListener('dragover', (ev) => { if (arrastrada && fila.draggable && fila !== arrastrada) { ev.preventDefault(); fila.classList.add('rp-sobre'); } });
        fila.addEventListener('dragleave', () => fila.classList.remove('rp-sobre'));
        fila.addEventListener('drop', async (ev) => {
            ev.preventDefault();
            fila.classList.remove('rp-sobre');
            if (!arrastrada || arrastrada === fila) return;
            const ids = Array.from(document.querySelectorAll('#lista-paradas > li[draggable=true]')).map((n) => parseInt(n.dataset.id, 10));
            const desde = ids.indexOf(parseInt(arrastrada.dataset.id, 10));
            const hasta = ids.indexOf(parseInt(fila.dataset.id, 10));
            ids.splice(hasta, 0, ids.splice(desde, 1)[0]);
            await accion('rutas/reordenar.php', { ruta_id: rutaId, orden: ids }, 'Orden actualizado');
        });
    }

    // ---------------- Pintado ----------------
    function pintar() {
        $('#r-codigo').textContent = ruta.codigo;
        limpiar($('#r-estado')).append(badge(ruta.estado, RP.ESTADOS_RUTA));
        document.title = ruta.codigo + ' · RoutePulse';

        const acc = limpiar($('#acciones'));
        acc.append(el('a', { className: 'btn btn-outline-secondary btn-sm', text: 'Volver', attrs: { href: 'rutas.php' } }));
        const boton = (texto, icono, clase, fn) => acc.append(el('button', { className: 'btn btn-sm ' + clase, attrs: { type: 'button' }, on: { click: fn } }, el('i', { className: 'bi ' + icono + ' me-1' }), texto));
        if (escribe) {
            if (ruta.estado === 'borrador') boton('Confirmar ruta', 'bi-check2-circle', 'btn-success', confirmar);
            if (['borrador', 'planificada'].includes(ruta.estado)) boton('Reasignar', 'bi-person-gear', 'btn-outline-primary', () => abrirReasignar(false));
            if (['borrador', 'planificada', 'en_curso'].includes(ruta.estado)) boton('Agregar paquete', 'bi-plus-circle', 'btn-outline-primary', abrirAgregar);
            if (['planificada', 'en_curso'].includes(ruta.estado)) boton('Recalcular', 'bi-arrow-repeat', 'btn-outline-warning', () => { $('#rc-motivo').value = ''; $('#rc-evitar').checked = false; $('#rc-evitar-campos').classList.add('d-none'); modales.recalcular.show(); });
            if (['borrador', 'planificada'].includes(ruta.estado)) boton('Cancelar ruta', 'bi-x-circle', 'btn-outline-danger', cancelar);
        }

        const r = ruta.resumen;
        const riesgo = ruta.paradas.filter((p) => p.en_riesgo).length;
        const pctKg = r.capacidad_kg ? Math.min(100, Math.round((r.carga_kg / r.capacidad_kg) * 100)) : 0;
        const tarjeta = (titulo, valor, extra) => el('div', { className: 'col-6 col-md-3' }, el('div', { className: 'card h-100' }, el('div', { className: 'card-body py-2' },
            el('div', { className: 'small text-muted', text: titulo }), el('div', { className: 'fs-5 fw-semibold', text: valor }), extra || null)));
        const c = limpiar($('#contenido'));
        c.append(
            el('div', { className: 'row g-2 mb-3' },
                tarjeta('Paquetes', r.resueltas + ' / ' + r.total_paquetes + ' resueltos'),
                tarjeta('Carga', r.carga_kg + ' kg' + (r.capacidad_kg ? ' de ' + r.capacidad_kg : ''), r.capacidad_kg ? el('div', { className: 'progress', attrs: { style: 'height:6px' } }, el('div', { className: 'progress-bar ' + (pctKg > 90 ? 'bg-danger' : ''), attrs: { style: 'width:' + pctKg + '%' } })) : null),
                tarjeta('Distancia / duración', RP.km(r.distancia_km_estimada) + ' · ' + RP.duracion(r.duracion_min_estimada)),
                tarjeta('Paradas en riesgo', String(riesgo), riesgo ? el('div', { className: 'small text-danger', text: 'Llegan después de su ventana' }) : null)),
            el('div', { className: 'row g-3' },
                el('div', { className: 'col-xl-7' }, el('div', { className: 'card' }, el('div', { className: 'card-body p-2' }, el('div', { id: 'mapa-ruta', className: 'rp-mapa-grande' })))),
                el('div', { className: 'col-xl-5' },
                    el('div', { className: 'card mb-3' }, el('div', { className: 'card-body small' },
                        el('div', {}, el('strong', { text: 'Fecha: ' }), RP.soloFecha(ruta.fecha)),
                        el('div', {}, el('strong', { text: 'HUB: ' }), ruta.hub_nombre),
                        el('div', {}, el('strong', { text: 'Zona: ' }), ruta.zona_nombre || 'Mixta'),
                        el('div', {}, el('strong', { text: 'Conductor: ' }), ruta.conductor ? ruta.conductor.nombre + (ruta.conductor.telefono ? ' · ' + ruta.conductor.telefono : '') : 'Sin asignar'),
                        el('div', {}, el('strong', { text: 'Vehículo: ' }), ruta.vehiculo ? ruta.vehiculo.placa + ' · ' + (RP.TIPOS_VEHICULO[ruta.vehiculo.tipo] || '') : 'Sin asignar'),
                        ruta.inicio_real ? el('div', {}, el('strong', { text: 'Inicio: ' }), RP.fecha(ruta.inicio_real)) : null,
                        ruta.fin_real ? el('div', {}, el('strong', { text: 'Fin: ' }), RP.fecha(ruta.fin_real) + (ruta.distancia_km_real !== null ? ' · ' + RP.km(ruta.distancia_km_real) + ' recorridos' : '')) : null)),
                    el('div', { className: 'card' },
                        el('div', { className: 'card-header fw-semibold d-flex justify-content-between' }, el('span', { text: 'Paradas' }), escribe && ['borrador', 'planificada', 'en_curso'].includes(ruta.estado) ? el('span', { className: 'small text-muted fw-normal', text: 'Arrastra para reordenar' }) : null),
                        el('ul', { className: 'list-group list-group-flush', attrs: { id: 'lista-paradas' } }, ruta.paradas.length ? ruta.paradas.map(filaParada) : el('li', { className: 'list-group-item text-muted', text: 'La ruta no tiene paradas.' }))))));
        dibujarMapa();
    }

    async function cargar(silencioso) {
        try {
            const r = await get('rutas/ver.php', { ruta_id: rutaId });
            ruta = r.data;
            mapa = null; // el contenedor se recrea en cada pintado
            pintar();
        } catch (e) {
            if (!silencioso) limpiar($('#contenido')).append(el('div', { className: 'alert alert-danger', text: 'No encontramos lo que buscas.' }));
        }
    }

    async function accion(ruta_api, datos, mensaje) {
        try {
            const r = await post(ruta_api, datos);
            if (mensaje) RP.exito(mensaje);
            await cargar();
            return r;
        } catch (e) { await cargar(true); return null; }
    }

    // ---------------- Acciones ----------------
    async function confirmar() {
        if (!ruta.conductor_id || !ruta.vehiculo_id) { abrirReasignar(true); return; }
        const ok = await RP.confirmar('Confirmar ruta', 'Los ' + ruta.resumen.total_paquetes + ' paquetes pasarán a «Asignado a ruta». ¿Confirmar ' + ruta.codigo + '?', 'Confirmar', false);
        if (ok) await accion('rutas/confirmar.php', { ruta_id: rutaId }, 'Ruta confirmada');
    }

    async function cancelar() {
        const v = await RP.formularioModal('Cancelar ruta ' + ruta.codigo, [{ nombre: 'motivo', etiqueta: 'Motivo (opcional)', tipo: 'textarea', max: 255 }], 'Cancelar ruta');
        if (!v) return;
        const ok = await RP.confirmar('Cancelar ruta', 'Los paquetes asignados volverán a «En clasificación». ¿Cancelar ' + ruta.codigo + '?', 'Sí, cancelar');
        if (ok) await accion('rutas/cancelar.php', { ruta_id: rutaId, motivo: v.motivo || null }, 'Ruta cancelada');
    }

    async function quitar(p) {
        const ok = await RP.confirmar('Quitar paquete', '¿Quitar ' + p.paquete.codigo_guia + ' de la ruta? Volverá a «En clasificación».', 'Quitar');
        if (ok) await accion('rutas/quitar_paquete.php', { ruta_id: rutaId, paquete_id: p.paquete_id }, 'Paquete quitado');
    }

    async function omitir(p) {
        const v = await RP.formularioModal('Omitir parada de ' + p.paquete.codigo_guia, [{ nombre: 'motivo', etiqueta: 'Motivo', tipo: 'textarea', max: 255, ayuda: 'Se registra una incidencia «Otro» sin sumar intento.' }], 'Omitir parada');
        if (!v) return;
        await accion('rutas/omitir_parada.php', { ruta_id: rutaId, parada_id: p.id, motivo: v.motivo || null }, 'Parada omitida y ruta recalculada');
    }

    // ---------------- Reasignar / asignar y confirmar ----------------
    async function abrirReasignar(yConfirmar) {
        despuesDeAsignar = yConfirmar;
        $('#modal-reasignar-titulo').textContent = yConfirmar ? 'Asignar conductor y confirmar' : 'Reasignar ruta';
        $('#a-enviar').textContent = yConfirmar ? 'Asignar y confirmar' : 'Guardar';
        const selC = limpiar($('#a-conductor')), selV = limpiar($('#a-vehiculo'));
        selC.append(el('option', { text: 'Selecciona…', attrs: { value: '' } }));
        selV.append(el('option', { text: 'Selecciona…', attrs: { value: '' } }));
        try {
            const cs = (await get('conductores/listar.php', { disponibles_para_fecha: ruta.fecha, por_pagina: 100 })).data;
            if (ruta.conductor && !cs.some((c) => c.id === ruta.conductor_id)) cs.unshift({ id: ruta.conductor_id, usuario: { nombre: ruta.conductor.nombre }, vehiculo_id: ruta.vehiculo_id });
            cs.forEach((c) => selC.append(el('option', { text: c.usuario.nombre, attrs: { value: c.id }, dataset: { vehiculo: c.vehiculo_id || '' } })));
            (await get('vehiculos/listar.php', { activo: 1, por_pagina: 100 })).data.forEach((v) => selV.append(el('option', { text: v.placa + ' · ' + (RP.TIPOS_VEHICULO[v.tipo] || v.tipo) + ' (' + v.capacidad_kg + ' kg)', attrs: { value: v.id } })));
        } catch (e) { /* api() ya mostró el error */ }
        selC.value = ruta.conductor_id || '';
        selV.value = ruta.vehiculo_id || '';
        modales.reasignar.show();
    }

    // ---------------- Agregar paquete ----------------
    let candidatoElegido = null;
    async function abrirAgregar() {
        candidatoElegido = null;
        $('#ag-enviar').disabled = true;
        $('#ag-pos').value = '';
        const cuerpo = limpiar($('#ag-lista'));
        cuerpo.append(RP.filaVacia(5, 'Cargando…'));
        modales.agregar.show();
        try {
            const r = await get('paquetes/listar.php', { estado: 'en_clasificacion', hub_id: ruta.hub_id, por_pagina: 100 });
            const enRuta = new Set(ruta.paradas.filter((p) => p.estado !== 'omitida').map((p) => p.paquete_id));
            const candidatos = r.data.filter((p) => p.lat !== null && p.lng !== null && !enRuta.has(p.id));
            limpiar(cuerpo);
            if (!candidatos.length) cuerpo.append(RP.filaVacia(5, 'No hay paquetes disponibles para agregar.'));
            candidatos.forEach((p) => {
                const radio = el('input', { className: 'form-check-input', attrs: { type: 'radio', name: 'ag-paquete', value: p.id, 'aria-label': 'Elegir ' + p.codigo_guia } });
                radio.addEventListener('change', () => { candidatoElegido = p.id; $('#ag-enviar').disabled = false; });
                cuerpo.append(el('tr', {}, el('td', {}, radio), el('td', { className: 'fw-semibold', text: p.codigo_guia }), el('td', { className: 'small', text: p.destinatario_nombre + ' · ' + p.direccion_entrega }),
                    el('td', { text: p.prioridad === 'urgente' ? 'Urgente' : 'Normal' }), el('td', { text: p.peso_kg + ' kg' })));
            });
        } catch (e) { limpiar(cuerpo).append(RP.filaVacia(5, 'No se pudo cargar la lista.')); }
    }

    if (escribe) {
        modales.reasignar = new bootstrap.Modal($('#modal-reasignar'));
        modales.agregar = new bootstrap.Modal($('#modal-agregar'));
        modales.recalcular = new bootstrap.Modal($('#modal-recalcular'));

        $('#a-conductor').addEventListener('change', () => {
            const op = $('#a-conductor').selectedOptions[0];
            if (op && op.dataset.vehiculo) $('#a-vehiculo').value = op.dataset.vehiculo;
        });
        $('#form-reasignar').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const datos = { ruta_id: rutaId };
            if ($('#a-conductor').value) datos.conductor_id = parseInt($('#a-conductor').value, 10);
            if ($('#a-vehiculo').value) datos.vehiculo_id = parseInt($('#a-vehiculo').value, 10);
            if (!datos.conductor_id && !datos.vehiculo_id) { RP.advertencia('Elige un conductor o un vehículo.'); return; }
            $('#a-enviar').disabled = true;
            if (despuesDeAsignar) {
                const r = await accion('rutas/confirmar.php', datos, 'Ruta confirmada');
                if (r) modales.reasignar.hide();
            } else {
                const r = await accion('rutas/reasignar.php', datos, 'Ruta reasignada');
                if (r) modales.reasignar.hide();
            }
            $('#a-enviar').disabled = false;
        });

        $('#form-agregar').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (!candidatoElegido) return;
            const datos = { ruta_id: rutaId, paquete_id: candidatoElegido };
            if ($('#ag-pos').value) datos.posicion = parseInt($('#ag-pos').value, 10);
            $('#ag-enviar').disabled = true;
            const r = await accion('rutas/agregar_paquete.php', datos, 'Paquete agregado');
            if (r) modales.agregar.hide(); else $('#ag-enviar').disabled = false;
        });

        $('#rc-evitar').addEventListener('change', () => $('#rc-evitar-campos').classList.toggle('d-none', !$('#rc-evitar').checked));
        $('#rc-elegir').addEventListener('click', () => {
            modales.recalcular.hide();
            modoPunto = true;
            mapa.getContainer().style.cursor = 'crosshair';
            RP.aviso('Haz clic en el mapa para elegir el punto a evitar.', 'warning');
        });
        $('#form-recalcular').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const motivo = $('#rc-motivo').value.trim();
            if (!motivo) { RP.marcarErrores($('#form-recalcular'), { motivo: 'El motivo es obligatorio.' }); return; }
            const datos = { ruta_id: rutaId, motivo };
            if ($('#rc-evitar').checked && $('#rc-lat').value && $('#rc-lng').value) {
                datos.evitar = [{ lat: parseFloat($('#rc-lat').value), lng: parseFloat($('#rc-lng').value), radio_m: parseFloat($('#rc-radio').value) || 500 }];
            }
            $('#rc-enviar').disabled = true;
            try {
                const r = await post('rutas/recalcular.php', datos);
                modales.recalcular.hide();
                await cargar();
                const n = (r.data.cambios || []).length;
                limpiar($('#mensaje')).append(el('div', { className: 'alert alert-info alert-dismissible' },
                    n ? 'Ruta recalculada. Cambió la posición de ' + n + ' parada(s): ' + r.data.cambios.map((c) => c.codigo_guia + ' (' + c.orden_anterior + '→' + c.orden_nuevo + ')').join(', ') : 'Ruta recalculada: el orden actual ya era el mejor.',
                    el('button', { className: 'btn-close', attrs: { type: 'button', 'data-bs-dismiss': 'alert', 'aria-label': 'Cerrar' } })));
            } catch (e) { /* api() ya mostró el error */ }
            $('#rc-enviar').disabled = false;
        });
    }

    if (!rutaId) {
        limpiar($('#contenido')).append(el('div', { className: 'alert alert-warning', text: 'Falta indicar la ruta.' }));
        return;
    }
    cargar();
    // Mientras la ruta va en curso, se refresca sola cada 30 s (si no hay ventanas abiertas).
    setInterval(() => { if (ruta && ruta.estado === 'en_curso' && !document.querySelector('.modal.show') && !modoPunto) cargar(true); }, 30000);
})();
