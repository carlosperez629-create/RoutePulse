<?php
/**
 * M6 - Reportes y exportación a CSV (roles: admin, gerente). Pantalla de Karoline.
 * La vista previa se carga por AJAX desde assets/js/m6/reportes.js; los CSV los genera kpi/exportar.php.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

auth_requerir_pagina(['admin', 'gerente']);
render_header('Reportes', 'm6');

$hasta = kpi_hoy_local();
$desde = (new DateTimeImmutable($hasta))->modify('-6 days')->format('Y-m-d');
$base = rtrim((string) app_cfg('app.url', ''), '/');
?>
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/m6.css">
<div class="container-fluid py-3" id="pantalla-reportes">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Reportes</h1>
        <button type="button" class="btn btn-outline-secondary no-imprimir" id="btn-imprimir">
            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir / guardar PDF
        </button>
    </div>

    <form class="row g-2 align-items-end mb-3 filtros-m6 no-imprimir" id="filtros" autocomplete="off">
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

    <div class="row g-3 mb-4 no-imprimir" id="catalogo">
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column">
            <h2 class="h6">Resumen</h2>
            <p class="small text-muted">OTIF, FTDR, tiempo de parada, incidencias y costos del periodo.</p>
            <a class="btn btn-outline-primary mt-auto" data-reporte="resumen" href="#"><i class="bi bi-download" aria-hidden="true"></i> Exportar CSV</a>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column">
            <h2 class="h6">Ranking de conductores</h2>
            <p class="small text-muted">Indicadores de cada conductor, ordenados por OTIF.</p>
            <a class="btn btn-outline-primary mt-auto" data-reporte="conductores" href="#"><i class="bi bi-download" aria-hidden="true"></i> Exportar CSV</a>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column">
            <h2 class="h6">Por zona</h2>
            <p class="small text-muted">Despachos, incidencias y tasa de rechazo por zona.</p>
            <a class="btn btn-outline-primary mt-auto" data-reporte="zonas" href="#"><i class="bi bi-download" aria-hidden="true"></i> Exportar CSV</a>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column">
            <h2 class="h6">Detalle de entregas</h2>
            <p class="small text-muted">Cada intento de entrega, fila por fila (máximo 20 000).</p>
            <a class="btn btn-outline-primary mt-auto" data-reporte="entregas" href="#"><i class="bi bi-download" aria-hidden="true"></i> Exportar CSV</a>
        </div></div></div>
    </div>

    <section id="vista-previa">
        <h2 class="h5">Vista previa del informe <small class="text-muted" id="vp-periodo"></small></h2>
        <div class="table-responsive mb-4"><table class="table table-sm w-auto">
            <thead><tr><th scope="col">Indicador</th><th scope="col" class="text-end">Valor</th></tr></thead>
            <tbody id="cuerpo-resumen"></tbody>
        </table></div>
        <h3 class="h6">Ranking de conductores</h3>
        <div class="table-responsive mb-4"><table class="table table-sm">
            <thead><tr><th scope="col">Conductor</th><th scope="col" class="text-end">Despachados</th><th scope="col" class="text-end">Entregados</th>
                <th scope="col" class="text-end">OTIF</th><th scope="col" class="text-end">FTDR</th><th scope="col" class="text-end">T. parada</th>
                <th scope="col" class="text-end">Km reales</th><th scope="col" class="text-end">Costo</th></tr></thead>
            <tbody id="cuerpo-conductores"></tbody>
        </table></div>
        <h3 class="h6">Por zona</h3>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th scope="col">Zona</th><th scope="col" class="text-end">Despachados</th><th scope="col" class="text-end">Entregados</th>
                <th scope="col" class="text-end">Incidencias</th><th scope="col" class="text-end">Rechazo</th><th scope="col" class="text-end">OTIF</th></tr></thead>
            <tbody id="cuerpo-zonas"></tbody>
        </table></div>
    </section>
</div>
<?php
render_footer(['assets/js/pantalla_util.js', 'assets/js/m6/filtros.js', 'assets/js/m6/reportes.js']);
