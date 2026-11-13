<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    operatore: { type: String, default: '' },
    codOpMag: { type: Array, default: () => [] },
    da: { type: String, default: '' },
    a: { type: String, default: '' },
    selezionate: { type: Object, default: () => ({}) },
    risultato: { type: Object, default: null },
    errore: { type: String, default: null },
});

const messaggioErrore = computed(() => props.errore || usePage().props.flash?.error);
const righe = computed(() => props.risultato?.righe || []);
const totale = computed(() => props.risultato?.totale ?? 0);
const anteprima = computed(() => props.risultato?.anteprima ?? 0);
const troncato = computed(() => totale.value > righe.value.length);

const filtroOperatore = ref(props.operatore);
const filtroDa = ref(props.da);
const filtroA = ref(props.a);

// Opzioni delle checklist (valori presenti nel periodo) e selezioni correnti.
const opzioni = computed(() => props.risultato?.opzioni || { famiglia: [], macrofamiglia: [], microfamiglia: [] });
const selFamiglia = ref([]);
const selMacro = ref([]);
const selMicro = ref([]);

// Inizializza una checklist dalle selezioni applicate; se vuote, tutte spuntate (= nessun filtro).
function iniziale(gruppo) {
    const opts = opzioni.value[gruppo] || [];
    const sel = props.selezionate?.[gruppo] || [];
    return sel.length ? sel.filter((v) => opts.includes(v)) : [...opts];
}
function sincronizza() {
    selFamiglia.value = iniziale('famiglia');
    selMacro.value = iniziale('macrofamiglia');
    selMicro.value = iniziale('microfamiglia');
}
sincronizza();
// Dopo ogni "Aggiorna" i dati cambiano: riallinea le checklist a ciò che il server ha applicato.
watch(() => props.risultato, sincronizza);

// Manda un gruppo solo se è un sottoinsieme reale (altrimenti = nessun filtro).
function paramGruppo(checked, opts) {
    if (!checked.length || checked.length >= opts.length) {
        return undefined;
    }
    return checked;
}

function aggiorna() {
    const q = { operatore: filtroOperatore.value, da: filtroDa.value, a: filtroA.value };
    const f = paramGruppo(selFamiglia.value, opzioni.value.famiglia);
    const ma = paramGruppo(selMacro.value, opzioni.value.macrofamiglia);
    const mi = paramGruppo(selMicro.value, opzioni.value.microfamiglia);
    if (f) q.fam = f;
    if (ma) q.macro = ma;
    if (mi) q.micro = mi;
    router.get(route('carichi-produzione.index'), q, { preserveState: true });
}

// Il download rispetta i filtri ATTUALMENTE applicati (quelli negli props, non le spunte non ancora inviate).
const urlDownload = computed(() => {
    const q = { operatore: props.operatore, da: props.da, a: props.a };
    const sel = props.selezionate || {};
    if (sel.famiglia?.length) q.fam = sel.famiglia;
    if (sel.macrofamiglia?.length) q.macro = sel.macrofamiglia;
    if (sel.microfamiglia?.length) q.micro = sel.microfamiglia;
    return route('carichi-produzione.scarica', q);
});

// Stato "già scaricato in Omni" per lotto PF: caricato in BACKGROUND (fetch) per non rallentare la
// pagina. Mappa lotto => n. scarichi; link download Omni per riga.
const statoOmni = ref({});
const caricandoOmni = ref(false);
const urlOmni = (lotto) => route('carichi-produzione.omni', { lotto });

async function caricaStatoOmni() {
    const lotti = [...new Set(righe.value.map((r) => r.lotto).filter(Boolean))];
    if (!lotti.length) {
        statoOmni.value = {};
        return;
    }
    caricandoOmni.value = true;
    try {
        const params = new URLSearchParams();
        lotti.forEach((l) => params.append('lotti[]', l));
        const resp = await fetch(route('carichi-produzione.stato-omni') + '?' + params.toString(), {
            headers: { Accept: 'application/json' },
        });
        statoOmni.value = resp.ok ? await resp.json() : {};
    } catch (e) {
        statoOmni.value = {};
    } finally {
        caricandoOmni.value = false;
    }
}

