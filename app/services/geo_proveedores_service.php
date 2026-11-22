<?php
/** M7 - geocodificación y rutas (simulado por defecto; OSRM/Nominatim cuando modo_simulado=0). */

function geocodificar(string $direccion, ?string $ciudad = null): ?array
{
    $texto = trim($direccion . ($ciudad ? ', ' . $ciudad : ''));
    if ($texto === '') { return null; }
    $hash = hash('sha256', mb_strtolower($texto));
    $dir = rp_ruta_storage('cache/geocode');
    $archivo = $dir . '/' . $hash . '.json';
    if (is_file($archivo)) {
        $c = json_decode((string) @file_get_contents($archivo), true);
        if (is_array($c)) { return $c; }
    }
    if (integracion_simulada('google_maps') && integracion_simulada('osrm')) {
        $a = hexdec(substr($hash, 0, 6)) / 0xFFFFFF;
        $b = hexdec(substr($hash, 6, 6)) / 0xFFFFFF;
        $r = ['lat' => round(10.5571993 + ($a - 0.5) * 0.06, 7), 'lng' => round(-71.6490662 + ($b - 0.5) * 0.06, 7), 'confianza' => 0.5, 'proveedor' => 'simulado'];
    } else {
        $base = rtrim((string) app_cfg('nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $res = integracion_http('GET', $base . '/search?format=json&limit=1&q=' . rawurlencode($texto), null,
            ['User-Agent: RoutePulse/1.0 ' . app_cfg('nominatim.contacto', '')]);
        $j = $res['cuerpo'] ? json_decode($res['cuerpo'], true) : null;
        if (!is_array($j) || empty($j[0]['lat'])) {
            integracion_log('osrm', 'geocodificar_fallo', ['q' => $texto, 'http' => $res['http']]);
            return null;
        }
        $r = ['lat' => (float) $j[0]['lat'], 'lng' => (float) $j[0]['lon'], 'confianza' => (float) ($j[0]['importance'] ?? 0.6), 'proveedor' => 'nominatim'];
    }
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($archivo, json_encode($r));
    return $r;
}

function ruta_matriz_haversine(array $puntos, string $proveedor): array
{
    $v = max(1.0, (float) config_get('eta_velocidad_media_kmh', 25)) / 3.6;
    $n = count($puntos);
    $dist = $dur = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $d = $i === $j ? 0.0 : geo_distancia_m((float) $puntos[$i]['lat'], (float) $puntos[$i]['lng'], (float) $puntos[$j]['lat'], (float) $puntos[$j]['lng']) * 1.3;
            $dist[$i][$j] = $d;
            $dur[$i][$j] = $d / $v;
        }
    }
    return ['distancias_m' => $dist, 'duraciones_s' => $dur, 'proveedor' => $proveedor];
}

function ruta_matriz(array $puntos): array
{
    $puntos = array_values($puntos);
    if (count($puntos) < 2 || integracion_simulada('osrm')) {
        return ruta_matriz_haversine($puntos, 'simulado');
    }
    $coords = implode(';', array_map(fn($p) => $p['lng'] . ',' . $p['lat'], $puntos));
    $base = rtrim((string) app_cfg('osrm.base_url', 'https://router.project-osrm.org'), '/');
    $res = integracion_http('GET', "$base/table/v1/driving/$coords?annotations=duration,distance");
    $j = $res['cuerpo'] ? json_decode($res['cuerpo'], true) : null;
    if (!is_array($j) || ($j['code'] ?? '') !== 'Ok' || empty($j['durations'])) {
        integracion_log('osrm', 'matriz_fallo', ['http' => $res['http'], 'error' => $res['error']]);
        return ruta_matriz_haversine($puntos, 'respaldo');
    }
    return ['distancias_m' => $j['distances'] ?? ruta_matriz_haversine($puntos, 'respaldo')['distancias_m'], 'duraciones_s' => $j['durations'], 'proveedor' => 'osrm'];
}

/** Lista de [lat,lng] siguiendo calles, o null si no hay proveedor real. */
function ruta_geometria(array $puntos): ?array
{
    $puntos = array_values($puntos);
    if (count($puntos) < 2 || integracion_simulada('osrm')) { return null; }
    $coords = implode(';', array_map(fn($p) => (is_array($p) && isset($p['lng']) ? $p['lng'] . ',' . $p['lat'] : $p[1] . ',' . $p[0]), $puntos));
    $base = rtrim((string) app_cfg('osrm.base_url', 'https://router.project-osrm.org'), '/');
    $res = integracion_http('GET', "$base/route/v1/driving/$coords?overview=full&geometries=geojson");
    $j = $res['cuerpo'] ? json_decode($res['cuerpo'], true) : null;
    $g = $j['routes'][0]['geometry']['coordinates'] ?? null;
    return is_array($g) ? array_map(fn($c) => [(float) $c[1], (float) $c[0]], $g) : null;
}
