<?php
/**
 * Cambio de contraseña propio. Es obligatorio cuando el usuario tiene
 * debe_cambiar_password = 1 (no puede ir a otra pantalla hasta cambiarla).
 */
require_once __DIR__ . '/../app/core/bootstrap.php';

$usuario = auth_requerir_pagina(['*'], ['permitir_cambio_pendiente' => true]);
$minimo = max(8, (int) config_get('password_min_largo', 10));

render_header('Cambiar contraseña', 'm8');
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <h1 class="h4 mb-3">Cambiar contraseña</h1>

<?php if ($usuario['debe_cambiar_password']): ?>
            <div class="alert alert-info" role="alert">Por seguridad, debes cambiar tu contraseña antes de continuar.</div>
<?php endif; ?>

            <form id="form-password" class="card card-body shadow-sm" novalidate>
                <div class="mb-3">
                    <label for="password_actual" class="form-label">Contraseña actual</label>
                    <input type="password" class="form-control" id="password_actual" name="password_actual" autocomplete="current-password" maxlength="200" required>
                </div>
                <div class="mb-3">
                    <label for="password_nueva" class="form-label">Contraseña nueva</label>
                    <input type="password" class="form-control" id="password_nueva" name="password_nueva" autocomplete="new-password" maxlength="200" required>
                    <div class="form-text">Mínimo <?= (int) $minimo ?> caracteres, con al menos una letra y un número, y sin incluir tu correo.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmar" class="form-label">Confirmar contraseña nueva</label>
                    <input type="password" class="form-control" id="password_confirmar" name="password_confirmar" autocomplete="new-password" maxlength="200" required>
                </div>
                <button type="submit" class="btn btn-primary">Guardar contraseña</button>
            </form>
        </div>
    </div>
</div>
<?php
render_footer(['m8/cambiar_password.js']);
