<script setup>
import OperatorLayout from '@/Layouts/OperatorLayout.vue';
import { azione } from '@/offline/sync';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    step: { type: Object, required: true },
    fase: { type: Object, required: true },
    materiali: { type: Array, required: true },
});

const quantita = reactive({});
const lotti = reactive({});
const confermatiLocali = reactive({});
// Traccia quali righe lotto provengono dalla proposta FIFO (solo per informazione UI).
const daProposta = reactive({});
props.materiali.forEach((m) => {
    quantita[m.id] = m.quantita_effettiva ?? m.quantita_pianificata;
    if (m.gestione_lotto) {
        if (m.lotti.length) {
            // Gia' confermato: mostra i lotti reali.
            lotti[m.id] = m.lotti.map((l) => ({ lotto: l.lotto, quantita: l.quantita }));
        } else if (m.proposta_fifo && m.proposta_fifo.length) {
            // Proposta FIFO pre-compilata dal mag. 06 (§5.2): confermabile con un tap o modificabile.
            lotti[m.id] = m.proposta_fifo.map((l) => ({ lotto: l.lotto, quantita: l.quantita }));
            daProposta[m.id] = true;
        } else {
            lotti[m.id] = [{ lotto: '', quantita: m.quantita_pianificata }];
        }
    }
});

const statoLocale = ref(props.step.stato);
const quantitaProdotta = ref(props.fase.quantita);
const lottoProdotto = ref(props.fase.lotto_uscita ?? '');
const avviso = ref('');
// Prelievo da stock (§5.3): lotto di semilavorato gia' esistente da indicare.
const lottoStock = ref('');

// Raggruppa i lotti a stock per magazzino, per una vista piu' leggibile.
function raggruppaPerMagazzino(lotti) {
    const gruppi = [];
    const idx = {};
    (lotti || []).forEach((l) => {
        if (!(l.magazzino in idx)) {
            idx[l.magazzino] = gruppi.length;
            gruppi.push({ magazzino: l.magazzino, lotti: [], totale: 0 });
        }
        const g = gruppi[idx[l.magazzino]];
        g.lotti.push(l);
        g.totale += Number(l.quantita) || 0;
    });
    return gruppi;
}
const lottiStockRaggruppati = computed(() => raggruppaPerMagazzino(props.fase.lotti_stock));

const daLavorare = computed(() => statoLocale.value === 'da_lavorare');
const inCorso = computed(() => statoLocale.value === 'in_corso');
const chiusa = computed(() => statoLocale.value === 'chiusa');
const confermato = (m) => m.confermato || !!confermatiLocali[m.id];
const tutteConfermate = computed(() => props.materiali.every((m) => confermato(m)));
const lottoUscitaOk = computed(() => !props.fase.richiede_lotto_uscita || lottoProdotto.value.trim() !== '');
const puoChiudere = computed(
    () => inCorso.value && (!props.step.consuma_materiali || tutteConfermate.value) && lottoUscitaOk.value,
);

const sommaLotti = (id) => (lotti[id] || []).reduce((acc, r) => acc + (parseFloat(r.quantita) || 0), 0);
const aggiungiLotto = (id) => lotti[id].push({ lotto: '', quantita: 0 });
const rimuoviLotto = (id, i) => lotti[id].splice(i, 1);

// Sceglie un lotto tra quelli disponibili sul mag.06: riempie la riga vuota corrente o ne aggiunge
// una nuova (multi-lotto). Se il lotto e' gia' presente, non fa nulla.
const scegliLotto = (m, lotto) => {
    const righe = lotti[m.id];
    if (!righe || !righe.length) {
        lotti[m.id] = [{ lotto, quantita: m.quantita_pianificata }];
        return;
    }
    if (righe.some((r) => (r.lotto || '').trim() === lotto)) {
        return;
    }
    const ultima = righe[righe.length - 1];
    if (!(ultima.lotto || '').trim()) {
        ultima.lotto = lotto;
    } else {
        righe.push({ lotto, quantita: 0 });
    }
};

// Avviso lato client per gli articoli NON a lotto: quantita > giacenza mag. 06 (il blocco vero e' server-side).
const giacenzaInsufficiente = (m) =>
    !m.flag_lotto
    && !m.semilavorato
    && m.giacenza_mag06 !== null
    && m.giacenza_mag06 !== undefined
    && (parseFloat(quantita[m.id]) || 0) > Number(m.giacenza_mag06) + 1e-9;