onMounted(caricaStatoOmni);
// Dopo ogni "Aggiorna"/cambio filtro ricarico lo stato Omni per le nuove righe.
watch(() => props.risultato, () => {
    statoOmni.value = {};
    caricaStatoOmni();
});

// In tabella mostriamo solo la data (l'orario resta nel dato grezzo).
const soloData = (d) => (d ? String(d).split(' ')[0] : '');
const qta = (n) => Number(n ?? 0).toLocaleString('it-IT', { maximumFractionDigits: 6 });
</script>

<template>
    <Head title="Carichi di produzione" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Carichi di produzione (prima nota)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="messaggioErrore" class="rounded-lg bg-red-100 px-4 py-3 text-red-800">{{ messaggioErrore }}</div>

                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm">
                    Carichi di produzione presi dalla <strong>prima nota</strong> ESOLVER
                    (CodOpMag <strong>{{ codOpMag.join(' / ') || '—' }}</strong>), creati
                    dall'operatore indicato (<em>FirmaCreazOperatore</em>), nell'<strong>intervallo di date</strong> scelto.
                    Il file Excel contiene tutte le righe del periodo, con famiglia / macrofamiglia / microfamiglia dell'articolo.
                    <span class="text-gray-400">Suggerimento: periodi ampi su molti anni possono essere pesanti — restringi le date.</span>
                </div>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="aggiorna">
                    <div class="flex flex-col">
                        <label class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Operatore (creatore documento)</label>
                        <input
                            v-model="filtroOperatore"
                            type="text"
                            placeholder="Es. N.CELESTINO"
                            class="w-60 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                    <div class="flex flex-col">
                        <label class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Dal</label>
                        <input v-model="filtroDa" type="date" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div class="flex flex-col">
                        <label class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Al</label>
                        <input v-model="filtroA" type="date" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500">Aggiorna</button>
                    <a
                        v-if="risultato && totale > 0"
                        :href="urlDownload"
                        class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500"
                    >
                        Scarica Excel
                    </a>
                    <button
                        v-else
                        type="button"
                        disabled
                        title="Nessun carico da esportare"
                        class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white opacity-40"
                    >
                        Scarica Excel
                    </button>
                </form>

                <!-- Filtri a checklist per classificazione merceologica (si applicano con "Aggiorna"). -->
                <div
                    v-if="risultato && (opzioni.famiglia.length || opzioni.macrofamiglia.length || opzioni.microfamiglia.length)"
                    class="rounded-lg bg-white p-4 shadow-sm"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Filtri classificazione</h3>
                        <span class="text-xs text-gray-400">Spunta/deseleziona e premi “Aggiorna”.</span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div v-if="opzioni.famiglia.length">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Famiglia</span>
                                <span class="space-x-2 text-xs">
                                    <button type="button" class="text-indigo-600 hover:underline" @click="selFamiglia = [...opzioni.famiglia]">Tutti</button>
                                    <button type="button" class="text-gray-400 hover:underline" @click="selFamiglia = []">Nessuno</button>
                                </span>
                            </div>
                            <div class="max-h-44 space-y-1 overflow-auto rounded-md border border-gray-100 p-2">
                                <label v-for="o in opzioni.famiglia" :key="o" class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" :value="o" v-model="selFamiglia" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    <span class="truncate">{{ o }}</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="opzioni.macrofamiglia.length">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Macrofamiglia</span>
                                <span class="space-x-2 text-xs">
                                    <button type="button" class="text-indigo-600 hover:underline" @click="selMacro = [...opzioni.macrofamiglia]">Tutti</button>
                                    <button type="button" class="text-gray-400 hover:underline" @click="selMacro = []">Nessuno</button>
                                </span>
                            </div>
                            <div class="max-h-44 space-y-1 overflow-auto rounded-md border border-gray-100 p-2">
                                <label v-for="o in opzioni.macrofamiglia" :key="o" class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" :value="o" v-model="selMacro" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    <span class="truncate">{{ o }}</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="opzioni.microfamiglia.length">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Microfamiglia</span>
                                <span class="space-x-2 text-xs">
                                    <button type="button" class="text-indigo-600 hover:underline" @click="selMicro = [...opzioni.microfamiglia]">Tutti</button>
                                    <button type="button" class="text-gray-400 hover:underline" @click="selMicro = []">Nessuno</button>
                                </span>
                            </div>
                            <div class="max-h-44 space-y-1 overflow-auto rounded-md border border-gray-100 p-2">
                                <label v-for="o in opzioni.microfamiglia" :key="o" class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" :value="o" v-model="selMicro" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    <span class="truncate">{{ o }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="risultato" class="rounded-lg bg-white p-5 shadow-sm">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h3 class="font-semibold text-gray-800">Carichi trovati</h3>
                        <span class="rounded-full bg-indigo-100 px-3 py-0.5 text-sm font-semibold text-indigo-800">{{ totale }}</span>
                    </div>

                    <div class="max-h-[36rem] overflow-auto rounded-lg border border-gray-100">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2.5">Data</th>
                                    <th class="px-3 py-2.5">Articolo</th>
                                    <th class="px-3 py-2.5">Descrizione</th>
                                    <th class="px-3 py-2.5">Lotto</th>
                                    <th class="px-3 py-2.5">Lotto PF</th>
                                    <th class="px-3 py-2.5 text-right">Quantità</th>
                                    <th class="px-3 py-2.5">UM</th>
                                    <th class="px-3 py-2.5 text-center">Mag.</th>
                                    <th class="px-3 py-2.5">Causale</th>
                                    <th class="px-3 py-2.5">Cod.Op.</th>
                                    <th class="px-3 py-2.5">Operatore</th>
                                    <th class="px-3 py-2.5">Famiglia</th>
                                    <th class="px-3 py-2.5">Macrofamiglia</th>
                                    <th class="px-3 py-2.5">Microfamiglia</th>
                                    <th class="px-3 py-2.5">Omni</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(r, i) in righe" :key="i" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-3 py-1.5 text-gray-500">{{ soloData(r.data) }}</td>
                                    <td class="px-3 py-1.5 font-medium text-gray-800">{{ r.articolo }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.descrizione }}</td>
                                    <td class="px-3 py-1.5 font-mono text-gray-600">{{ r.lotto }}</td>
                                    <td class="px-3 py-1.5 font-mono text-gray-500">{{ r.lotto_pf }}</td>
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ qta(r.quantita) }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.um }}</td>
                                    <td class="px-3 py-1.5 text-center text-gray-600">{{ r.magazzino }}</td>
                                    <td class="px-3 py-1.5 text-gray-500">{{ r.causale }}</td>
                                    <td class="px-3 py-1.5 text-gray-500">{{ r.cod_op_mag }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.operatore }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.famiglia }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.macrofamiglia }}</td>
                                    <td class="px-3 py-1.5 text-gray-600">{{ r.microfamiglia }}</td>
                                    <td class="whitespace-nowrap px-3 py-1.5">
                                        <span v-if="caricandoOmni" class="text-xs text-gray-400">…</span>
                                        <template v-else>
                                            <span
                                                v-if="(statoOmni[r.lotto] || 0) > 0"
                                                class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700"
                                                :title="'Già scaricato in Omni (' + statoOmni[r.lotto] + ' scarichi)'"
                                            >
                                                già scaricato ({{ statoOmni[r.lotto] }})
                                            </span>
                                            <span v-else class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-semibold text-emerald-700">da scaricare</span>
                                        </template>
                                        <a :href="urlOmni(r.lotto)" class="ml-2 text-xs font-semibold text-indigo-600 hover:underline">file Omni</a>
                                    </td>
                                </tr>
                                <tr v-if="righe.length === 0">
                                    <td colspan="15" class="px-3 py-3 text-center text-gray-400">Nessun carico trovato</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="troncato" class="mt-2 text-xs text-gray-400">
                        Anteprima primi {{ anteprima }} — scarica l'Excel per l'elenco completo.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
