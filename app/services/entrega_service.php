<?php
/**
 * entrega_service.php - M4 App del conductor.
 * Contrato: 00-base/07-servicios-internos.md, sección F. Reglas: modulos/M4-app-conductor.md.
 *
 * Estado de las funciones:
 *   entrega_mi_ruta, entrega_iniciar_ruta, entrega_marcar_llegada,
 *   entrega_solicitar_otp, entrega_finalizar_ruta     COMPLETAS (T8). Son funciones internas de M4 que usan
 *                                                     los endpoints de public/api/conductor/.
 *   entrega_registrar                                  FIRMA DEL CONTRATO con cuerpo mínimo (T9).
 *
 * Seguridad: el conductor SIEMPRE llega como parámetro ($conductorId = auth_conductor_id()), nunca de lo que
 * envía el cliente. La propiedad de la ruta o de la parada se valida aquí (SIN_PERMISO), antes del estado.
 */

const ENTREGA_PARADAS_ABIERTAS = ['pendiente', 'en_camino', 'llegado'];

/**
 * Carga una ruta y comprueba que sea del conductor.
 *
 * @return array{id:int, codigo:string, estado:string}
 * @throws AppError NO_ENCONTRADO o SIN_PERMISO.
 */
function entrega_cargar_ruta(int $rutaId, int $conductorId): array
{
    $ruta = db_one('SELECT id, codigo, estado, conductor_id FROM rutas WHERE id = ?', 'i', [$rutaId]);
    if ($ruta === null) {
        error_no_encontrado('La ruta');
    }
    if ((int) $ruta['conductor_id'] !== $conductorId) {
        error_sin_permiso();
    }
    return ['id' => (int) $ruta['id'], 'codigo' => $ruta['codigo'], 'estado' => $ruta['estado']];
}

/**
 * Carga una parada y comprueba que su ruta sea del conductor.
 * Con $bloquear = true toma un bloqueo de fila (SELECT ... FOR UPDATE): solo dentro de una transacción.
 *
 * @return array Fila de la parada más 'ruta_estado'.
 * @throws AppError NO_ENCONTRADO o SIN_PERMISO.
 */
function entrega_cargar_parada(int $paradaId, int $conductorId, bool $bloquear = false): array
{
    $parada = db_one(
        'SELECT id, ruta_id, paquete_id, orden, estado, lat, lng, intento_num, llegada_real FROM paradas WHERE id = ?'
        . ($bloquear ? ' FOR UPDATE' : ''),
        'i',
        [$paradaId]
    );
    if ($parada === null) {
        error_no_encontrado('La parada');
    }
    $ruta = db_one('SELECT conductor_id, estado FROM rutas WHERE id = ?', 'i', [(int) $parada['ruta_id']]);
    if ($ruta === null || (int) $ruta['conductor_id'] !== $conductorId) {
        error_sin_permiso();
    }
    $parada['ruta_estado'] = $ruta['estado'];
    return $parada;
}

/**
 * Número de coordenada para un enlace (sin ceros sobrantes): 4.6350000 -> "4.635".
 */
function entrega_coordenada_url(float $valor): string
{
    return rtrim(rtrim(number_format($valor, 7, '.', ''), '0'), '.');
}

/**
 * Enlaces para abrir la navegación hacia un punto, construidos en el servidor (M4 §5, mi_ruta).
 *
 * @return array{waze:string, google:string, apple:string}
 */
function entrega_enlaces_navegacion(float $lat, float $lng): array
{
    $punto = entrega_coordenada_url($lat) . ',' . entrega_coordenada_url($lng);
    return [
        'waze'   => 'https://waze.com/ul?ll=' . $punto . '&navigate=yes',
        'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . $punto,
        'apple'  => 'https://maps.apple.com/?daddr=' . $punto . '&dirflg=d',
    ];
}

/**
 * Hoja de ruta del conductor: su ruta activa (en curso o la planificada de hoy), el vehículo, la configuración
 * que necesita la app y las paradas con el paquete y los enlaces de navegación.
 *
 * @return array|null null si no tiene ruta.
 */
