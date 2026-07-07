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
props.materiali.forEach((m) => {
    quantita[m.id] = m.quantita_effettiva ?? m.quantita_pianificata;
    if (m.flag_lotto) {
        lotti[m.id] = m.lotti.length
            ? m.lotti.map((l) => ({ lotto: l.lotto, quantita: l.quantita }))
            : [{ lotto: '', quantita: m.quantita_pianificata }];
    }
});

const statoLocale = ref(props.step.stato);
const quantitaProdotta = ref(props.fase.quantita);
const lottoProdotto = ref(props.fase.lotto_uscita ?? '');
const avviso = ref('');

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

const conferma = (m) => {
    const payload = m.flag_lotto
        ? { step_id: props.step.id, materiale_id: m.id, quantita_effettiva: sommaLotti(m.id), lotti: lotti[m.id] }
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
                        </div>
                        <button
                            type="button"
                            class="rounded-lg px-4 py-3 text-lg font-semibold"
                            :class="confermato(m) ? 'bg-emerald-700' : 'bg-indigo-600 active:bg-indigo-500'"
                            @click="conferma(m)"
                        >
                            {{ confermato(m) ? '✓ Confermato' : 'Conferma' }}
                        </button>
                    </div>

                    <div v-if="!m.flag_lotto" class="mt-2 flex items-center gap-2">
                        <input v-model="quantita[m.id]" type="number" step="0.000001" min="0" class="w-36 rounded-lg border-0 bg-slate-900 px-3 py-3 text-right text-xl text-white" />
                        <span class="text-slate-400">{{ m.udm }}</span>
                    </div>

                    <div v-else class="mt-3">
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
