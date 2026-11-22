<?php
/**
 * Parcial: cabecera de la página. Lo incluye render_header() (layout.php), por eso
 * dispone de: $titulo, $modulo, $usuario, $menu, $mostrarNavbar, $urlBase,
 * $zonaHoraria, $css, $vendorCss.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · RoutePulse</title>
    <meta name="rp-url-base" content="<?= e($urlBase) ?>">
    <meta name="rp-zona-horaria" content="<?= e($zonaHoraria) ?>">
    <meta name="rp-modulo" content="<?= e($modulo) ?>">
<?php if ($modulo === 'm4'): ?>
    <link rel="manifest" href="<?= e(rp_url('conductor/manifest.json')) ?>">
    <meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="<?= e(rp_asset('img/icon-192.png')) ?>">
<?php endif; ?>
    <link rel="stylesheet" href="<?= e(rp_asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(rp_asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<?php foreach ($vendorCss as $archivoVendor): ?>
    <link rel="stylesheet" href="<?= e(rp_asset('vendor/' . ltrim((string) $archivoVendor, '/'))) ?>">
<?php endforeach; ?>
    <link rel="stylesheet" href="<?= e(rp_asset('css/estados.css')) ?>">
    <link rel="stylesheet" href="<?= e(rp_asset('css/app.css')) ?>">
<?php foreach ($css as $archivoCss): ?>
    <link rel="stylesheet" href="<?= e(rp_asset('css/' . ltrim((string) $archivoCss, '/'))) ?>">
<?php endforeach; ?>
</head>
<body class="rp-modulo-<?= e($modulo) ?>">
<?php if ($mostrarNavbar && $usuario !== null) {
    require RP_RAIZ . '/app/views/partials/navbar.php';
} ?>
<main class="rp-contenido">
