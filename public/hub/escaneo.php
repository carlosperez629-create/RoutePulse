<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Escaneo en HUB', 'm3', ['css' => ['m3.css']]);
?>
<div class="container py-3" id="m3-escaneo">
    <h1 class="h4 mb-3"><i class="bi bi-upc-scan me-2"></i>Escaneo en HUB</h1>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="accion">Acción</label>
                    <select class="form-select" id="accion">
                        <option value="ingreso_hub">Ingreso al HUB</option>
                        <option value="clasificar">Clasificar</option>
                    </select>
                </div>
                <div class="col-md-4" id="bloque-hub">
                    <label class="form-label" for="hub">HUB de ingreso</label>
                    <select class="form-select" id="hub"></select>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="sonido" checked>
                        <label class="form-check-label" for="sonido">Sonido</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="codigo">Código de guía (escanea o escribe y pulsa Enter)</label>
                    <input class="form-control form-control-lg rp-escaneo-input" id="codigo" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="RP-AAMMDD-XXXXXX" aria-describedby="estado-escaneo">
                    <div id="estado-escaneo" class="form-text">Listo para escanear.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Últimos escaneos</span>
            <span class="small text-muted"><span class="badge text-bg-success" id="cont-ok">0</span> correctos · <span class="badge text-bg-danger" id="cont-error">0</span> con error</span>
        </div>
        <ul class="list-group list-group-flush" id="lista-escaneos">
            <li class="list-group-item text-muted" id="vacio">Aún no hay escaneos.</li>
        </ul>
    </div>
</div>
<?php render_footer(['m3/comun.js', 'm3/escaneo.js']); ?>
