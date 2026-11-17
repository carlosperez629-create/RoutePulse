<?php
/**
 * M5 - Portal del cliente: página pública de seguimiento (URL /t/{token}, reescrita a t.php?t={token}).
 * Sin sesión de usuario, sin menú y sin enlaces al resto del sistema. Mobile first (360 px).
 * Los datos llegan por AJAX desde api/tracking/estado.php (assets/js/m5/portal.js).
 */
require __DIR__ . '/../app/core/bootstrap.php';

tracking_cabeceras_publicas();

// Esta página pública solo lee el token de la URL; si no tiene el formato correcto responde 404.
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$tokenValido = tracking_token_valido($token);
if (!$tokenValido) {
    http_response_code(404);
}
$base = rtrim((string) app_cfg('app.url', ''), '/');
$zonaUi = (string) config_get('zona_horaria_ui', 'UTC');
$paradasEnVivo = (int) config_get('portal_paradas_en_vivo', 3);
$diasMax = (int) config_get('reprogramar_dias_max', 7);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Seguimiento de tu paquete · RoutePulse</title>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/estados.css">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/m5.css">
</head>
<body class="bg-light">
<main id="portal" class="portal"
      data-token="<?= e($token) ?>"
      data-token-valido="<?= $tokenValido ? '1' : '0' ?>"
      data-base="<?= e($base) ?>"
      data-zona="<?= e($zonaUi) ?>"
      data-paradas-en-vivo="<?= (int) $paradasEnVivo ?>"
      data-dias-max="<?= (int) $diasMax ?>">

    <header class="portal-cabecera">
        <div class="portal-marca"><i class="bi bi-box-seam" aria-hidden="true"></i> RoutePulse</div>
        <h1 class="portal-titulo">Seguimiento de tu paquete</h1>
        <p class="portal-guia mb-0">Guía <strong id="portal-guia">—</strong></p>
    </header>

    <div id="portal-aviso" class="alert alert-success d-none" role="status"></div>

    <div id="portal-cargando" class="text-center py-5" role="status">
        <div class="spinner-border text-primary" aria-hidden="true"></div>
        <p class="mt-3 mb-0">Consultando tu paquete…</p>
    </div>

    <div id="portal-invalido" class="alert alert-warning d-none" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span id="portal-invalido-texto">Este enlace de seguimiento no es válido o ya venció.</span>
    </div>

    <div id="portal-conexion" class="alert alert-secondary py-2 small d-none" role="status"></div>

    <section id="portal-contenido" class="d-none">
        <div class="portal-tarjeta text-center">
            <span id="portal-estado" class="portal-estado">—</span>
            <p id="portal-mensaje" class="portal-mensaje"></p>
            <p id="portal-eta" class="portal-eta d-none"></p>
            <button id="portal-btn-reprogramar" type="button" class="btn btn-primary btn-lg w-100 d-none">
                <i class="bi bi-calendar-event" aria-hidden="true"></i> Reprogramar entrega
            </button>
            <p id="portal-sin-reprogramar" class="small text-muted mb-0 d-none">
                Comunícate con la empresa para coordinar una nueva entrega.
            </p>
        </div>

        <div id="portal-mapa-tarjeta" class="portal-tarjeta d-none">
            <h2 class="portal-subtitulo">Tu repartidor</h2>
            <p id="portal-paradas" class="mb-2"></p>
            <div id="portal-mapa" class="portal-mapa" role="img" aria-label="Mapa con la posición de tu repartidor"></div>
            <p class="small text-muted mt-2 mb-0">El punto de entrega se muestra de forma aproximada.</p>
        </div>
        <div id="portal-sin-ubicacion" class="portal-tarjeta d-none">
            <p class="mb-0 text-muted"><i class="bi bi-geo-alt" aria-hidden="true"></i> Ubicación no disponible por el momento.</p>
        </div>
        <div id="portal-mapa-pronto" class="portal-tarjeta d-none">
            <p class="mb-0 text-muted"><i class="bi bi-map" aria-hidden="true"></i> El mapa se activará cuando tu repartidor esté cerca.</p>
        </div>

        <div class="portal-tarjeta">
            <h2 class="portal-subtitulo">Historial</h2>
            <ol id="portal-historial" class="portal-linea"></ol>
        </div>

        <p class="portal-direccion small text-muted text-center mb-1">
            Destinatario: <span id="portal-destinatario">—</span> · Dirección: <span id="portal-direccion">—</span>
        </p>
    </section>

    <footer class="portal-pie small text-muted text-center">
        Solo mostramos la información necesaria para seguir tu envío. No compartas este enlace con personas desconocidas.
    </footer>
</main>

<div class="modal fade" id="portal-modal" tabindex="-1" aria-labelledby="portal-modal-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="portal-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="portal-modal-titulo">Reprogramar entrega</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="portal-modal-error" class="alert alert-danger d-none" role="alert"></div>
                <div class="mb-3">
                    <label for="rep-fecha" class="form-label">Fecha</label>
                    <input type="date" class="form-control form-control-lg" id="rep-fecha" name="fecha" required>
                    <div class="invalid-feedback" data-campo="fecha"></div>
                    <div class="form-text">Desde mañana y hasta dentro de <?= (int) $diasMax ?> días.</div>
                </div>
                <div class="mb-3">
                    <label for="rep-franja" class="form-label">Horario</label>
                    <select class="form-select form-select-lg" id="rep-franja" name="franja" required>
                        <option value="manana">Mañana (8:00 a 12:00)</option>
                        <option value="tarde">Tarde (1:00 a 6:00 p. m.)</option>
                        <option value="cualquiera" selected>Cualquier hora (8:00 a 18:00)</option>
                    </select>
                    <div class="invalid-feedback" data-campo="franja"></div>
                </div>
                <div class="mb-0">
                    <label for="rep-nota" class="form-label">Nota (opcional)</label>
                    <textarea class="form-control" id="rep-nota" name="nota" rows="2" maxlength="300"></textarea>
                    <div class="invalid-feedback" data-campo="nota"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="portal-form-enviar">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= e($base) ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e($base) ?>/assets/vendor/leaflet/leaflet.js"></script>
<script src="<?= e($base) ?>/assets/js/m5/portal.js"></script>
</body>
</html>
