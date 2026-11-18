<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Conductores', 'm1', ['css' => ['m3.css', 'm1.css']]);
?>
<div class="container-fluid py-3" id="m1-conductores">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-person-badge me-2"></i>Conductores</h1>
        <button type="button" class="btn btn-primary" id="btn-nuevo"><i class="bi bi-plus-lg"></i> Nuevo conductor</button>
    </div>
    <form class="card card-body mb-3" id="filtros" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="f-estado">Estado</label>
                <select class="form-select" id="f-estado"><option value="">Todos</option><option value="disponible">Disponible</option><option value="en_ruta">En ruta</option><option value="inactivo">Inactivo</option></select></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="f-zona">Zona</label>
                <select class="form-select" id="f-zona"><option value="">Todas</option></select></div>
        </div>
    </form>
    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Conductor</th><th>Contacto</th><th>Licencia</th><th>Vehículo</th><th>Zonas</th><th>Estado</th><th></th></tr></thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
        <div class="card-footer" id="paginador"></div>
    </div>
</div>

<div class="modal fade" id="modal-conductor" tabindex="-1" aria-labelledby="modal-conductor-titulo" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="form-conductor" novalidate autocomplete="off">
            <div class="modal-header"><h5 class="modal-title" id="modal-conductor-titulo">Conductor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div id="bloque-usuario" class="row g-2">
                    <div class="col-12"><label class="form-label" for="c-nombre">Nombre *</label><input class="form-control" id="c-nombre" name="nombre" maxlength="100"></div>
                    <div class="col-md-7"><label class="form-label" for="c-email">Correo *</label><input class="form-control" type="email" id="c-email" name="email" maxlength="150"></div>
                    <div class="col-md-5"><label class="form-label" for="c-telefono">Teléfono *</label><input class="form-control" id="c-telefono" name="telefono" inputmode="tel" placeholder="+573001234567"></div>
                    <div class="col-12"><label class="form-label" for="c-password">Contraseña</label><input class="form-control" type="text" id="c-password" name="password" autocomplete="new-password">
                        <div class="form-text">Si la dejas vacía se genera una temporal y se muestra una sola vez.</div></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-6"><label class="form-label" for="c-licencia">Licencia</label><input class="form-control" id="c-licencia" name="licencia" maxlength="40"></div>
                    <div class="col-md-6"><label class="form-label" for="c-vehiculo">Vehículo habitual</label><select class="form-select" id="c-vehiculo" name="vehiculo_id"><option value="">Sin vehículo</option></select></div>
                    <div class="col-md-6" id="bloque-estado"><label class="form-label" for="c-estado">Estado</label>
                        <select class="form-select" id="c-estado" name="estado"><option value="disponible">Disponible</option><option value="inactivo">Inactivo</option></select>
                        <div class="form-text d-none" id="c-estado-ayuda">En ruta: lo cambia el sistema al terminar la ruta.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="c-guardar">Guardar</button></div>
        </form>
    </div>
</div>
<?php render_footer(['m3/comun.js', 'm1/conductores.js']); ?>
