<?php
/**
 * RoutePulse - plantilla de configuracion.
 * Copiar a config/config.php (NO versionado) y ajustar los valores.
 * Ver docs: 00-base/08-configuracion-y-entorno.md
 */
return [
    'app' => [
        'entorno'       => 'dev',                            // dev | prod
        'url'           => 'http://localhost/routepulse/public', // sin barra final
        'clave_secreta' => 'CAMBIAR_por_bin2hex_random_bytes_32', // firma del OTP (HMAC)
        // true SOLO si el servidor esta detras de un proxy/balanceador de confianza que envia
        // X-Forwarded-For y X-Forwarded-Proto. En cualquier otro caso dejar en false.
        'proxy_confiable' => false,
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,
        'nombre'  => 'routepulse',
        'usuario' => 'root',
        'clave'   => '',
        // Usuario con DELETE solo sobre puntos_gps, usado por cron/limpiar_gps.php.
        // Vacio en dev: el cron cae al usuario normal. En produccion configurarlo.
        'mantenimiento_usuario' => '',
        'mantenimiento_clave'   => '',
    ],
    'storage' => [
        'ruta' => __DIR__ . '/../storage',
    ],
    // --- Solo produccion / integraciones reales (dejar vacio mientras modo_simulado = 1) ---
    'twilio' => [
        'sid' => '', 'token' => '', 'from_sms' => '', 'from_whatsapp' => '',
    ],
    'meta_whatsapp' => [
        'token' => '', 'phone_id' => '',
    ],
    'smtp' => [
        'host' => '', 'puerto' => 587, 'usuario' => '', 'clave' => '', 'desde' => '',
    ],
    'google_maps' => [
        'api_key' => '',
    ],
    'osrm' => [
        'base_url' => 'https://router.project-osrm.org',
    ],
    'nominatim' => [
        'base_url' => 'https://nominatim.openstreetmap.org',
        'contacto' => '',   // correo de contacto exigido por la politica de uso
    ],
    'webhook_secretos' => [
        'shopify' => '', 'woocommerce' => '', 'magento' => '', 'mercadolibre' => '',
    ],
];
