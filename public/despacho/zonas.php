<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Zonas de cobertura', 'm1', ['css' => ['m3.css', 'm1.css'], 'vendor_css' => ['leaflet/leaflet.css', 'leaflet-draw/leaflet.draw.css']]);
?>
<div class="container-fluid py-3" id="m1-zonas">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-pentagon me-2"></i>Zonas de cobertura</h1>
        <button type="button" class="btn btn-primary" id="btn-nueva"><i class="bi bi-plus-lg"></i> Nueva zona</button>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-2">
                    <div id="mapa-zonas" class="rp-mapa-grande"></div>
                    <div class="small text-muted mt-2">Haz clic en una zona para editarla. Con la herramienta de polígono (arriba a la izquierda del mapa) dibujas o corriges el área.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header fw-semibold">Zonas</div>
                <ul class="list-group list-group-flush" id="lista-zonas"></ul>
            </div>
            <form class="card" id="form-zona" novalidate autocomplete="off">
                <div class="card-header fw-semibold" id="titulo-form">Selecciona o crea una zona</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label" for="z-nombre">Nombre *</label><input class="form-control" id="z-nombre" name="nombre" maxlength="80" required></div>
                        <div class="col-12"><label class="form-label" for="z-descripcion">Descripción</label><input class="form-control" id="z-descripcion" name="descripcion" maxlength="255"></div>
                        <div class="col-6"><label class="form-label" for="z-color">Color</label><input class="form-control form-control-color w-100" type="color" id="z-color" name="color" value="#0d6efd"></div>
                        <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="z-activa" checked><label class="form-check-label" for="z-activa">Activa</label></div></div>
                        <div class="col-12"><label class="form-label" for="z-cp">Códigos postales</label>
                            <textarea class="form-control" id="z-cp" name="codigos_postales" rows="2" placeholder="110111, 110121"></textarea>
                            <div class="form-text">Separados por coma. Se usan si el punto no cae en ningún polígono.</div></div>
                        <div class="col-12"><div class="form-label">Conductores de la zona</div><div id="z-conductores" class="border rounded p-2" style="max-height:10rem;overflow:auto"></div></div>
                        <div class="col-12 small" id="estado-poligono"></div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="btn-cancelar">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="z-guardar" disabled>Guardar zona</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php render_footer(['vendor/leaflet/leaflet.js', 'vendor/leaflet-draw/leaflet.draw.js', 'm3/comun.js', 'm1/zonas.js']); ?>
