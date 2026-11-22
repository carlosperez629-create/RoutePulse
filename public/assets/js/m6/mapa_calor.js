/*
 * M6 - Pantalla gerencia/mapa_calor.php
 * Mapa de calor (Leaflet + Leaflet.heat) de entregas o incidencias, con filtros de fecha, zona y conductor.
 * Endpoint: kpi/mapa_calor.php?tipo=entregas|incidencias → [[lat, lng, peso], ...]
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-mapa-calor');
    if (!raiz) {
        return;
    }

    const { crear, llamar } = RP;
    const lat = parseFloat(raiz.dataset.centroLat);
    const lng = parseFloat(raiz.dataset.centroLng);
    const centro = Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : [0, 0];
    const zoomInicial = Number.isFinite(lat) && Number.isFinite(lng) ? 13 : 2;

    const mapa = L.map('mapa-calor').setView(centro, zoomInicial);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; Colaboradores de OpenStreetMap',
    }).addTo(mapa);

    // Leyenda de intensidad.
    const leyenda = L.control({ position: 'bottomright' });
    leyenda.onAdd = () => {
        const div = crear('div', { class: 'leyenda-calor' },
            crear('strong', {}, 'Intensidad'),
            crear('div', { class: 'barra' }),
            crear('div', { class: 'extremos' }, crear('span', {}, 'Baja'), crear('span', {}, 'Alta')));
        return div;
    };
    leyenda.addTo(mapa);

    let capa = null;

    function tipoActual() {
        return document.querySelector('input[name="tipo"]:checked').value;
    }

    function pintar(puntos, tipo) {
        if (capa) {
            mapa.removeLayer(capa);
            capa = null;
        }
        const resumen = document.getElementById('resumen-mapa');
        const nombre = tipo === 'entregas' ? 'entregas' : 'incidencias';
        if (!puntos.length) {
            resumen.textContent = 'Sin ' + nombre + ' en el periodo y filtros elegidos.';
            return;
        }
        const total = puntos.reduce((s, p) => s + p[2], 0);
        const maximo = Math.max(...puntos.map((p) => p[2]));
        resumen.textContent = total + ' ' + nombre + ' en ' + puntos.length + ' ubicaciones. Cada punto agrupa los eventos a unos 11 m.';
        capa = L.heatLayer(puntos, { radius: 28, blur: 18, maxZoom: 17, max: maximo }).addTo(mapa);
        mapa.fitBounds(L.latLngBounds(puntos.map((p) => [p[0], p[1]])).pad(0.3), { maxZoom: 16 });
    }

    async function cargar(parametros) {
        const tipo = tipoActual();
        const { desde, hasta, zona_id, conductor_id } = parametros;
        try {
            const { data } = await llamar('kpi/mapa_calor.php', { params: { tipo, desde, hasta, zona_id, conductor_id } });
            pintar(data || [], tipo);
        } catch (e) {
            document.getElementById('resumen-mapa').textContent = 'No se pudo cargar el mapa.';
        }
    }

    const aplicar = RP6.iniciar(cargar);
    // Cambiar entre entregas e incidencias recarga de inmediato.
    document.querySelectorAll('input[name="tipo"]').forEach((r) => r.addEventListener('change', aplicar));
    RP6.poblarOpciones().then(aplicar);
})();
