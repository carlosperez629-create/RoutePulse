<?php
/**
 * M8 - Parámetros del sistema (rol: admin). Pantalla de Karoline; los endpoints son de Carlos.
 * La tabla se arma por AJAX desde assets/js/m8/configuracion.js.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

auth_requerir_pagina(['admin']);
render_header('Configuración', 'm8');
?>
<div class="container-fluid py-3" id="pantalla-configuracion">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Configuración del sistema</h1>
        <div class="col-12 col-md-4 p-0">
            <label for="f-buscar" class="visually-hidden">Buscar parámetro</label>
            <input type="search" class="form-control" id="f-buscar" placeholder="Buscar por nombre o descripción">
        </div>
    </div>
    <p class="text-muted">Edita un valor y pulsa <strong>Guardar</strong> (o Enter). Cada cambio queda en la auditoría con el valor anterior y el nuevo.</p>
    <div id="grupos"></div>
</div>
<?php
render_footer(['assets/js/pantalla_util.js', 'assets/js/m8/configuracion.js']);
