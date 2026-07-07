/*
 * Coda offline in IndexedDB (§8). Stesso schema usato dal service worker (public/sw.js):
 * DB 'mes-offline', store 'coda' con chiave client_uuid.
 */
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

export function aggiungi(azione) {
    // IndexedDB usa lo structured clone e NON puo' clonare i Proxy reattivi di Vue.
    // Serializziamo a JS puro (dati sempre JSON-safe) prima del put per evitare DataCloneError.
    const record = JSON.parse(JSON.stringify(azione));

    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).put(record);
                tx.oncomplete = () => resolve(record);
                tx.onerror = () => reject(tx.error);
            }),
    );
}

export function tutte() {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            }),
    );
}

export function rimuovi(uuid) {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).delete(uuid);
                tx.oncomplete = () => resolve();
                tx.onerror = () => reject(tx.error);
            }),
    );
}

export function conteggio() {
    return openDb().then(
        (db) =>
            new Promise((resolve, reject) => {
                const req = db.transaction(STORE, 'readonly').objectStore(STORE).count();
                req.onsuccess = () => resolve(req.result || 0);
                req.onerror = () => reject(req.error);
            }),
    );
}
