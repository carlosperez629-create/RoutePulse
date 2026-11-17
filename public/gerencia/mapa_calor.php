<?php
/**
 * M6 - Mapa de calor de entregas o incidencias (roles: admin, gerente). Pantalla de Karoline.
 * Los puntos se cargan por AJAX desde assets/js/m6/mapa_calor.js (endpoint kpi/mapa_calor.php).
 */
require __DIR__ . '/../../app/core/bootstrap.php';

auth_requerir_pagina(['admin', 'gerente']);
render_header('Mapa de calor', 'm6');

$hasta = kpi_hoy_local();
$desde = (new DateTimeImmutable($hasta))->modify('-6 days')->format('Y-m-d');
$base = rtrim((string) app_cfg('app.url', ''), '/');

// Centro inicial del mapa: el HUB por defecto (id 1) o, si no existe, el primer HUB activo.
$hub = db_one('SELECT lat, lng FROM hubs WHERE id = 1 AND activo = 1')
    ?? db_one('SELECT lat, lng FROM hubs WHERE activo = 1 ORDER BY id LIMIT 1');
$centro = $hub !== null ? [(float) $hub['lat'], (float) $hub['lng']] : null;
?>
<link rel="stylesheet" href="<?= e($base) ?>/assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/m6.css">
<div class="container-fluid py-3" id="pantalla-mapa-calor"
     data-centro-lat="<?= $centro !== null ? e((string) $centro[0]) : '' ?>"
     data-centro-lng="<?= $centro !== null ? e((string) $centro[1]) : '' ?>">
    <h1 class="h3 mb-3">Mapa de calor</h1>

    <form class="row g-2 align-items-end mb-3 filtros-m6" id="filtros" autocomplete="off">
        <div class="col-12 col-md-3">
            <span class="form-label small mb-1 d-block" id="lbl-tipo">Mostrar</span>
            <div class="btn-group w-100" role="group" aria-labelledby="lbl-tipo">
                <input type="radio" class="btn-check" name="tipo" id="tipo-entregas" value="entregas" checked>
                <label class="btn btn-outline-success" for="tipo-entregas">Entregas</label>
                <input type="radio" class="btn-check" name="tipo" id="tipo-incidencias" value="incidencias">
                <label class="btn btn-outline-danger" for="tipo-incidencias">Incidencias</label>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-desde" class="form-label small mb-1">Desde</label>
            <input type="date" class="form-control" id="f-desde" value="<?= e($desde) ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-hasta" class="form-label small mb-1">Hasta</label>
            <input type="date" class="form-control" id="f-hasta" value="<?= e($hasta) ?>" required>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-zona" class="form-label small mb-1">Zona</label>
            <select class="form-select" id="f-zona"><option value="">Todas</option></select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-conductor" class="form-label small mb-1">Conductor</label>
            <select class="form-select" id="f-conductor"><option value="">Todos</option></select>
        </div>
        <div class="col-12 col-md-1 d-grid">
            <button type="submit" class="btn btn-primary" id="btn-aplicar">Aplicar</button>
        </div>
        <div class="col-12"><div class="text-danger small" id="error-filtros" role="alert"></div></div>
    </form>

    <p class="text-muted small" id="resumen-mapa" role="status"></p>
    <div id="mapa-calor" class="mapa-calor-m6" role="img" aria-label="Mapa de calor"></div>
</div>
<?php
render_footer([
    'assets/vendor/leaflet/leaflet.js',
    'assets/vendor/leaflet-heat/leaflet-heat.js',
    'assets/js/pantalla_util.js',
    'assets/js/m6/filtros.js',
    'assets/js/m6/mapa_calor.js',
]);
