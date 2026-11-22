/*
 * sw.js - Service Worker de la PWA del conductor (M4).
 * Shell con cache-first; navegación y mi_ruta.php con network-first y caída a la última copia.
 * No cachea POST ni el resto de la API (mutaciones y archivos siempre van a la red).
 */
'use strict';

const VERSION = 'v1';
const CACHE_SHELL = 'routepulse-conductor-shell-' + VERSION;
const CACHE_PAGINAS = 'routepulse-conductor-paginas-' + VERSION;
const CACHE_DATOS = 'routepulse-conductor-datos-' + VERSION;
const CACHES_ACTUALES = [CACHE_SHELL, CACHE_PAGINAS, CACHE_DATOS];

const SHELL = [
    './offline.html',
    './manifest.json',
    '../assets/vendor/bootstrap/bootstrap.min.css',
    '../assets/vendor/bootstrap/bootstrap.bundle.min.js',
    '../assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    '../assets/vendor/signature_pad/signature_pad.umd.min.js',
    '../assets/css/estados.css',
    '../assets/css/app.css',
    '../assets/css/m4.css',
    '../assets/js/api.js',
    '../assets/js/ui.js',
    '../assets/js/gps_tracker.js',
    '../assets/js/m4/comun.js',
    '../assets/js/m4/cola.js',
    '../assets/js/m4/app.js',
    '../assets/js/m4/parada.js',
    '../assets/img/icon-192.png',
    '../assets/img/icon-512.png',
    '../assets/img/icon-maskable-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_SHELL)
            .then((cache) => cache.addAll(SHELL))
            // La página de la ruta se guarda aparte: si la sesión venció, no debe tumbar la instalación.
            .then(() => caches.open(CACHE_PAGINAS))
            .then((cache) => cache.add(new URL('./', self.registration.scope).href).catch(() => undefined))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((nombres) => Promise.all(
                nombres
                    .filter((nombre) => nombre.indexOf('routepulse-conductor-') === 0 && CACHES_ACTUALES.indexOf(nombre) === -1)
                    .map((nombre) => caches.delete(nombre))
            ))
            .then(() => self.clients.claim())
    );
});

async function redPrimero(request, nombreCache) {
    const cache = await caches.open(nombreCache);
    try {
        const respuesta = await fetch(request);
        if (respuesta && respuesta.ok) {
            cache.put(request, respuesta.clone());
        }
        return respuesta;
    } catch (falla) {
        const guardada = await cache.match(request);
        if (guardada) {
            return guardada;
        }
        throw falla;
    }
}

async function navegacion(request) {
    const cache = await caches.open(CACHE_PAGINAS);
    try {
        const respuesta = await fetch(request);
        if (respuesta && respuesta.ok && respuesta.type === 'basic') {
            cache.put(request, respuesta.clone());
        }
        return respuesta;
    } catch (falla) {
        const exacta = await cache.match(request);
        if (exacta) {
            return exacta;
        }
        const indice = await cache.match(new URL('./', self.registration.scope).href)
            || await cache.match(new URL('./index.php', self.registration.scope).href);
        if (indice) {
            return indice;
        }
        const fuera = await caches.match(new URL('./offline.html', self.location).href);
        return fuera || Response.error();
    }
}

async function cachePrimero(request) {
    const cache = await caches.open(CACHE_SHELL);
    const guardada = await cache.match(request);
    if (guardada) {
        return guardada;
    }
    const respuesta = await fetch(request);
    if (respuesta && respuesta.ok) {
        cache.put(request, respuesta.clone());
    }
    return respuesta;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.endsWith('/api/conductor/mi_ruta.php')) {
        event.respondWith(redPrimero(request, CACHE_DATOS));
        return;
    }
    if (url.pathname.indexOf('/api/') !== -1) {
        return;   // el resto de la API y los archivos no se cachean
    }

    if (request.mode === 'navigate') {
        event.respondWith(navegacion(request));
        return;
    }

    event.respondWith(cachePrimero(request));
});