function entrega_mi_ruta(int $conductorId): ?array
{
    $activa = ruta_activa_de_conductor($conductorId);
    if ($activa === null) {
        return null;
    }
    $rutaId = (int) $activa['id'];

    // Se consulta aquí lo que la app necesita (datos del paquete que ruta_obtener no incluye, como el teléfono o el cobro).
    $ruta = db_one(
        'SELECT r.id, r.codigo, r.estado, r.fecha, v.placa, v.tipo AS vehiculo_tipo
           FROM rutas r LEFT JOIN vehiculos v ON v.id = r.vehiculo_id
          WHERE r.id = ? AND r.conductor_id = ?',
        'ii',
        [$rutaId, $conductorId]
    );
    if ($ruta === null) {
        return null;
    }

    $filas = db_all(
        'SELECT p.id, p.orden, p.estado, p.eta, p.lat, p.lng,
                pq.id AS paquete_id, pq.codigo_guia, pq.destinatario_nombre, pq.destinatario_telefono,
                pq.direccion_entrega, pq.referencia, pq.peso_kg, pq.cobro_contra_entrega, pq.moneda,
                pq.ventana_inicio, pq.ventana_fin, pq.notas
           FROM paradas p JOIN paquetes pq ON pq.id = p.paquete_id
          WHERE p.ruta_id = ? ORDER BY p.orden',
        'i',
        [$rutaId]
    );

    $paradas = [];
    $resueltas = 0;
    foreach ($filas as $f) {
        if (!in_array($f['estado'], ENTREGA_PARADAS_ABIERTAS, true)) {
            $resueltas++;
        }
        $lat = (float) $f['lat'];
        $lng = (float) $f['lng'];
        $paradas[] = [
            'id'         => (int) $f['id'],
            'orden'      => (int) $f['orden'],
            'estado'     => $f['estado'],
            'eta'        => $f['eta'] === null ? null : fecha_a_iso((string) $f['eta']),
            'lat'        => $lat,
            'lng'        => $lng,
            'paquete'    => [
                'id'                    => (int) $f['paquete_id'],
                'codigo_guia'           => $f['codigo_guia'],
                'destinatario_nombre'   => $f['destinatario_nombre'],
                'destinatario_telefono' => $f['destinatario_telefono'],
                'direccion_entrega'     => $f['direccion_entrega'],
                'referencia'            => $f['referencia'],
                'peso_kg'               => (float) $f['peso_kg'],
                'cobro_contra_entrega'  => $f['cobro_contra_entrega'] === null ? null : (float) $f['cobro_contra_entrega'],
                'moneda'                => $f['moneda'],
                'ventana_inicio'        => $f['ventana_inicio'] === null ? null : fecha_a_iso((string) $f['ventana_inicio']),
                'ventana_fin'           => $f['ventana_fin'] === null ? null : fecha_a_iso((string) $f['ventana_fin']),
                'notas'                 => $f['notas'],
            ],
            'navegacion' => entrega_enlaces_navegacion($lat, $lng),
        ];
    }

    return [
        'ruta' => [
            'id'        => (int) $ruta['id'],
            'codigo'    => $ruta['codigo'],
            'estado'    => $ruta['estado'],
            'fecha'     => $ruta['fecha'],
            'total'     => count($paradas),
            'resueltas' => $resueltas,
        ],
        'vehiculo' => $ruta['placa'] === null ? null : ['placa' => $ruta['placa'], 'tipo' => $ruta['vehiculo_tipo']],
        'config'   => [
            'gps_intervalo_s'         => (int) config_get('gps_intervalo_s', 10),
            'otp_obligatorio'         => (bool) (int) config_get('otp_obligatorio', 1),
            'entrega_distancia_max_m' => (int) config_get('entrega_distancia_max_m', 300),
        ],
        'paradas'  => $paradas,
    ];
}

/**
 * Inicia la ruta del conductor (planificada -> en curso). El trabajo de fondo lo hace ruta_iniciar() de M1.
 *
 * @throws AppError NO_ENCONTRADO, SIN_PERMISO o ESTADO_INVALIDO (no está planificada).
 */
function entrega_iniciar_ruta(int $rutaId, int $conductorId): array
{
    $ruta = entrega_cargar_ruta($rutaId, $conductorId);
    if ($ruta['estado'] !== 'planificada') {
        error_estado('La ruta no está planificada, así que no se puede iniciar.');
    }

    $resultado = ruta_iniciar($rutaId, $conductorId);
    audit('ruta_iniciar', 'ruta', $rutaId, ['codigo' => $ruta['codigo']]);
    return $resultado;
}

/**
 * Marca la llegada del conductor a la parada (en_camino -> llegado, con llegada_real = $ocurridoAt).
 * Idempotente: si la parada ya está 'llegado' devuelve su estado actual sin cambiar nada.
 *
 * @param string $ocurridoAt 'Y-m-d H:i:s' UTC (cuando el conductor pulsó "Llegué", aunque sea de una cola offline).
 * @return array{id:int, estado:string, llegada_real:?string}
 * @throws AppError NO_ENCONTRADO, SIN_PERMISO o ESTADO_INVALIDO.
 */