// Gestisce l'esito di un'azione: online -> ricarica dal server; offline -> aggiorna in ottico + avvisa.
async function esegui(tipo, payload, ottimistico, dopoOk) {
    avviso.value = '';
    const r = await azione(tipo, payload);
    if (r.stato === 'ok') {
        dopoOk ? dopoOk() : router.reload({ preserveScroll: true });
    } else if (r.stato === 'accodata') {
        ottimistico?.();
        avviso.value = 'Salvato offline: verra sincronizzato al ritorno della connessione.';
    } else {
        avviso.value = r.messaggio || 'Operazione non riuscita.';
    }
}

const avvia = () => esegui('avvio_step', { step_id: props.step.id }, () => (statoLocale.value = 'in_corso'));

// Completa la fase da stock indicando un lotto di semilavorato gia' esistente (§5.3): la fase e'
// chiusa senza consumare i componenti. Il lotto deve esistere a sistema (verificato lato server).
const completaDaStock = () => {
    if (lottoStock.value.trim() === '') {
        avviso.value = 'Indica il lotto di semilavorato esistente.';
        return;
    }
    if (!window.confirm(`Completare la fase da stock con il lotto "${lottoStock.value.trim()}"? I componenti NON verranno consumati.`)) {
        return;
    }
    return esegui(
        'completa_da_stock',
        { fase_id: props.fase.id, lotto: lottoStock.value.trim() },
        () => (statoLocale.value = 'chiusa'),
        () => router.visit(route('operatore.coda')),
    );
};

const conferma = (m) => {
    // La giacenza insufficiente sul mag. 06 viene bloccata lato server per QUALSIASI articolo (anche
    // con lotti digitati a mano): qui inviamo e basta, l'eventuale errore compare nell'avviso.
    const payload = m.gestione_lotto
        ? {
              step_id: props.step.id,
              materiale_id: m.id,
              quantita_effettiva: sommaLotti(m.id),
              lotti: lotti[m.id],
          }
        : { step_id: props.step.id, materiale_id: m.id, quantita_effettiva: quantita[m.id], lotti: [] };

    return esegui('conferma_materiale', payload, () => (confermatiLocali[m.id] = true));
};

const chiudi = () =>
    esegui(
        'chiusura_step',
        { step_id: props.step.id, quantita_prodotta: quantitaProdotta.value, lotto_prodotto: lottoProdotto.value },
        () => (statoLocale.value = 'chiusa'),
        () => router.visit(route('operatore.coda')),
    );
</script>

