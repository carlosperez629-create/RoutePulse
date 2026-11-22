<?php
/**
 * RoutePulse - Errores de aplicación (núcleo).
 *
 * Los errores "esperados" (validación, permisos, estado inválido, etc.) se lanzan
 * como AppError. api_run() los captura y los convierte en la respuesta JSON del
 * contrato: {"ok":false,"error":{"codigo","mensaje","campos"}}.
 *
 * Códigos y HTTP: ver docs/00-base/05-contrato-api.md, sección 2.
 * El mensaje va en español, listo para mostrarse al usuario, y NUNCA contiene
 * detalles internos (SQL, rutas de archivos, trazas).
 */

class AppError extends Exception {
    public string $codigo;
    public int $http;
    public array $campos;
    /** Segundos para el encabezado Retry-After (solo para LIMITE). */
    public ?int $reintentar_despues = null;

    public function __construct(string $codigo, string $mensaje, int $http = 400, array $campos = [], ?Throwable $previo = null) {
        parent::__construct($mensaje, 0, $previo);
        $this->codigo = $codigo;
        $this->http = $http;
        $this->campos = $campos;
    }
}

/**
 * HTTP por defecto de cada código del contrato.
 */
function error_http_por_codigo(string $codigo): int {
    $mapa = [
        'PARAMETROS'          => 400,
        'NO_AUTENTICADO'      => 401,
        'SIN_PERMISO'         => 403,
        'CSRF_INVALIDO'       => 403,
        'NO_ENCONTRADO'       => 404,
        'METODO_NO_PERMITIDO' => 405,
        'CONFLICTO'           => 409,
        'ESTADO_INVALIDO'     => 409,
        'OTP_INVALIDO'        => 422,
        'VALIDACION'          => 422,
        'LIMITE'              => 429,
        'ERROR_INTERNO'       => 500,
        'PROVEEDOR_EXTERNO'   => 502,
    ];
    return $mapa[$codigo] ?? 400;
}

function lanzar_error(string $codigo, string $mensaje, int $http = 400, array $campos = []): never {
    throw new AppError($codigo, $mensaje, $http, $campos);
}

function error_no_encontrado(string $que = 'El recurso'): never {
    throw new AppError('NO_ENCONTRADO', $que . ' no existe o no está disponible.', 404);
}

function error_sin_permiso(string $mensaje = 'No tienes permiso para realizar esta acción.'): never {
    throw new AppError('SIN_PERMISO', $mensaje, 403);
}

function error_validacion(array $campos, string $mensaje = 'Revisa los campos marcados.'): never {
    throw new AppError('VALIDACION', $mensaje, 422, $campos);
}

function error_conflicto(string $mensaje): never {
    throw new AppError('CONFLICTO', $mensaje, 409);
}

function error_estado(string $mensaje): never {
    throw new AppError('ESTADO_INVALIDO', $mensaje, 409);
}
