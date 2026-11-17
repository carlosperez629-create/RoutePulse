/*
 * RoutePulse - api.js
 * Helper ÚNICO para llamar a la API (docs/00-base/01-convenciones.md, sección 4).
 *
 *   const datos = await api('paquetes/ver.php', { datos: { id: 5 } });          // GET
 *   const creado = await api.post('paquetes/crear.php', { ... }, { formulario: form });
 *   const lista = await api('paquetes/listar.php', { datos: { page: 2 }, completa: true });
 *   // lista = { ok: true, data: [...], meta: { page, por_pagina, total, paginas } }
 *   await api.subir('conductor/entregar.php', formData);                         // multipart
 *
 * Opciones: metodo ('GET'|'POST'), datos (objeto), formData (FormData),
 *   formulario (<form> donde marcar los errores de VALIDACION), silencioso (no muestra avisos),
 *   noRedirigir (no ir al login ante 401), sinCsrf (para endpoints públicos),
 *   completa (devuelve {ok,data,meta}), timeoutMs (30000 por defecto).
 *
 * Hace por ti: agrega el encabezado X-CSRF-Token (lo pide a auth/csrf.php y reintenta
 * una vez si caducó), convierte la respuesta, redirige al login ante 401 (excepto en
 * login.php) y muestra los errores con ui.toast(). Lanza ApiError ante cualquier error.
 */
(function (global) {
    'use strict';

    var MENSAJE_GENERICO = 'Ocurrió un problema. Intenta de nuevo; si persiste, avisa al administrador.';
    var tokenCsrf = null;

    class ApiError extends Error {
        constructor(codigo, mensaje, http, campos) {
            super(mensaje);
            this.name = 'ApiError';
            this.mensaje = mensaje;
            this.codigo = codigo;
            this.http = http;
            this.campos = campos || {};
        }
    }

    function leerMeta(nombre) {
        var el = document.querySelector('meta[name="' + nombre + '"]');
        return el ? (el.getAttribute('content') || '') : '';
    }

    function urlBase() {
        return leerMeta('rp-url-base').replace(/\/+$/, '');
    }

    function urlApi(endpoint) {
        return urlBase() + '/api/' + String(endpoint).replace(/^\/+/, '');
    }

    function enLogin() {
        return /\/login\.php$/.test(global.location.pathname);
    }

    function construirQuery(datos) {
        var params = new URLSearchParams();
        Object.keys(datos || {}).forEach(function (clave) {
            var valor = datos[clave];
            if (valor === null || valor === undefined || valor === '') {
                return;
            }
            if (Array.isArray(valor)) {
                if (valor.length > 0) {
                    params.append(clave, valor.join(','));
                }
            } else if (typeof valor === 'boolean') {
                params.append(clave, valor ? '1' : '0');
            } else {
                params.append(clave, String(valor));
            }
        });
        return params.toString();
    }

    async function pedir(url, init, timeoutMs) {
        var control = new AbortController();
        var temporizador = setTimeout(function () { control.abort(); }, timeoutMs);
        try {
            return await fetch(url, Object.assign({ credentials: 'same-origin', signal: control.signal }, init));
        } finally {
            clearTimeout(temporizador);
        }
    }

    async function obtenerCsrf(forzar) {
        if (tokenCsrf && !forzar) {
            return tokenCsrf;
        }
        var respuesta = await pedir(urlApi('auth/csrf.php'), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        }, 15000);
        var json = await respuesta.json();
        if (!json || json.ok !== true || !json.data || !json.data.csrf_token) {
            throw new ApiError('ERROR_INTERNO', MENSAJE_GENERICO, respuesta.status, {});
        }
        tokenCsrf = json.data.csrf_token;
        return tokenCsrf;
    }

    // Muestra el error al usuario (salvo "silencioso") y gestiona la sesión vencida.
    function atender(error, opciones) {
        if (error.codigo === 'NO_AUTENTICADO' && !opciones.noRedirigir && !enLogin()) {
            global.location.href = urlBase() + '/login.php?expirada=1';
            return error;
        }
        if (!opciones.silencioso) {
            if (error.codigo === 'VALIDACION' && opciones.formulario && global.ui) {
                global.ui.marcarErrores(opciones.formulario, error.campos);
            }
            if (global.ui) {
                global.ui.toast(error.message, 'danger');
            }
        }
        return error;
    }

    async function api(endpoint, opciones) {
        var o = Object.assign({
            metodo: 'GET', datos: null, formData: null, formulario: null,
            silencioso: false, noRedirigir: false, sinCsrf: false, completa: false, timeoutMs: 30000
        }, opciones || {});
        var metodo = String(o.metodo).toUpperCase();
        var url = urlApi(endpoint);
        var init = { method: metodo, headers: { 'Accept': 'application/json' }, cache: 'no-store' };

        if (o.formulario && global.ui) {
            global.ui.limpiarErrores(o.formulario);
        }

        if (metodo === 'GET') {
            var consulta = construirQuery(o.datos);
            if (consulta) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + consulta;
            }
        } else if (o.formData) {
            init.body = o.formData;
        } else {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(o.datos || {});
        }

        for (var intento = 0; intento < 2; intento++) {
            var respuesta;
            try {
                if (metodo !== 'GET' && !o.sinCsrf) {
                    init.headers['X-CSRF-Token'] = await obtenerCsrf(intento > 0);
                }
                respuesta = await pedir(url, init, o.timeoutMs);
            } catch (falla) {
                if (falla instanceof ApiError) {
                    throw atender(falla, o);
                }
                throw atender(new ApiError('SIN_CONEXION', 'No hay conexión con el servidor. Revisa tu conexión e inténtalo de nuevo.', 0, {}), o);
            }

            var json = null;
            try {
                json = await respuesta.json();
            } catch (falla) {
                json = null;
            }

            if (json && json.ok === true) {
                if (json.data && json.data.csrf_token) {
                    tokenCsrf = json.data.csrf_token;
                }
                return o.completa ? json : json.data;
            }

            var error = (json && json.error)
                ? new ApiError(json.error.codigo, json.error.mensaje, respuesta.status, json.error.campos)
                : new ApiError('ERROR_INTERNO', MENSAJE_GENERICO, respuesta.status, {});

            if (error.codigo === 'CSRF_INVALIDO' && intento === 0 && metodo !== 'GET' && !o.sinCsrf) {
                tokenCsrf = null;   // el token caducó: se pide uno nuevo y se reintenta una vez
                continue;
            }
            throw atender(error, o);
        }
    }

    api.get = function (endpoint, datos, opciones) {
        return api(endpoint, Object.assign({}, opciones, { metodo: 'GET', datos: datos }));
    };
    api.post = function (endpoint, datos, opciones) {
        return api(endpoint, Object.assign({}, opciones, { metodo: 'POST', datos: datos }));
    };
    api.subir = function (endpoint, formData, opciones) {
        return api(endpoint, Object.assign({ timeoutMs: 120000 }, opciones, { metodo: 'POST', formData: formData }));
    };
    api.ApiError = ApiError;
    api.urlBase = urlBase;

    global.api = api;
})(window);