function entrega_marcar_llegada(int $paradaId, int $conductorId, string $ocurridoAt): array
{
    db_tx(static function () use ($paradaId, $conductorId, $ocurridoAt): void {
        $parada = entrega_cargar_parada($paradaId, $conductorId, true);   // bloqueada: dos toques seguidos no la marcan dos veces

        if ($parada['ruta_estado'] !== 'en_curso') {
            error_estado('La ruta no está en curso.');
        }
        if ($parada['estado'] === 'llegado') {
            return;   // ya registrada
        }
        if ($parada['estado'] !== 'en_camino') {
            error_estado('Solo se puede marcar la llegada en la parada que está en camino.');
        }
        parada_actualizar_estado($paradaId, 'llegado', ['ocurrido_at' => $ocurridoAt]);
    });

    $parada = db_one('SELECT id, estado, llegada_real FROM paradas WHERE id = ?', 'i', [$paradaId]);
    return [
        'id'           => (int) $parada['id'],
        'estado'       => $parada['estado'],
        'llegada_real' => fecha_a_iso($parada['llegada_real']),
    ];
}

/**
 * Envía al destinatario el código de entrega de la parada (OTP). Requiere conexión.
 * Límite de otp_max_por_hora por paquete (LIMITE) en otp_generar_y_enviar().
 *
 * @return array{canal:?string, expira_at:string, otp_debug?:string}
 * @throws AppError NO_ENCONTRADO, SIN_PERMISO, ESTADO_INVALIDO, LIMITE o PROVEEDOR_EXTERNO.
 */
function entrega_solicitar_otp(int $paradaId, int $conductorId): array
{
    $parada = entrega_cargar_parada($paradaId, $conductorId);
    if ($parada['ruta_estado'] !== 'en_curso') {
        error_estado('La ruta no está en curso.');
    }
    if (!in_array($parada['estado'], ['en_camino', 'llegado'], true)) {
        error_estado('Solo se puede pedir el código en la parada actual.');
    }

    $respuesta = otp_generar_y_enviar((int) $parada['paquete_id']);
    audit('otp_solicitar', 'paquete', (int) $parada['paquete_id'], ['parada_id' => $paradaId]);   // sin el código
    return $respuesta;
}

/**
 * Cierra la ruta del conductor. Exige que no queden paradas pendientes, en camino ni llegadas.
 * El cliente debe vaciar la cola de GPS (GpsTracker.enviarPendientes) ANTES de llamar a este servicio.
 *
 * @throws AppError NO_ENCONTRADO, SIN_PERMISO o ESTADO_INVALIDO (con la lista de paradas que faltan).
 */
function entrega_finalizar_ruta(int $rutaId, int $conductorId, ?int $usuarioId): array
{
    $ruta = entrega_cargar_ruta($rutaId, $conductorId);
    if ($ruta['estado'] !== 'en_curso') {
        error_estado('La ruta no está en curso.');
    }

    $abiertas = db_all(
        'SELECT p.orden, pq.codigo_guia
           FROM paradas p JOIN paquetes pq ON pq.id = p.paquete_id
          WHERE p.ruta_id = ? AND p.estado IN (\'pendiente\', \'en_camino\', \'llegado\')
          ORDER BY p.orden',
        'i',
        [$rutaId]
    );
    if ($abiertas !== []) {
        $lista = array_map(static fn(array $a): string => $a['orden'] . ' (' . $a['codigo_guia'] . ')', array_slice($abiertas, 0, 10));
        $mas = count($abiertas) > 10 ? ' y ' . (count($abiertas) - 10) . ' más' : '';
        error_estado('Aún quedan paradas sin resolver: ' . implode(', ', $lista) . $mas . '.');
    }

    $resultado = ruta_finalizar($rutaId, $usuarioId);
    audit('ruta_finalizar', 'ruta', $rutaId, ['codigo' => $ruta['codigo']]);
    return $resultado;
}

/**
 * Registra una entrega o un fallo de entrega (idempotente por 'uuid') y orquesta sus efectos.
 * FIRMA DEL CONTRATO (Hito 1) con cuerpo mínimo: la implementación real llega en la tarea T9.
 *
 * @param array $datos       Campos de conductor/entregar.php o conductor/fallar.php (ver M4, sección 5).
 * @param int   $conductorId Siempre el de la sesión (auth_conductor_id()), nunca del cliente.
 * @return array {entrega, siguiente_parada, ruta_completa, advertencias} (+ duplicado:true si el uuid ya existía)
 */
function entrega_registrar(array $datos, int $conductorId): array
{
    lanzar_error('ERROR_INTERNO', 'Esta función todavía no está disponible.', 500);
}
