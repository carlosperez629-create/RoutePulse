/*
 * M8 - Pantalla admin/configuracion.php
 * Parámetros de la tabla `configuracion` agrupados por módulo dueño, con edición en línea y
 * validación por tipo (int, float, bool, string, json). El servidor tiene la última palabra.
 * Endpoints (Carlos): configuracion/listar.php y configuracion/actualizar.php.
 */
(() => {
    'use strict';

    const raiz = document.getElementById('pantalla-configuracion');
    if (!raiz) {
        return;
    }

    const { crear, vaciar, llamar, errorCampos } = RP;
    const contGrupos = document.getElementById('grupos');

    const MODULOS = {
        M1: 'M1 · Despacho y rutas',
        M2: 'M2 · Rastreo GPS',
        M3: 'M3 · Trazabilidad y HUBs',
        M4: 'M4 · App del conductor',
        M5: 'M5 · Portal del cliente',
        M6: 'M6 · Dashboard',
        M7: 'M7 · Integraciones',
        M8: 'M8 · Seguridad y usuarios',
    };
    const TIPOS = { int: 'Entero', float: 'Decimal', bool: 'Sí / No', string: 'Texto', json: 'JSON' };

    let parametros = [];

    /** El valor se maneja siempre como texto (así se guarda en la base de datos). */
    function aTexto(valor, tipo) {
        if (tipo === 'bool') {
            return (valor === true || valor === 1 || valor === '1' || valor === 'true') ? '1' : '0';
        }
        if (valor !== null && typeof valor === 'object') {
            return JSON.stringify(valor);
        }
        return valor === null || valor === undefined ? '' : String(valor);
    }

    /** Validación por tipo en el navegador. Devuelve el mensaje de error o null. */
    function validarTipo(texto, tipo) {
        const t = texto.trim();
        switch (tipo) {
            case 'int':
                return /^-?\d+$/.test(t) ? null : 'Escribe un número entero.';
            case 'float':
                return t !== '' && Number.isFinite(Number(t)) ? null : 'Escribe un número válido.';
            case 'bool':
                return t === '0' || t === '1' ? null : 'Elige Sí o No.';
            case 'json':
                try {
                    JSON.parse(t);
                    return null;
                } catch (e) {
                    return 'El JSON no es válido.';
                }
            default:
                return t !== '' ? null : 'El valor no puede estar vacío.';
        }
    }

    function crearEntrada(p, valor) {
        const comunes = { id: 'cfg-' + p.clave, 'aria-label': 'Valor de ' + p.clave };
        switch (p.tipo) {
            case 'bool': {
                const sel = crear('select', { ...comunes, class: 'form-select form-select-sm' },
                    crear('option', { value: '1' }, 'Sí'), crear('option', { value: '0' }, 'No'));
                sel.value = valor;
                return sel;
            }
            case 'json': {
                const ta = crear('textarea', { ...comunes, class: 'form-control form-control-sm font-monospace', rows: '2' });
                ta.value = valor;
                return ta;
            }
            case 'int':
            case 'float': {
                const inp = crear('input', { ...comunes, type: 'number', step: p.tipo === 'int' ? '1' : 'any', class: 'form-control form-control-sm' });
                inp.value = valor;
                return inp;
            }
            default: {
                const inp = crear('input', { ...comunes, type: 'text', maxlength: '500', class: 'form-control form-control-sm' });
                inp.value = valor;
                return inp;
            }
        }
    }

    function filaParametro(p) {
        let original = aTexto(p.valor, p.tipo);
        const entrada = crearEntrada(p, original);
        const error = crear('div', { class: 'invalid-feedback' });
        const guardar = crear('button', { type: 'button', class: 'btn btn-sm btn-primary', disabled: true }, 'Guardar');
        const tr = crear('tr', { 'data-clave': p.clave },
            crear('td', {}, crear('code', {}, p.clave)),
            crear('td', { class: 'small' }, p.descripcion || RP.guion),
            crear('td', {}, crear('span', { class: 'badge text-bg-light border' }, TIPOS[p.tipo] || p.tipo)),
            crear('td', { class: 'col-valor' }, entrada, error),
            crear('td', { class: 'text-end' }, guardar));

        const alCambiar = () => {
            guardar.disabled = entrada.value === original;
            tr.classList.toggle('table-warning', entrada.value !== original);
            entrada.classList.remove('is-invalid');
        };
        entrada.addEventListener('input', alCambiar);
        entrada.addEventListener('change', alCambiar);
        entrada.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' && p.tipo !== 'json' && !guardar.disabled) {
                ev.preventDefault();
                enviar();
            }
        });
        guardar.addEventListener('click', enviar);

        async function enviar() {
            const valor = p.tipo === 'string' ? entrada.value : entrada.value.trim();
            const mensaje = validarTipo(valor, p.tipo);
            if (mensaje) {
                entrada.classList.add('is-invalid');
                error.textContent = mensaje;
                return;
            }
            guardar.disabled = true;
            try {
                await llamar('configuracion/actualizar.php', { metodo: 'POST', cuerpo: { clave: p.clave, valor } });
                original = valor;
                entrada.value = valor;
                tr.classList.remove('table-warning');
                RP.exito('Parámetro «' + p.clave + '» actualizado');
            } catch (e) {
                const campos = errorCampos(e);
                entrada.classList.add('is-invalid');
                error.textContent = (campos && (campos.valor || campos.clave)) || RP.errorMensaje(e);
                guardar.disabled = false;
            }
        }
        return tr;
    }

    function dibujar() {
        const filtro = document.getElementById('f-buscar').value.trim().toLowerCase();
        const visibles = parametros.filter((p) => !filtro
            || p.clave.toLowerCase().includes(filtro)
            || (p.descripcion || '').toLowerCase().includes(filtro));

        vaciar(contGrupos);
        if (visibles.length === 0) {
            contGrupos.append(crear('p', { class: 'text-center text-muted py-4' }, 'No hay resultados'));
            return;
        }
        const porModulo = new Map();
        visibles.forEach((p) => {
            const m = p.modulo_duenio || 'General';
            if (!porModulo.has(m)) {
                porModulo.set(m, []);
            }
            porModulo.get(m).push(p);
        });
        [...porModulo.keys()].sort().forEach((m) => {
            const cuerpo = crear('tbody');
            porModulo.get(m).sort((a, b) => a.clave.localeCompare(b.clave)).forEach((p) => cuerpo.append(filaParametro(p)));
            contGrupos.append(crear('section', { class: 'mb-4' },
                crear('h2', { class: 'h5' }, MODULOS[m] || m),
                crear('div', { class: 'table-responsive' },
                    crear('table', { class: 'table table-sm align-middle' },
                        crear('thead', {}, crear('tr', {},
                            crear('th', { scope: 'col' }, 'Parámetro'),
                            crear('th', { scope: 'col' }, 'Descripción'),
                            crear('th', { scope: 'col' }, 'Tipo'),
                            crear('th', { scope: 'col' }, 'Valor'),
                            crear('th', { scope: 'col' }, ''))),
                        cuerpo))));
        });
    }

    async function cargar() {
        vaciar(contGrupos).append(RP.spinner());
        try {
            const { data } = await llamar('configuracion/listar.php');
            parametros = Array.isArray(data) ? data : [];
            dibujar();
        } catch (e) {
            vaciar(contGrupos).append(crear('p', { class: 'text-center text-danger py-4' },
                'No se pudieron cargar los parámetros. ' + RP.errorMensaje(e)));
        }
    }

    // Al buscar se redibuja con los valores guardados; los cambios sin guardar de otras filas se pierden.
    document.getElementById('f-buscar').addEventListener('input', RP.debounce(dibujar, 300));
    cargar();
})();
