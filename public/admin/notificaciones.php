<?php
require __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador', 'gerente']);
render_header('Notificaciones', 'm7');
?>
<div id="pantalla-notificaciones" class="container py-3">
  <h1 class="h4">Notificaciones</h1>
  <select id="f-estado" class="form-select form-select-sm mb-2" style="max-width:200px">
    <option value="">Todos los estados</option><option>pendiente</option><option>enviado</option><option>fallido</option><option>cancelado</option>
  </select>
  <table class="table table-sm"><thead><tr><th>#</th><th>Guía</th><th>Tipo</th><th>Canal</th><th>Destino</th><th>Estado</th><th>Int.</th><th>Error</th><th></th></tr></thead><tbody id="filas"></tbody></table>
  <h2 class="h5 mt-3">Log de envíos simulados</h2>
  <pre id="sim" class="border p-2 small" style="max-height:240px;overflow:auto"></pre>
</div>
<?php render_footer(['assets/js/pantalla_util.js', 'assets/js/m7/notificaciones.js']); ?>
