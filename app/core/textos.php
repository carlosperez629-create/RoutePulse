<?php
/**
 * RoutePulse - Textos en español de estados, tipos de incidencia y roles.
 * Fuente: docs/00-base/04-estados-y-reglas.md. Nunca se muestra al usuario el
 * valor interno (en_clasificacion): siempre su texto.
 */

function rp_estados_texto(): array {
    return [
        // Paquete
        'creado'           => 'Creado',
        'ingresado_hub'    => 'Ingresado en HUB',
        'en_clasificacion' => 'En clasificación',
        'asignado_ruta'    => 'Asignado a ruta',
        'en_transito'      => 'En tránsito',
        'entregado'        => 'Entregado',
        'incidencia'       => 'Incidencia',
        'cancelado'        => 'Cancelado',
        'devuelto'         => 'Devuelto',
        // Ruta
        'borrador'         => 'Borrador',
        'planificada'      => 'Planificada',
        'en_curso'         => 'En curso',
        'completada'       => 'Completada',
        'cancelada'        => 'Cancelada',
        // Parada
        'pendiente'        => 'Pendiente',
        'en_camino'        => 'En camino',
        'llegado'          => 'Llegó',
        'entregada'        => 'Entregada',
        'fallida'          => 'Fallida',
        'omitida'          => 'Omitida',
    ];
}

function rp_estado_texto(string $estado): string {
    $mapa = rp_estados_texto();
    return $mapa[$estado] ?? $estado;
}

function rp_incidencias_texto(): array {
    return [
        'direccion_no_encontrada' => 'Dirección no encontrada',
        'cliente_ausente'         => 'Cliente ausente',
        'cobro_no_realizado'      => 'Cobro no realizado',
        'rechazado_por_cliente'   => 'Rechazado por el cliente',
        'paquete_danado'          => 'Paquete dañado',
        'zona_insegura'           => 'Zona insegura',
        'otro'                    => 'Otro',
    ];
}

function rp_incidencia_texto(string $tipo): string {
    $mapa = rp_incidencias_texto();
    return $mapa[$tipo] ?? $tipo;
}

function rp_rol_texto(string $rol): string {
    $mapa = [
        'admin'       => 'Administrador',
        'despachador' => 'Despachador',
        'gerente'     => 'Gerente de logística',
        'conductor'   => 'Conductor',
    ];
    return $mapa[$rol] ?? $rol;
}
