<?php
/**
 * Monitor de flota en vivo (M2). Roles: admin, despachador, gerente (solo lectura).
 * Los datos se cargan por AJAX desde assets/js/m2/flota.js (gps/flota.php cada monitor_refresh_s).
 */
require __DIR__ . '/../../app/core/bootstrap.php';
auth_requerir_pagina(['admin', 'despachador', 'gerente']);

// HUB para dibujarlo en el mapa (si el servicio de M3 ya existe).
$hubs = function_exists('hub_listar') ? hub_listar(true) : [];
$hub = $hubs[0] ?? null;

render_header('Monitor de flota', 'monitor');
?>
<link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="../assets/css/monitor.css">

<div id="monitor" class="monitor"
     data-refresh="<?= e((string) config_get('monitor_refresh_s', 5)) ?>"
     data-zona-horaria="<?= e((string) config_get('zona_horaria_ui', 'UTC')) ?>"
<?php if ($hub): ?>
     data-hub-lat="<?= e((string) $hub['lat']) ?>"
     data-hub-lng="<?= e((string) $hub['lng']) ?>"
     data-hub-nombre="<?= e((string) $hub['nombre']) ?>"
<?php endif; ?>>

    <div class="monitor-barra d-flex flex-wrap align-items-end gap-2 p-2 border-bottom bg-body">
        <div>
            <label for="filtro-zona" class="form-label mb-0 small">Zona</label>
            <select id="filtro-zona" class="form-select form-select-sm">
                <option value="">Todas</option>
            </select>
        </div>
        <div>
            <label for="filtro-senal" class="form-label mb-0 small">Señal</label>
            <select id="filtro-senal" class="form-select form-select-sm">
                <option value="">Todas</option>
                <option value="en_linea">En línea</option>
                <option value="demorado">Demorado</option>
                <option value="sin_senal">Sin señal</option>
            </select>
        </div>
        <button type="button" id="btn-centrar" class="btn btn-outline-secondary btn-sm">Ver toda la flota</button>
        <button type="button" id="btn-pantalla-completa" class="btn btn-outline-secondary btn-sm">Pantalla completa</button>
        <a href="../hub/incidencias.php" class="btn btn-outline-danger btn-sm">Incidencias abiertas</a>
        <div id="monitor-estado" class="ms-auto small text-muted" role="status" aria-live="polite"></div>
    </div>

    <div class="monitor-cuerpo">
        <aside id="monitor-panel" class="monitor-panel list-group list-group-flush" aria-label="Rutas en curso">
            <div class="p-3 text-muted" id="monitor-cargando">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Cargando flota…
            </div>
        </aside>
        <div id="mapa" class="monitor-mapa" aria-label="Mapa de la flota"></div>
    </div>
</div>

<script src="../assets/vendor/leaflet/leaflet.js"></script>
<script src="../assets/js/mapa_util.js"></script>
<script src="../assets/js/m2/flota.js"></script>
<?php render_footer(); ?>
