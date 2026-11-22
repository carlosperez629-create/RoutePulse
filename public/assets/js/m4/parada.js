/*
 * parada.js - Detalle de una parada y registro de entrega/fallo (M4, public/conductor/parada.php).
 * La parada se busca en la ruta cacheada (o se refresca con mi_ruta.php). La entrega y el fallo
 * se guardan primero en la cola offline y luego se sincronizan; al terminar se vuelve a la hoja de ruta.
 */
(function (global) {
    'use strict';

    var contenedor = document.getElementById('parada-app');
    if (!contenedor) {
        return;
    }

    var paradaId = parseInt(contenedor.dataset.paradaId || '0', 10);
    var estado = {
        ruta: null,
        parada: null,
        config: {},
        firmaPad: null,
        fotoEntrega: null,
        fotoFallo: null,
        comprimiendoEntrega: false,
        comprimiendoFallo: false,
        gps: null
    };

    function el(id) { return document.getElementById(id); }

    function mostrarError(mensaje) {
        el('parada-cargando').classList.add('d-none');
        el('parada-contenido').classList.add('d-none');
        var caja = el('parada-error');
        caja.textContent = mensaje;
        caja.classList.remove('d-none');
    }

    function buscarParada(ruta, id) {
        if (!ruta || !Array.isArray(ruta.paradas)) { return null; }
        var encontrada = null;
        ruta.paradas.forEach(function (p) {
            if (Number(p.id) === Number(id)) { encontrada = p; }
        });
        return encontrada;
    }

    // ---------------------------------------------------------------- Detalle

    function fila(etiqueta, valor) {
        if (valor === null || valor === undefined || valor === '') { return null; }
        return ui.crear('div', { className: 'd-flex justify-content-between gap-3 border-bottom py-2' },
            ui.crear('dt', { className: 'text-muted fw-normal mb-0', texto: etiqueta }),
            ui.crear('dd', { className: 'mb-0 text-end', texto: String(valor) }));
    }

    function ventana(parada) {
        var p = parada.paquete || {};
        if (!p.ventana_inicio && !p.ventana_fin) { return null; }
        return (p.ventana_inicio ? M4.hora(p.ventana_inicio) : '—') + '–' + (p.ventana_fin ? M4.hora(p.ventana_fin) : '—');
    }

    function renderDetalle(parada) {
        var p = parada.paquete || {};
        var lista = ui.crear('dl', { className: 'mb-0' });
        var filas = [
            fila('Guía', p.codigo_guia),
            fila('Dirección', p.direccion_entrega),
            fila('Referencia', p.referencia),
            fila('Peso', Number(p.peso_kg) > 0 ? ui.formatoNumero(p.peso_kg, 1) + ' kg' : null),
            fila('Ventana horaria', ventana(parada)),
            fila('ETA', parada.eta ? M4.hora(parada.eta) : null),
            fila('Cobro contra entrega', Number(p.cobro_contra_entrega) > 0
                ? ui.formatoMoneda(p.cobro_contra_entrega, p.moneda) : null),
            fila('Notas', p.notas)
        ];
        filas.forEach(function (f) { if (f) { lista.appendChild(f); } });
        el('parada-detalle').textContent = '';
        el('parada-detalle').appendChild(lista);

        var nav = el('parada-navegacion');
        nav.textContent = '';
        var enlaces = parada.navegacion || {};
        [['Abrir en Waze', enlaces.waze], ['Abrir en Google Maps', enlaces.google], ['Abrir en Apple Maps', enlaces.apple]]
            .forEach(function (par) {
                if (!par[1]) { return; }
                nav.appendChild(ui.crear('a', {
                    className: 'btn btn-outline-primary',
                    href: par[1], target: '_blank', rel: 'noopener'
                }, ui.crear('i', { className: 'bi bi-geo-alt', 'aria-hidden': 'true' }), ' ' + par[0]));
            });
        var telefono = p.destinatario_telefono ? String(p.destinatario_telefono).replace(/[^+0-9]/g, '') : '';
        if (telefono) {
            nav.appendChild(ui.crear('a', { className: 'btn btn-outline-secondary', href: 'tel:' + telefono },
                ui.crear('i', { className: 'bi bi-telephone', 'aria-hidden': 'true' }), ' Llamar a ' + p.destinatario_telefono));
        }
    }

    function mostrarFormulario(cual) {
        var entrega = el('form-entrega');
        var fallo = el('form-fallo');
        entrega.classList.toggle('d-none', cual !== 'entrega');
        fallo.classList.toggle('d-none', cual !== 'fallo');
        if (cual === 'entrega') {
            prepararFirma();
            if (estado.firmaPad) { redimensionarFirma(); }
            entrega.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            fallo.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function renderAcciones(parada) {
        var cont = el('acciones-parada');
        cont.textContent = '';

        if (parada.estado === 'llegado') {
            var entregar = ui.crear('button', { type: 'button', className: 'btn btn-success btn-touch' },
                ui.crear('i', { className: 'bi bi-check2-circle', 'aria-hidden': 'true' }), ' Entregar');
            entregar.addEventListener('click', function () { mostrarFormulario('entrega'); });
            var fallar = ui.crear('button', { type: 'button', className: 'btn btn-outline-danger btn-touch' },
                ui.crear('i', { className: 'bi bi-x-octagon', 'aria-hidden': 'true' }), ' No se pudo entregar');
            fallar.addEventListener('click', function () { mostrarFormulario('fallo'); });
            cont.appendChild(entregar);
            cont.appendChild(fallar);
        } else if (parada.estado === 'en_camino') {
            var llegue = ui.crear('button', { type: 'button', className: 'btn btn-primary btn-touch' },
                ui.crear('i', { className: 'bi bi-geo-alt-fill', 'aria-hidden': 'true' }), ' Llegué');
            llegue.addEventListener('click', function () { enviarLlegada(llegue); });
            cont.appendChild(llegue);
            cont.appendChild(ui.crear('div', { className: 'alert alert-info mb-0 mt-2',
                texto: 'Marca "Llegué" para registrar la entrega o el fallo.' }));
        } else if (parada.estado === 'pendiente') {
            cont.appendChild(ui.crear('div', { className: 'alert alert-info mb-0',
                texto: 'Esta parada todavía no está en camino. Vuelve a la hoja de ruta para continuar con el orden.' }));
        } else {
            cont.appendChild(ui.crear('div', { className: 'alert alert-success mb-0',
                texto: 'Esta parada ya está ' + ui.estadoTexto(parada.estado).toLowerCase() + '.' }));
        }
        el('form-entrega').classList.add('d-none');
        el('form-fallo').classList.add('d-none');
    }

    function render() {
        var parada = estado.parada;
        if (!parada) { return; }
        var p = parada.paquete || {};

        el('parada-cargando').classList.add('d-none');
        el('parada-error').classList.add('d-none');
        el('parada-contenido').classList.remove('d-none');

        el('parada-titulo').textContent = p.destinatario_nombre || ('Parada ' + parada.orden);
        var insignia = el('parada-estado');
        insignia.textContent = '';
        insignia.className = 'badge estado-' + String(parada.estado || '').replace(/[^a-z_]/g, '');
        insignia.textContent = ui.estadoTexto(parada.estado);

        renderDetalle(parada);
        renderAcciones(parada);
        aplicarConfig();
    }

    // ---------------------------------------------------------------- OTP / cobro

    function aplicarConfig() {
        var config = estado.config || {};
        var obligatorio = !!config.otp_obligatorio && global.navigator.onLine;
        el('bloque-otp').classList.toggle('d-none', false);
        var ayuda = el('otp-ayuda');
        if (!global.navigator.onLine) {
            ayuda.textContent = 'Sin conexión: puedes entregar sin código. Si lo escribes, se validará al sincronizar.';
        } else if (obligatorio) {
            ayuda.textContent = 'Obligatorio con conexión. El cliente recibe el código por SMS o WhatsApp.';
        } else {
            ayuda.textContent = 'Opcional. El cliente recibe el código por SMS o WhatsApp.';
        }

        var cobro = Number(estado.parada && estado.parada.paquete ? estado.parada.paquete.cobro_contra_entrega : 0);
        var bloque = el('bloque-cobro');
        bloque.classList.toggle('d-none', !(cobro > 0));
        if (cobro > 0) {
            el('monto-cobro').textContent = ui.formatoMoneda(cobro, estado.parada.paquete.moneda);
        }
    }

    function enviarOtp() {
        if (!global.navigator.onLine) {
            ui.toast('Necesitas conexión para enviar el código al cliente.', 'warning');
            return;
        }
        var boton = el('btn-otp');
        ui.bloquear(boton, true, 'Enviando…');
        api.post('conductor/solicitar_otp.php', { parada_id: Number(paradaId) }).then(function (respuesta) {
            ui.toast('Código enviado al cliente por ' + ((respuesta && respuesta.canal) || 'SMS') + '.', 'success');
            if (respuesta && respuesta.otp_debug) {
                el('otp-ayuda').textContent = 'Entorno de prueba · código: ' + respuesta.otp_debug;
            }
        }).catch(function (error) {
            ui.toast((error && (error.mensaje || error.message)) || 'No se pudo enviar el código.', 'danger');
        }).then(function () {
            ui.bloquear(boton, false);
        });
    }

    // ---------------------------------------------------------------- Firma

    function opcionesFirma() {
        return {
            minWidth: 1.2,
            maxWidth: 3.2,
            penColor: '#111827',
            backgroundColor: '#ffffff'
        };
    }

    function ajustarCanvas() {
        var lienzo = el('firma-canvas');
        var ancho = lienzo.offsetWidth;
        if (!ancho) { return false; }
        var ratio = Math.max(global.devicePixelRatio || 1, 1);
        var alto = lienzo.offsetHeight || 220;
        lienzo.width = Math.round(ancho * ratio);
        lienzo.height = Math.round(alto * ratio);
        lienzo.getContext('2d').scale(ratio, ratio);
        return true;
    }

    function prepararFirma() {
        if (estado.firmaPad || !global.SignaturePad) { return; }
        var lienzo = el('firma-canvas');
        if (!ajustarCanvas()) {
            // El formulario acaba de hacerse visible; se espera al siguiente cuadro.
            global.requestAnimationFrame(function () { prepararFirma(); });
            return;
        }
        estado.firmaPad = new global.SignaturePad(lienzo, opcionesFirma());
    }

    function redimensionarFirma() {
        if (!estado.firmaPad) { return; }
        var lienzo = el('firma-canvas');
        if (!lienzo.offsetWidth) { return; }
        var datos = estado.firmaPad.toData();
        ajustarCanvas();
        estado.firmaPad = new global.SignaturePad(lienzo, opcionesFirma());
        estado.firmaPad.fromData(datos);
    }

    // ---------------------------------------------------------------- Fotos

    function prepararFoto(input, previa, destino, alTerminar) {
        var archivo = input.files && input.files[0] ? input.files[0] : null;
        if (!archivo) {
            destino.blob = null;
            previa.classList.add('d-none');
            previa.removeAttribute('src');
            return;
        }
        if (!/^image\//.test(archivo.type || '')) {
            destino.blob = null;
            previa.classList.add('d-none');
            previa.removeAttribute('src');
            ui.toast('Elige una imagen JPG, PNG o WebP.', 'warning');
            input.value = '';
            return;
        }
        destino.comprimiendo = true;
        if (alTerminar) { alTerminar(true); }
        M4.comprimirImagen(archivo).then(function (blob) {
            destino.blob = blob;
            previa.src = M4.urlBlob(blob);
            previa.classList.remove('d-none');
        }).catch(function (error) {
            destino.blob = null;
            previa.classList.add('d-none');
            input.value = '';
            ui.toast((error && error.message) || 'No se pudo procesar la foto.', 'danger');
        }).then(function () {
            destino.comprimiendo = false;
            if (alTerminar) { alTerminar(false); }
        });
    }

    // ---------------------------------------------------------------- Envíos

    function limpiarErrores(formulario) {
        ui.limpiarErrores(formulario);
        formulario.querySelectorAll('.is-invalid').forEach(function (campo) { campo.classList.remove('is-invalid'); });
    }

    function validarComun() {
        var posicion = null;
        return M4.posicion(15000).then(function (pos) {
            posicion = pos;
            return posicion;
        });
    }

    function enviarLlegada(boton) {
        ui.bloquear(boton, true, 'Guardando…');
        M4.posicion(5000).then(function (pos) {
            var campos = { parada_id: Number(paradaId), ocurrido_at: M4.horaIso() };
            if (pos) {
                campos.lat = pos.lat;
                campos.lng = pos.lng;
                campos.precision_m = pos.precision_m;
            }
            return M4Cola.encolar({
                endpoint: 'conductor/llegada.php', tipo: 'json', campos: campos,
                parada_id: Number(paradaId), efecto: 'llegado'
            });
        }).then(function () {
            return M4.avanzarRutaLocal(paradaId, 'llegado').catch(function () { return null; });
        }).then(function (ruta) {
            if (!ruta) { return; }
            estado.ruta = ruta;
            estado.parada = buscarParada(ruta, paradaId);
            render();
            ui.toast(global.navigator.onLine ? 'Llegada guardada.' : 'Llegada guardada sin conexión.', 'success');
        }).catch(function (error) {
            ui.bloquear(boton, false);
            ui.toast((error && (error.mensaje || error.message)) || 'No se pudo guardar la llegada.', 'danger');
        });
    }

    function registrarEntrega(evento) {
        evento.preventDefault();
        var form = el('form-entrega');
        limpiarErrores(form);

        var nombre = el('receptor_nombre').value.trim();
        var documento = el('receptor_documento').value.trim();
        var otp = el('otp').value.trim();
        var nota = el('nota').value.trim();
        var completa = el('completa').checked;
        var obligatorio = !!estado.config.otp_obligatorio && global.navigator.onLine;

        var errores = {};
        if (!nombre) { errores.receptor_nombre = 'Escribe el nombre de quien recibe.'; }
        if (otp && !/^\d+$/.test(otp)) { errores.otp = 'El código debe tener solo dígitos.'; }
        if (obligatorio && !otp) { errores.otp = 'El código de entrega es obligatorio.'; }
        ui.marcarErrores(form, errores);

        if (Object.keys(errores).length) {
            var primero = form.querySelector('.is-invalid');
            if (primero) { primero.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return;
        }
        if (!estado.fotoEntrega) {
            ui.toast('Toma o elige la foto de la entrega.', 'warning');
            el('foto').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (estado.comprimiendoEntrega) {
            ui.toast('Espera a que la foto termine de procesarse.', 'warning');
            return;
        }
        if (!estado.firmaPad || estado.firmaPad.isEmpty()) {
            ui.toast('Pide al cliente que firme en el recuadro.', 'warning');
            el('firma-canvas').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var cobro = Number(estado.parada.paquete && estado.parada.paquete.cobro_contra_entrega || 0);
        if (cobro > 0 && !el('cobro_realizado').checked) {
            ui.toast('Marca "Cobro realizado" o registra el fallo con motivo "Cobro no realizado".', 'warning');
            el('cobro_realizado').focus();
            return;
        }

        var boton = el('btn-entregar');
        ui.bloquear(boton, true, 'Obteniendo ubicación…');

        validarComun().then(function (pos) {
            if (!pos) {
                throw new Error('No se pudo obtener tu ubicación. Activa el GPS y vuelve a intentarlo.');
            }
            var uuid = M4.uuid();
            var campos = {
                uuid: uuid,
                parada_id: Number(paradaId),
                receptor_nombre: nombre,
                completa: completa,
                lat: pos.lat,
                lng: pos.lng,
                precision_m: pos.precision_m,
                ocurrido_at: M4.horaIso(),
                fuera_de_linea: !global.navigator.onLine
            };
            if (documento) { campos.receptor_documento = documento; }
            if (otp) { campos.otp = otp; }
            if (nota) { campos.nota = nota; }

            return M4Cola.encolar({
                uuid: uuid,
                endpoint: 'conductor/entregar.php',
                tipo: 'multipart',
                campos: campos,
                firma_base64: estado.firmaPad.toDataURL('image/png'),
                foto_blob: estado.fotoEntrega,
                parada_id: Number(paradaId),
                efecto: 'entregada'
            });
        }).then(function () {
            return M4.avanzarRutaLocal(paradaId, 'entregada').catch(function () { return null; });
        }).then(function () {
            ui.toast(global.navigator.onLine ? 'Entrega registrada.' : 'Entrega guardada sin conexión. Se enviará al recuperar señal.', 'success');
            var sincronizacion = global.navigator.onLine
                ? M4Cola.sincronizar().catch(function () { return null; })
                : Promise.resolve();
            var esperaMaxima = new Promise(function (resolver) { global.setTimeout(resolver, 1500); });
            return Promise.race([sincronizacion, esperaMaxima]);
        }).then(function () {
            global.location.href = './';
        }).catch(function (error) {
            ui.bloquear(boton, false);
            ui.toast((error && (error.mensaje || error.message)) || 'No se pudo registrar la entrega.', 'danger');
        });
    }

    function registrarFallo(evento) {
        evento.preventDefault();
        var form = el('form-fallo');
        limpiarErrores(form);

        var motivo = el('motivo_fallo').value;
        var nota = el('nota_fallo').value.trim();

        var errores = {};
        if (!motivo) { errores.motivo_fallo = 'Elige el motivo del fallo.'; }
        ui.marcarErrores(form, errores);
        if (Object.keys(errores).length) {
            var primero = form.querySelector('.is-invalid');
            if (primero) { primero.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return;
        }
        if (motivo === 'paquete_danado' && !estado.fotoFallo) {
            ui.toast('Para "Paquete dañado" la foto es obligatoria.', 'warning');
            el('foto_fallo').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (estado.comprimiendoFallo) {
            ui.toast('Espera a que la foto termine de procesarse.', 'warning');
            return;
        }

        var boton = el('btn-fallar');
        ui.bloquear(boton, true, 'Obteniendo ubicación…');

        validarComun().then(function (pos) {
            if (!pos) {
                throw new Error('No se pudo obtener tu ubicación. Activa el GPS y vuelve a intentarlo.');
            }
            var uuid = M4.uuid();
            var campos = {
                uuid: uuid,
                parada_id: Number(paradaId),
                motivo_fallo: motivo,
                lat: pos.lat,
                lng: pos.lng,
                precision_m: pos.precision_m,
                ocurrido_at: M4.horaIso(),
                fuera_de_linea: !global.navigator.onLine
            };
            if (nota) { campos.nota = nota; }

            var operacion = {
                uuid: uuid,
                endpoint: 'conductor/fallar.php',
                tipo: 'multipart',
                campos: campos,
                parada_id: Number(paradaId),
                efecto: 'fallida'
            };
            if (estado.fotoFallo) { operacion.foto_blob = estado.fotoFallo; }
            return M4Cola.encolar(operacion);
        }).then(function () {
            return M4.avanzarRutaLocal(paradaId, 'fallida').catch(function () { return null; });
        }).then(function () {
            ui.toast(global.navigator.onLine ? 'Fallo registrado.' : 'Fallo guardado sin conexión. Se enviará al recuperar señal.', 'success');
            var sincronizacion = global.navigator.onLine
                ? M4Cola.sincronizar().catch(function () { return null; })
                : Promise.resolve();
            var esperaMaxima = new Promise(function (resolver) { global.setTimeout(resolver, 1500); });
            return Promise.race([sincronizacion, esperaMaxima]);
        }).then(function () {
            global.location.href = './';
        }).catch(function (error) {
            ui.bloquear(boton, false);
            ui.toast((error && (error.mensaje || error.message)) || 'No se pudo registrar el fallo.', 'danger');
        });
    }

    // ---------------------------------------------------------------- Estado del teléfono

    function actualizarGps(info) {
        estado.gps = info || estado.gps;
        var g = estado.gps || {};
        var texto = 'Inactivo';
        if (g.activo) {
            if (g.permiso === 'denegado') { texto = 'Permiso denegado'; }
            else if (g.permiso === 'concedido') { texto = 'Activo'; }
            else { texto = 'Iniciando…'; }
        } else if (g.permiso === 'denegado') {
            texto = 'Permiso denegado';
        }
        if (el('gps-estado')) { el('gps-estado').textContent = texto; }
        if (el('gps-precision')) {
            el('gps-precision').textContent = g.ultimaPrecision !== null && g.ultimaPrecision !== undefined
                ? Math.round(g.ultimaPrecision) + ' m' : '—';
        }
        if (el('gps-pendientes')) { el('gps-pendientes').textContent = String(g.pendientes || 0); }
        actualizarUltimaSincronizacion();
    }

    function actualizarUltimaSincronizacion() {
        if (!el('ultima-sincronizacion')) { return; }
        var opSync = M4Cola.ultimaSincronizacion();
        var gpsSync = estado.gps && estado.gps.ultimoEnvio ? estado.gps.ultimoEnvio : null;
        var ultima = null;
        if (opSync && gpsSync) {
            ultima = new Date(opSync) > new Date(gpsSync) ? opSync : gpsSync;
        } else {
            ultima = gpsSync || opSync;
        }
        el('ultima-sincronizacion').textContent = ultima ? M4.horaRelativa(ultima) : '—';
    }

    function iniciarGps() {
        if (!global.GpsTracker || !estado.ruta || !estado.ruta.ruta) { return; }
        if (estado.ruta.ruta.estado !== 'en_curso') { return; }
        var config = estado.config || {};
        global.GpsTracker.iniciar({
            rutaId: Number(estado.ruta.ruta.id),
            intervaloS: Number(config.gps_intervalo_s) || 10,
            alCambiarEstado: actualizarGps
        }).catch(function () { /* la app sigue sin GPS */ });
    }

    // ---------------------------------------------------------------- Carga

    function cargar(permitirRed) {
        return M4.obtenerRuta(!!permitirRed).then(function (ruta) {
            if (!ruta) {
                mostrarError('No tienes una ruta activa. Vuelve a la hoja de ruta.');
                return;
            }
            var parada = buscarParada(ruta, paradaId);
            if (!parada) {
                mostrarError('La parada no está en tu ruta actual. Puede que el despachador la haya reasignado.');
                return;
            }
            estado.ruta = ruta;
            estado.parada = parada;
            estado.config = ruta.config || {};
            render();
            iniciarGps();

            if (global.location.hash === '#fallo' && parada.estado === 'llegado') {
                mostrarFormulario('fallo');
            }
        }).catch(function (error) {
            if (error && error.codigo === 'NO_AUTENTICADO') { return; }
            mostrarError('No se pudo cargar la parada. Revisa tu conexión.');
        });
    }

    // ---------------------------------------------------------------- Eventos

    el('btn-otp').addEventListener('click', enviarOtp);
    el('btn-limpiar-firma').addEventListener('click', function () {
        if (estado.firmaPad) { estado.firmaPad.clear(); }
    });
    el('foto').addEventListener('change', function () {
        prepararFoto(el('foto'), el('foto-previa'), {
            get blob() { return estado.fotoEntrega; },
            set blob(valor) { estado.fotoEntrega = valor; },
            get comprimiendo() { return estado.comprimiendoEntrega; },
            set comprimiendo(valor) { estado.comprimiendoEntrega = valor; }
        });
    });
    el('foto_fallo').addEventListener('change', function () {
        prepararFoto(el('foto_fallo'), el('foto-fallo-previa'), {
            get blob() { return estado.fotoFallo; },
            set blob(valor) { estado.fotoFallo = valor; },
            get comprimiendo() { return estado.comprimiendoFallo; },
            set comprimiendo(valor) { estado.comprimiendoFallo = valor; }
        });
    });
    el('form-entrega').addEventListener('submit', registrarEntrega);
    el('form-fallo').addEventListener('submit', registrarFallo);

    var redimensionTimer = null;
    global.addEventListener('resize', function () {
        global.clearTimeout(redimensionTimer);
        redimensionTimer = global.setTimeout(redimensionFirma, 200);
    });
    global.addEventListener('online', function () {
        aplicarConfig();
        M4Cola.sincronizar().catch(function () { /* se reintenta */ });
    });
    global.addEventListener('offline', aplicarConfig);
    document.addEventListener('m4:cola', actualizarUltimaSincronizacion);
    document.addEventListener('m4:operacion-sincronizada', actualizarUltimaSincronizacion);

    M4Cola.iniciarAuto();
    M4.registrarServiceWorker();
    M4.iniciarBateria(function (porcentaje) {
        if (el('bateria')) { el('bateria').textContent = porcentaje + ' %'; }
    });
    cargar(true);

    global.setInterval(function () {
        if (document.visibilityState === 'visible') {
            actualizarUltimaSincronizacion();
            if (global.GpsTracker) { actualizarGps(global.GpsTracker.estado()); }
        }
    }, 10000);
})(window);
