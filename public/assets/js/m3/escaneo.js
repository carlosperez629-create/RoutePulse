'use strict';
/** hub/escaneo.php: ingreso al HUB y clasificación con lector de código de barras (el lector escribe y envía Enter). */
(function () {
    const { el, $, get, post } = window.RP;
    const campo = $('#codigo');
    const lista = $('#lista-escaneos');
    let ok = 0, error = 0, ocupado = false;
    let ctxAudio = null;

    function pitido(bueno) {
        if (!$('#sonido').checked) return;
        try {
            ctxAudio = ctxAudio || new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctxAudio.createOscillator(), ganancia = ctxAudio.createGain();
            osc.frequency.value = bueno ? 880 : 220;
            osc.type = bueno ? 'sine' : 'square';
            ganancia.gain.value = 0.08;
            osc.connect(ganancia); ganancia.connect(ctxAudio.destination);
            osc.start(); osc.stop(ctxAudio.currentTime + (bueno ? 0.12 : 0.35));
        } catch (e) { /* el sonido es opcional */ }
    }

    function registrar(codigo, bueno, mensaje) {
        const vacio = $('#vacio');
        if (vacio) vacio.remove();
        const item = el('li', { className: 'list-group-item d-flex justify-content-between align-items-start ' + (bueno ? 'rp-escaneo-ok' : 'rp-escaneo-error') },
            el('div', {}, el('div', { className: 'fw-semibold', text: codigo }), el('div', { className: 'small', text: mensaje })),
            el('span', { className: 'small text-muted ms-2', text: new Date().toLocaleTimeString('es-CO', { hour12: false }) }));
        lista.prepend(item);
        while (lista.children.length > 30) lista.removeChild(lista.lastChild);
        bueno ? ok++ : error++;
        $('#cont-ok').textContent = String(ok);
        $('#cont-error').textContent = String(error);
        $('#estado-escaneo').textContent = mensaje;
        pitido(bueno);
    }

    async function escanear() {
        const codigo = campo.value.trim();
        if (!codigo || ocupado) return;
        ocupado = true;
        campo.disabled = true;
        const accion = $('#accion').value;
        const datos = { codigo_guia: codigo, accion };
        if (accion === 'ingreso_hub') datos.hub_id = parseInt($('#hub').value, 10) || null;
        try {
            const r = await post('paquetes/escanear.php', datos, { silencioso: true });
            const p = r.data;
            let msg = accion === 'ingreso_hub' ? 'Ingresado al HUB' : 'Clasificado';
            if ((p.advertencias || []).includes('sin_coordenadas')) msg += ' (sin coordenadas: corrige la dirección para poder rutearlo)';
            registrar(p.codigo_guia, true, msg);
        } catch (e) {
            registrar(codigo, false, (e && e.message) || 'No se pudo procesar el escaneo');
        }
        campo.value = '';
        campo.disabled = false;
        ocupado = false;
        campo.focus();
    }

    campo.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); escanear(); } });
    $('#accion').addEventListener('change', () => {
        $('#bloque-hub').classList.toggle('d-none', $('#accion').value !== 'ingreso_hub');
        campo.focus();
    });
    // Foco permanente: si se pierde (y no se está usando otro control), vuelve al campo.
    document.addEventListener('click', (ev) => {
        if (!ev.target.closest('select, button, a, label, input[type=checkbox]')) campo.focus();
    });
    campo.addEventListener('blur', () => {
        setTimeout(() => {
            const a = document.activeElement;
            if (!a || a === document.body) campo.focus();
        }, 150);
    });

    (async function init() {
        try {
            const r = await get('hubs/listar.php', { activo: 1 });
            r.data.forEach((h) => $('#hub').append(el('option', { text: h.nombre, attrs: { value: h.id } })));
        } catch (e) { /* sin HUBs no se puede ingresar */ }
        campo.focus();
    })();
})();
