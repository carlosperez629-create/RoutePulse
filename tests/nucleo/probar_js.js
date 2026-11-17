// RoutePulse - Pruebas de api.js y ui.js en Node con un DOM y un fetch simulados.
// Uso (desde la raíz del proyecto):  node tests/nucleo/probar_js.js
// No necesita servidor, base de datos ni librerías: solo Node.js 18 o superior.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const ruta = require('path').join(__dirname, '..', '..', 'public', 'assets', 'js') + '/';

function crearEntorno(opts) {
    opts = opts || {};
    const metas = { 'rp-url-base': 'http://x/rp', 'rp-zona-horaria': opts.zona || 'America/Bogota' };
    const avisos = [];
    const marcados = [];
    class Elemento {
        constructor(t) { this.tagName = t; this.children = []; this.attrs = {}; this.classList = { add() {}, remove() {} }; this.textContent = ''; this.dataset = {}; }
        setAttribute(k, v) { this.attrs[k] = v; } appendChild(h) { this.children.push(h); return h; }
        addEventListener() {} get nodeType() { return 1; }
    }
    const document = {
        querySelector: (s) => { const m = /meta\[name="(.+)"\]/.exec(s); return m && metas[m[1]] !== undefined ? { getAttribute: () => metas[m[1]] } : null; },
        createElement: (t) => new Elemento(t), createTextNode: (t) => ({ nodeType: 3, textContent: t }),
        addEventListener() {}, getElementById: () => null, querySelectorAll: () => []
    };
    const llamadas = [];
    const ventana = {
        location: { pathname: opts.ruta || '/rp/pagina.php', href: '' },
        console, setTimeout, clearTimeout, URLSearchParams, AbortController, JSON, Intl, Date, Promise, Error, Object, Array, String, Number, Math, isFinite, isNaN,
        document, fetch: async (url, init) => { llamadas.push({ url, init }); return opts.fetch(url, init, llamadas); }
    };
    ventana.window = ventana; ventana.globalThis = ventana;
    vm.createContext(ventana);
    vm.runInContext(fs.readFileSync(ruta + 'ui.js', 'utf8'), ventana);
    vm.runInContext(fs.readFileSync(ruta + 'api.js', 'utf8'), ventana);
    ventana.ui.toast = (m, t) => avisos.push([m, t]);
    ventana.ui.marcarErrores = (f, c) => marcados.push(c);
    return { v: ventana, avisos, marcados, llamadas };
}
const resp = (json, status) => ({ status: status || 200, json: async () => json });
let ok = 0;
async function prueba(nombre, fn) { try { await fn(); ok++; console.log('  OK  ' + nombre); } catch (e) { console.log('FALLA ' + nombre + ' -> ' + e.message); process.exitCode = 1; } }

