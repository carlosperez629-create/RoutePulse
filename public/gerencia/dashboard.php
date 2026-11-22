<?php
/**
 * M6 - Dashboard de analítica (roles: admin, gerente). Pantalla de Karoline.
 * KPIs, gráficos y tablas se cargan por AJAX desde assets/js/m6/dashboard.js (endpoints kpi/*).
 */
require __DIR__ . '/../../app/core/bootstrap.php';

auth_requerir_pagina(['admin', 'gerente']);
render_header('Dashboard', 'm6');

// Rango por defecto: últimos 7 días (hoy incluido) en la zona horaria de la interfaz.
$hasta = kpi_hoy_local();
$desde = (new DateTimeImmutable($hasta))->modify('-6 days')->format('Y-m-d');
$base = rtrim((string) app_cfg('app.url', ''), '/');
?>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/m6.css">
<div class="container-fluid py-3" id="pantalla-dashboard">
    <h1 class="h3 mb-3">Dashboard de operaciones</h1>

    <form class="row g-2 align-items-end mb-3 filtros-m6" id="filtros" autocomplete="off">
        <div class="col-6 col-md-2">
            <label for="f-desde" class="form-label small mb-1">Desde</label>
            <input type="date" class="form-control" id="f-desde" value="<?= e($desde) ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-hasta" class="form-label small mb-1">Hasta</label>
            <input type="date" class="form-control" id="f-hasta" value="<?= e($hasta) ?>" required>
        </div>
        <div class="col-12 col-md-2">
            <label for="f-zona" class="form-label small mb-1">Zona</label>
            <select class="form-select" id="f-zona"><option value="">Todas</option></select>
        </div>
        <div class="col-12 col-md-3">
            <label for="f-conductor" class="form-label small mb-1">Conductor</label>
            <select class="form-select" id="f-conductor"><option value="">Todos</option></select>
        </div>
        <div class="col-12 col-md-2">
            <label for="f-vehiculo" class="form-label small mb-1">Tipo de vehículo</label>
            <select class="form-select" id="f-vehiculo">
                <option value="">Todos</option>
                <option value="furgon">Furgón</option>
                <option value="motocicleta">Motocicleta</option>
                <option value="vehiculo_electrico">Vehículo eléctrico</option>
                <option value="bicicleta">Bicicleta</option>
            </select>
        </div>
        <div class="col-12 col-md-1 d-grid">
            <button type="submit" class="btn btn-primary" id="btn-aplicar">Aplicar</button>
        </div>
        <div class="col-12"><div class="text-danger small" id="error-filtros" role="alert"></div></div>
    </form>

    <div class="row g-3 mb-3" id="tarjetas">
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">OTIF</div><div class="fs-3 fw-semibold" id="k-otif">—</div>
            <div class="small text-muted">A tiempo y completas</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">FTDR</div><div class="fs-3 fw-semibold" id="k-ftdr">—</div>
            <div class="small text-muted">Entrega al primer intento</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">Tiempo de parada</div><div class="fs-3 fw-semibold" id="k-dwell">—</div>
            <div class="small text-muted">Promedio (mm:ss)</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">Entregados / Despachados</div><div class="fs-3 fw-semibold" id="k-entregas">—</div>
            <div class="small text-muted" id="k-nivel">Nivel de servicio: —</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">Incidencias</div><div class="fs-3 fw-semibold" id="k-incidencias">—</div>
            <div class="small text-muted" id="k-incidencias-tasa">Tasa: —</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">Costo por km</div><div class="fs-3 fw-semibold" id="k-costo-km">—</div>
            <div class="small text-muted" id="k-km">Km reales: —</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100 tarjeta-kpi"><div class="card-body">
            <div class="small text-muted">Costo por entrega</div><div class="fs-3 fw-semibold" id="k-costo-entrega">—</div>
            <div class="small text-muted" id="k-costo-total">Costo operativo: —</div></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
            <h2 class="h6">OTIF y FTDR por día (%)</h2>
            <div class="grafico-m6"><canvas id="grafico-lineas" role="img" aria-label="Gráfico de líneas de OTIF y FTDR por día"></canvas></div>
        </div></div></div>
        <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
            <h2 class="h6">Entregas e incidencias por día</h2>
            <div class="grafico-m6"><canvas id="grafico-barras" role="img" aria-label="Gráfico de barras de entregas e incidencias por día"></canvas></div>
        </div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-7"><div class="card h-100"><div class="card-body">
            <h2 class="h6">Ranking de conductores</h2>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr id="cab-ranking">
                    <th scope="col" data-orden="nombre">Conductor</th>
                    <th scope="col" class="text-end" data-orden="despachados">Despachados</th>
                    <th scope="col" class="text-end" data-orden="entregados">Entregados</th>
                    <th scope="col" class="text-end" data-orden="otif_pct">OTIF</th>
                    <th scope="col" class="text-end" data-orden="ftdr_pct">FTDR</th>
                    <th scope="col" class="text-end" data-orden="dwell_seg">T. parada</th>
                </tr></thead>
                <tbody id="cuerpo-ranking"></tbody>
            </table></div>
        </div></div></div>
        <div class="col-12 col-xl-5"><div class="card h-100"><div class="card-body">
            <h2 class="h6">Zonas y tasa de rechazo</h2>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th scope="col">Zona</th>
                    <th scope="col" class="text-end">Despachados</th>
                    <th scope="col" class="text-end">Incidencias</th>
                    <th scope="col" class="text-end">Rechazo</th>
                    <th scope="col" class="text-end">OTIF</th>
                </tr></thead>
                <tbody id="cuerpo-zonas"></tbody>
            </table></div>
        </div></div></div>
    </div>
</div>
<?php
render_footer([
    'assets/vendor/chartjs/chart.umd.min.js',
    'assets/js/pantalla_util.js',
    'assets/js/m6/filtros.js',
    'assets/js/m6/dashboard.js',
]);
