(() => {
    'use strict';
    if (!document.getElementById('pantalla-notificaciones')) { return; }
    const { crear, vaciar, llamar } = RP;
    const $ = (id) => document.getElementById(id);
    async function cargar() {
        const estado = $('f-estado').value;
        const { data } = await llamar('notificaciones/listar.php', { params: estado ? { estado } : null });
        vaciar($('filas'));
        data.forEach((n) => {
            const b = n.estado === 'enviado' ? '' : crear('button', { class: 'btn btn-sm btn-outline-secondary', type: 'button', onclick: async () => { await llamar('notificaciones/reenviar.php', { metodo: 'POST', cuerpo: { id: n.id } }); cargar(); } }, 'Reenviar');
            $('filas').appendChild(crear('tr', {}, crear('td', {}, String(n.id)), crear('td', {}, n.codigo_guia || ''), crear('td', {}, n.tipo), crear('td', {}, n.canal), crear('td', {}, n.destino), crear('td', {}, n.estado), crear('td', {}, String(n.intentos)), crear('td', {}, n.error || ''), crear('td', {}, b)));
        });
        try { const l = await llamar('integraciones/log.php', { params: { archivo: 'sim' } }); $('sim').textContent = l.data.join('\n'); } catch (e) { $('sim').textContent = '(solo admin)'; }
    }
    $('f-estado').onchange = cargar;
    cargar();
})();
