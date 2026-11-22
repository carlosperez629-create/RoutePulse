<?php
require __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin']);
render_header('Integraciones', 'm7');
?>
<div id="pantalla-integraciones" class="container py-3">
  <h1 class="h4">Integraciones (M7)</h1>
  <div id="msg" class="alert alert-info d-none"></div>
  <table class="table table-sm align-middle"><thead><tr><th>Código</th><th>Tipo</th><th>Activa</th><th>Simulada</th><th></th></tr></thead><tbody id="filas"></tbody></table>
  <h2 class="h5 mt-4">Pruebas rápidas</h2>
  <div class="d-flex gap-2 flex-wrap mb-2">
    <button class="btn btn-outline-primary btn-sm" id="t-geo" type="button">Geocodificar</button>
    <button class="btn btn-outline-primary btn-sm" id="t-matriz" type="button">Matriz de rutas</button>
    <input id="t-paq" class="form-control form-control-sm" style="max-width:140px" placeholder="ID paquete">
    <button class="btn btn-outline-primary btn-sm" id="t-notif" type="button">Enviar notificación</button>
  </div>
  <pre id="salida" class="border p-2 small"></pre>
  <h2 class="h5 mt-4">Webhooks recibidos</h2>
  <table class="table table-sm"><thead><tr><th>#</th><th>Origen</th><th>Pedido</th><th>Estado</th><th>Error</th><th></th></tr></thead><tbody id="webhooks"></tbody></table>
  <h2 class="h5 mt-4">Log de integraciones</h2>
  <pre id="log" class="border p-2 small" style="max-height:240px;overflow:auto"></pre>
</div>
<?php render_footer(['assets/js/pantalla_util.js', 'assets/js/m7/integraciones.js']); ?>
