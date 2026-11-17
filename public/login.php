<?php
/**
 * Pantalla de acceso. Si ya hay sesión, redirige a la página de inicio del rol.
 */
require_once __DIR__ . '/../app/core/bootstrap.php';

$usuario = auth_usuario();
if ($usuario !== null) {
    header('Location: ' . rp_url($usuario['debe_cambiar_password'] ? 'cambiar_password.php' : auth_url_inicio($usuario['rol'])));
    exit;
}

render_header('Iniciar sesión', 'm8', ['navbar' => false]);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-9 col-md-6 col-lg-4">
            <div class="text-center mb-4">
                <h1 class="h3 mb-1"><i class="bi bi-geo-alt-fill text-primary" aria-hidden="true"></i> RoutePulse</h1>
                <p class="text-muted mb-0">Torre de control de última milla</p>
            </div>

<?php if (!empty($_GET['expirada'])): ?>
            <div class="alert alert-warning" role="alert">Tu sesión expiró. Inicia sesión de nuevo.</div>
<?php endif; ?>

            <form id="form-login" class="card card-body shadow-sm" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label">Correo electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" autocomplete="username" maxlength="150" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" maxlength="200" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">Entrar</button>
            </form>
        </div>
    </div>
</div>
<?php
render_footer(['m8/login.js']);
