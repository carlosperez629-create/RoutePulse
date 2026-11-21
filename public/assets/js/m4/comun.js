/*
 * comun.js - Utilidades compartidas de la app del conductor (M4).
 * IndexedDB (routepulse_cola), ruta cacheada, UUID, ubicación, compresión de fotos y estado del Service Worker.
 * Todo el texto se inserta con textContent (ui.crear); este archivo no dibuja HTML.
 */
(function (global) {
    'use strict';

    var M4 = global.M4 || {};
    var BD = 'routepulse_cola';
    var VERSION_BD = 1;
    var bdPromesa = null;

    // ---------------------------------------------------------------- IndexedDB

    function abrirBd() {
        if (bdPromesa) {
            return bdPromesa;
        }
        bdPromesa = new Promise(function (resolver, rechazar) {
            if (!global.indexedDB) {
                rechazar(new Error('sin_indexeddb'));
                return;
            }
            var peticion = global.indexedDB.open(BD, VERSION_BD);
            peticion.onupgradeneeded = function () {
                var bd = peticion.result;
                if (!bd.objectStoreNames.contains('operaciones')) {
                    bd.createObjectStore('operaciones', { keyPath: 'id', autoIncrement: true });
                }
                if (!bd.objectStoreNames.contains('ruta')) {
                    bd.createObjectStore('ruta');
                }
            };
            peticion.onsuccess = function () { resolver(peticion.result); };
            peticion.onerror = function () { rechazar(peticion.error); };
            peticion.onblocked = function () { rechazar(new Error('bd_bloqueada')); };
        });
        return bdPromesa;
    }

    function ejecutar(almacen, modo, trabajo) {
        return abrirBd().then(function (bd) {
            return new Promise(function (resolver, rechazar) {
                var tx = bd.transaction(almacen, modo);
                var store = tx.objectStore(almacen);
                var salida;
                try {
                    salida = trabajo(store);
                } catch (e) {
                    rechazar(e);
                    return;
                }
                tx.oncomplete = function () {
                    resolver(salida && salida.valor !== undefined ? salida.valor : undefined);
                };
                tx.onerror = function () { rechazar(tx.error); };
                tx.onabort = function () { rechazar(tx.error || new Error('transaccion_abortada')); };
            });
        });
    }

    M4.idb = {
        agregar: function (almacen, valor) {
            var salida = {};
            return ejecutar(almacen, 'readwrite', function (store) {
                store.add(valor).onsuccess = function (evento) { salida.valor = evento.target.result; };
                return salida;
            });
        },
        poner: function (almacen, valor, clave) {
            return ejecutar(almacen, 'readwrite', function (store) {
                if (clave === undefined) { store.put(valor); } else { store.put(valor, clave); }
            });
        },
        obtener: function (almacen, clave) {
            var salida = {};
            return ejecutar(almacen, 'readonly', function (store) {
                store.get(clave).onsuccess = function (evento) { salida.valor = evento.target.result; };
                return salida;
            });
        },
        todos: function (almacen) {
            var salida = { valor: [] };
            return ejecutar(almacen, 'readonly', function (store) {
                store.getAll().onsuccess = function (evento) { salida.valor = evento.target.result || []; };
                return salida;
            });
        },
        borrar: function (almacen, clave) {
            return ejecutar(almacen, 'readwrite', function (store) { store.delete(clave); });
        },
        actualizar: function (almacen, clave, cambios) {
            return ejecutar(almacen, 'readwrite', function (store) {
                store.get(clave).onsuccess = function (evento) {
                    var valor = evento.target.result;
                    if (!valor) { return; }
                    Object.keys(cambios).forEach(function (campo) { valor[campo] = cambios[campo]; });
                    store.put(valor);
                };
            });
        }
    };

    // ---------------------------------------------------------------- Ruta cacheada

    M4.guardarRuta = function (ruta) {
        return M4.idb.poner('ruta', { datos: ruta, guardado_at: M4.horaIso() }, 'actual');
    };

    /** Devuelve la ruta guardada, null (no tiene ruta) o undefined (nunca se guardó). */
    M4.leerRuta = function () {
        return M4.idb.obtener('ruta', 'actual').then(function (registro) {
            return registro ? registro.datos : undefined;
        });
    };

    /**
     * Ruta desde la red (si hay conexión) con caída a la copia de IndexedDB.
     * @return {Promise<object|null|undefined>} undefined = no hay nada guardado.
     */
    M4.obtenerRuta = function (permitirRed) {
        if (permitirRed && global.navigator.onLine) {
            return global.api('conductor/mi_ruta.php', { silencioso: true })
                .then(function (ruta) {
                    return M4.guardarRuta(ruta).then(function () { return ruta; });
                })
                .catch(function (error) {
                    if (error && error.codigo === 'NO_AUTENTICADO') {
                        throw error;
                    }
                    return M4.leerRuta();
                });
        }
        return M4.leerRuta();
    };

    /**
     * Mueve una parada en la ruta guardada (para que la app funcione sin conexión). El servidor
     * sigue siendo la fuente de verdad al sincronizar; después se recarga la ruta.
     */
    M4.avanzarRutaLocal = function (paradaId, estado) {
        return M4.leerRuta().then(function (ruta) {
            if (!ruta || !Array.isArray(ruta.paradas)) {
                return ruta;
            }
            var parada = null;
            ruta.paradas.forEach(function (p) {
                if (Number(p.id) === Number(paradaId)) { parada = p; }
            });
            if (!parada) {
                return ruta;
            }

            parada.estado = estado;

            if (estado === 'llegado') {
                ruta.paradas.forEach(function (p) {
                    if (p.estado === 'en_camino' && Number(p.id) !== Number(paradaId)) {
                        p.estado = 'pendiente';
                    }
                });
            } else if (estado === 'entregada' || estado === 'fallida') {
                var abierta = ruta.paradas.some(function (p) {
                    return p.estado === 'en_camino' || p.estado === 'llegado';
                });
                if (!abierta) {
                    var siguiente = null;
                    ruta.paradas.forEach(function (p) {
                        if (p.estado === 'pendiente' && (!siguiente || p.orden < siguiente.orden)) {
                            siguiente = p;
                        }
                    });
                    if (siguiente) { siguiente.estado = 'en_camino'; }
                }
            }

            if (ruta.ruta) {
                ruta.ruta.resueltas = ruta.paradas.filter(function (p) {
                    return ['entregada', 'fallida', 'omitida'].indexOf(p.estado) >= 0;
                }).length;
            }
            return M4.guardarRuta(ruta).then(function () { return ruta; });
        });
    };

    // ---------------------------------------------------------------- Utilidades

    M4.horaIso = function () { return new Date().toISOString(); };

    M4.uuid = function () {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
            global.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    };

    function zonaHoraria() {
        var meta = document.querySelector('meta[name="rp-zona-horaria"]');
        return meta ? (meta.getAttribute('content') || 'UTC') : 'UTC';
    }
    M4.zonaHoraria = zonaHoraria;

    M4.hora = function (iso) {
        if (!iso) { return '—'; }
        try {
            return new Intl.DateTimeFormat('es', {
                hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: zonaHoraria()
            }).format(new Date(iso));
        } catch (e) {
            return new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', hour12: false });
        }
    };

    M4.horaRelativa = function (iso) {
        if (!iso) { return '—'; }
        var segundos = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
        if (segundos < 60) { return 'hace ' + segundos + ' s'; }
        if (segundos < 3600) { return 'hace ' + Math.floor(segundos / 60) + ' min'; }
        return 'hace ' + Math.floor(segundos / 3600) + ' h';
    };

    /** Posición actual o null si no está disponible (nunca rechaza). */
    M4.posicion = function (timeoutMs) {
        var limite = timeoutMs || 15000;
        return new Promise(function (resolver) {
            if (!global.navigator.geolocation) {
                resolver(null);
                return;
            }
            var terminado = false;
            var reloj = global.setTimeout(function () {
                if (!terminado) { terminado = true; resolver(null); }
            }, limite + 500);
            global.navigator.geolocation.getCurrentPosition(function (pos) {
                if (terminado) { return; }
                terminado = true;
                global.clearTimeout(reloj);
                resolver({
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    precision_m: pos.coords.accuracy
                });
            }, function () {
                if (terminado) { return; }
                terminado = true;
                global.clearTimeout(reloj);
                resolver(null);
            }, { enableHighAccuracy: true, timeout: limite, maximumAge: 30000 });
        });
    };

    // ---------------------------------------------------------------- Fotos

    function cargarBitmap(archivo) {
        if (global.createImageBitmap) {
            return global.createImageBitmap(archivo, { imageOrientation: 'from-image' })
                .catch(function () { return cargarConImagen(archivo); });
        }
        return cargarConImagen(archivo);
    }

    function cargarConImagen(archivo) {
        return new Promise(function (resolver, rechazar) {
            var url = global.URL.createObjectURL(archivo);
            var imagen = new global.Image();
            imagen.onload = function () {
                resolver(imagen);
                global.setTimeout(function () { global.URL.revokeObjectURL(url); }, 1000);
            };
            imagen.onerror = function () {
                global.URL.revokeObjectURL(url);
                rechazar(new Error('La foto no se pudo leer.'));
            };
            imagen.src = url;
        });
    }

    function aBlob(canvas, calidad) {
        return new Promise(function (resolver) {
            if (canvas.toBlob) {
                canvas.toBlob(resolver, 'image/jpeg', calidad);
            } else {
                resolver(null);
            }
        });
    }

    /**
     * Reduce una foto a 1600 px por lado y la convierte a JPEG (≤5 MB). Si el navegador no puede,
     * devuelve el archivo original cuando ya es una imagen aceptable.
     */
    M4.comprimirImagen = function (archivo) {
        var MAX = 1600;
        var LIMITE = 5 * 1024 * 1024;
        if (!archivo || !/^image\//.test(archivo.type || '')) {
            return Promise.reject(new Error('Elige una foto (JPG, PNG o WebP).'));
        }
        return cargarBitmap(archivo).then(function (fuente) {
            var ancho = fuente.width;
            var alto = fuente.height;
            var escala = Math.min(1, MAX / Math.max(ancho, alto));
            var destinoAncho = Math.max(1, Math.round(ancho * escala));
            var destinoAlto = Math.max(1, Math.round(alto * escala));
            var lienzo = document.createElement('canvas');
            lienzo.width = destinoAncho;
            lienzo.height = destinoAlto;
            var ctx = lienzo.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, destinoAncho, destinoAlto);
            ctx.drawImage(fuente, 0, 0, destinoAncho, destinoAlto);
            if (fuente.close) { fuente.close(); }

            var calidades = [0.82, 0.7, 0.55];
            return calidades.reduce(function (promesa, calidad) {
                return promesa.then(function (blob) {
                    if (blob) { return blob; }
                    return aBlob(lienzo, calidad).then(function (nuevo) {
                        if (nuevo && nuevo.size <= LIMITE) { return nuevo; }
                        return null;
                    });
                });
            }, Promise.resolve(null)).then(function (blob) {
                if (blob) { return blob; }
                if (['image/jpeg', 'image/png', 'image/webp'].indexOf(archivo.type) >= 0 && archivo.size <= LIMITE) {
                    return archivo;
                }
                throw new Error('La foto no se pudo reducir. Intenta con otra.');
            });
        });
    };

    M4.urlBlob = function (blob) {
        return global.URL.createObjectURL(blob);
    };

    // ---------------------------------------------------------------- PWA

    M4.registrarServiceWorker = function () {
        if (!('serviceWorker' in global.navigator)) {
            return;
        }
        global.addEventListener('load', function () {
            global.navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {
                /* sin PWA: la app sigue funcionando como web normal */
            });
        });
    };

    M4.iniciarBateria = function (alCambiar) {
        if (!global.navigator.getBattery) {
            return;
        }
        global.navigator.getBattery().then(function (bateria) {
            function avisar() { alCambiar(Math.round(bateria.level * 100)); }
            avisar();
            bateria.addEventListener('levelchange', avisar);
        }).catch(function () { /* sin batería */ });
    };

    global.M4 = M4;
})(window);
