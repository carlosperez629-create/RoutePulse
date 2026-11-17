<?php
/**
 * M8 - Administración de usuarios (rol: admin). Pantalla de Karoline; los endpoints son de Carlos.
 * Los datos se cargan por AJAX desde assets/js/m8/usuarios.js.
 */
require __DIR__ . '/../../app/core/bootstrap.php';

$usuario = auth_requerir_pagina(['admin']);
render_header('Usuarios', 'm8');
?>
<div class="container-fluid py-3" id="pantalla-usuarios" data-uid="<?= (int) $usuario['id'] ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Usuarios</h1>
        <button type="button" class="btn btn-primary" id="btn-nuevo">
            <i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo usuario
        </button>
    </div>

    <form class="row g-2 mb-3" id="filtros" role="search" autocomplete="off">
        <div class="col-12 col-md-5">
            <label for="f-q" class="form-label small mb-1">Buscar</label>
            <input type="search" class="form-control" id="f-q" placeholder="Nombre o correo">
        </div>
        <div class="col-6 col-md-3">
            <label for="f-rol" class="form-label small mb-1">Rol</label>
            <select class="form-select" id="f-rol">
                <option value="">Todos</option>
                <option value="admin">Administrador</option>
                <option value="despachador">Despachador</option>
                <option value="gerente">Gerente</option>
                <option value="conductor">Conductor</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="f-activo" class="form-label small mb-1">Estado</label>
            <select class="form-select" id="f-activo">
                <option value="">Todos</option>
                <option value="1">Activos</option>
                <option value="0">Inactivos</option>
            </select>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle" id="tabla-usuarios">
            <thead>
                <tr>
                    <th scope="col">Nombre</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Rol</th>
                    <th scope="col">Activo</th>
                    <th scope="col">Último acceso</th>
                    <th scope="col">Bloqueado</th>
                    <th scope="col" class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody id="cuerpo-usuarios"></tbody>
        </table>
    </div>
    <div id="paginador"></div>
</div>

<div class="modal fade" id="modal-usuario" tabindex="-1" aria-labelledby="modal-usuario-titulo" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="form-usuario" novalidate>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="modal-usuario-titulo">Nuevo usuario</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="usuario_id" value="">
                <div class="mb-3">
                    <label for="u-nombre" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="u-nombre" name="nombre" maxlength="100" required>
                    <div class="invalid-feedback" data-campo="nombre"></div>
                </div>
                <div class="mb-3">
                    <label for="u-email" class="form-label">Correo</label>
                    <input type="email" class="form-control" id="u-email" name="email" maxlength="150" required>
                    <div class="invalid-feedback" data-campo="email"></div>
                </div>
                <div class="mb-3">
                    <label for="u-rol" class="form-label">Rol</label>
                    <select class="form-select" id="u-rol" name="rol" required>
                        <option value="admin">Administrador</option>
                        <option value="despachador">Despachador</option>
                        <option value="gerente">Gerente</option>
                        <option value="conductor">Conductor</option>
                    </select>
                    <div class="invalid-feedback" data-campo="rol"></div>
                    <div class="form-text" id="u-rol-ayuda"></div>
                </div>
                <div class="mb-3">
                    <label for="u-telefono" class="form-label">Teléfono (opcional)</label>
                    <input type="tel" class="form-control" id="u-telefono" name="telefono" maxlength="30">
                    <div class="invalid-feedback" data-campo="telefono"></div>
                </div>
                <div class="mb-0" id="grupo-password">
                    <label for="u-password" class="form-label">Contraseña (opcional)</label>
                    <input type="password" class="form-control" id="u-password" name="password" autocomplete="new-password">
                    <div class="invalid-feedback" data-campo="password"></div>
                    <div class="form-text">Si la dejas vacía se genera una contraseña temporal, que se muestra una sola vez.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-guardar-usuario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-password" tabindex="-1" aria-labelledby="modal-password-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="modal-password-titulo">Contraseña temporal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Contraseña temporal de <strong id="pw-usuario"></strong>:</p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace" id="pw-valor" readonly aria-label="Contraseña temporal">
                    <button type="button" class="btn btn-outline-secondary" id="pw-copiar">Copiar</button>
                </div>
                <div class="alert alert-warning mb-0" role="alert">
                    Se muestra <strong>una sola vez</strong>. Entrégala al usuario por un medio seguro; deberá cambiarla en su primer acceso.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Listo</button>
            </div>
        </div>
    </div>
</div>
<?php
render_footer(['assets/js/pantalla_util.js', 'assets/js/m8/usuarios.js']);
