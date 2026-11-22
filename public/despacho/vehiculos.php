<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Vehículos', 'm1', ['css' => ['m3.css', 'm1.css']]);
?>
<div class="container-fluid py-3" id="m1-vehiculos">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Vehículos</h1>
        <button type="button" class="btn btn-primary" id="btn-nuevo"><i class="bi bi-plus-lg"></i> Nuevo vehículo</button>
    </div>
    <form class="card card-body mb-3" id="filtros" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label small mb-1" for="f-q">Buscar</label>
                <input type="search" class="form-control" id="f-q" maxlength="60" placeholder="Placa o modelo"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="f-tipo">Tipo</label>
                <select class="form-select" id="f-tipo"><option value="">Todos</option></select></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="f-activo">Estado</label>
                <select class="form-select" id="f-activo"><option value="">Todos</option><option value="1">Activos</option><option value="0">Inactivos</option></select></div>
        </div>
    </form>
    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Placa</th><th>Tipo</th><th>Marca / modelo</th><th>Capacidad</th><th>Costo por km</th><th>Estado</th><th></th></tr></thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
        <div class="card-footer" id="paginador"></div>
    </div>
</div>

<div class="modal fade" id="modal-vehiculo" tabindex="-1" aria-labelledby="modal-vehiculo-titulo" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="form-vehiculo" novalidate>
            <div class="modal-header"><h5 class="modal-title" id="modal-vehiculo-titulo">Vehículo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label" for="v-placa">Placa *</label><input class="form-control text-uppercase" id="v-placa" name="placa" maxlength="20" required></div>
                    <div class="col-md-7"><label class="form-label" for="v-tipo">Tipo *</label><select class="form-select" id="v-tipo" name="tipo" required></select></div>
                    <div class="col-12"><label class="form-label" for="v-modelo">Marca / modelo</label><input class="form-control" id="v-modelo" name="marca_modelo" maxlength="80"></div>
                    <div class="col-6"><label class="form-label" for="v-kg">Capacidad (kg) *</label><input class="form-control" type="number" step="0.01" min="0.01" id="v-kg" name="capacidad_kg" required></div>
                    <div class="col-6"><label class="form-label" for="v-m3">Capacidad (m³) *</label><input class="form-control" type="number" step="0.01" min="0.01" id="v-m3" name="capacidad_m3" required></div>
                    <div class="col-6"><label class="form-label" for="v-costo">Costo por km</label><input class="form-control" type="number" step="0.01" min="0" id="v-costo" name="costo_por_km" value="0"></div>
                    <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="v-activo" checked><label class="form-check-label" for="v-activo">Activo</label></div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="v-guardar">Guardar</button></div>
        </form>
    </div>
</div>
<?php render_footer(['m3/comun.js', 'm1/vehiculos.js']); ?>
