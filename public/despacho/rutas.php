<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Rutas', 'm1', ['css' => ['m3.css', 'm1.css']]);
$puedeEscribir = in_array($usuario['rol'], ['admin', 'despachador'], true);
?>
<div class="container-fluid py-3" id="m1-rutas" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-signpost-split me-2"></i>Rutas</h1>
        <?php if ($puedeEscribir): ?><button type="button" class="btn btn-primary" id="btn-generar"><i class="bi bi-magic"></i> Generar rutas</button><?php endif; ?>
    </div>
    <form class="card card-body mb-3" id="filtros" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="f-fecha">Fecha</label><input type="date" class="form-control" id="f-fecha"></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-1" for="f-estado">Estado</label>
                <select class="form-select" id="f-estado"><option value="">Todos</option><option value="borrador">Borrador</option><option value="planificada">Planificada</option><option value="en_curso">En curso</option><option value="completada">Completada</option><option value="cancelada">Cancelada</option></select></div>
            <div class="col-6 col-md-3 col-xl-3"><label class="form-label small mb-1" for="f-conductor">Conductor</label><select class="form-select" id="f-conductor"><option value="">Todos</option></select></div>
            <div class="col-6 col-md-3 col-xl-3"><label class="form-label small mb-1" for="f-zona">Zona</label><select class="form-select" id="f-zona"><option value="">Todas</option></select></div>
            <div class="col-12 col-xl-auto"><button type="button" class="btn btn-link btn-sm" id="btn-limpiar">Limpiar</button></div>
        </div>
    </form>
    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Código</th><th>Fecha</th><th>Estado</th><th>Conductor / vehículo</th><th>Zona</th><th>Avance</th><th>Carga</th><th>Distancia</th></tr></thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
        <div class="card-footer" id="paginador"></div>
    </div>
</div>

<?php if ($puedeEscribir): ?>
<div class="modal fade" id="modal-generar" tabindex="-1" aria-labelledby="modal-generar-titulo" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="form-generar" novalidate>
            <div class="modal-header"><h5 class="modal-title" id="modal-generar-titulo">Generar rutas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div id="gen-formulario">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label" for="g-fecha">Fecha de las rutas *</label><input type="date" class="form-control" id="g-fecha" name="fecha" required></div>
                        <div class="col-md-4"><label class="form-label" for="g-hub">HUB de salida *</label><select class="form-select" id="g-hub" name="hub_id" required></select></div>
                        <div class="col-md-4"><label class="form-label" for="g-zona">Zona (opcional)</label><select class="form-select" id="g-zona" name="zona_id"><option value="">Todas</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="g-hora">Hora de salida</label><input type="datetime-local" class="form-control" id="g-hora" name="hora_inicio"></div>
                        <div class="col-12"><div class="form-label">Conductores (opcional: si no eliges, se usan todos los disponibles)</div>
                            <div id="g-conductores" class="border rounded p-2" style="max-height:9rem;overflow:auto"><span class="text-muted small">Elige una fecha…</span></div></div>
                    </div>
                    <div class="form-text mt-2">Las rutas se crean en <strong>borrador</strong>: no cambia el estado de ningún paquete hasta que las confirmes.</div>
                </div>
                <div id="gen-resultado" class="d-none"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="g-cerrar">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="g-enviar">Generar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_footer(['m3/comun.js', 'm1/rutas.js']); ?>
