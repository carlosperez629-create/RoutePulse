<?php
/**
 * Parcial: contenedor de avisos (toasts) y ventana de confirmación. Lo incluye footer.php.
 * Los usa ui.toast() y ui.confirmar() de assets/js/ui.js.
 */
?>
<div id="rp-toasts" class="toast-container position-fixed top-0 end-0 p-3" aria-live="polite" aria-atomic="true"></div>

<div class="modal fade" id="rp-confirmar" tabindex="-1" aria-hidden="true" aria-labelledby="rp-confirmar-titulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="rp-confirmar-titulo" data-rp="titulo">Confirmar</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" data-rp="texto"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" data-rp="ok">Aceptar</button>
            </div>
        </div>
    </div>
</div>
