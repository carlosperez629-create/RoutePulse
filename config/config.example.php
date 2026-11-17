<?php
/**
 * RoutePulse - plantilla de configuración.
 * Copiar a config/config.php (NO versionado) y ajustar los valores.
 * Ver docs: 00-base/08-configuracion-y-entorno.md
 */
return [
    'app' => [
        'entorno'       => 'dev',                            // dev | prod
        'url'           => 'http://localhost/routepulse/public', // sin barra final
        'clave_secreta' => 'CAMBIAR_por_bin2hex_random_bytes_32', // firma del OTP (HMAC)
        // true SOLO si el servidor está detrás de un proxy/balanceador de confianza que envía
        // X-Forwarded-For y X-Forwarded-Proto. En cualquier otro caso dejar en false.
        'proxy_confiable' => false,
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,
        'nombre'  => 'routepulse',
        'usuario' => 'root',
        'clave'   => '',
    ],
    'storage' => [
        'ruta' => __DIR__ . '/../storage',
    ],
    // --- Solo producción / integraciones reales (dejar vacío mientras modo_simulado = 1) ---
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
        'contacto' => '',   // correo de contacto exigido por la política de uso
    ],
    'webhook_secretos' => [
        'shopify' => '', 'woocommerce' => '', 'magento' => '', 'mercadolibre' => '',
    ],
];
