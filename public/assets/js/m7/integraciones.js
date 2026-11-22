(() => {
    'use strict';
    if (!document.getElementById('pantalla-integraciones')) { return; }
    const { crear, vaciar, llamar } = RP;
    const $ = (id) => document.getElementById(id);
    const msg = (t) => { $('msg').textContent = t; $('msg').classList.remove('d-none'); };
    const err = (e) => msg(e && e.mensaje ? e.mensaje : 'Error');

    async function cargar() {
        const { data } = await llamar('integraciones/listar.php');
        vaciar($('filas'));
        data.forEach((i) => {
            const act = crear('input', { type: 'checkbox', checked: i.activo, onchange: () => guardar({ id: i.id, activo: act.checked }) });
            const sim = crear('input', { type: 'checkbox', checked: i.modo_simulado, onchange: () => guardar({ id: i.id, modo_simulado: sim.checked }) });
            const key = crear('button', { class: 'btn btn-sm btn-outline-secondary', type: 'button', onclick: async () => {
                try { const r = await llamar('integraciones/actualizar.php', { metodo: 'POST', cuerpo: { id: i.id, generar_api_key: true } }); msg('API key (cópiala, no se vuelve a mostrar): ' + r.data.api_key); } catch (e) { err(e); }
            } }, 'Generar API key');
            $('filas').appendChild(crear('tr', {}, crear('td', {}, i.codigo), crear('td', {}, i.tipo), crear('td', {}, act), crear('td', {}, sim), crear('td', {}, i.codigo === 'erp_generico' ? key : '')));
        });
        const w = await llamar('integraciones/webhooks.php');
        vaciar($('webhooks'));
        w.data.forEach((e) => {
            const b = crear('button', { class: 'btn btn-sm btn-outline-secondary', type: 'button', onclick: async () => { try { await llamar('integraciones/reprocesar_webhook.php', { metodo: 'POST', cuerpo: { id: e.id } }); cargar(); } catch (x) { err(x); } } }, 'Reprocesar');
            $('webhooks').appendChild(crear('tr', {}, crear('td', {}, String(e.id)), crear('td', {}, e.codigo), crear('td', {}, e.evento_externo_id), crear('td', {}, e.estado), crear('td', {}, e.error || ''), crear('td', {}, b)));
        });
        const l = await llamar('integraciones/log.php');
        $('log').textContent = l.data.join('\n');
    }
    async function guardar(c) { try { await llamar('integraciones/actualizar.php', { metodo: 'POST', cuerpo: c }); msg('Guardado.'); } catch (e) { err(e); } }
    async function probar(c) { try { const r = await llamar('integraciones/probar.php', { metodo: 'POST', cuerpo: c }); $('salida').textContent = JSON.stringify(r.data, null, 2); cargar(); } catch (e) { err(e); } }
    $('t-geo').onclick = () => probar({ prueba: 'geocodificar' });
    $('t-matriz').onclick = () => probar({ prueba: 'matriz' });
    $('t-notif').onclick = () => probar({ prueba: 'notificacion', paquete_id: Number($('t-paq').value) });
    cargar().catch(err);
})();
