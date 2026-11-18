'use strict';
/**
 * hub/etiqueta.php: guía de 100x150 mm con QR (solo el codigo_guia) y código de barras GS1-128 (AI 91).
 * El QR lo dibuja qrcode.js y las barras JsBarcode (CODE128 con FNC1 al inicio = GS1-128).
 */
(function () {
    const { el, $, get, limpiar } = window.RP;

    function etiqueta(p) {
        const qr = el('div', { className: 'rp-etiqueta-qr' });
        const barras = el('div', { className: 'rp-etiqueta-barras' });
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        barras.append(svg);

        const nodo = el('section', { className: 'rp-etiqueta', attrs: { 'aria-label': 'Etiqueta ' + p.codigo_guia } },
            el('div', { className: 'rp-etiqueta-cabecera' },
                el('div', { className: 'rp-etiqueta-logo', text: 'RoutePulse' }),
                p.prioridad === 'urgente' ? el('div', { className: 'rp-etiqueta-sello', text: 'URGENTE' }) : null),
            el('div', { className: 'rp-etiqueta-guia', text: p.codigo_guia }),
            el('div', { className: 'rp-etiqueta-codigos' }, qr, barras),
            el('div', { className: 'rp-etiqueta-destino' },
                el('div', { className: 'rp-et-nombre', text: p.destinatario_nombre }),
                el('div', { className: 'rp-et-dir', text: p.direccion_entrega + (p.referencia ? ' (' + p.referencia + ')' : '') }),
                el('div', { className: 'rp-et-ciudad', text: [p.ciudad, p.codigo_postal].filter(Boolean).join(' · ') }),
                el('div', { className: 'rp-et-ciudad', text: 'Tel: ' + p.destinatario_telefono }),
                el('div', { className: 'rp-et-ciudad fw-bold', text: 'Zona: ' + (p.zona_nombre || 'Sin zona') })),
            el('div', { className: 'rp-etiqueta-pie' },
                el('div', {}, el('div', { text: 'Peso: ' + p.peso_kg + ' kg' }), el('div', { text: RP.fecha(p.created_at) })),
                p.cobro_contra_entrega > 0 ? el('div', { className: 'rp-etiqueta-cod', text: 'COBRAR ' + RP.dinero(p.cobro_contra_entrega, p.moneda) }) : null));

        // QR con solo el código de guía
        new QRCode(qr, { text: p.qr_contenido, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
        // GS1-128: FNC1 + AI (91) + valor; el texto legible se muestra con paréntesis
        JsBarcode(svg, p.gs1_128.ai + p.gs1_128.valor, {
            format: 'CODE128', ean128: true, text: p.gs1_128.texto_legible, displayValue: true,
            width: 2, height: 70, margin: 0, fontSize: 18, textMargin: 2,
        });
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        return nodo;
    }

    (async function init() {
        const ids = (RP.param('ids') || '').split(',').map((s) => s.trim()).filter((s) => /^\d+$/.test(s));
        if (!ids.length) {
            $('#mensaje').append(el('div', { className: 'alert alert-warning', text: 'No se indicaron paquetes. Selecciona guías desde la lista de paquetes.' }));
            return;
        }
        try {
            const r = await get('paquetes/etiqueta.php', { ids: ids.join(',') });
            const cont = limpiar($('#etiquetas'));
            r.data.forEach((p) => cont.append(etiqueta(p)));
            $('#resumen').textContent = '(' + r.data.length + ' etiqueta' + (r.data.length === 1 ? '' : 's') + ')';
            $('#btn-imprimir').disabled = false;
            $('#btn-imprimir').addEventListener('click', () => window.print());
        } catch (e) {
            $('#mensaje').append(el('div', { className: 'alert alert-danger', text: 'No se pudieron cargar las etiquetas.' }));
        }
    })();
})();
