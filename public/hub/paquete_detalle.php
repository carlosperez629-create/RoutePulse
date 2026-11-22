<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
$usuario = auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Detalle del paquete', 'm3', ['css' => ['m3.css']]);
$puedeEscribir = in_array($usuario['rol'], ['admin', 'despachador'], true);
?>
<div class="container py-3" id="m3-detalle" data-escribe="<?= $puedeEscribir ? '1' : '0' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i><span id="d-guia">Paquete</span> <span id="d-estado"></span></h1>
        <div class="d-flex flex-wrap gap-2" id="acciones">
            <a class="btn btn-outline-secondary btn-sm" href="paquetes.php"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
    <div id="mensaje"></div>
    <div id="contenido">
        <div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Cargando…</div>
    </div>
</div>
<?php render_footer(['m3/comun.js', 'm3/paquete_detalle.js']); ?>
