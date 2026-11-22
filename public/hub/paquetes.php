<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Paquetes', 'm3', ['css' => ['m3.css']]);
$puedeEscribir = in_array($usuario['rol'], ['admin', 'despachador'], true);
?>
<div class="container-fluid py-3" id="m3-paquetes" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Paquetes</h1>
        <?php if ($puedeEscribir): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="paquete_nuevo.php"><i class="bi bi-plus-lg"></i> Nuevo paquete</a>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-csv"><i class="bi bi-filetype-csv"></i> Importar CSV</button>
            <button type="button" class="btn btn-outline-dark" id="btn-imprimir" disabled><i class="bi bi-printer"></i> Imprimir guías (<span id="num-sel">0</span>)</button>
        </div>
        <?php endif; ?>
    </div>

    <form class="card card-body mb-3" id="filtros" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label small mb-1" for="f-q">Buscar</label>
                <input type="search" class="form-control" id="f-q" name="q" maxlength="100" placeholder="Guía, nombre, teléfono o dirección">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-1" for="f-estado-btn">Estado</label>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle" type="button" id="f-estado-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">Todos</button>
                    <div class="dropdown-menu p-2" id="f-estado-menu"></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-1" for="f-hub">HUB</label>
                <select class="form-select" id="f-hub" name="hub_id"><option value="">Todos</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label small mb-1" for="f-zona">Zona</label>
                <select class="form-select" id="f-zona" name="zona_id"><option value="">Todas</option></select>
            </div>
            <div class="col-6 col-md-4 col-xl-1">
                <label class="form-label small mb-1" for="f-prioridad">Prioridad</label>
                <select class="form-select" id="f-prioridad" name="prioridad">
                    <option value="">Todas</option><option value="normal">Normal</option><option value="urgente">Urgente</option>
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-1">
                <label class="form-label small mb-1" for="f-desde">Desde</label>
                <input type="date" class="form-control" id="f-desde" name="desde">
            </div>
            <div class="col-6 col-md-4 col-xl-1">
                <label class="form-label small mb-1" for="f-hasta">Hasta</label>
                <input type="date" class="form-control" id="f-hasta" name="hasta">
            </div>
            <div class="col-12 col-xl-auto d-flex align-items-center gap-3">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="f-incidencia" name="con_incidencia_abierta" value="1">
                    <label class="form-check-label small" for="f-incidencia">Con incidencia abierta</label>
                </div>
                <button type="button" class="btn btn-link btn-sm" id="btn-limpiar">Limpiar</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="rp-tabla-scroll">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($puedeEscribir): ?><th style="width:2.5rem"><input class="form-check-input" type="checkbox" id="sel-todos" aria-label="Seleccionar todos"></th><?php endif; ?>
                        <th><button type="button" class="btn btn-link p-0 text-decoration-none text-body fw-semibold" data-orden="created_at">Guía</button></th>
                        <th>Destinatario</th>
                        <th>Dirección</th>
                        <th>Zona / HUB</th>
                        <th><button type="button" class="btn btn-link p-0 text-decoration-none text-body fw-semibold" data-orden="prioridad">Prioridad</button></th>
                        <th><button type="button" class="btn btn-link p-0 text-decoration-none text-body fw-semibold" data-orden="estado">Estado</button></th>
                        <th><button type="button" class="btn btn-link p-0 text-decoration-none text-body fw-semibold" data-orden="ventana_fin">Ventana</button></th>
                    </tr>
                </thead>
                <tbody id="tabla-cuerpo"></tbody>
            </table>
        </div>
        <div class="card-footer" id="paginador"></div>
    </div>
</div>

<?php if ($puedeEscribir): ?>
<div class="modal fade" id="modal-csv" tabindex="-1" aria-labelledby="modal-csv-titulo" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="form-csv">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-csv-titulo">Importar paquetes desde CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">CSV UTF-8 separado por comas, máximo 500 filas. La primera fila son los encabezados, con los mismos nombres de los campos de «Nuevo paquete» (por ejemplo <code>remitente_nombre, destinatario_nombre, destinatario_telefono, direccion_entrega, ciudad, codigo_postal, peso_kg</code>).</p>
                <label class="form-label" for="csv-archivo">Archivo</label>
                <input class="form-control" type="file" id="csv-archivo" name="archivo" accept=".csv,text/csv" required>
                <div id="csv-resultado" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-primary" id="csv-enviar">Importar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_footer(['m3/comun.js', 'm3/paquetes.js']); ?>
