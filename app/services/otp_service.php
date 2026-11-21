<?php
/**
 * otp_service.php - M4 App del conductor: código de entrega (OTP).
 * Contrato: 00-base/07-servicios-internos.md, sección F. Reglas: modulos/M4-app-conductor.md, §7.2.
 *
 * El OTP vigente de cada paquete vive en las columnas otp_* de `paquetes` (las escribe SOLO este servicio):
 *   otp_hash, otp_expira_at, otp_intentos, otp_solicitudes, otp_ventana_at.
 * El código en claro nunca se guarda en `paquetes`: solo su HMAC-SHA256 con app.clave_secreta.
 * La propiedad del paquete (que la parada sea de la ruta del conductor) la valida el endpoint/servicio que llama.
 */

/**
 * Clave secreta para el HMAC. Falla (ERROR_INTERNO) si falta o, en producción, si sigue el valor de ejemplo.
 */
function otp_clave(): string
{
    $clave = (string) app_cfg('app.clave_secreta', '');
    $esEjemplo = strpos($clave, 'CAMBIAR') === 0;
    if ($clave === '' || ($esEjemplo && app_cfg('app.entorno') === 'prod')) {
        log_app('error', 'otp_service: app.clave_secreta no está configurada correctamente');
        lanzar_error('ERROR_INTERNO', 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.', 500);
    }
    return $clave;
}

/**
 * HMAC-SHA256 del código (lo único que se guarda).
 */
function otp_hash_codigo(string $codigo): string
{
    return hash_hmac('sha256', $codigo, otp_clave());
}

/**
 * Genera un OTP nuevo, lo guarda (sobrescribiendo el anterior) y lo envía al destinatario con notificar().
 *
 * Límite: otp_max_por_hora solicitudes por paquete dentro de una ventana de 1 hora (si no, LIMITE 429).
 * Un OTP nuevo reinicia otp_intentos a 0.
 *
 * @return array{canal:?string, expira_at:string, otp_debug?:string}
 *         otp_debug (el código) solo se incluye con app.entorno = dev y mensajería simulada.
 * @throws AppError NO_ENCONTRADO, LIMITE, PROVEEDOR_EXTERNO (no hay canal para enviarlo) o ERROR_INTERNO.
 */
function otp_generar_y_enviar(int $paqueteId): array
{
    $longitud = max(4, min(10, (int) config_get('otp_longitud', 6)));
    $expiraMin = max(1, (int) config_get('otp_expira_min', 30));
    $maxPorHora = max(1, (int) config_get('otp_max_por_hora', 3));

    $codigo = str_pad((string) random_int(0, (10 ** $longitud) - 1), $longitud, '0', STR_PAD_LEFT);
    $hash = otp_hash_codigo($codigo);   // falla antes de tocar la base si no hay clave
    $ahora = time();
    $expiraAt = gmdate('Y-m-d H:i:s', $ahora + $expiraMin * 60);

    db_tx(static function () use ($paqueteId, $hash, $expiraAt, $ahora, $maxPorHora): void {
        $p = db_one('SELECT id, otp_solicitudes, otp_ventana_at FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);
        if ($p === null) {
            error_no_encontrado('El paquete');
        }

        $inicioVentana = $p['otp_ventana_at'] === null ? null : strtotime($p['otp_ventana_at'] . ' UTC');
        if ($inicioVentana === null || $inicioVentana + 3600 <= $ahora) {
            // Ventana vacía o vencida: se reinicia.
            $solicitudes = 1;
            $ventanaAt = gmdate('Y-m-d H:i:s', $ahora);
        } else {
            if ((int) $p['otp_solicitudes'] >= $maxPorHora) {
                lanzar_error('LIMITE', 'Ya se enviaron ' . $maxPorHora . ' códigos para este paquete en la última hora. Intenta más tarde.', 429);
            }
            $solicitudes = (int) $p['otp_solicitudes'] + 1;
            $ventanaAt = $p['otp_ventana_at'];
        }

        db_exec(
            'UPDATE paquetes SET otp_hash = ?, otp_expira_at = ?, otp_intentos = 0, otp_solicitudes = ?, otp_ventana_at = ? WHERE id = ?',
            'ssisi',
            [$hash, $expiraAt, $solicitudes, $ventanaAt, $paqueteId]
        );
    });

    // El envío va fuera de la transacción (puede tardar). notificar() no lanza errores: devuelve cuántas filas creó.
    $creadas = 0;
    try {
        $creadas = notificar($paqueteId, 'otp', ['otp' => $codigo]);
    } catch (Throwable $e) {
        log_app('error', 'otp_service: notificar falló', ['paquete_id' => $paqueteId, 'error' => $e->getMessage()]);
    }
    if ($creadas < 1) {
        lanzar_error('PROVEEDOR_EXTERNO', 'No pudimos enviar el código al cliente. Intenta de nuevo o avisa al despachador.', 502);
    }

    $envio = db_one(
        'SELECT canal, proveedor_mensaje_id FROM notificaciones WHERE paquete_id = ? AND tipo = \'otp\' ORDER BY id DESC LIMIT 1',
        'i',
        [$paqueteId]
    );
    $respuesta = [
        'canal'     => $envio['canal'] ?? null,
        'expira_at' => fecha_a_iso($expiraAt),
    ];
    $simulado = $envio !== null && strpos((string) $envio['proveedor_mensaje_id'], 'SIM-') === 0;
    if (app_cfg('app.entorno') === 'dev' && $simulado) {
        $respuesta['otp_debug'] = $codigo;
    }
    return $respuesta;
}

/**
 * Valida el código contra el OTP vigente del paquete.
 *
 *  - Sin OTP vigente (otp_hash vacío) o vencido: false.
 *  - Coincide: se limpia otp_hash (queda USADO) y devuelve true.
 *  - No coincide: otp_intentos + 1; al llegar a otp_max_intentos se limpia otp_hash (BLOQUEADO: hay que pedir otro).
 *
 * Corre en su propia transacción y se confirma siempre: los intentos fallidos quedan contados aunque quien
 * llama después rechace la entrega. Por eso debe llamarse FUERA de la transacción de registro de la entrega.
 */
function otp_validar(int $paqueteId, string $codigo): bool
{
    $maxIntentos = max(1, (int) config_get('otp_max_intentos', 3));
    $codigo = trim($codigo);
    $ahora = time();

    return (bool) db_tx(static function () use ($paqueteId, $codigo, $maxIntentos, $ahora): bool {
        $p = db_one('SELECT otp_hash, otp_expira_at, otp_intentos FROM paquetes WHERE id = ? FOR UPDATE', 'i', [$paqueteId]);
        if ($p === null || !is_string($p['otp_hash']) || $p['otp_hash'] === '' || $p['otp_expira_at'] === null) {
            return false;
        }
        if (strtotime($p['otp_expira_at'] . ' UTC') <= $ahora) {
            return false;
        }

        if ($codigo !== '' && ctype_digit($codigo) && hash_equals($p['otp_hash'], otp_hash_codigo($codigo))) {
            db_exec('UPDATE paquetes SET otp_hash = NULL WHERE id = ?', 'i', [$paqueteId]);   // usado
            return true;
        }

        $intentos = (int) $p['otp_intentos'] + 1;
        if ($intentos >= $maxIntentos) {
            db_exec('UPDATE paquetes SET otp_intentos = ?, otp_hash = NULL WHERE id = ?', 'ii', [$intentos, $paqueteId]);   // bloqueado
        } else {
            db_exec('UPDATE paquetes SET otp_intentos = ? WHERE id = ?', 'ii', [$intentos, $paqueteId]);
        }
        return false;
    });
}
