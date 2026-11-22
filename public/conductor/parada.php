<?php
/**
 * public/conductor/parada.php - M4. Detalle de una parada y registro de entrega/fallo.
 * El id llega por GET; m4/parada.js busca la parada en la última ruta guardada y en el servidor.
 */
require_once __DIR__ . '/../../app/core/bootstrap.php';

$usuario = auth_requerir_pagina(['conductor']);
$paradaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

render_header('Parada', 'm4', ['css' => ['m4.css']]);
?>

<div id="parada-app" class="rp-m4" data-parada-id="<?= $paradaId ?>">
    <a class="btn btn-link px-0 mb-2" href="./">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a mi ruta
    </a>

    <div id="parada-cargando" class="text-center text-muted py-5" role="status">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span> Cargando la parada…
    </div>

    <div id="parada-error" class="alert alert-warning d-none" role="alert"></div>

    <div id="parada-contenido" class="d-none">
        <section class="card mb-3" aria-labelledby="parada-titulo">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <h1 id="parada-titulo" class="h5 mb-0">Parada</h1>
                    <span id="parada-estado" class="badge bg-secondary">—</span>
                </div>
                <div id="parada-detalle" class="mt-3"></div>
                <div id="parada-navegacion" class="d-grid gap-2 mt-3"></div>
            </div>
        </section>

        <div id="acciones-parada" class="d-grid gap-2 mb-3"></div>

        <form id="form-entrega" class="card card-body mb-3 d-none" novalidate>
            <h2 class="h6 mb-3"><i class="bi bi-check2-square" aria-hidden="true"></i> Registrar entrega</h2>

            <div class="mb-3">
                <label class="form-label" for="receptor_nombre">Nombre de quien recibe</label>
                <input class="form-control form-control-lg" id="receptor_nombre" name="receptor_nombre"
                       maxlength="100" autocomplete="name" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="receptor_documento">Documento (opcional)</label>
                <input class="form-control" id="receptor_documento" name="receptor_documento"
                       maxlength="30" inputmode="text" autocomplete="off">
            </div>

            <div class="mb-3" id="bloque-otp">
                <label class="form-label" for="otp">Código de entrega (OTP)</label>
                <div class="input-group input-group-lg">
                    <input class="form-control" id="otp" name="otp" maxlength="10"
                           inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                           aria-describedby="otp-ayuda">
                    <button class="btn btn-outline-primary" type="button" id="btn-otp">Enviar código</button>
                </div>
                <div class="form-text" id="otp-ayuda">El cliente recibe el código por SMS o WhatsApp.</div>
            </div>

            <div class="mb-3 d-none" id="bloque-cobro">
                <div class="alert alert-warning mb-2">
                    Cobro contra entrega: <strong id="monto-cobro">—</strong>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="cobro_realizado">
                    <label class="form-check-label" for="cobro_realizado">Cobro realizado</label>
                </div>
                <div class="form-text">Si no se cobró, usa «No se pudo entregar» con el motivo «Cobro no realizado».</div>
            </div>

            <div class="mb-3">
                <span class="form-label d-block">Firma de quien recibe</span>
                <div class="rp-firma">
                    <canvas id="firma-canvas" aria-label="Área para firmar con el dedo"></canvas>
                </div>
                <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="btn-limpiar-firma">
                    <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar firma
                </button>
            </div>

            <div class="mb-3">
                <label class="form-label" for="foto">Foto de la entrega</label>
                <input class="form-control" type="file" id="foto" name="foto"
                       accept="image/*" capture="environment" required>
                <img id="foto-previa" class="rp-foto-previa d-none mt-2" alt="Vista previa de la foto">
                <div class="form-text">Se reduce a 1600 px antes de subirla. Máximo 5 MB.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="completa" checked>
                <label class="form-check-label" for="completa">Entrega completa</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="nota">Nota (opcional)</label>
                <textarea class="form-control" id="nota" name="nota" rows="2" maxlength="500"></textarea>
            </div>

            <button class="btn btn-success btn-touch w-100" type="submit" id="btn-entregar">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Registrar entrega
            </button>
        </form>

        <form id="form-fallo" class="card card-body mb-3 d-none" novalidate>
            <h2 class="h6 mb-3"><i class="bi bi-x-octagon" aria-hidden="true"></i> No se pudo entregar</h2>

            <div class="mb-3">
                <label class="form-label" for="motivo_fallo">Motivo</label>
                <select class="form-select form-select-lg" id="motivo_fallo" name="motivo_fallo" required>
                    <option value="">Elige un motivo</option>
                    <?php foreach (INCIDENCIA_TIPOS as $clave => $texto): ?>
                        <option value="<?= e($clave) ?>"><?= e($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="nota_fallo">Nota (opcional)</label>
                <textarea class="form-control" id="nota_fallo" name="nota_fallo" rows="2" maxlength="500"></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label" for="foto_fallo">Foto (opcional)</label>
                <input class="form-control" type="file" id="foto_fallo" name="foto_fallo"
                       accept="image/*" capture="environment">
                <img id="foto-fallo-previa" class="rp-foto-previa d-none mt-2" alt="Vista previa de la foto del fallo">
                <div class="form-text" id="foto-fallo-ayuda">Obligatoria si el motivo es «Paquete dañado».</div>
            </div>

            <button class="btn btn-danger btn-touch w-100" type="submit" id="btn-fallar">
                <i class="bi bi-x-circle" aria-hidden="true"></i> Registrar fallo
            </button>
        </form>
    </div>
</div>

<div class="collapse rp-m4-estado" id="panel-estado">
    <div class="card card-body">
        <h2 class="h6">Estado del teléfono</h2>
        <dl class="row small mb-0">
            <dt class="col-6">GPS</dt><dd class="col-6 mb-1" id="gps-estado">Inactivo</dd>
            <dt class="col-6">Precisión</dt><dd class="col-6 mb-1" id="gps-precision">—</dd>
            <dt class="col-6">Puntos GPS sin enviar</dt><dd class="col-6 mb-1" id="gps-pendientes">0</dd>
            <dt class="col-6">Batería</dt><dd class="col-6 mb-1" id="bateria">—</dd>
            <dt class="col-6">Última sincronización</dt><dd class="col-6 mb-0" id="ultima-sincronizacion">—</dd>
        </dl>
    </div>
</div>

<nav class="rp-m4-barra" aria-label="Navegación del conductor">
    <a class="rp-m4-barra__item" href="./">
        <i class="bi bi-signpost-split" aria-hidden="true"></i><span>Ruta</span>
    </a>
    <button class="rp-m4-barra__item" type="button" data-bs-toggle="collapse" data-bs-target="#panel-estado" aria-controls="panel-estado" aria-expanded="false">
        <i class="bi bi-broadcast" aria-hidden="true"></i><span>Estado</span>
    </button>
</nav>
<?php
render_footer([
    'vendor/signature_pad/signature_pad.umd.min.js',
    'gps_tracker.js',
    'm4/comun.js',
    'm4/cola.js',
    'm4/parada.js',
]);
