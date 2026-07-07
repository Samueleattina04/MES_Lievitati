/*
 * Service worker MES (§8). Strategia di caching + svuotamento coda offline.
 *
 * NOTA (§2-bis): i service worker funzionano solo in HTTPS (o su localhost). In produzione su IIS
 * il sito DEVE essere https. Qui e' scritto a mano (vanilla) per robustezza; in produzione puo'
 * essere sostituito/generato con Workbox mantenendo lo stesso contratto verso /api/sync. // TODO-DECISIONE
 */

const CACHE = 'mes-cache-v2';
const APP_SHELL = ['/manifest.webmanifest', '/icons/icon.svg', '/operatore/login'];

// --- IndexedDB (stesso schema del lato pagina: db.js) ---
const DB_NAME = 'mes-offline';
const STORE = 'coda';

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'client_uuid' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function idb(store, mode, fn) {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const tx = db.transaction(store, mode);
                const result = fn(tx.objectStore(store));
                tx.oncomplete = () => resolve(result && result.result !== undefined ? result.result : undefined);
                tx.onerror = () => reject(tx.error);
            }),
    );
}

function getAllAzioni() {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            }),
    );
}

function rimuovi(uuid) {
    return idb(STORE, 'readwrite', (s) => s.delete(uuid));
}

/**
 * Invia la coda a /api/sync e rimuove le azioni acked (ok, duplicato o errore permanente).
 * Le sole azioni con errore transitorio restano in coda per un nuovo tentativo.
 */
async function flushCoda() {
    const azioni = await getAllAzioni();
    if (azioni.length === 0) {
        return;
    }

    const resp = await fetch('/api/sync', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ azioni }),
    });
    if (!resp.ok) {
        throw new Error('sync HTTP ' + resp.status);
    }

    const { risultati = [] } = await resp.json();
    for (const r of risultati) {
        if (r.ok || r.duplicato || r.permanente) {
            await rimuovi(r.client_uuid);
        }
    }

    // Notifica le pagine aperte perche' aggiornino stato e contatore.
    const clients = await self.clients.matchAll({ includeUncontrolled: true });
    clients.forEach((c) => c.postMessage({ type: 'mes-sync-done', risultati }));
}

// --- Caching ---
self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return; // POST /api/sync ecc. passano diretti; l'offline e' gestito dalla coda IndexedDB.
    }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Asset buildati (hash nel nome): stale-while-revalidate. Altrimenti navigazioni/dati: network-first.
    // Entrambe le strategie garantiscono SEMPRE un Response (mai una promise che risolve undefined).
    event.respondWith(
        url.pathname.startsWith('/build/') ? staleWhileRevalidate(req) : networkFirst(req, url),
    );
});

async function staleWhileRevalidate(req) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(req);
    const fromNetwork = fetch(req)
        .then((res) => {
            if (res && res.ok) {
                cache.put(req, res.clone());
            }
            return res;
        })
        .catch(() => null);

    // Cache hit -> subito; altrimenti aspetta la rete; se anche quella fallisce -> fallback.
    const res = cached || (await fromNetwork);
    return res || fallbackOffline(req);
}

async function networkFirst(req, url) {
    try {
        const res = await fetch(req);
        if (res && res.ok && (req.mode === 'navigate' || url.pathname.startsWith('/operatore'))) {
            const cache = await caches.open(CACHE);
            cache.put(req, res.clone());
        }
        return res; // sempre un Response (anche 4xx/5xx: e' comunque valido)
    } catch {
        // Rete non disponibile: prova la cache.
        const cached = await caches.match(req);
        if (cached) {
            return cached;
        }
        // Per una navigazione non in cache, servi la shell dell'app (se presente).
        if (req.mode === 'navigate') {
            const shell = (await caches.match('/operatore')) || (await caches.match('/operatore/login'));
            if (shell) {
                return shell;
            }
        }
        return fallbackOffline(req);
    }
}

/** Fallback che restituisce SEMPRE un Response valido quando non c'e' ne' rete ne' cache. */
function fallbackOffline(req) {
    if (req.mode === 'navigate') {
        return new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            + '<title>Offline</title>'
            + '<body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;'
            + 'font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;text-align:center">'
            + '<div><h1>Offline</h1><p>Questa pagina non e\' disponibile offline.<br>Riconnettiti e riprova.</p></div>',
            { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/html; charset=utf-8' } },
        );
    }

    return new Response(JSON.stringify({ offline: true }), {
        status: 503,
        statusText: 'Offline',
        headers: { 'Content-Type': 'application/json' },
    });
}

// --- Background Sync (Chrome/Edge) ---
self.addEventListener('sync', (event) => {
    if (event.tag === 'mes-sync') {
        event.waitUntil(flushCoda());
    }
});

// --- Flush on demand dalla pagina (fallback per browser senza Background Sync, es. Safari) ---
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'mes-flush') {
        event.waitUntil(flushCoda());
    }
});
