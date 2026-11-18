<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Detalle de ruta', 'm1', ['css' => ['m3.css', 'm1.css'], 'vendor_css' => ['leaflet/leaflet.css']]);
$puedeEscribir = in_array($usuario['rol'], ['admin', 'despachador'], true);
?>
<div class="container-fluid py-3" id="m1-ruta" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-signpost-split me-2"></i><span id="r-codigo">Ruta</span> <span id="r-estado"></span></h1>
        <div class="d-flex flex-wrap gap-2" id="acciones"><a class="btn btn-outline-secondary btn-sm" href="rutas.php"><i class="bi bi-arrow-left"></i> Volver</a></div>
    </div>
    <div id="mensaje"></div>
    <div id="contenido"><div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Cargando…</div></div>
</div>

<?php if ($puedeEscribir): ?>
<div class="modal fade" id="modal-reasignar" tabindex="-1" aria-labelledby="modal-reasignar-titulo" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="form-reasignar" novalidate>
        <div class="modal-header"><h5 class="modal-title" id="modal-reasignar-titulo">Asignar conductor y vehículo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label" for="a-conductor">Conductor</label><select class="form-select" id="a-conductor" name="conductor_id"></select></div>
            <div class="mb-3"><label class="form-label" for="a-vehiculo">Vehículo</label><select class="form-select" id="a-vehiculo" name="vehiculo_id"></select>
                <div class="form-text">Al elegir un conductor se propone su vehículo habitual.</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="a-enviar">Guardar</button></div>
    </form></div>
</div>
<div class="modal fade" id="modal-agregar" tabindex="-1" aria-labelledby="modal-agregar-titulo" aria-hidden="true">
    <div class="modal-dialog modal-lg"><form class="modal-content" id="form-agregar" novalidate>
        <div class="modal-header"><h5 class="modal-title" id="modal-agregar-titulo">Agregar paquete a la ruta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <p class="small text-muted">Paquetes «En clasificación» del HUB de la ruta, con coordenadas.</p>
            <div class="rp-tabla-scroll" style="max-height:18rem"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th></th><th>Guía</th><th>Destinatario</th><th>Prioridad</th><th>Peso</th></tr></thead><tbody id="ag-lista"></tbody></table></div>
            <div class="row g-2 mt-2"><div class="col-md-4"><label class="form-label" for="ag-pos">Posición (opcional)</label><input type="number" min="1" class="form-control" id="ag-pos" placeholder="Automática"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="ag-enviar" disabled>Agregar</button></div>
    </form></div>
</div>
<div class="modal fade" id="modal-recalcular" tabindex="-1" aria-labelledby="modal-recalcular-titulo" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="form-recalcular" novalidate>
        <div class="modal-header"><h5 class="modal-title" id="modal-recalcular-titulo">Recalcular ruta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label" for="rc-motivo">Motivo *</label><input class="form-control" id="rc-motivo" name="motivo" maxlength="255" placeholder="Bloqueo vial, entrega urgente…" required></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="rc-evitar"><label class="form-check-label" for="rc-evitar">Evitar una zona (las paradas dentro pasan al final)</label></div>
            <div class="row g-2 d-none" id="rc-evitar-campos">
                <div class="col-4"><label class="form-label small" for="rc-lat">Latitud</label><input class="form-control form-control-sm" id="rc-lat" inputmode="decimal"></div>
                <div class="col-4"><label class="form-label small" for="rc-lng">Longitud</label><input class="form-control form-control-sm" id="rc-lng" inputmode="decimal"></div>
                <div class="col-4"><label class="form-label small" for="rc-radio">Radio (m)</label><input class="form-control form-control-sm" type="number" min="50" value="500" id="rc-radio"></div>
                <div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm" id="rc-elegir"><i class="bi bi-cursor"></i> Elegir el punto en el mapa</button></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="rc-enviar">Recalcular</button></div>
    </form></div>
</div>
<?php endif; ?>
<?php render_footer(['vendor/leaflet/leaflet.js', 'm3/comun.js', 'm1/ruta_detalle.js']); ?>
