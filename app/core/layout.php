<?php
/**
 * RoutePulse - Plantilla común de pantallas.
 *
 * Una pantalla típica:
 *     require_once __DIR__ . '/../../app/core/bootstrap.php';
 *     $usuario = auth_requerir_pagina(['admin', 'despachador']);
 *     render_header('Paquetes', 'm3');
 *     ... HTML (Bootstrap) con contenedores vacíos ...
 *     render_footer(['m3/paquetes.js']);
 *
 * La CSP del proyecto NO permite scripts en línea: toda la lógica va en archivos .js.
 */

/**
 * URL de un recurso dentro de public/assets/ con "?v=<fecha de modificación>" para
 * que el navegador recargue el archivo cuando cambia.
 */
function rp_asset(string $ruta): string {
    $ruta = ltrim($ruta, '/');
    $archivo = RP_RAIZ . '/public/assets/' . $ruta;
    $url = rp_url('assets/' . $ruta);
    if (is_file($archivo)) {
        $url .= '?v=' . filemtime($archivo);
    }
    return $url;
}

/**
 * ¿La opción del menú corresponde a la página actual?
 */
function rp_menu_activo(string $urlRelativa): bool {
    $actual = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $opcion = (string) parse_url(rp_url($urlRelativa), PHP_URL_PATH);
    if ($opcion === '' || $actual === '') {
        return false;
    }
    return $actual === $opcion || rtrim($actual, '/') === rtrim($opcion, '/');
}

/**
 * Abre la página: <head>, hojas de estilo, barra de navegación y <main>.
 *
 * @param string $titulo  Título de la pestaña
 * @param string $modulo  Módulo de la pantalla (m1..m8), para estilos y scripts
 * @param array  $opciones
 *     'navbar'     => bool   mostrar la barra de navegación (por defecto sí; false en login)
 *     'css'        => array  hojas de estilo propias, relativas a assets/css/ (ej. ['m3.css'])
 *     'vendor_css' => array  hojas de librerías, relativas a assets/vendor/ (ej. ['leaflet/leaflet.css'])
 */
function render_header(string $titulo, string $modulo, array $opciones = []): void {
    $mostrarNavbar = array_key_exists('navbar', $opciones) ? (bool) $opciones['navbar'] : true;
    $usuario = $mostrarNavbar ? auth_usuario() : null;
    $menu = $usuario !== null ? auth_menu_por_rol($usuario['rol']) : [];
    $urlBase = rtrim((string) app_cfg('app.url', ''), '/');
    $zonaHoraria = (string) config_get('zona_horaria_ui', 'UTC');
    $css = isset($opciones['css']) && is_array($opciones['css']) ? $opciones['css'] : [];
    $vendorCss = isset($opciones['vendor_css']) && is_array($opciones['vendor_css']) ? $opciones['vendor_css'] : [];

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    require RP_RAIZ . '/app/views/partials/header.php';
}

/**
 * Cierra la página y carga los scripts.
 *
 * @param array $scripts Rutas relativas a assets/js/ (ej. 'm3/paquetes.js'). Si empiezan con
 *                       'vendor/' o 'js/' se toman relativas a assets/ (ej. 'vendor/leaflet/leaflet.js').
 *                       Se cargan en el orden dado, después de bootstrap.bundle, api.js y ui.js.
 */
function render_footer(array $scripts = []): void {
    $rutas = [];
    foreach ($scripts as $script) {
        $script = (string) $script;
        $rutas[] = (str_starts_with($script, 'vendor/') || str_starts_with($script, 'js/')) ? $script : 'js/' . $script;
    }
    require RP_RAIZ . '/app/views/partials/footer.php';
}