<template>
    <Head :title="`Fase ${fase.articolo}`" />

    <OperatorLayout>
        <Link :href="route('operatore.coda')" class="mb-3 inline-block text-slate-400">← Coda</Link>

        <div v-if="avviso" class="mb-3 rounded-xl bg-amber-600 px-4 py-3 text-lg font-semibold">{{ avviso }}</div>

        <div class="rounded-2xl bg-slate-800 p-5">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ fase.ordine_numero }}</div>
                    <h1 class="text-2xl font-bold">{{ fase.articolo }}</h1>
                    <div class="text-slate-400">{{ fase.descrizione }}</div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold">{{ fase.quantita }} {{ fase.udm }}</div>
                    <span v-if="fase.condiviso" class="rounded bg-red-500/20 px-2 py-0.5 text-xs font-semibold text-red-300">CONDIVISO</span>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                <span
                    v-for="(s, i) in fase.steps"
                    :key="i"
                    class="rounded-full px-3 py-1"
                    :class="{
                        'bg-emerald-500/20 text-emerald-300': s.stato === 'chiusa',
                        'bg-amber-500/20 text-amber-300': s.stato === 'in_corso',
                        'bg-slate-600 text-slate-200': s.stato === 'da_lavorare',
                        'ring-2 ring-white/40': s.ordine === step.ordine,
                    }"
                >
                    {{ s.reparto }}
                </span>
            </div>
        </div>

        <!-- Avvio -->
        <div v-if="daLavorare" class="mt-6 text-center">
            <p v-if="!step.lavorabile" class="mb-4 rounded-xl bg-slate-800 p-4 text-lg text-amber-300">🔒 {{ step.motivo }}</p>
            <button
                type="button"
                class="h-20 w-full max-w-md rounded-2xl bg-emerald-600 text-2xl font-bold active:bg-emerald-500 disabled:opacity-40"
                :disabled="!step.lavorabile"
                @click="avvia"
            >
                ▶ Avvia lavorazione
            </button>

            <!-- Prelievo da stock (§5.3): il semilavorato esiste gia' a un lotto noto -> fase chiusa senza consumo. -->
            <div v-if="fase.permetti_da_stock" class="mx-auto mt-5 max-w-md rounded-2xl bg-slate-800 p-4 text-left">
                <p class="mb-2 text-sm text-slate-300">
                    Oppure, se questo semilavorato è già disponibile a stock, indica il <strong>lotto esistente</strong>:
                    la fase verrà chiusa <strong>senza consumare i componenti</strong>.
                </p>
                <div class="flex items-center gap-2">
                    <input
                        v-model="lottoStock"
                        type="text"
                        placeholder="Lotto esistente"
                        class="flex-1 rounded-lg border-0 bg-slate-900 px-3 py-3 text-lg text-white"
                    />
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-3 text-lg font-semibold active:bg-indigo-500 disabled:opacity-40"
                        :disabled="lottoStock.trim() === ''"
                        @click="completaDaStock"
                    >
                        Completa da stock
                    </button>
                </div>

                <!-- Lotti a giacenza su tutti i magazzini (change #2), raggruppati per magazzino. -->
                <div v-if="fase.lotti_stock && fase.lotti_stock.length" class="mt-4 space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Disponibile a magazzino</p>
                    <div v-for="grp in lottiStockRaggruppati" :key="grp.magazzino" class="overflow-hidden rounded-xl bg-slate-900/70">
                        <div class="flex items-center justify-between bg-slate-700/50 px-3 py-2">
                            <span class="rounded-md bg-indigo-500/30 px-2 py-0.5 text-xs font-bold text-indigo-200">Mag. {{ grp.magazzino }}</span>
                            <span class="text-xs text-slate-400">{{ grp.lotti.length }} lotti · {{ grp.totale.toFixed(3) }} {{ fase.udm }}</span>
                        </div>
                        <div class="divide-y divide-slate-800">
                            <button
                                v-for="l in grp.lotti"
                                :key="l.lotto"
                                type="button"
                                class="flex w-full items-center justify-between px-3 py-3 text-left transition"
                                :class="lottoStock.trim() === l.lotto ? 'bg-indigo-600' : 'active:bg-slate-800'"
                                @click="lottoStock = l.lotto"
                            >
                                <span class="font-mono text-sm">{{ l.lotto }}</span>
                                <span class="text-sm font-semibold tabular-nums">{{ Number(l.quantita).toFixed(3) }} {{ fase.udm }}</span>
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-slate-500">
                    Nessun lotto a giacenza nei magazzini per questo articolo: inseriscilo a mano.
                </p>
            </div>
        </div>

        <!-- Conferma materiali -->
        <div v-if="inCorso && step.consuma_materiali" class="mt-6">
            <h2 class="mb-3 text-xl font-bold">Materiali ({{ materiali.filter((m) => confermato(m)).length }}/{{ materiali.length }})</h2>
            <div class="space-y-3">
                <div
                    v-for="m in materiali"
                    :key="m.id"
                    class="rounded-xl border-l-8 bg-slate-800 p-4"
                    :class="confermato(m) ? 'border-emerald-500' : 'border-slate-500'"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-lg font-semibold">{{ m.articolo }}</div>
                            <div class="text-sm text-slate-400">
                                {{ m.descrizione }}
                                <span v-if="m.semilavorato" class="ml-1 text-indigo-300">(semilavorato)</span>
                            </div>
                            <div class="text-sm text-slate-400">Previsto: {{ m.quantita_pianificata }} {{ m.udm }}</div>
                            <div v-if="m.giacenza_mag06 !== null && m.giacenza_mag06 !== undefined" class="text-xs" :class="giacenzaInsufficiente(m) ? 'text-red-400 font-semibold' : 'text-slate-500'">
                                Giac. mag.06: {{ m.giacenza_mag06 }} {{ m.udm }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg px-4 py-3 text-lg font-semibold"
                            :class="confermato(m) ? 'bg-emerald-700 active:bg-emerald-600' : 'bg-indigo-600 active:bg-indigo-500'"
                            @click="conferma(m)"
                        >
                            {{ confermato(m) ? '✓ Confermato · Aggiorna' : 'Conferma' }}
                        </button>
                    </div>

                    <div v-if="!m.gestione_lotto" class="mt-2">
                        <div class="flex items-center gap-2">
                            <input v-model="quantita[m.id]" type="number" step="0.000001" min="0" class="w-36 rounded-lg border-0 bg-slate-900 px-3 py-3 text-right text-xl text-white" />
                            <span class="text-slate-400">{{ m.udm }}</span>
                        </div>
                        <p v-if="giacenzaInsufficiente(m)" class="mt-1 text-sm font-semibold text-red-400">
                            ⚠ Giacenza mag.06 insufficiente: la registrazione verra bloccata.
                        </p>
                    </div>

                    <div v-else class="mt-3">
                        <p v-if="m.semilavorato" class="mb-2 text-xs text-indigo-300">
                            Lotto ereditato dalla fase produttrice — modificabile.
                        </p>
                        <p v-else-if="daProposta[m.id]" class="mb-2 text-xs text-emerald-300">
                            Proposta FIFO dal mag.06 — confermabile o modificabile.
                        </p>
                        <div v-for="(riga, i) in lotti[m.id]" :key="i" class="mb-2 flex items-center gap-2">
                            <input v-model="riga.lotto" type="text" placeholder="Lotto" class="flex-1 rounded-lg border-0 bg-slate-900 px-3 py-3 text-lg text-white" />
                            <input v-model="riga.quantita" type="number" step="0.000001" min="0" class="w-28 rounded-lg border-0 bg-slate-900 px-3 py-3 text-right text-lg text-white" />
                            <span class="w-8 text-slate-400">{{ m.udm }}</span>
                            <button type="button" class="rounded-lg bg-slate-700 px-3 py-3 text-lg" @click="rimuoviLotto(m.id, i)">✕</button>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <button type="button" class="rounded-lg bg-slate-700 px-3 py-2 font-semibold active:bg-slate-600" @click="aggiungiLotto(m.id)">+ Lotto</button>
                            <span class="text-slate-400">Totale lotti: {{ sommaLotti(m.id).toFixed(3) }} {{ m.udm }}</span>
                        </div>

                        <!-- Lotti disponibili sul mag.06: tocca per usarli (§5.2). -->
                        <div v-if="m.lotti_disponibili && m.lotti_disponibili.length" class="mt-3">
                            <p class="mb-1 text-xs text-slate-400">Lotti in mag.06 (tocca per usarli):</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="l in m.lotti_disponibili"
                                    :key="l.lotto"
                                    type="button"
                                    class="rounded-lg bg-slate-700 px-3 py-2 text-sm active:bg-slate-600"
                                    @click="scegliLotto(m, l.lotto)"
                                >
                                    {{ l.lotto }} · {{ Number(l.quantita).toFixed(3) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chiusura -->
        <div v-if="inCorso" class="mt-6 rounded-2xl bg-slate-800 p-5">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <label class="text-lg font-semibold">Quantita prodotta:</label>
                <input v-model="quantitaProdotta" type="number" step="0.000001" min="0" class="w-40 rounded-lg border-0 bg-slate-900 px-3 py-3 text-right text-xl text-white" />
                <span class="text-slate-400">{{ fase.udm }}</span>
            </div>
            <div v-if="fase.richiede_lotto_uscita" class="mb-3 flex flex-wrap items-center gap-3">
                <label class="text-lg font-semibold">Lotto prodotto:</label>
                <input v-model="lottoProdotto" type="text" placeholder="Lotto in uscita" class="w-56 rounded-lg border-0 bg-slate-900 px-3 py-3 text-lg text-white" />
            </div>
            <p v-if="step.consuma_materiali && !tutteConfermate" class="mb-3 text-amber-300">Conferma tutti i materiali prima di chiudere.</p>
            <p v-else-if="!lottoUscitaOk" class="mb-3 text-amber-300">Inserisci il lotto del prodotto in uscita.</p>
            <button
                type="button"
                class="h-16 w-full rounded-2xl bg-emerald-600 text-2xl font-bold active:bg-emerald-500 disabled:opacity-40"
                :disabled="!puoChiudere"
                @click="chiudi"
            >
                ✓ Chiudi {{ fase.steps.length > 1 ? 'step' : 'fase' }}
            </button>
        </div>

        <div v-if="chiusa" class="mt-6 rounded-2xl bg-emerald-700 p-6 text-center text-2xl font-bold">✓ Step completato</div>
    </OperatorLayout>
</template>
