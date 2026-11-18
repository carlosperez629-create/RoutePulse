<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Incidencias', 'm3', ['css' => ['m3.css']]);
$puedeEscribir = in_array($usuario['rol'], ['admin', 'despachador'], true);
?>
<div class="container-fluid py-3" id="m3-incidencias" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <h1 class="h4 mb-3"><i class="bi bi-exclamation-triangle me-2"></i>Incidencias</h1>
    <form class="card card-body mb-3" id="filtros" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="f-estado">Estado</label>
                <select class="form-select" id="f-estado"><option value="">Todos</option><option value="abierta" selected>Abierta</option><option value="reprogramada">Reprogramada</option><option value="devuelta">Devuelta</option><option value="cancelada">Cancelada</option></select></div>
            <div class="col-6 col-md-3 col-xl-3"><label class="form-label small mb-1" for="f-tipo">Tipo</label>
                <select class="form-select" id="f-tipo"><option value="">Todos</option></select></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="f-desde">Desde</label>
                <input type="date" class="form-control" id="f-desde"></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="f-hasta">Hasta</label>
                <input type="date" class="form-control" id="f-hasta"></div>
        </div>
    </form>
    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Guía</th><th>Destinatario</th><th>Tipo</th><th>Descripción</th><th>Estado</th><th>Intentos</th><th>Fecha</th><?php if ($puedeEscribir): ?><th></th><?php endif; ?></tr></thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
        <div class="card-footer" id="paginador"></div>
    </div>
</div>

<?php if ($puedeEscribir): ?>
<div class="modal fade" id="modal-resolver" tabindex="-1" aria-labelledby="modal-resolver-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="form-resolver" novalidate>
            <div class="modal-header"><h5 class="modal-title" id="modal-resolver-titulo">Resolver incidencia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <p class="small text-muted" id="resolver-resumen"></p>
                <div class="mb-3"><label class="form-label" for="r-accion">¿Qué se hace con el paquete?</label>
                    <select class="form-select" id="r-accion" name="accion">
                        <option value="reprogramar">Reprogramar la entrega</option>
                        <option value="devolver">Devolver al remitente</option>
                        <option value="cancelar">Cancelar el paquete</option>
                    </select>
                    <div class="form-text text-danger d-none" id="r-sin-intentos">Se alcanzó el máximo de intentos: solo se puede devolver o cancelar.</div></div>
                <div id="bloque-reprogramar">
                    <div class="mb-3"><label class="form-label" for="r-fecha">Nueva fecha *</label>
                        <input type="date" class="form-control" id="r-fecha" name="fecha"></div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label" for="r-vi">Ventana desde</label><input type="datetime-local" class="form-control" id="r-vi" name="ventana_inicio"></div>
                        <div class="col-6"><label class="form-label" for="r-vf">Ventana hasta</label><input type="datetime-local" class="form-control" id="r-vf" name="ventana_fin"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="r-enviar">Resolver</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_footer(['m3/comun.js', 'm3/incidencias.js']); ?>
