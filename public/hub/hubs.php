<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('HUBs', 'm3', ['css' => ['m3.css'], 'vendor_css' => ['leaflet/leaflet.css']]);
$puedeEscribir = $usuario['rol'] === 'admin';
?>
<div class="container py-3" id="m3-hubs" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-building me-2"></i>HUBs</h1>
        <?php if ($puedeEscribir): ?><button type="button" class="btn btn-primary" id="btn-nuevo"><i class="bi bi-plus-lg"></i> Nuevo HUB</button><?php endif; ?>
    </div>
    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Nombre</th><th>Dirección</th><th>Ubicación</th><th>Paquetes sin cerrar</th><th>Estado</th><?php if ($puedeEscribir): ?><th></th><?php endif; ?></tr></thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($puedeEscribir): ?>
<div class="modal fade" id="modal-hub" tabindex="-1" aria-labelledby="modal-hub-titulo" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="form-hub" novalidate>
            <div class="modal-header"><h5 class="modal-title" id="modal-hub-titulo">HUB</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label" for="h-nombre">Nombre *</label><input class="form-control" id="h-nombre" name="nombre" maxlength="100" required></div>
                    <div class="col-md-6"><label class="form-label" for="h-direccion">Dirección</label><input class="form-control" id="h-direccion" name="direccion" maxlength="255"></div>
                    <div class="col-6 col-md-4"><label class="form-label" for="h-lat">Latitud *</label><input class="form-control" id="h-lat" name="lat" inputmode="decimal" required></div>
                    <div class="col-6 col-md-4"><label class="form-label" for="h-lng">Longitud *</label><input class="form-control" id="h-lng" name="lng" inputmode="decimal" required></div>
                    <div class="col-12 col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="h-activo" name="activo" checked><label class="form-check-label" for="h-activo">Activo</label></div></div>
                    <div class="col-12"><div class="small text-muted mb-1">Haz clic en el mapa o arrastra el marcador para ubicar el HUB.</div><div id="mapa-hub" class="rp-mapa"></div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="h-guardar">Guardar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_footer(['vendor/leaflet/leaflet.js', 'm3/comun.js', 'm3/hubs.js']); ?>
