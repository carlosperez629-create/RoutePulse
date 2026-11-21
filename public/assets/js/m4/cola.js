/*
 * cola.js - Cola offline de la app del conductor (M4, IndexedDB routepulse_cola.operaciones).
 * Toda llegada/entrega/fallo se guarda PRIMERO aquí y luego se intenta enviar. FIFO, una a la vez,
 * reutilizando el uuid (idempotencia del servidor). 2xx borra; 4xx (salvo 429) marca error;
 * red/429/5xx reintenta con espera creciente (5, 15, 30, 60 s).
 */
(function (global) {
    'use strict';

    var M4 = global.M4;
    var ESPERAS = [5000, 15000, 30000, 60000];
    var sincronizando = null;
    var temporizadorReintento = null;
    var autoIniciado = false;
    var CLAVE_ULTIMA_SINC = 'routepulse_ultima_sync';

    function emitir() {
        document.dispatchEvent(new CustomEvent('m4:cola'));
    }

    function listar() {
        return M4.idb.todos('operaciones').then(function (lista) {
            return lista.sort(function (a, b) { return a.id - b.id; });
        });
    }

    function marcarUltimaSincronizacion() {
        try { global.localStorage.setItem(CLAVE_ULTIMA_SINC, M4.horaIso()); } catch (e) { /* sin storage */ }
    }

    function construirFormData(operacion) {
        var fd = new FormData();
        var campos = operacion.campos || {};
        Object.keys(campos).forEach(function (clave) {
            var valor = campos[clave];
            if (valor === null || valor === undefined || valor === '') {
                return;
            }
            if (typeof valor === 'boolean') {
                fd.append(clave, valor ? '1' : '0');
            } else {
                fd.append(clave, String(valor));
            }
        });
        if (operacion.firma_base64) {
            fd.append('firma_base64', operacion.firma_base64);
        }
        if (operacion.foto_blob) {
            fd.append('foto', operacion.foto_blob, 'foto.jpg');
        }
        return fd;
    }

    function enviar(operacion) {
        var opciones = { silencioso: true, noRedirigir: true };
        if (operacion.tipo === 'multipart') {
            return global.api.subir(operacion.endpoint, construirFormData(operacion), opciones);
        }
        return global.api.post(operacion.endpoint, operacion.campos || {}, opciones);
    }

    function programarReintento(milisegundos) {
        global.clearTimeout(temporizadorReintento);
        temporizadorReintento = global.setTimeout(function () {
            sincronizar().catch(function () { /* se reintenta en el próximo ciclo */ });
        }, milisegundos);
    }

    function sincronizar() {
        if (sincronizando) {
            return sincronizando;
        }
        if (!global.navigator.onLine) {
            return Promise.resolve();
        }

        sincronizando = listar().then(function (operaciones) {
            var cola = operaciones.filter(function (op) { return op.estado !== 'error'; });

            function paso(indice) {
                if (indice >= cola.length) {
                    return Promise.resolve();
                }
                if (!global.navigator.onLine) {
                    return Promise.resolve();
                }
                var op = cola[indice];
                var intentos = (op.intentos || 0) + 1;

                return M4.idb.actualizar('operaciones', op.id, { estado: 'enviando', intentos: intentos }).then(function () {
                    emitir();
                    return enviar(op);
                }).then(function (respuesta) {
                    return M4.idb.borrar('operaciones', op.id).then(function () {
                        marcarUltimaSincronizacion();
                        document.dispatchEvent(new CustomEvent('m4:operacion-sincronizada', {
                            detail: { operacion: op, respuesta: respuesta }
                        }));
                        emitir();
                        return paso(indice + 1);
                    });
                }).catch(function (error) {
                    var http = error && error.http ? error.http : 0;
                    var mensaje = (error && (error.mensaje || error.message)) || 'No se pudo sincronizar.';

                    if (error && error.codigo === 'NO_AUTENTICADO') {
                        return M4.idb.actualizar('operaciones', op.id, {
                            estado: 'pendiente', ultimo_error: mensaje
                        }).then(emitir);
                    }

                    var reintentar = http === 0 || http === 429 || http >= 500;
                    var cambios = {
                        estado: reintentar ? 'pendiente' : 'error',
                        ultimo_error: mensaje
                    };
                    return M4.idb.actualizar('operaciones', op.id, cambios).then(function () {
                        emitir();
                        if (reintentar) {
                            var espera = ESPERAS[Math.min(intentos - 1, ESPERAS.length - 1)];
                            programarReintento(espera);
                            return undefined;   // se corta el FIFO; el reintento lo retoma
                        }
                        document.dispatchEvent(new CustomEvent('m4:operacion-error', {
                            detail: { operacion: op, error: error }
                        }));
                        return paso(indice + 1);   // un 4xx no bloquea las siguientes
                    });
                });
            }

            return paso(0);
        });

        return sincronizando.then(function (resultado) {
            sincronizando = null;
            emitir();
            return resultado;
        }, function (error) {
            sincronizando = null;
            emitir();
            throw error;
        });
    }

    function encolar(operacion) {
        operacion.uuid = operacion.uuid || M4.uuid();
        operacion.creado_at = operacion.creado_at || M4.horaIso();
        operacion.estado = 'pendiente';
        operacion.intentos = 0;
        operacion.ultimo_error = null;
        if (!operacion.tipo) {
            operacion.tipo = 'json';
        }
        return M4.idb.agregar('operaciones', operacion).then(function (id) {
            emitir();
            sincronizar().catch(function () { /* queda pendiente */ });
            return id;
        });
    }

    function reintentar(id) {
        return M4.idb.actualizar('operaciones', id, { estado: 'pendiente', ultimo_error: null })
            .then(function () { emitir(); return sincronizar(); });
    }

    function pendientes() {
        return listar().then(function (lista) {
            return lista.filter(function (op) { return op.estado !== 'error'; });
        });
    }

    function errores() {
        return listar().then(function (lista) {
            return lista.filter(function (op) { return op.estado === 'error'; });
        });
    }

    function contar() {
        return pendientes().then(function (lista) { return lista.length; });
    }

    function iniciarAuto() {
        if (autoIniciado) {
            return;
        }
        autoIniciado = true;

        global.addEventListener('online', function () {
            sincronizar().catch(function () { /* se reintenta */ });
        });

        global.setInterval(function () {
            if (!global.navigator.onLine) {
                return;
            }
            pendientes().then(function (lista) {
                if (lista.length > 0) {
                    sincronizar().catch(function () { /* se reintenta */ });
                }
            });
        }, 30000);

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && global.navigator.onLine) {
                sincronizar().catch(function () { /* se reintenta */ });
            }
        });
    }

    global.M4Cola = {
        encolar: encolar,
        sincronizar: sincronizar,
        reintentar: reintentar,
        pendientes: pendientes,
        errores: errores,
        contar: contar,
        iniciarAuto: iniciarAuto,
        ultimaSincronizacion: function () {
            try { return global.localStorage.getItem(CLAVE_ULTIMA_SINC); } catch (e) { return null; }
        }
    };
})(window);
