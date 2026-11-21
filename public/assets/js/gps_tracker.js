/**
 * gps_tracker.js - Rastreo GPS del conductor (M2). Lo consume la app del conductor (M4).
 *
 * Interfaz fija (M2 §6):
 *   GpsTracker.iniciar({ rutaId, intervaloS, alCambiarEstado })
 *   GpsTracker.detener()
 *   GpsTracker.estado() -> { activo, ultimoEnvio, pendientes, permiso, ultimaPrecision }
 *       permiso: 'concedido' | 'denegado' | 'pendiente'
 *       ultimoEnvio: ISO 8601 UTC del último envío exitoso, o null
 *
 * Extensión compatible con la interfaz (se pueden agregar funciones, no quitar):
 *   GpsTracker.enviarPendientes() -> Promise<number>  intenta vaciar la cola y devuelve cuántos quedan.
 *       M4 debe llamarla (y esperarla) ANTES de finalizar la ruta: una vez la ruta deja de estar
 *       'en_curso', el servidor rechaza los puntos.
 *
 * alCambiarEstado(info) recibe { motivo, ...estado() }. Motivos:
 *   'permiso_denegado'   el usuario negó la ubicación
 *   'permiso_concedido'  la ubicación quedó disponible
 *   'sin_envio_60s'      hay puntos por enviar y pasaron más de 60 s sin poder enviarlos
 *   'envio_restablecido' se volvió a enviar después de 'sin_envio_60s'
 *   'sesion_expirada'    el servidor respondió 401 (los puntos se conservan en el teléfono)
 *   'ruta_no_activa'     la ruta ya no es del conductor o no está en curso; se detiene el rastreo
 *   'lote_descartado'    el servidor rechazó un lote de forma definitiva (4xx)
 *
 * Cómo funciona:
 *   1. watchPosition (alta precisión) guarda siempre la última posición.
 *   2. Cada intervaloS segundos se toma una muestra y se guarda en IndexedDB ('puntos_pendientes').
 *   3. La cola se envía en orden FIFO, en lotes de hasta 100 puntos, a POST gps/enviar.php.
 *      Un 2xx borra el lote. Red caída, 5xx y 429 se reintentan con espera 5, 15, 30, 60 s.
 *      Al volver la conexión ('online') se reintenta de inmediato.
 *   4. Wake Lock mantiene la pantalla encendida si el navegador lo permite.
 *
 * La base IndexedDB de este archivo es 'routepulse_gps' (aparte de 'routepulse_cola' de M4).
 * Sin llamar a la API de otro módulo: solo gps/enviar.php y auth/csrf.php.
 */
