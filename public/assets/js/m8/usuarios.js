/*
 * M8 - Pantalla admin/usuarios.php
 * Lista paginada de usuarios y acciones: crear, editar, activar/desactivar, desbloquear y resetear contraseña.
 * Endpoints (Carlos): usuarios/listar.php, ver.php, crear.php, actualizar.php, resetear_password.php.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-usuarios');
    if (!raiz) {
        return;
    }

    const { crear, vaciar, llamar, errorCampos } = RP;
    const MI_ID = parseInt(raiz.dataset.uid, 10);
    const POR_PAGINA = 25;

    const ROLES = {
        admin: 'Administrador',
        despachador: 'Despachador',
        gerente: 'Gerente',
        conductor: 'Conductor',
    };

    const cuerpo = document.getElementById('cuerpo-usuarios');
    const contPaginador = document.getElementById('paginador');
    const form = document.getElementById('form-usuario');
    const modalEl = document.getElementById('modal-usuario');
    const modal = new bootstrap.Modal(modalEl);
    const modalPw = new bootstrap.Modal(document.getElementById('modal-password'));
    const btnGuardar = document.getElementById('btn-guardar-usuario');

    let page = 1;
    let peticion = 0;   // descarta respuestas viejas si el usuario sigue escribiendo

    // ------------------------------------------------------------ listado

    function estaBloqueado(u) {
        if (typeof u.bloqueado === 'boolean') {
            return u.bloqueado;
        }
        return !!u.bloqueado_hasta && new Date(u.bloqueado_hasta).getTime() > Date.now();
    }

    function insignia(texto, clase) {
        return crear('span', { class: 'badge ' + clase }, texto);
    }

    function boton(texto, clase, icono, alClick, opciones = {}) {
        return crear('button', {
            type: 'button',
            class: 'btn btn-sm ' + clase,
            title: opciones.titulo || texto,
            'aria-label': texto + ' ' + (opciones.sujeto || ''),
            disabled: opciones.deshabilitado,
            onclick: alClick,
        }, crear('i', { class: 'bi bi-' + icono, 'aria-hidden': 'true' }));
    }

    function fila(u) {
        const bloqueado = estaBloqueado(u);
        const soyYo = u.id === MI_ID;
        const acciones = crear('div', { class: 'd-inline-flex gap-1' },
            boton('Editar', 'btn-outline-primary', 'pencil', () => abrirEdicion(u), { sujeto: u.nombre }),
            boton(u.activo ? 'Desactivar' : 'Activar', u.activo ? 'btn-outline-danger' : 'btn-outline-success',
                u.activo ? 'person-x' : 'person-check', () => alternarActivo(u),
                { sujeto: u.nombre, deshabilitado: soyYo && u.activo, titulo: soyYo && u.activo ? 'No puedes desactivarte a ti mismo' : null }),
            bloqueado ? boton('Desbloquear', 'btn-outline-warning', 'unlock', () => desbloquear(u), { sujeto: u.nombre }) : null,
            boton('Resetear contraseña', 'btn-outline-secondary', 'key', () => resetear(u), { sujeto: u.nombre }));

        return crear('tr', {},
            crear('td', {}, u.nombre),
            crear('td', {}, u.email),
            crear('td', {}, ROLES[u.rol] || u.rol),
            crear('td', {}, u.activo ? insignia('Sí', 'text-bg-success') : insignia('No', 'text-bg-secondary')),
            crear('td', {}, RP.fecha(u.ultimo_login)),
            crear('td', {}, bloqueado ? insignia('Bloqueado', 'text-bg-warning') : RP.guion),
            crear('td', { class: 'text-end' }, acciones));
    }

    async function cargar() {
        const id = ++peticion;
        vaciar(cuerpo).append(crear('tr', {}, crear('td', { colspan: '7' }, RP.spinner())));
        try {
            const { data, meta } = await llamar('usuarios/listar.php', {
                params: {
                    page,
                    por_pagina: POR_PAGINA,
                    q: document.getElementById('f-q').value.trim(),
                    rol: document.getElementById('f-rol').value,
                    activo: document.getElementById('f-activo').value,
                },
            });
            if (id !== peticion) {
                return;
            }
            vaciar(cuerpo);
            if (!data || data.length === 0) {
                cuerpo.append(crear('tr', {}, crear('td', { colspan: '7', class: 'text-center text-muted py-4' }, 'No hay resultados')));
            } else {
                data.forEach((u) => cuerpo.append(fila(u)));
            }
            RP.paginador(contPaginador, {
                meta, page, porPagina: POR_PAGINA, cantidad: (data || []).length,
                alCambiar: (p) => { page = p; cargar(); },
            });
        } catch (e) {
            if (id === peticion) {
                vaciar(cuerpo).append(crear('tr', {}, crear('td', { colspan: '7', class: 'text-center text-danger py-4' },
                    'No se pudo cargar la lista. ' + RP.errorMensaje(e))));
            }
        }
    }

    // ------------------------------------------------------------ crear y editar

    function prepararFormulario(titulo, u) {
        RP.limpiarCampos(form);
        form.reset();
        document.getElementById('modal-usuario-titulo').textContent = titulo;
        form.elements.usuario_id.value = u ? u.id : '';
        form.elements.nombre.value = u ? u.nombre : '';
        form.elements.email.value = u ? u.email : '';
        form.elements.rol.value = u ? u.rol : 'despachador';
        form.elements.telefono.value = u && u.telefono ? u.telefono : '';
        // La contraseña solo se define al crear; luego se usa «Resetear contraseña».
        document.getElementById('grupo-password').classList.toggle('d-none', !!u);
        const esYo = !!u && u.id === MI_ID;
        form.elements.rol.disabled = esYo;
        document.getElementById('u-rol-ayuda').textContent = esYo ? 'No puedes cambiar tu propio rol.' : '';
    }

    function abrirCreacion() {
        prepararFormulario('Nuevo usuario', null);
        modal.show();
    }

    async function abrirEdicion(resumen) {
        try {
            // Se pide el detalle para editar con los datos más recientes.
            const { data } = await llamar('usuarios/ver.php', { params: { usuario_id: resumen.id } });
            prepararFormulario('Editar usuario', data || resumen);
            modal.show();
        } catch (e) {
            // api() ya mostró el aviso de error.
        }
    }

    function mostrarPasswordTemporal(nombre, password) {
        document.getElementById('pw-usuario').textContent = nombre;
        document.getElementById('pw-valor').value = password;
        modalPw.show();
    }

    /** La contraseña temporal puede venir con distintos nombres según la implementación del endpoint. */
    function leerPasswordTemporal(data) {
        if (!data) {
            return null;
        }
        return data.password_temporal || data.password_generada || data.clave_temporal || data.password || null;
    }

    async function guardar(ev) {
        ev.preventDefault();
        RP.limpiarCampos(form);
        const editando = form.elements.usuario_id.value !== '';
        const cuerpoPeticion = {
            nombre: form.elements.nombre.value.trim(),
            email: form.elements.email.value.trim(),
            telefono: form.elements.telefono.value.trim() || null,
        };
        if (!form.elements.rol.disabled) {
            cuerpoPeticion.rol = form.elements.rol.value;
        }
        if (editando) {
            cuerpoPeticion.usuario_id = parseInt(form.elements.usuario_id.value, 10);
        } else if (form.elements.password.value !== '') {
            cuerpoPeticion.password = form.elements.password.value;
        }

        btnGuardar.disabled = true;
        try {
            const { data } = await llamar(editando ? 'usuarios/actualizar.php' : 'usuarios/crear.php', {
                metodo: 'POST',
                cuerpo: cuerpoPeticion,
            });
            modal.hide();
            RP.exito(editando ? 'Usuario actualizado' : 'Usuario creado');
            if (!editando) {
                const temporal = leerPasswordTemporal(data);
                if (temporal) {
                    mostrarPasswordTemporal(cuerpoPeticion.nombre, temporal);
                }
            }
            cargar();
        } catch (e) {
            const campos = errorCampos(e);
            if (campos) {
                RP.marcarCampos(form, campos);
            }
        } finally {
            btnGuardar.disabled = false;
        }
    }

    // ------------------------------------------------------------ acciones por fila

    async function accion(ruta, cuerpoPeticion, mensajeExito) {
        try {
            await llamar(ruta, { metodo: 'POST', cuerpo: cuerpoPeticion });
            RP.exito(mensajeExito);
            cargar();
            return true;
        } catch (e) {
            return false;   // api() ya mostró el error
        }
    }

    async function alternarActivo(u) {
        const desactivar = !!u.activo;
        const ok = await RP.confirmar({
            titulo: desactivar ? 'Desactivar usuario' : 'Activar usuario',
            mensaje: (desactivar ? '¿Desactivar a «' : '¿Activar a «') + u.nombre + '»?'
                + (desactivar ? ' Perderá el acceso en su siguiente petición.' : ''),
            textoBoton: desactivar ? 'Desactivar' : 'Activar',
            peligro: desactivar,
        });
        if (ok) {
            accion('usuarios/actualizar.php', { usuario_id: u.id, activo: !desactivar },
                desactivar ? 'Usuario desactivado' : 'Usuario activado');
        }
    }

    async function desbloquear(u) {
        const ok = await RP.confirmar({
            titulo: 'Desbloquear usuario',
            mensaje: '¿Desbloquear la cuenta de «' + u.nombre + '»?',
            textoBoton: 'Desbloquear',
        });
        if (ok) {
            accion('usuarios/actualizar.php', { usuario_id: u.id, desbloquear: true }, 'Usuario desbloqueado');
        }
    }

    async function resetear(u) {
        const ok = await RP.confirmar({
            titulo: 'Resetear contraseña',
            mensaje: 'Se generará una contraseña temporal para «' + u.nombre + '» y la actual dejará de funcionar. ¿Continuar?',
            textoBoton: 'Resetear',
            peligro: true,
        });
        if (!ok) {
            return;
        }
        try {
            const { data } = await llamar('usuarios/resetear_password.php', { metodo: 'POST', cuerpo: { usuario_id: u.id } });
            RP.exito('Contraseña reseteada');
            const temporal = leerPasswordTemporal(data);
            if (temporal) {
                mostrarPasswordTemporal(u.nombre, temporal);
            }
            cargar();
        } catch (e) {
            // api() ya mostró el error
        }
    }

    // ------------------------------------------------------------ eventos

    const recargarDesdeUno = () => { page = 1; cargar(); };
    document.getElementById('f-q').addEventListener('input', RP.debounce(recargarDesdeUno, 400));
    document.getElementById('f-rol').addEventListener('change', recargarDesdeUno);
    document.getElementById('f-activo').addEventListener('change', recargarDesdeUno);
    document.getElementById('filtros').addEventListener('submit', (ev) => ev.preventDefault());
    document.getElementById('btn-nuevo').addEventListener('click', abrirCreacion);
    form.addEventListener('submit', guardar);
    document.getElementById('pw-copiar').addEventListener('click', async () => {
        const campo = document.getElementById('pw-valor');
        try {
            await navigator.clipboard.writeText(campo.value);
            RP.exito('Contraseña copiada');
        } catch (e) {
            campo.select();   // sin permiso del portapapeles: queda seleccionada para copiar a mano
        }
    });
    // Al cerrar la ventana de la contraseña se borra del DOM: no debe quedar visible.
    document.getElementById('modal-password').addEventListener('hidden.bs.modal', () => {
        document.getElementById('pw-valor').value = '';
    });

    cargar();
})();
