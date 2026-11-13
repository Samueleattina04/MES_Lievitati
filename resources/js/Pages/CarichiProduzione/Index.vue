<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    operatore: { type: String, default: '' },
    codOpMag: { type: Array, default: () => [] },
    risultato: { type: Object, default: null },
    errore: { type: String, default: null },
});

const messaggioErrore = computed(() => props.errore || usePage().props.flash?.error);
const righe = computed(() => props.risultato?.righe || []);
const totale = computed(() => props.risultato?.totale ?? 0);
const anteprima = computed(() => props.risultato?.anteprima ?? 0);
const troncato = computed(() => totale.value > righe.value.length);

const filtroOperatore = ref(props.operatore);

function aggiorna() {
    router.get(
        route('carichi-produzione.index'),
        { operatore: filtroOperatore.value },
        { preserveState: true },
    );
}

// Il download rispetta l'operatore attualmente filtrato.
const urlDownload = computed(() =>
    route('carichi-produzione.scarica', { operatore: props.operatore }),
);

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
                    dall'operatore indicato (<em>FirmaCreazOperatore</em>). Il file Excel contiene tutte le righe,
                    con famiglia / macrofamiglia / microfamiglia dell'articolo.
                </div>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="aggiorna">
                    <div class="flex flex-col">
                        <label class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Operatore (creatore documento)</label>
                        <input
                            v-model="filtroOperatore"
                            type="text"
                            placeholder="Es. N.CELESTINO"
                            class="w-72 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
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
                                </tr>
                                <tr v-if="righe.length === 0">
                                    <td colspan="14" class="px-3 py-3 text-center text-gray-400">Nessun carico trovato</td>
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
