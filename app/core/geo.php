<?php
/**
 * RoutePulse - Utilidades geográficas, de fechas y de identificadores.
 */

/**
 * Distancia en metros entre dos coordenadas (fórmula de Haversine).
 */
function geo_distancia_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $radioTierra = 6371000.0;
    $f1 = deg2rad($lat1);
    $f2 = deg2rad($lat2);
    $df = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);

    $a = sin($df / 2) ** 2 + cos($f1) * cos($f2) * sin($dl / 2) ** 2;
    return 2 * $radioTierra * asin(min(1.0, sqrt($a)));
}

/**
 * ¿El punto está dentro del anillo? (ray casting). Los puntos del anillo son [lng, lat].
 */
function rp_punto_en_anillo(float $lat, float $lng, array $anillo): bool {
    $dentro = false;
    $n = count($anillo);
    if ($n < 3) {
        return false;
    }
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        if (!isset($anillo[$i][0], $anillo[$i][1], $anillo[$j][0], $anillo[$j][1])) {
            return false;
        }
        $xi = (float) $anillo[$i][0];
        $yi = (float) $anillo[$i][1];
        $xj = (float) $anillo[$j][0];
        $yj = (float) $anillo[$j][1];

        if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $dentro = !$dentro;
        }
    }
    return $dentro;
}

/**
 * ¿El punto está dentro de un GeoJSON de tipo Polygon (o un Feature con Polygon)?
 * El primer anillo es el exterior; los siguientes son huecos.
 */
function geo_punto_en_poligono(float $lat, float $lng, array $geojson): bool {
    if (isset($geojson['type']) && $geojson['type'] === 'Feature' && isset($geojson['geometry']) && is_array($geojson['geometry'])) {
        $geojson = $geojson['geometry'];
    }
    if (!isset($geojson['type'], $geojson['coordinates']) || $geojson['type'] !== 'Polygon' || !is_array($geojson['coordinates'])) {
        return false;
    }

    $anillos = $geojson['coordinates'];
    if ($anillos === [] || !is_array($anillos[0]) || !rp_punto_en_anillo($lat, $lng, $anillos[0])) {
        return false;
    }
    for ($k = 1; $k < count($anillos); $k++) {
        if (is_array($anillos[$k]) && rp_punto_en_anillo($lat, $lng, $anillos[$k])) {
            return false;
        }
    }
    return true;
}

/**
 * Fecha y hora actual en UTC: "Y-m-d H:i:s".
 */
function fecha_utc_ahora(): string {
    return gmdate('Y-m-d H:i:s');
}

/**
 * "Y-m-d H:i:s[.fff]" en UTC → ISO 8601 "2026-11-13T15:04:05Z" (con milisegundos si los tiene).
 * Una fecha sin hora ("Y-m-d") se devuelve igual. Devuelve null si no es una fecha válida.
 */
function fecha_a_iso(?string $fechaUtc): ?string {
    if ($fechaUtc === null || $fechaUtc === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaUtc) === 1) {
        return $fechaUtc === '0000-00-00' ? null : $fechaUtc;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.(\d{1,6}))?$/', $fechaUtc, $m) !== 1) {
        return null;
    }
    if ($m[1] === '0000-00-00') {
        return null;
    }
    $milis = '';
    if (isset($m[4]) && $m[4] !== '') {
        $tresDigitos = substr(str_pad($m[4], 3, '0'), 0, 3);
        if ($tresDigitos !== '000') {
            $milis = '.' . $tresDigitos;
        }
    }
    return $m[1] . 'T' . $m[2] . $milis . 'Z';
}

/**
 * UUID versión 4.
 */
function uuid_v4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}
