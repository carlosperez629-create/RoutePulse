/*
 * M6 - Filtros compartidos por dashboard, mapa de calor y reportes (objeto global RP6).
 * Los selectores de zona y conductor se llenan con los propios endpoints de M6
 * (kpi/por_zona.php y kpi/ranking_conductores.php), que listan todas las zonas y conductores activos.
 */
(() => {
    'use strict';

    const MAX_DIAS = 366;
    const { crear, llamar } = RP;
    const id = (x) => document.getElementById(x);

    /** Llena los selects de zona y conductor (si existen en la página). */
    async function poblarOpciones() {
        const selZona = id('f-zona');
        const selConductor = id('f-conductor');
        const [zonas, conductores] = await Promise.allSettled([
            selZona ? llamar('kpi/por_zona.php') : Promise.resolve({ data: [] }),
            selConductor ? llamar('kpi/ranking_conductores.php', { params: { orden: 'nombre_asc' } }) : Promise.resolve({ data: [] }),
        ]);
        if (selZona && zonas.status === 'fulfilled') {
            (zonas.value.data || []).filter((z) => z.zona_id !== null).forEach((z) => {
                selZona.append(crear('option', { value: String(z.zona_id) }, z.nombre));
            });
        }
        if (selConductor && conductores.status === 'fulfilled') {
            (conductores.value.data || []).forEach((c) => {
                selConductor.append(crear('option', { value: String(c.conductor_id) }, c.nombre));
            });
        }
    }

    /** Parámetros actuales del formulario (solo los que tienen valor). */
    function leer() {
        const valor = (x) => (id(x) ? id(x).value : '');
        return {
            desde: valor('f-desde'),
            hasta: valor('f-hasta'),
            zona_id: valor('f-zona'),
            conductor_id: valor('f-conductor'),
            vehiculo_tipo: valor('f-vehiculo'),
        };
    }

    /** Valida el rango en el navegador (el servidor vuelve a validar). Devuelve el mensaje o null. */
    function validarRango({ desde, hasta }) {
        if (!desde || !hasta) {
            return 'Elige las fechas «Desde» y «Hasta».';
        }
        if (desde > hasta) {
            return 'La fecha «Desde» no puede ser posterior a «Hasta».';
        }
        const dias = Math.round((Date.parse(hasta) - Date.parse(desde)) / 86400000) + 1;
        if (dias > MAX_DIAS) {
            return 'El rango máximo es de ' + MAX_DIAS + ' días.';
        }
        return null;
    }

    /**
     * Conecta el formulario: al enviarlo valida y llama a `alAplicar(parametros)`.
     * Devuelve una función para disparar la carga inicial.
     */
    function iniciar(alAplicar) {
        const form = id('filtros');
        const error = id('error-filtros');
        const boton = id('btn-aplicar');
        const aplicar = async () => {
            const p = leer();
            const mensaje = validarRango(p);
            error.textContent = mensaje || '';
            if (mensaje) {
                return;
            }
            boton.disabled = true;
            try {
                await alAplicar(p);
            } finally {
                boton.disabled = false;
            }
        };
        form.addEventListener('submit', (ev) => {
            ev.preventDefault();
            aplicar();
        });
        return aplicar;
    }

    window.RP6 = { poblarOpciones, leer, validarRango, iniciar };
})();
