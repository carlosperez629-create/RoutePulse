<?php
/**
 * Playback de recorridos (M2). Roles: admin, despachador, gerente (solo lectura).
 * Muestra el recorrido real sobre el sugerido y lo reproduce en el tiempo.
 * Datos por AJAX desde assets/js/m2/playback.js (gps/recorrido.php). Acepta ?ruta_id=N para abrir una ruta directa.
 */
require __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador', 'gerente']);

$zonaHoraria = (string) config_get('zona_horaria_ui', 'UTC');
try {
    $hoy = (new DateTimeImmutable('now', new DateTimeZone($zonaHoraria)))->format('Y-m-d');
} catch (Throwable $e) {
    $hoy = gmdate('Y-m-d');
}

render_header('Playback de recorridos', 'monitor');
?>
<link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="../assets/css/monitor.css">

<div id="playback" class="container-fluid py-3" data-zona-horaria="<?= e($zonaHoraria) ?>">
    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
            <label for="pb-fecha" class="form-label mb-0 small">Fecha</label>
            <input type="date" id="pb-fecha" class="form-control form-control-sm" value="<?= e($hoy) ?>">
        </div>
        <div>
            <label for="pb-ruta" class="form-label mb-0 small">Ruta</label>
            <select id="pb-ruta" class="form-select form-select-sm">
                <option value="">Cargando rutas…</option>
            </select>
        </div>
        <button type="button" id="pb-verificar" class="btn btn-outline-secondary btn-sm" disabled>Verificar integridad</button>
        <span id="pb-integridad" class="badge text-bg-light" role="status"></span>
        <div id="pb-aviso" class="small text-muted ms-auto" role="status" aria-live="polite"></div>
    </div>

    <div class="row g-2 mb-3" id="pb-tarjetas">
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Distancia real</div><div class="fs-5 fw-semibold" id="pb-dist-real">—</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Distancia sugerida</div><div class="fs-5 fw-semibold" id="pb-dist-sug">—</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Desviación</div><div class="fs-5 fw-semibold" id="pb-desv">—</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Duración real</div><div class="fs-5 fw-semibold" id="pb-duracion">—</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Tiempo en paradas</div><div class="fs-5 fw-semibold" id="pb-paradas">—</div></div></div></div>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
            <div class="small text-muted">Eventos de geocerca</div><div class="fs-5 fw-semibold" id="pb-eventos">—</div></div></div></div>
    </div>

    <div id="mapa-playback" class="playback-mapa border rounded" aria-label="Mapa del recorrido"></div>

    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <button type="button" id="pb-play" class="btn btn-primary btn-sm" disabled aria-label="Reproducir">Reproducir</button>
        <div class="btn-group btn-group-sm" role="group" aria-label="Velocidad de reproducción">
            <button type="button" class="btn btn-outline-secondary" data-velocidad="1" aria-pressed="true">×1</button>
            <button type="button" class="btn btn-outline-secondary" data-velocidad="4" aria-pressed="false">×4</button>
            <button type="button" class="btn btn-outline-secondary" data-velocidad="16" aria-pressed="false">×16</button>
        </div>
        <label for="pb-slider" class="visually-hidden">Posición en el tiempo</label>
        <input type="range" id="pb-slider" class="form-range flex-grow-1" min="0" max="0" step="1" value="0" disabled>
        <span id="pb-reloj" class="small text-muted" aria-live="off">—</span>
    </div>

    <ul id="pb-lista-eventos" class="small text-muted mt-3 mb-0 ps-3"></ul>
</div>

<script src="../assets/vendor/leaflet/leaflet.js"></script>
<script src="../assets/js/mapa_util.js"></script>
<script src="../assets/js/m2/playback.js"></script>
<?php render_footer(); ?>
