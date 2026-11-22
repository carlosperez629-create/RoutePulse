<?php
/**
 * M8 - Bitácora de auditoría (roles: admin, gerente). Pantalla de Karoline; el endpoint es de Carlos.
 * La tabla y los filtros se manejan por AJAX desde assets/js/m8/auditoria.js.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

$usuario = auth_requerir_pagina(['admin', 'gerente']);
render_header('Auditoría', 'm8');
?>
<div class="container-fluid py-3" id="pantalla-auditoria" data-rol="<?= e($usuario['rol']) ?>">
    <h1 class="h3 mb-3">Auditoría</h1>

    <form class="row g-2 mb-3" id="filtros" autocomplete="off">
        <div class="col-12 col-md-3" id="grupo-usuario">
            <label for="f-usuario" class="form-label small mb-1">Usuario</label>
        </div>
        <div class="col-12 col-md-3">
            <label for="f-accion" class="form-label small mb-1">Acción</label>
            <select class="form-select" id="f-accion"><option value="">Todas</option></select>
        </div>
        <div class="col-12 col-md-2">
            <label for="f-entidad" class="form-label small mb-1">Entidad</label>
            <input type="text" class="form-control" id="f-entidad" maxlength="40" placeholder="usuario, paquete…">
        </div>
        <div class="col-6 col-md-2">
            <label for="f-desde" class="form-label small mb-1">Desde</label>
            <input type="date" class="form-control" id="f-desde">
        </div>
        <div class="col-6 col-md-2">
            <label for="f-hasta" class="form-label small mb-1">Hasta</label>
            <input type="date" class="form-control" id="f-hasta">
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <button type="button" class="btn btn-outline-secondary" id="btn-limpiar">Limpiar</button>
        </div>
        <div class="invalid-feedback d-block" id="error-filtros" data-campo="filtros"></div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Usuario</th>
                    <th scope="col">Acción</th>
                    <th scope="col">Entidad</th>
                    <th scope="col">IP</th>
                    <th scope="col" class="text-end">Detalle</th>
                </tr>
            </thead>
            <tbody id="cuerpo-auditoria"></tbody>
        </table>
    </div>
    <div id="paginador"></div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="panel-detalle" aria-labelledby="panel-detalle-titulo">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title fs-5" id="panel-detalle-titulo">Detalle del registro</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body">
        <dl class="row small" id="detalle-resumen"></dl>
        <h3 class="h6">Detalle (JSON)</h3>
        <pre class="bg-light border rounded p-2 small" id="detalle-json"></pre>
    </div>
</div>
<?php
render_footer(['assets/js/pantalla_util.js', 'assets/js/m8/auditoria.js']);
