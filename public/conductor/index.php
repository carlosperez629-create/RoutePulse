<?php
/**
 * public/conductor/index.php - M4. Hoja de ruta del conductor (PWA).
 * Carga la ruta con conductor/mi_ruta.php, la guarda en IndexedDB y la dibuja con m4/app.js.
 */
require_once __DIR__ . '/../../app/core/bootstrap.php';

$usuario = auth_requerir_pagina(['conductor']);

render_header('Mi ruta', 'm4', ['css' => ['m4.css']]);
?>

<div id="conductor-app" class="rp-m4" data-vista="ruta">
    <section class="card rp-m4-cabecera mb-3" aria-labelledby="ruta-codigo">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="text-muted small">Ruta de hoy</div>
                    <h1 id="ruta-codigo" class="h5 mb-0">—</h1>
                </div>
                <span id="estado-conexion" class="badge bg-success">En línea</span>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-2 small text-muted">
                <span><i class="bi bi-truck" aria-hidden="true"></i> <span id="vehiculo">—</span></span>
                <span><i class="bi bi-check2-circle" aria-hidden="true"></i> <span id="progreso">0/0</span></span>
                <span><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> <span id="pendientes">0 pendientes</span></span>
            </div>
            <div class="progress mt-3" role="progressbar" aria-label="Progreso de la ruta" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="progreso-barra">
                <div class="progress-bar bg-success" id="barra-progreso" style="width:0%"></div>
            </div>
            <div class="d-grid gap-2 mt-3">
                <button id="btn-iniciar" class="btn btn-success btn-touch d-none" type="button">
                    <i class="bi bi-play-fill" aria-hidden="true"></i> Iniciar ruta
                </button>
                <button id="btn-finalizar" class="btn btn-outline-danger btn-touch d-none" type="button">
                    <i class="bi bi-flag" aria-hidden="true"></i> Finalizar ruta
                </button>
            </div>
            <div id="aviso-ruta" class="alert alert-info mt-3 mb-0 d-none" role="status"></div>
        </div>
    </section>

    <section id="parada-actual" class="mb-3" aria-live="polite"></section>

    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h2 class="h6 text-uppercase text-muted mb-0">Paradas</h2>
        <button id="btn-recargar" class="btn btn-sm btn-outline-secondary" type="button">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar
        </button>
    </div>
    <div id="lista-paradas" class="list-group rp-m4-lista" aria-live="polite"></div>

    <section id="cola-errores" class="mt-3" aria-live="polite"></section>
</div>

<div class="collapse rp-m4-estado" id="panel-estado">
    <div class="card card-body">
        <h2 class="h6">Estado del teléfono</h2>
        <dl class="row small mb-0">
            <dt class="col-6">GPS</dt><dd class="col-6 mb-1" id="gps-estado">Inactivo</dd>
            <dt class="col-6">Precisión</dt><dd class="col-6 mb-1" id="gps-precision">—</dd>
            <dt class="col-6">Puntos GPS sin enviar</dt><dd class="col-6 mb-1" id="gps-pendientes">0</dd>
            <dt class="col-6">Batería</dt><dd class="col-6 mb-1" id="bateria">—</dd>
            <dt class="col-6">Última sincronización</dt><dd class="col-6 mb-0" id="ultima-sincronizacion">—</dd>
        </dl>
    </div>
</div>

<nav class="rp-m4-barra" aria-label="Navegación del conductor">
    <a class="rp-m4-barra__item active" href="./" aria-current="page">
        <i class="bi bi-signpost-split" aria-hidden="true"></i><span>Ruta</span>
    </a>
    <button class="rp-m4-barra__item" type="button" data-bs-toggle="collapse" data-bs-target="#panel-estado" aria-controls="panel-estado" aria-expanded="false">
        <i class="bi bi-broadcast" aria-hidden="true"></i><span>Estado</span>
    </button>
</nav>
<?php
render_footer([
    'gps_tracker.js',
    'm4/comun.js',
    'm4/cola.js',
    'm4/app.js',
]);