(function (global) {
    'use strict';

    // ---------- Constantes ----------
    var NOMBRE_BD = 'routepulse_gps';
    var NOMBRE_ALMACEN = 'puntos_pendientes';
    var LOTE_MAX = 100;                    // M2 §6: lotes de hasta 100 (el servidor acepta 200)
    var ESPERAS_S = [5, 15, 30, 60];       // espera creciente entre reintentos
    var AVISO_SIN_ENVIO_MS = 60000;        // M2 §6: aviso a los 60 s
    var TIMEOUT_PETICION_MS = 15000;
    var INTERVALO_DEFECTO_S = 10;          // gps_intervalo_s
    var INTERVALO_MIN_S = 2;

    // Las rutas de la API se calculan a partir de la URL de este script, para que funcione
    // tanto en http://localhost/routepulse/public/ como en un dominio propio.
    var BASE_PUBLICA = (function () {
        try {
            var origen = document.currentScript && document.currentScript.src;
            if (origen) { return new URL('../../', origen).href; }   // .../public/assets/js/ -> .../public/
        } catch (e) { /* se usa el origen */ }
        return global.location.origin + '/';
    })();
    var URL_ENVIAR = new URL('api/gps/enviar.php', BASE_PUBLICA).href;
    var URL_CSRF = new URL('api/auth/csrf.php', BASE_PUBLICA).href;

    // ---------- Estado interno ----------
    var activo = false;
    var rutaId = null;
    var intervaloS = INTERVALO_DEFECTO_S;
    var callback = null;

    var idWatch = null;
    var temporizadorMuestreo = null;
    var temporizadorVigilancia = null;
    var wakeLock = null;

    var ultimaPos = null;          // última posición recibida de watchPosition
    var ultimoTimestampMuestreado = null;
    var bateria = null;            // objeto BatteryManager si existe

    var permiso = 'pendiente';
    var ultimaPrecision = null;
    var ultimoEnvioMs = null;      // último envío exitoso
    var ultimoOkMs = 0;            // último momento "al día" (envío exitoso o cola vacía)
    var pendientes = 0;
    var avisoSinEnvio = false;
    var avisoSesion = false;

    var fallos = 0;
    var proximoIntentoMs = 0;
    var vaciando = null;           // promesa del vaciado en curso
    var csrf = null;
    var almacenPromesa = null;

    // ---------- Almacén: IndexedDB con respaldo en memoria ----------
    function crearAlmacenIdb() {
        var bdPromesa = new Promise(function (resolver, rechazar) {
            if (!global.indexedDB) { rechazar(new Error('sin_indexeddb')); return; }
            var peticion = global.indexedDB.open(NOMBRE_BD, 1);
            peticion.onupgradeneeded = function () {
                peticion.result.createObjectStore(NOMBRE_ALMACEN, { keyPath: 'id', autoIncrement: true });
            };
            peticion.onsuccess = function () { resolver(peticion.result); };
            peticion.onerror = function () { rechazar(peticion.error); };
            peticion.onblocked = function () { rechazar(new Error('bd_bloqueada')); };
        });

        function transaccion(modo, trabajo) {
            return bdPromesa.then(function (bd) {
                return new Promise(function (resolver, rechazar) {
                    var tx = bd.transaction(NOMBRE_ALMACEN, modo);
                    var almacen = tx.objectStore(NOMBRE_ALMACEN);
                    var resultado = trabajo(almacen);
                    tx.oncomplete = function () { resolver(resultado && resultado.valor !== undefined ? resultado.valor : undefined); };
                    tx.onerror = function () { rechazar(tx.error); };
                    tx.onabort = function () { rechazar(tx.error || new Error('transaccion_abortada')); };
                });
            });
        }

        return bdPromesa.then(function () {
            return {
                agregar: function (item) {
                    return transaccion('readwrite', function (a) { a.add(item); });
                },
                primeros: function (n) {
                    var salida = { valor: [] };
                    return transaccion('readwrite', function (a) {
                        // Se recorre en orden de clave (= orden de inserción).
                        a.openCursor().onsuccess = function (ev) {
                            var cursor = ev.target.result;
                            if (cursor && salida.valor.length < n) {
                                salida.valor.push(cursor.value);
                                cursor.continue();
                            }
                        };
                        return salida;
                    });
                },
                borrar: function (ids) {
                    return transaccion('readwrite', function (a) { ids.forEach(function (id) { a.delete(id); }); });
                },
                borrarPorRuta: function (rutaABorrar) {
                    return transaccion('readwrite', function (a) {
                        a.openCursor().onsuccess = function (ev) {
                            var cursor = ev.target.result;
                            if (cursor) {
                                if (cursor.value.rutaId === rutaABorrar) { cursor.delete(); }
                                cursor.continue();
                            }
                        };
                    });
                },
                contar: function () {
                    var salida = { valor: 0 };
                    return transaccion('readonly', function (a) {
                        a.count().onsuccess = function (ev) { salida.valor = ev.target.result; };
                        return salida;
                    });
                }
            };
        });
    }

    function crearAlmacenMemoria() {
        var filas = [];
        var siguienteId = 1;
        return {
            agregar: function (item) { item.id = siguienteId++; filas.push(item); return Promise.resolve(); },
            primeros: function (n) { return Promise.resolve(filas.slice(0, n)); },
            borrar: function (ids) { filas = filas.filter(function (f) { return ids.indexOf(f.id) === -1; }); return Promise.resolve(); },
            borrarPorRuta: function (r) { filas = filas.filter(function (f) { return f.rutaId !== r; }); return Promise.resolve(); },
            contar: function () { return Promise.resolve(filas.length); }
        };
    }

    function obtenerAlmacen() {
        if (!almacenPromesa) {
            almacenPromesa = crearAlmacenIdb().catch(function () { return crearAlmacenMemoria(); });
        }
        return almacenPromesa;
    }

    // ---------- Utilidades ----------
    function avisar(motivo) {
        if (typeof callback !== 'function') { return; }
        try {
            var info = estado();
            info.motivo = motivo;
            callback(info);
        } catch (e) { /* un error del callback no debe detener el rastreo */ }
    }

    function esNumero(v) { return typeof v === 'number' && isFinite(v); }

    function redondear(v, decimales) {
        var f = Math.pow(10, decimales);
        return Math.round(v * f) / f;
    }

    function cambiarPermiso(nuevo) {
        if (permiso === nuevo) { return; }
        permiso = nuevo;
        avisar(nuevo === 'denegado' ? 'permiso_denegado' : 'permiso_concedido');
    }

    // ---------- Posición y muestreo ----------
    function alRecibirPosicion(pos) {
        ultimaPos = pos;
        cambiarPermiso('concedido');
    }

    function alFallarPosicion(error) {
        // 1 = permiso denegado. 2 (sin posición) y 3 (tiempo agotado) son transitorios: watchPosition sigue.
        if (error && error.code === 1) { cambiarPermiso('denegado'); }
    }

    function construirPunto(pos) {
        var c = pos.coords;
        var velocidad = esNumero(c.speed) ? redondear(c.speed * 3.6, 1) : null;   // m/s -> km/h
        var rumbo = esNumero(c.heading) ? Math.round(c.heading) % 360 : null;
        var marca = esNumero(pos.timestamp) ? pos.timestamp : Date.now();
        return {
            lat: redondear(c.latitude, 7),
            lng: redondear(c.longitude, 7),
            velocidad_kmh: velocidad,
            precision_m: esNumero(c.accuracy) ? redondear(c.accuracy, 1) : null,
            rumbo: rumbo,
            bateria_pct: bateria && esNumero(bateria.level) ? Math.round(bateria.level * 100) : null,
            capturado_at: new Date(marca).toISOString()   // UTC con milisegundos y Z
        };
    }

    function tomarMuestra() {
        if (!activo || !ultimaPos) { return Promise.resolve(); }
        // Sin posición nueva desde la última muestra no se inventa un punto: se espera la siguiente.
        if (ultimaPos.timestamp === ultimoTimestampMuestreado) { return Promise.resolve(); }
        ultimoTimestampMuestreado = ultimaPos.timestamp;

        var punto = construirPunto(ultimaPos);
        ultimaPrecision = punto.precision_m;
        var rutaDeLaMuestra = rutaId;

        return obtenerAlmacen().then(function (almacen) {
            return almacen.agregar({ rutaId: rutaDeLaMuestra, punto: punto });
        }).then(function () {
            pendientes += 1;
        });
    }

    function alTemporizadorMuestreo() {
        tomarMuestra().then(function () { return vaciar(false); }).catch(function () { /* se reintenta en el siguiente ciclo */ });
    }

    // ---------- Red ----------
    function obtenerCsrf(forzar) {
        if (csrf && !forzar) { return Promise.resolve(csrf); }
        return fetch(URL_CSRF, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || json.ok !== true || !json.data || !json.data.csrf_token) { throw new Error('csrf'); }
                csrf = json.data.csrf_token;
                return csrf;
            });
    }

    /**
     * Envía un lote. Resultado: { tipo: 'ok' | 'reintentar' | 'sesion' | 'ruta_invalida' | 'descartar' }.
     */
    function enviarLote(rutaDelLote, puntos, yaReintentoCsrf) {
        return obtenerCsrf(false).then(function (token) {
            var control = new AbortController();
            var reloj = setTimeout(function () { control.abort(); }, TIMEOUT_PETICION_MS);
            return fetch(URL_ENVIAR, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
                body: JSON.stringify({ ruta_id: rutaDelLote, puntos: puntos }),
                signal: control.signal
            }).then(function (res) {
                clearTimeout(reloj);
                return res.json().catch(function () { return null; }).then(function (json) {
                    return { status: res.status, ok: res.ok, json: json };
                });
            }, function (err) { clearTimeout(reloj); throw err; });
        }).then(function (r) {
            var codigo = r.json && r.json.error ? r.json.error.codigo : null;

            if (r.ok) {
                // Un 200 que no es nuestro JSON (p. ej. portal cautivo) no cuenta como enviado.
                return (r.json && r.json.ok === true) ? { tipo: 'ok' } : { tipo: 'reintentar' };
            }
            if (r.status === 401) { return { tipo: 'sesion' }; }
            if (r.status === 403 && codigo === 'CSRF_INVALIDO') {
                if (yaReintentoCsrf) { return { tipo: 'reintentar' }; }
                return obtenerCsrf(true).then(function () { return enviarLote(rutaDelLote, puntos, true); });
            }
            if (r.status === 403 || r.status === 404 || r.status === 409) { return { tipo: 'ruta_invalida' }; }   // SIN_PERMISO, NO_ENCONTRADO, ESTADO_INVALIDO
            if (r.status === 429 || r.status >= 500) { return { tipo: 'reintentar' }; }
            return { tipo: 'descartar' };   // 400, 422 y demás 4xx: reenviar el mismo lote no lo arregla
        }).catch(function () {
            return { tipo: 'reintentar' };  // sin red, tiempo agotado o error de CSRF por red
        });
    }

    // ---------- Vaciado de la cola ----------
    function loteContiguo(filas) {
        // Un lote lleva una sola ruta: las filas de la misma ruta que abren la cola.
        var ruta = filas[0].rutaId;
        var lote = [];
        for (var i = 0; i < filas.length && lote.length < LOTE_MAX; i += 1) {
            if (filas[i].rutaId !== ruta) { break; }
            lote.push(filas[i]);
        }
        return lote;
    }

    function vaciar(forzar) {
        if (vaciando) { return vaciando; }
        if (!forzar && Date.now() < proximoIntentoMs) { return Promise.resolve(pendientes); }

        vaciando = obtenerAlmacen().then(function (almacen) {
            function paso() {
                return almacen.primeros(LOTE_MAX).then(function (filas) {
                    if (!filas.length) { ultimoOkMs = Date.now(); return null; }

                    var lote = loteContiguo(filas);
                    var ids = lote.map(function (f) { return f.id; });
                    var rutaDelLote = lote[0].rutaId;

                    return enviarLote(rutaDelLote, lote.map(function (f) { return f.punto; }), false).then(function (r) {
                        if (r.tipo === 'ok') {
                            return almacen.borrar(ids).then(function () {
                                pendientes = Math.max(0, pendientes - ids.length);
                                ultimoEnvioMs = Date.now();
                                ultimoOkMs = ultimoEnvioMs;
                                fallos = 0;
                                proximoIntentoMs = 0;
                                avisoSesion = false;
                                if (avisoSinEnvio) { avisoSinEnvio = false; avisar('envio_restablecido'); }
                                return paso();   // sigue con el siguiente lote hasta vaciar la cola
                            });
                        }
                        if (r.tipo === 'descartar') {
                            return almacen.borrar(ids).then(function () {
                                pendientes = Math.max(0, pendientes - ids.length);
                                avisar('lote_descartado');
                                return paso();
                            });
                        }
                        if (r.tipo === 'ruta_invalida') {
                            return almacen.borrarPorRuta(rutaDelLote).then(function () { return almacen.contar(); }).then(function (n) {
                                pendientes = n;
                                if (rutaDelLote === rutaId) { detenerInterno(); }
                                avisar('ruta_no_activa');
                                return paso();
                            });
                        }
                        if (r.tipo === 'sesion') {
                            fallos += 1;
                            proximoIntentoMs = Date.now() + ESPERAS_S[Math.min(fallos - 1, ESPERAS_S.length - 1)] * 1000;
                            if (!avisoSesion) { avisoSesion = true; avisar('sesion_expirada'); }
                            return null;
                        }
                        // reintentar
                        fallos += 1;
                        proximoIntentoMs = Date.now() + ESPERAS_S[Math.min(fallos - 1, ESPERAS_S.length - 1)] * 1000;
                        return null;
                    });
                });
            }
            return paso().then(function () { return almacen.contar(); });
        }).then(function (n) {
            pendientes = n;
            return n;
        }, function () {
            return pendientes;
        }).then(function (n) {
            vaciando = null;
            return n;
        });
        return vaciando;
    }

    // ---------- Vigilancia, Wake Lock y eventos ----------
    function vigilar() {
        if (!activo) { return; }
        if (!avisoSinEnvio && pendientes > 0 && Date.now() - ultimoOkMs > AVISO_SIN_ENVIO_MS) {
            avisoSinEnvio = true;
            avisar('sin_envio_60s');
        }
    }

    function pedirWakeLock() {
        if (!activo || !global.navigator || !global.navigator.wakeLock) { return; }
        global.navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
            lock.addEventListener('release', function () { if (wakeLock === lock) { wakeLock = null; } });
        }).catch(function () { /* sin permiso o sin soporte: el rastreo sigue igual */ });
    }

    function soltarWakeLock() {
        if (wakeLock) {
            try { wakeLock.release(); } catch (e) { /* ya liberado */ }
            wakeLock = null;
        }
    }

    function alVolverConexion() { if (activo || pendientes > 0) { vaciar(true); } }

    function alCambiarVisibilidad() {
        if (global.document.visibilityState === 'visible') {
            if (activo && !wakeLock) { pedirWakeLock(); }   // el navegador suelta el bloqueo al ocultar la pestaña
            if (activo) { vaciar(true); }
        }
    }

    function observarPermiso() {
        if (!global.navigator.permissions || !global.navigator.permissions.query) { return; }
        global.navigator.permissions.query({ name: 'geolocation' }).then(function (estadoPermiso) {
            function aplicar() {
                if (estadoPermiso.state === 'denied') { cambiarPermiso('denegado'); }
                else if (estadoPermiso.state === 'granted') { cambiarPermiso('concedido'); }
            }
            aplicar();
            estadoPermiso.onchange = aplicar;
        }).catch(function () { /* Safari antiguo: se deduce de watchPosition */ });
    }

    // ---------- API pública ----------
    function iniciar(opciones) {
        opciones = opciones || {};
        var nuevaRuta = parseInt(opciones.rutaId, 10);
        if (!nuevaRuta || nuevaRuta < 1) { throw new Error('GpsTracker.iniciar: falta rutaId.'); }

        if (activo) {
            if (rutaId === nuevaRuta) { callback = opciones.alCambiarEstado || callback; return Promise.resolve(); }
            detenerInterno();
        }

        if (!global.navigator || !global.navigator.geolocation) {
            callback = opciones.alCambiarEstado || null;
            cambiarPermiso('denegado');
            return Promise.resolve();
        }

        rutaId = nuevaRuta;
        var s = parseFloat(opciones.intervaloS);
        intervaloS = isFinite(s) && s >= INTERVALO_MIN_S ? s : INTERVALO_DEFECTO_S;
        callback = opciones.alCambiarEstado || null;

        activo = true;
        ultimaPos = null;
        ultimoTimestampMuestreado = null;
        avisoSinEnvio = false;
        avisoSesion = false;
        fallos = 0;
        proximoIntentoMs = 0;
        ultimoOkMs = Date.now();

        if (global.navigator.getBattery) {
            global.navigator.getBattery().then(function (b) { bateria = b; }).catch(function () { bateria = null; });
        }

        observarPermiso();
        idWatch = global.navigator.geolocation.watchPosition(alRecibirPosicion, alFallarPosicion, {
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 20000
        });
        temporizadorMuestreo = global.setInterval(alTemporizadorMuestreo, intervaloS * 1000);
        temporizadorVigilancia = global.setInterval(vigilar, 5000);

        global.addEventListener('online', alVolverConexion);
        global.document.addEventListener('visibilitychange', alCambiarVisibilidad);
        pedirWakeLock();

        // Retoma lo que haya quedado en el teléfono de una sesión anterior.
        return obtenerAlmacen().then(function (almacen) { return almacen.contar(); }).then(function (n) {
            pendientes = n;
            return vaciar(true);
        }).then(function () { /* listo */ }, function () { /* sin cola: no impide rastrear */ });
    }

    function detenerInterno() {
        activo = false;
        if (idWatch !== null && global.navigator.geolocation) { global.navigator.geolocation.clearWatch(idWatch); }
        idWatch = null;
        if (temporizadorMuestreo) { global.clearInterval(temporizadorMuestreo); temporizadorMuestreo = null; }
        if (temporizadorVigilancia) { global.clearInterval(temporizadorVigilancia); temporizadorVigilancia = null; }
        global.removeEventListener('online', alVolverConexion);
        global.document.removeEventListener('visibilitychange', alCambiarVisibilidad);
        soltarWakeLock();
    }

    function detener() {
        detenerInterno();
        // No se vacía la cola aquí: si quedan puntos se conservan y se enviarán la próxima vez
        // que se inicie el rastreo, o antes con enviarPendientes().
    }

    function estado() {
        return {
            activo: activo,
            ultimoEnvio: ultimoEnvioMs ? new Date(ultimoEnvioMs).toISOString() : null,
            pendientes: pendientes,
            permiso: permiso,
            ultimaPrecision: ultimaPrecision
        };
    }

    function enviarPendientes() {
        return vaciar(true);
    }

    global.GpsTracker = {
        iniciar: iniciar,
        detener: detener,
        estado: estado,
        enviarPendientes: enviarPendientes
    };
})(window);
