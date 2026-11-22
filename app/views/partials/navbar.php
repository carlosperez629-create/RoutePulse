<?php
/**
 * Parcial: barra de navegación. Lo incluye header.php; dispone de $usuario y $menu.
 */
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark rp-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= e(rp_url(auth_url_inicio($usuario['rol']))) ?>">
            <i class="bi bi-geo-alt-fill" aria-hidden="true"></i> RoutePulse
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#rp-menu"
                aria-controls="rp-menu" aria-expanded="false" aria-label="Mostrar u ocultar el menú">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="rp-menu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
<?php foreach ($menu as $opcion): ?>
                <li class="nav-item">
                    <a class="nav-link<?= rp_menu_activo($opcion['url']) ? ' active' : '' ?>" href="<?= e(rp_url($opcion['url'])) ?>">
                        <i class="bi <?= e($opcion['icono']) ?>" aria-hidden="true"></i> <?= e($opcion['texto']) ?>
                    </a>
                </li>
<?php endforeach; ?>
            </ul>
            <div class="dropdown">
                <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle" aria-hidden="true"></i> <?= e($usuario['nombre']) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text text-muted small"><?= e(rp_rol_texto($usuario['rol'])) ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= e(rp_url('cambiar_password.php')) ?>">Cambiar contraseña</a></li>
                    <li><button class="dropdown-item" type="button" data-accion="logout">Salir</button></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
