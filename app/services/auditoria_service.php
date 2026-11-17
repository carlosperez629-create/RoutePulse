<?php
/**
 * RoutePulse - Servicio de auditoría (M8).
 *
 * audit() registra en log_auditoria quién hizo qué. Catálogo de acciones:
 * docs/modulos/M8-seguridad-rbac.md, sección 6.7 (todos los módulos usan esos nombres).
 *
 * Un fallo al auditar NUNCA interrumpe la operación: se registra con log_app().
 */

/**
 * @param string          $accion     Ej.: 'paquete_cambiar_estado'
 * @param string          $entidad    Ej.: 'paquete'
 * @param int|string|null $entidadId  Id de la entidad afectada
 * @param array           $detalle    Datos adicionales (se guardan como JSON; sin contraseñas ni tokens)
 * @param int|null        $usuarioId  Si se omite se usa el usuario de la sesión (o null si no hay)
 */
function audit(string $accion, string $entidad, int|string|null $entidadId = null, array $detalle = [], ?int $usuarioId = null): void {
    try {
        if ($usuarioId === null && !empty($_SESSION['usuario_id'])) {
            $usuarioId = (int) $_SESSION['usuario_id'];
        }

        $json = null;
        if ($detalle !== []) {
            $codificado = json_encode(
                rp_ocultar_sensibles($detalle),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            $json = $codificado === false ? null : $codificado;
        }

        $agente = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        db_insert(
            'INSERT INTO log_auditoria (usuario_id, accion, entidad, entidad_id, detalle, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss',
            [
                $usuarioId,
                mb_substr($accion, 0, 60),
                mb_substr($entidad, 0, 40),
                $entidadId === null ? null : mb_substr((string) $entidadId, 0, 40),
                $json,
                rp_ip_cliente(),
                $agente,
            ]
        );
    } catch (Throwable $e) {
        log_app('error', 'No se pudo registrar la auditoría (' . $accion . '): ' . $e->getMessage());
    }
}
