/*
 * Client di sincronizzazione offline (§8). Ogni azione operatore passa da qui:
 *  - online: POST immediato a /api/sync;
 *  - offline o rete KO: accodata in IndexedDB con client_uuid (UUID v4) e ritentata.
 *
 * Rilevamento ritorno connessione: Background Sync API dove disponibile (Chrome/Edge),
 * fallback su evento 'online' + polling periodico (Safari/iPad) — vedi §2-bis.3.
 */
import { ref } from 'vue';
import { aggiungi, conteggio, rimuovi, tutte } from './db';

export const online = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
export const inSospeso = ref(0);

const POLL_MS = 30000;
const SYNC_TAG = 'mes-sync';

function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

async function aggiornaConteggio() {
    inSospeso.value = await conteggio();
}

async function registraBackgroundSync() {
    if (!('serviceWorker' in navigator) || !('SyncManager' in window)) {
        return false;
    }
    try {
        const reg = await navigator.serviceWorker.ready;
        await reg.sync.register(SYNC_TAG);
        return true;
    } catch {
        return false;
    }
}

async function chiediFlushAlServiceWorker() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'mes-flush' });
    }
}

/**
 * Svuota la coda: invia tutte le azioni a /api/sync e rimuove quelle acked
 * (ok, duplicato o errore permanente). Restituisce i risultati per la UI.
 */
export async function flush() {
    const azioni = await tutte();
    if (azioni.length === 0) {
        return { risultati: [] };
    }
    const resp = await fetch('/api/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ azioni }),
    });
    if (!resp.ok) {
        throw new Error('sync HTTP ' + resp.status);
    }
    const dati = await resp.json();
    for (const r of dati.risultati || []) {
        if (r.ok || r.duplicato || r.permanente) {
            await rimuovi(r.client_uuid);
        }
    }
    await aggiornaConteggio();
    return dati;
}

/**
 * Esegue un'azione operatore. Ritorna:
 *   { stato: 'ok' }        applicata dal server
 *   { stato: 'errore', messaggio } rifiuto di dominio (permanente)
 *   { stato: 'accodata' }  offline o rete KO: verra' sincronizzata
 */
export async function azione(tipo, payload) {
    // Spacchetta eventuali oggetti reattivi Vue (Proxy) in dati JS puri: necessario per la coda
    // IndexedDB (structured clone) e comunque coerente con l'invio JSON a /api/sync.
    const record = {
        client_uuid: uuid(),
        tipo_azione: tipo,
        payload: JSON.parse(JSON.stringify(payload)),
        created_at: Date.now(),
    };

    if (!navigator.onLine) {
        await aggiungi(record);
        await aggiornaConteggio();
        await registraBackgroundSync();
        return { stato: 'accodata' };
    }

    try {
        const resp = await fetch('/api/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ azioni: [record] }),
        });
        if (!resp.ok) {
            throw new Error('HTTP ' + resp.status);
        }
        const dati = await resp.json();
        const r = (dati.risultati || [])[0] || {};
        if (r.ok || r.duplicato) {
            return { stato: 'ok' };
        }
        if (r.permanente) {
            return { stato: 'errore', messaggio: r.errore };
        }
        // Errore transitorio: accoda per ritentare.
        await aggiungi(record);
        await aggiornaConteggio();
        await registraBackgroundSync();
        return { stato: 'accodata' };
    } catch {
        // Rete caduta durante l'invio: accoda.
        await aggiungi(record);
        await aggiornaConteggio();
        await registraBackgroundSync();
        return { stato: 'accodata' };
    }
}

let avviato = false;

/** Inizializza listener online/offline, polling di fallback e ascolto messaggi dal SW. */
export function initSync() {
    if (avviato) {
        return;
    }
    avviato = true;

    aggiornaConteggio();

    window.addEventListener('online', () => {
        online.value = true;
        flush().catch(() => {});
    });
    window.addEventListener('offline', () => {
        online.value = false;
    });

    // Fallback polling (Safari/iPad: niente Background Sync).
    setInterval(() => {
        online.value = navigator.onLine;
        if (navigator.onLine && inSospeso.value > 0) {
            flush().catch(() => chiediFlushAlServiceWorker());
        }
    }, POLL_MS);

    // Il SW notifica il completamento del flush in background.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'mes-sync-done') {
                aggiornaConteggio();
            }
        });
    }
}
