/*
 * M6 - Pantalla gerencia/dashboard.php
 * Tarjetas de KPIs, gráficos (Chart.js) y tablas de ranking y zonas, con filtros de fecha, zona,
 * conductor y tipo de vehículo. Los porcentajes ya vienen calculados por el servidor.
 * Endpoints: kpi/resumen.php, serie.php, ranking_conductores.php, por_zona.php.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-dashboard');
    if (!raiz) {
        return;
    }

    const { crear, vaciar, llamar } = RP;
    const $ = (x) => document.getElementById(x);
    const COLOR = { otif: '#0d6efd', ftdr: '#198754', entregas: '#198754', incidencias: '#dc3545' };

    let graficoLineas = null;
    let graficoBarras = null;
    let ordenRanking = 'otif_pct_desc';
    let ultimosParametros = null;

    const texto = (x, valor) => { $(x).textContent = valor; };

    // ------------------------------------------------------------ tarjetas

    function pintarResumen(r) {
        texto('k-otif', RP.porcentaje(r.otif_pct));
        texto('k-ftdr', RP.porcentaje(r.ftdr_pct));
        texto('k-dwell', r.dwell_texto === null ? RP.guion : r.dwell_texto);
        texto('k-entregas', r.entregados + ' / ' + r.despachados);
        texto('k-nivel', 'Nivel de servicio: ' + RP.porcentaje(r.nivel_servicio_pct));
        texto('k-incidencias', String(r.incidencias));
        texto('k-incidencias-tasa', 'Tasa: ' + RP.porcentaje(r.tasa_incidencias_pct));
        texto('k-costo-km', r.costo_por_km === null ? RP.guion : RP.dinero(r.costo_por_km, 'USD', 4));
        texto('k-km', 'Km reales: ' + RP.numero(r.km_reales, 1));
        texto('k-costo-entrega', r.costo_por_entrega === null ? RP.guion : RP.dinero(r.costo_por_entrega));
        texto('k-costo-total', 'Costo operativo: ' + RP.dinero(r.costo_operativo));
    }

    function tarjetasEnError() {
        ['k-otif', 'k-ftdr', 'k-dwell', 'k-entregas', 'k-incidencias', 'k-costo-km', 'k-costo-entrega'].forEach((x) => texto(x, RP.guion));
    }

    // ------------------------------------------------------------ gráficos

    const etiquetaDia = (f) => f.slice(8, 10) + '/' + f.slice(5, 7);

    function pintarGraficos(serie) {
        const etiquetas = serie.map((d) => etiquetaDia(d.fecha));
        if (graficoLineas) {
            graficoLineas.destroy();
        }
        if (graficoBarras) {
            graficoBarras.destroy();
        }
        graficoLineas = new Chart($('grafico-lineas'), {
            type: 'line',
            data: {
                labels: etiquetas,
                datasets: [
                    { label: 'OTIF (%)', data: serie.map((d) => d.otif_pct), borderColor: COLOR.otif, backgroundColor: COLOR.otif, tension: 0.2, spanGaps: false },
                    { label: 'FTDR (%)', data: serie.map((d) => d.ftdr_pct), borderColor: COLOR.ftdr, backgroundColor: COLOR.ftdr, tension: 0.2, spanGaps: false },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { min: 0, max: 100, title: { display: true, text: '%' } } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
        graficoBarras = new Chart($('grafico-barras'), {
            type: 'bar',
            data: {
                labels: etiquetas,
                datasets: [
                    { label: 'Entregados', data: serie.map((d) => d.entregados), backgroundColor: COLOR.entregas },
                    { label: 'Incidencias', data: serie.map((d) => d.incidencias), backgroundColor: COLOR.incidencias },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    // ------------------------------------------------------------ tablas

    const celdaNum = (v) => crear('td', { class: 'text-end' }, v);

    function pintarRanking(filas) {
        const cuerpo = vaciar($('cuerpo-ranking'));
        if (!filas.length) {
            cuerpo.append(crear('tr', {}, crear('td', { colspan: '6', class: 'text-center text-muted py-3' }, 'No hay resultados')));
            return;
        }
        filas.forEach((c) => cuerpo.append(crear('tr', {},
            crear('td', {}, c.nombre),
            celdaNum(String(c.despachados)),
            celdaNum(String(c.entregados)),
            celdaNum(RP.porcentaje(c.otif_pct)),
            celdaNum(RP.porcentaje(c.ftdr_pct)),
            celdaNum(c.dwell_seg === null ? RP.guion : Math.floor(c.dwell_seg / 60) + ':' + String(c.dwell_seg % 60).padStart(2, '0')))));
    }

    function pintarZonas(filas) {
        const cuerpo = vaciar($('cuerpo-zonas'));
        if (!filas.length) {
            cuerpo.append(crear('tr', {}, crear('td', { colspan: '5', class: 'text-center text-muted py-3' }, 'No hay resultados')));
            return;
        }
        filas.forEach((z) => cuerpo.append(crear('tr', {},
            crear('td', {}, z.nombre),
            celdaNum(String(z.despachados)),
            celdaNum(String(z.incidencias)),
            celdaNum(RP.porcentaje(z.tasa_rechazo_pct)),
            celdaNum(RP.porcentaje(z.otif_pct)))));
    }

    function marcarOrdenEnCabecera() {
        document.querySelectorAll('#cab-ranking th[data-orden]').forEach((th) => {
            const [campo, sentido] = [ordenRanking.replace(/_(asc|desc)$/, ''), ordenRanking.endsWith('_asc') ? 'ascending' : 'descending'];
            th.setAttribute('aria-sort', th.dataset.orden === campo ? sentido : 'none');
        });
    }

    // ------------------------------------------------------------ carga

    const sinVacios = (p) => ({ ...p });

    async function cargarRanking() {
        if (!ultimosParametros) {
            return;
        }
        vaciar($('cuerpo-ranking')).append(crear('tr', {}, crear('td', { colspan: '6' }, RP.spinner())));
        try {
            const { data } = await llamar('kpi/ranking_conductores.php', { params: { ...sinVacios(ultimosParametros), orden: ordenRanking } });
            pintarRanking(data || []);
        } catch (e) {
            vaciar($('cuerpo-ranking')).append(crear('tr', {}, crear('td', { colspan: '6', class: 'text-center text-danger py-3' }, 'No se pudo cargar el ranking.')));
        }
        marcarOrdenEnCabecera();
    }

    async function cargarTodo(parametros) {
        ultimosParametros = parametros;
        const [resumen, serie, ranking, zonas] = await Promise.allSettled([
            llamar('kpi/resumen.php', { params: parametros }),
            llamar('kpi/serie.php', { params: parametros }),
            llamar('kpi/ranking_conductores.php', { params: { ...parametros, orden: ordenRanking } }),
            llamar('kpi/por_zona.php', { params: parametros }),
        ]);

        if (resumen.status === 'fulfilled') {
            pintarResumen(resumen.value.data);
        } else {
            tarjetasEnError();
        }
        if (serie.status === 'fulfilled') {
            pintarGraficos(serie.value.data || []);
        }
        if (ranking.status === 'fulfilled') {
            pintarRanking(ranking.value.data || []);
        }
        if (zonas.status === 'fulfilled') {
            pintarZonas(zonas.value.data || []);
        }
        marcarOrdenEnCabecera();
    }

    // Orden por columna (el servidor ordena; aquí solo se cambia el campo y el sentido).
    $('cab-ranking').addEventListener('click', (ev) => {
        const th = ev.target.closest('th[data-orden]');
        if (!th) {
            return;
        }
        const campo = th.dataset.orden;
        const actual = ordenRanking.replace(/_(asc|desc)$/, '');
        const sentido = actual === campo && ordenRanking.endsWith('_desc') ? 'asc' : (campo === 'nombre' && actual !== campo ? 'asc' : 'desc');
        ordenRanking = campo + '_' + sentido;
        cargarRanking();
    });

    const aplicar = RP6.iniciar(cargarTodo);
    RP6.poblarOpciones().then(aplicar);
})();
