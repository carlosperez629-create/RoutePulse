/*
 * M6 - Pantalla gerencia/reportes.php
 * Vista previa del informe (resumen, ranking y zonas), exportación a CSV (kpi/exportar.php)
 * e impresión (CSS @media print en m6.css).
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-reportes');
    if (!raiz) {
        return;
    }

    const { crear, vaciar, llamar } = RP;
    const $ = (x) => document.getElementById(x);
    const num = (v) => crear('td', { class: 'text-end' }, v);
    const mmss = (s) => (s === null ? RP.guion : Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'));

    function parametrosUrl(p) {
        const q = new URLSearchParams();
        Object.entries(p).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined) {
                q.append(k, v);
            }
        });
        return q;
    }

    /** Apunta cada botón «Exportar CSV» al endpoint con los filtros actuales. */
    function actualizarEnlaces(p) {
        document.querySelectorAll('#catalogo a[data-reporte]').forEach((a) => {
            const q = parametrosUrl(p);
            q.set('reporte', a.dataset.reporte);
            a.setAttribute('href', RP.base + '/api/kpi/exportar.php?' + q.toString());
        });
    }

    function filasResumen(r) {
        return [
            ['Despachados', String(r.despachados)],
            ['Entregados', String(r.entregados)],
            ['OTIF', RP.porcentaje(r.otif_pct)],
            ['FTDR', RP.porcentaje(r.ftdr_pct)],
            ['Tiempo de parada', r.dwell_texto === null ? RP.guion : r.dwell_texto],
            ['Incidencias', r.incidencias + ' (' + RP.porcentaje(r.tasa_incidencias_pct) + ')'],
            ['Km reales', RP.numero(r.km_reales, 1)],
            ['Costo operativo', RP.dinero(r.costo_operativo)],
            ['Costo por km', r.costo_por_km === null ? RP.guion : RP.dinero(r.costo_por_km, 'USD', 4)],
            ['Costo por entrega', r.costo_por_entrega === null ? RP.guion : RP.dinero(r.costo_por_entrega)],
            ['Nivel de servicio global', RP.porcentaje(r.nivel_servicio_pct)],
        ];
    }

    function vacio(cuerpo, columnas) {
        cuerpo.append(crear('tr', {}, crear('td', { colspan: String(columnas), class: 'text-center text-muted py-3' }, 'No hay resultados')));
    }

    async function cargar(p) {
        actualizarEnlaces(p);
        const [resumen, conductores, zonas] = await Promise.allSettled([
            llamar('kpi/resumen.php', { params: p }),
            llamar('kpi/ranking_conductores.php', { params: p }),
            llamar('kpi/por_zona.php', { params: p }),
        ]);

        const cResumen = vaciar($('cuerpo-resumen'));
        if (resumen.status === 'fulfilled') {
            const r = resumen.value.data;
            $('vp-periodo').textContent = '(' + r.periodo.desde.split('-').reverse().join('/') + ' al ' + r.periodo.hasta.split('-').reverse().join('/') + ')';
            filasResumen(r).forEach(([k, v]) => cResumen.append(crear('tr', {}, crear('td', {}, k), num(v))));
        } else {
            vacio(cResumen, 2);
        }

        const cCond = vaciar($('cuerpo-conductores'));
        if (conductores.status === 'fulfilled' && (conductores.value.data || []).length) {
            conductores.value.data.forEach((c) => cCond.append(crear('tr', {},
                crear('td', {}, c.nombre), num(String(c.despachados)), num(String(c.entregados)),
                num(RP.porcentaje(c.otif_pct)), num(RP.porcentaje(c.ftdr_pct)), num(mmss(c.dwell_seg)),
                num(RP.numero(c.km_reales, 1)), num(RP.dinero(c.costo_operativo)))));
        } else {
            vacio(cCond, 8);
        }

        const cZonas = vaciar($('cuerpo-zonas'));
        if (zonas.status === 'fulfilled' && (zonas.value.data || []).length) {
            zonas.value.data.forEach((z) => cZonas.append(crear('tr', {},
                crear('td', {}, z.nombre), num(String(z.despachados)), num(String(z.entregados)),
                num(String(z.incidencias)), num(RP.porcentaje(z.tasa_rechazo_pct)), num(RP.porcentaje(z.otif_pct)))));
        } else {
            vacio(cZonas, 6);
        }
    }

    $('btn-imprimir').addEventListener('click', () => window.print());

    const aplicar = RP6.iniciar(cargar);
    RP6.poblarOpciones().then(aplicar);
})();
