<?php
require_once __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador']);
render_header('Etiquetas', 'm3', ['css' => ['m3.css']]);
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 rp-no-imprimir">
        <h1 class="h4 mb-0"><i class="bi bi-printer me-2"></i>Guías para imprimir <small class="text-muted fs-6" id="resumen"></small></h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" id="btn-imprimir" disabled><i class="bi bi-printer"></i> Imprimir</button>
            <a class="btn btn-outline-secondary" href="paquetes.php">Volver</a>
        </div>
    </div>
    <p class="small text-muted rp-no-imprimir">Formato 100 × 150 mm (impresora térmica). En el cuadro de impresión desactiva encabezados, pies de página y márgenes.</p>
    <div id="mensaje" class="rp-no-imprimir"></div>
    <div class="rp-etiquetas" id="etiquetas"></div>
</div>
<?php render_footer(['vendor/qrcodejs/qrcode.min.js', 'vendor/jsbarcode/JsBarcode.all.min.js', 'm3/comun.js', 'm3/etiqueta.js']); ?>
