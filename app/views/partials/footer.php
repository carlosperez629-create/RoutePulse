<?php
/**
 * Parcial: pie de la página. Lo incluye render_footer() (layout.php); dispone de $rutas
 * (scripts relativos a public/assets/, ya normalizados).
 */
?>
</main>
<?php require RP_RAIZ . '/app/views/partials/mensajes.php'; ?>
<script src="<?= e(rp_asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(rp_asset('js/api.js')) ?>"></script>
<script src="<?= e(rp_asset('js/ui.js')) ?>"></script>
<?php foreach ($rutas as $rutaScript): ?>
<script src="<?= e(rp_asset((string) $rutaScript)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
