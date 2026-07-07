import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initSync, segnalaOffline } from './offline/sync';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// PWA / offline (§8): registra il service worker (solo in HTTPS o su localhost) e avvia la coda di sync.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
initSync();

// Navigazione offline verso una pagina non in cache (§8): il service worker risponde 503 e Inertia
// riceve una risposta non valida. Intercettiamo qui per mostrare un messaggio pulito all'operatore
// invece dell'errore grezzo / modal in console. Solo quando offline: online lasciamo il comportamento
// di default (utile a vedere veri errori 5xx in sviluppo).
const MSG_OFFLINE = 'Questa pagina non è disponibile offline. Riprova quando torni online.';

router.on('invalid', (event) => {
    const status = event.detail?.response?.status;
    if (! navigator.onLine || status === 503) {
        event.preventDefault();
        segnalaOffline(MSG_OFFLINE);
    }
});

router.on('exception', (event) => {
    if (! navigator.onLine) {
        // Impedisce il rethrow dell'errore di rete in console durante una visita offline.
        event.preventDefault();
        segnalaOffline(MSG_OFFLINE);
    }
});
