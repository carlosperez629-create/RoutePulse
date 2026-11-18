<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Paquete', 'm3', ['css' => ['m3.css'], 'vendor_css' => ['leaflet/leaflet.css']]);
?>
<div class="container py-3" id="m3-nuevo">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i><span id="titulo-pantalla">Nuevo paquete</span></h1>
        <a class="btn btn-outline-secondary btn-sm" href="paquetes.php"><i class="bi bi-arrow-left"></i> Volver a la lista</a>
    </div>

    <div id="resultado"></div>

    <form id="form-paquete" novalidate autocomplete="off">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Remitente y destinatario</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-7"><label class="form-label" for="remitente_nombre">Remitente *</label>
                                <input class="form-control" id="remitente_nombre" name="remitente_nombre" maxlength="100" required></div>
                            <div class="col-md-5"><label class="form-label" for="remitente_telefono">Teléfono remitente</label>
                                <input class="form-control" id="remitente_telefono" name="remitente_telefono" inputmode="tel" placeholder="+573001234567"></div>
                            <div class="col-md-7"><label class="form-label" for="destinatario_nombre">Destinatario *</label>
                                <input class="form-control" id="destinatario_nombre" name="destinatario_nombre" maxlength="100" required></div>
                            <div class="col-md-5"><label class="form-label" for="destinatario_telefono">Teléfono destinatario *</label>
                                <input class="form-control" id="destinatario_telefono" name="destinatario_telefono" inputmode="tel" placeholder="+573001234567" required></div>
                            <div class="col-12"><label class="form-label" for="destinatario_email">Correo del destinatario</label>
                                <input class="form-control" type="email" id="destinatario_email" name="destinatario_email" maxlength="150"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Dirección de entrega</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label" for="direccion_entrega">Dirección *</label>
                                <input class="form-control" id="direccion_entrega" name="direccion_entrega" maxlength="255" required></div>
                            <div class="col-12"><label class="form-label" for="referencia">Referencia</label>
                                <input class="form-control" id="referencia" name="referencia" maxlength="255" placeholder="Torre 2, apto 301, portería…"></div>
                            <div class="col-md-7"><label class="form-label" for="ciudad">Ciudad</label>
                                <input class="form-control" id="ciudad" name="ciudad" maxlength="80"></div>
                            <div class="col-md-5"><label class="form-label" for="codigo_postal">Código postal</label>
                                <input class="form-control" id="codigo_postal" name="codigo_postal" maxlength="12"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="fw-semibold">Ubicación en el mapa</span>
                        <div class="d-flex align-items-center gap-2">
                            <span id="zona-resuelta" class="badge text-bg-secondary">Zona: sin resolver</span>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-ubicar"><i class="bi bi-geo-alt"></i> Ubicar en mapa</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="mapa" class="rp-mapa mb-2"></div>
                        <div class="row g-2 align-items-end">
                            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="lat">Latitud</label>
                                <input class="form-control form-control-sm" id="lat" name="lat" inputmode="decimal"></div>
                            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="lng">Longitud</label>
                                <input class="form-control form-control-sm" id="lng" name="lng" inputmode="decimal"></div>
                            <div class="col-12 col-md-6 small text-muted">Arrastra el marcador para corregir la ubicación. Si no marcas nada, el sistema geocodifica la dirección al guardar.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Carga y cobro</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6 col-md-4"><label class="form-label" for="peso_kg">Peso (kg)</label>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="1000" id="peso_kg" name="peso_kg" value="0.5"></div>
                            <div class="col-6 col-md-4"><label class="form-label" for="volumen_m3">Volumen (m³)</label>
                                <input class="form-control" type="number" step="0.0001" min="0.0001" max="50" id="volumen_m3" name="volumen_m3" value="0.01"></div>
                            <div class="col-6 col-md-4"><label class="form-label" for="prioridad">Prioridad</label>
                                <select class="form-select" id="prioridad" name="prioridad"><option value="normal">Normal</option><option value="urgente">Urgente</option></select></div>
                            <div class="col-8"><label class="form-label" for="cobro_contra_entrega">Cobro contra entrega</label>
                                <input class="form-control" type="number" step="0.01" min="0" id="cobro_contra_entrega" name="cobro_contra_entrega" value="0"></div>
                            <div class="col-4"><label class="form-label" for="moneda">Moneda</label>
                                <input class="form-control text-uppercase" id="moneda" name="moneda" maxlength="3" value="USD"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Horario y notas</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label" for="ventana_inicio">Ventana: desde</label>
                                <input class="form-control" type="datetime-local" id="ventana_inicio" name="ventana_inicio"></div>
                            <div class="col-md-6"><label class="form-label" for="ventana_fin">Ventana: hasta</label>
                                <input class="form-control" type="datetime-local" id="ventana_fin" name="ventana_fin"></div>
                            <div class="col-12"><label class="form-label" for="hub_id">HUB</label>
                                <select class="form-select" id="hub_id" name="hub_id"><option value="">Sin asignar (se define al ingresar)</option></select></div>
                            <div class="col-12"><label class="form-label" for="notas">Notas</label>
                                <textarea class="form-control" id="notas" name="notas" rows="2" maxlength="500"></textarea></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 justify-content-end mt-3">
            <a class="btn btn-outline-secondary" href="paquetes.php">Cancelar</a>
            <button type="submit" class="btn btn-primary" id="btn-guardar"><i class="bi bi-check-lg"></i> <span id="texto-guardar">Crear paquete</span></button>
        </div>
    </form>
</div>
<?php render_footer(['vendor/leaflet/leaflet.js', 'm3/comun.js', 'm3/paquete_nuevo.js']); ?>
