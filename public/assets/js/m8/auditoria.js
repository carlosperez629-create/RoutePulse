/*
 * M8 - Pantalla admin/auditoria.php
 * Bitácora con filtros (usuario, acción, entidad, fechas) y panel con el detalle JSON.
 * Endpoint (Carlos): auditoria/listar.php. Roles: admin y gerente.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-auditoria');
    if (!raiz) {
        return;
    }

    const { crear, vaciar, llamar, errorCampos } = RP;
    const POR_PAGINA = 25;
    const esAdmin = raiz.dataset.rol === 'admin';

    // Catálogo de acciones de docs/modulos/M8-seguridad-rbac.md, sección 6.7.
    const ACCIONES = [
        'login_ok', 'login_fallido', 'login_bloqueado', 'logout', 'password_cambiar', 'usuario_crear',
        'usuario_actualizar', 'password_resetear', 'configuracion_actualizar',
        'paquete_crear', 'paquete_actualizar', 'paquete_cambiar_estado', 'incidencia_crear', 'incidencia_resolver',
        'hub_crear', 'hub_actualizar',
        'ruta_generar', 'ruta_confirmar', 'ruta_reordenar', 'ruta_recalcular', 'ruta_cancelar', 'ruta_reasignar',
        'parada_omitir', 'vehiculo_crear', 'vehiculo_actualizar', 'conductor_crear', 'conductor_actualizar',
        'zona_crear', 'zona_actualizar',
        'ruta_iniciar', 'ruta_finalizar', 'entrega_registrar', 'entrega_fallida', 'otp_solicitar',
        'integracion_actualizar', 'api_key_regenerar', 'notificacion_reenviar', 'webhook_reprocesar',
        'kpi_exportar',
    ];

    const cuerpo = document.getElementById('cuerpo-auditoria');
    const contPaginador = document.getElementById('paginador');
    const errorFiltros = document.getElementById('error-filtros');
    const panel = new bootstrap.Offcanvas(document.getElementById('panel-detalle'));
    let campoUsuario = null;
    let page = 1;
    let peticion = 0;

    // ------------------------------------------------------------ filtros

    ACCIONES.forEach((a) => document.getElementById('f-accion').append(crear('option', { value: a }, a)));

    /** El administrador elige al usuario de una lista; el gerente (sin acceso a usuarios) escribe su ID. */
    async function prepararFiltroUsuario() {
        const grupo = document.getElementById('grupo-usuario');
        if (esAdmin) {
            campoUsuario = crear('select', { class: 'form-select', id: 'f-usuario' }, crear('option', { value: '' }, 'Todos'));
            grupo.append(campoUsuario);
            try {
                const { data } = await llamar('usuarios/listar.php', { params: { por_pagina: 100 } });
                (data || []).forEach((u) => campoUsuario.append(crear('option', { value: String(u.id) }, u.nombre + ' (' + u.email + ')')));
            } catch (e) {
                // Sin la lista, el filtro por usuario queda en «Todos».
            }
        } else {
            campoUsuario = crear('input', { class: 'form-control', id: 'f-usuario', type: 'number', min: '1', placeholder: 'ID de usuario' });
            grupo.append(campoUsuario);
        }
    }

    function leerFiltros() {
        return {
            usuario_id: campoUsuario ? campoUsuario.value.trim() : '',
            accion: document.getElementById('f-accion').value,
            entidad: document.getElementById('f-entidad').value.trim(),
            desde: document.getElementById('f-desde').value,
            hasta: document.getElementById('f-hasta').value,
        };
    }

    // ------------------------------------------------------------ listado

    function nombreUsuario(a) {
        if (a.usuario_nombre) {
            return a.usuario_nombre;
        }
        if (a.usuario && a.usuario.nombre) {
            return a.usuario.nombre;
        }
        return a.usuario_id ? 'Usuario #' + a.usuario_id : 'Sistema';
    }

    /** `detalle` llega como objeto; si llegara como texto JSON, se interpreta. */
    function leerDetalle(a) {
        let d = a.detalle;
        if (typeof d === 'string' && d !== '') {
            try {
                d = JSON.parse(d);
            } catch (e) {
                return d;
            }
        }
        return d === undefined ? null : d;
    }

    function verDetalle(a) {
        const resumen = vaciar(document.getElementById('detalle-resumen'));
        [
            ['Fecha', RP.fecha(a.created_at)],
            ['Usuario', nombreUsuario(a)],
            ['Acción', a.accion],
            ['Entidad', a.entidad + (a.entidad_id ? ' #' + a.entidad_id : '')],
            ['IP', a.ip || RP.guion],
            ['Navegador', a.user_agent || RP.guion],
        ].forEach(([k, v]) => resumen.append(crear('dt', { class: 'col-4' }, k), crear('dd', { class: 'col-8 text-break' }, v)));
        const d = leerDetalle(a);
        document.getElementById('detalle-json').textContent = d === null ? 'Sin detalle' : (typeof d === 'string' ? d : JSON.stringify(d, null, 2));
        panel.show();
    }

    function fila(a) {
        return crear('tr', {},
            crear('td', { class: 'text-nowrap' }, RP.fecha(a.created_at)),
            crear('td', {}, nombreUsuario(a)),
            crear('td', {}, crear('code', {}, a.accion)),
            crear('td', {}, a.entidad + (a.entidad_id ? ' #' + a.entidad_id : '')),
            crear('td', { class: 'small' }, a.ip || RP.guion),
            crear('td', { class: 'text-end' },
                crear('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', onclick: () => verDetalle(a) }, 'Ver')));
    }

    async function cargar() {
        const id = ++peticion;
        errorFiltros.textContent = '';
        vaciar(cuerpo).append(crear('tr', {}, crear('td', { colspan: '6' }, RP.spinner())));
        try {
            const { data, meta } = await llamar('auditoria/listar.php', { params: { page, por_pagina: POR_PAGINA, ...leerFiltros() } });
            if (id !== peticion) {
                return;
            }
            vaciar(cuerpo);
            if (!data || data.length === 0) {
                cuerpo.append(crear('tr', {}, crear('td', { colspan: '6', class: 'text-center text-muted py-4' }, 'No hay resultados')));
            } else {
                data.forEach((a) => cuerpo.append(fila(a)));
            }
            RP.paginador(contPaginador, {
                meta, page, porPagina: POR_PAGINA, cantidad: (data || []).length,
                alCambiar: (p) => { page = p; cargar(); },
            });
        } catch (e) {
            if (id !== peticion) {
                return;
            }
            const campos = errorCampos(e);
            errorFiltros.textContent = campos ? Object.values(campos).join(' ') : '';
            vaciar(cuerpo).append(crear('tr', {}, crear('td', { colspan: '6', class: 'text-center text-danger py-4' },
                'No se pudo cargar la bitácora. ' + RP.errorMensaje(e))));
            vaciar(contPaginador);
        }
    }

    // ------------------------------------------------------------ eventos

    document.getElementById('filtros').addEventListener('submit', (ev) => {
        ev.preventDefault();
        const f = leerFiltros();
        if (f.desde && f.hasta && f.desde > f.hasta) {
            errorFiltros.textContent = 'La fecha «Desde» no puede ser posterior a «Hasta».';
            return;
        }
        page = 1;
        cargar();
    });
    document.getElementById('btn-limpiar').addEventListener('click', () => {
        document.getElementById('filtros').reset();
        page = 1;
        cargar();
    });

    prepararFiltroUsuario().then(cargar);
})();
