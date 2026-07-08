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

// Avviso mostrato quando una navigazione offline fallisce (pagina non in cache, §8).
export const avvisoOffline = ref('');
let avvisoTimer = null;

/** Mostra un avviso di navigazione offline, auto-nascosto dopo qualche secondo. */
export function segnalaOffline(messaggio) {
    avvisoOffline.value = messaggio;
    if (avvisoTimer) {
        clearTimeout(avvisoTimer);
    }
    avvisoTimer = setTimeout(() => {
        avvisoOffline.value = '';
    }, 6000);
}

const POLL_MS = 30000;
const SYNC_TAG = 'mes-sync';

let flushInCorso = false;

/** Log diagnostico a basso livello per tracciare cosa innesca (o no) il flush della coda. */
function log(...args) {
    console.info('[MES sync]', ...args);
}

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
        log('Background Sync non supportato dal browser: uso fallback online/focus/polling.');
        return false;
    }
    // Diagnostica: stato del permesso 'background-sync' (per distinguere ambiente/policy/certificato).
    try {
        if (navigator.permissions?.query) {
            const stato = await navigator.permissions.query({ name: 'background-sync' });
            log('permesso background-sync:', stato.state);
        }
    } catch {
        // Alcuni browser non espongono questo nome di permesso: ignoriamo.
    }

    try {
        const reg = await navigator.serviceWorker.ready;
        await reg.sync.register(SYNC_TAG);
        log('Background Sync registrato (tag:', SYNC_TAG + ').');
        return true;
    } catch (e) {
        log('Registrazione Background Sync FALLITA:', e?.message || e, '— si usano i fallback (online/focus/polling).');
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
export async function flush(motivo = 'manuale') {
    const azioni = await tutte();
    if (azioni.length === 0) {
        return { risultati: [] };
    }
    if (flushInCorso) {
        return { risultati: [] }; // evita invii concorrenti dallo stesso client
    }
    flushInCorso = true;
    log('flush avviato — trigger:', motivo, '— azioni in coda:', azioni.length);

    try {
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
        let sincronizzate = 0;
        for (const r of dati.risultati || []) {
            if (r.ok || r.duplicato || r.permanente) {
                await rimuovi(r.client_uuid);
                sincronizzate++;
            }
        }
        await aggiornaConteggio();
        log('flush OK — trigger:', motivo, '— sincronizzate:', sincronizzate, '— rimaste:', inSospeso.value);
        return dati;
    } finally {
        flushInCorso = false;
    }
}

/** flush() protetto: non fa nulla se offline, e su errore ripiega sul Service Worker. */
async function flushSicuro(motivo) {
    if (!navigator.onLine) {
        return;
    }
    try {
        await flush(motivo);
    } catch (e) {
        log('flush FALLITO — trigger:', motivo, '—', e?.message || e, '— ritento via Service Worker.');
        chiediFlushAlServiceWorker();
    }
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

/**
 * Inizializza i meccanismi di svuotamento coda. Collegato UNA sola volta all'avvio dell'app,
 * resta attivo per tutta la sessione (Inertia SPA: app.js gira una volta). Piu' trigger
 * ridondanti perche' l'evento 'online' da solo e' inaffidabile (specie via DevTools):
 *   1) evento window 'online'      2) ritorno di focus/visibilita' della scheda
 *   3) polling periodico            4) Background Sync lato Service Worker (registrato in azione()).
 */
export function initSync() {
    if (avviato) {
        return;
    }
    avviato = true;

    // Flush all'avvio se ci sono azioni rimaste da una sessione precedente.
    aggiornaConteggio().then(() => {
        if (inSospeso.value > 0) {
            log('coda non vuota all\'avvio:', inSospeso.value, 'azioni.');
            flushSicuro('init');
        }
    });

    window.addEventListener('online', () => {
        online.value = true;
        log('evento "online" rilevato.');
        flushSicuro('online');
    });
    window.addEventListener('offline', () => {
        online.value = false;
        log('evento "offline" rilevato.');
    });

    // Ritorno di visibilita'/focus della scheda: recupero affidabile quando 'online' non scatta.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            flushSicuro('visibilitychange');
        }
    });
    window.addEventListener('focus', () => flushSicuro('focus'));
    window.addEventListener('pageshow', () => flushSicuro('pageshow'));

    // Polling periodico (fallback universale, es. Safari senza Background Sync).
    setInterval(() => {
        online.value = navigator.onLine;
        flushSicuro('poll');
    }, POLL_MS);

    // Il SW notifica il completamento del flush eseguito in background (Background Sync).
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'mes-sync-done') {
                log('flush completato dal Service Worker (Background Sync).');
                aggiornaConteggio();
            }
        });
    }
}