(async () => {
    // ---- ui.js: formatos ----
    const u = crearEntorno({ fetch: async () => resp({}) }).v.ui;
    await prueba('formatoFecha convierte UTC a la zona local (Bogotá UTC-5)', () => assert.strictEqual(u.formatoFecha('2026-11-13T15:04:05Z'), '13/11/2026 10:04'));
    await prueba('formatoFecha cruza de día según la zona', () => assert.strictEqual(u.formatoFecha('2026-11-14T03:30:00Z'), '13/11/2026 22:30'));
    await prueba('formatoFecha con solo fecha (DATE) no se desplaza', () => assert.strictEqual(u.formatoFecha('2026-11-13'), '13/11/2026'));
    await prueba('formatoFecha null y basura -> "—"', () => { assert.strictEqual(u.formatoFecha(null), '—'); assert.strictEqual(u.formatoFecha('no es fecha'), '—'); });
    await prueba('formatoFecha soloFecha', () => assert.strictEqual(u.formatoFecha('2026-11-13T15:04:05Z', { soloFecha: true }), '13/11/2026'));
    await prueba('formatoFecha medianoche muestra 00:xx (no 24:xx)', () => assert.strictEqual(u.formatoFecha('2026-11-13T05:05:00Z'), '13/11/2026 00:05'));
    await prueba('formatoNumero usa coma decimal', () => assert.strictEqual(u.formatoNumero(12.4, 1), '12,4'));
    await prueba('formatoPorcentaje / formatoKm', () => { assert.strictEqual(u.formatoPorcentaje(87.5), '87,5 %'); assert.strictEqual(u.formatoKm(12.44), '12,4 km'); });
    await prueba('formatoDuracionMin', () => { assert.strictEqual(u.formatoDuracionMin(95), '1 h 35 min'); assert.strictEqual(u.formatoDuracionMin(45), '45 min'); });
    await prueba('formatoDuracionSeg', () => { assert.strictEqual(u.formatoDuracionSeg(300), '5:00'); assert.strictEqual(u.formatoDuracionSeg(65), '1:05'); });
    await prueba('formatoMoneda y nulos', () => { assert.ok(/12,50/.test(u.formatoMoneda(12.5, 'USD'))); assert.strictEqual(u.formatoMoneda(null), '—'); });
    await prueba('estadoTexto / motivoTexto', () => { assert.strictEqual(u.estadoTexto('en_clasificacion'), 'En clasificación'); assert.strictEqual(u.motivoTexto('cliente_ausente'), 'Cliente ausente'); assert.strictEqual(u.estadoTexto('raro'), 'raro'); });
    await prueba('badgeEstado usa la clase estado-<estado>', () => { const b = u.badgeEstado('en_transito'); assert.strictEqual(b.className, 'badge estado-en_transito'); });

    // ---- api.js ----
    await prueba('GET arma la URL con filtros (lista -> coma, vacíos omitidos)', async () => {
        const e = crearEntorno({ fetch: async () => resp({ ok: true, data: { a: 1 } }) });
        const d = await e.v.api('paquetes/listar.php', { datos: { estado: ['creado', 'ingresado_hub'], q: '', page: 2, activo: true, nulo: null } });
        assert.deepStrictEqual(d, { a: 1 });
        assert.strictEqual(e.llamadas[0].url, 'http://x/rp/api/paquetes/listar.php?estado=creado%2Cingresado_hub&page=2&activo=1');
    });
    await prueba('POST pide el token CSRF una vez y lo envía en X-CSRF-Token', async () => {
        const e = crearEntorno({ fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'T1' } }) : resp({ ok: true, data: 5 }) });
        await e.v.api.post('x/a.php', { z: 1 }); await e.v.api.post('x/b.php', {});
        const posts = e.llamadas.filter(c => c.init.method === 'POST');
        assert.strictEqual(posts[0].init.headers['X-CSRF-Token'], 'T1');
        assert.strictEqual(e.llamadas.filter(c => c.url.includes('csrf.php')).length, 1, 'el token se reutiliza');
        assert.strictEqual(posts[0].init.body, '{"z":1}');
        assert.strictEqual(posts[0].init.headers['Content-Type'], 'application/json');
    });
    await prueba('CSRF_INVALIDO: pide token nuevo y reintenta UNA vez', async () => {
        let n = 0;
        const e = crearEntorno({ fetch: async (url) => {
            if (url.includes('csrf.php')) { n++; return resp({ ok: true, data: { csrf_token: 'T' + n } }); }
            return n < 2 ? resp({ ok: false, error: { codigo: 'CSRF_INVALIDO', mensaje: 'x' } }, 403) : resp({ ok: true, data: 'listo' });
        } });
        assert.strictEqual(await e.v.api.post('x/a.php', {}), 'listo');
        assert.strictEqual(n, 2);
    });
    await prueba('CSRF_INVALIDO persistente: falla tras un solo reintento', async () => {
        const e = crearEntorno({ fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'T' } }) : resp({ ok: false, error: { codigo: 'CSRF_INVALIDO', mensaje: 'x' } }, 403) });
        await assert.rejects(() => e.v.api.post('x/a.php', {}, { silencioso: true }), (er) => er.codigo === 'CSRF_INVALIDO');
        assert.strictEqual(e.llamadas.filter(c => c.init.method === 'POST').length, 2);
    });
    await prueba('VALIDACION marca campos del formulario y muestra aviso', async () => {
        const e = crearEntorno({ fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'T' } }) : resp({ ok: false, error: { codigo: 'VALIDACION', mensaje: 'Revisa los campos marcados.', campos: { email: 'mal' } } }, 422) });
        const form = { querySelectorAll: () => [] };
        await assert.rejects(() => e.v.api.post('x/a.php', {}, { formulario: form }), (er) => er.campos.email === 'mal' && er.http === 422);
        assert.deepStrictEqual(e.marcados[0], { email: 'mal' });
        assert.strictEqual(e.avisos[0][1], 'danger');
        assert.strictEqual(e.avisos[0][0], 'Revisa los campos marcados.');
    });
    await prueba('401 fuera del login redirige a login.php?expirada=1', async () => {
        const e = crearEntorno({ fetch: async () => resp({ ok: false, error: { codigo: 'NO_AUTENTICADO', mensaje: 'x' } }, 401) });
        await assert.rejects(() => e.v.api('auth/me.php'));
        assert.strictEqual(e.v.location.href, 'http://x/rp/login.php?expirada=1');
    });
    await prueba('401 en login.php NO redirige (muestra el error)', async () => {
        const e = crearEntorno({ ruta: '/rp/login.php', fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'T' } }) : resp({ ok: false, error: { codigo: 'NO_AUTENTICADO', mensaje: 'Correo o contraseña incorrectos.' } }, 401) });
        await assert.rejects(() => e.v.api.post('auth/login.php', {}));
        assert.strictEqual(e.v.location.href, '');
        assert.strictEqual(e.avisos[0][0], 'Correo o contraseña incorrectos.');
    });
    await prueba('respuesta que no es JSON -> ERROR_INTERNO con mensaje genérico', async () => {
        const e = crearEntorno({ fetch: async () => ({ status: 500, json: async () => { throw new Error('html'); } }) });
        await assert.rejects(() => e.v.api('x/a.php', { silencioso: true }), (er) => er.codigo === 'ERROR_INTERNO' && /Ocurrió un problema/.test(er.message));
    });
    await prueba('sin conexión -> SIN_CONEXION', async () => {
        const e = crearEntorno({ fetch: async () => { throw new TypeError('Failed to fetch'); } });
        await assert.rejects(() => e.v.api('x/a.php', { silencioso: true }), (er) => er.codigo === 'SIN_CONEXION');
    });
    await prueba('completa:true devuelve {ok,data,meta}; el login actualiza el token CSRF', async () => {
        const e = crearEntorno({ fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'VIEJO' } }) : resp({ ok: true, data: { csrf_token: 'NUEVO', filas: [] }, meta: { page: 1 } }) });
        const r = await e.v.api.post('auth/login.php', {}, { completa: true });
        assert.deepStrictEqual(r.meta, { page: 1 });
        await e.v.api.post('x/a.php', {});
        const ultimo = e.llamadas.filter(c => c.init.method === 'POST').pop();
        assert.strictEqual(ultimo.init.headers['X-CSRF-Token'], 'NUEVO');
    });
    await prueba('subir() manda FormData sin Content-Type', async () => {
        const e = crearEntorno({ fetch: async (url) => url.includes('csrf.php') ? resp({ ok: true, data: { csrf_token: 'T' } }) : resp({ ok: true, data: 1 }) });
        const fd = { fake: true };
        await e.v.api.subir('conductor/entregar.php', fd);
        const p = e.llamadas.find(c => c.init.method === 'POST');
        assert.strictEqual(p.init.body, fd); assert.strictEqual(p.init.headers['Content-Type'], undefined);
    });
    console.log('\nJS: ' + ok + ' pruebas correctas' + (process.exitCode ? ' (HAY FALLAS)' : ''));
})();
