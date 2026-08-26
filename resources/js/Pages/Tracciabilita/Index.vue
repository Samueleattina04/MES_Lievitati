<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    lotto: { type: String, default: '' },
    risultato: { type: Object, default: null },
    omniPronto: { type: Boolean, default: false },
});

const ricerca = ref(props.lotto);

function cerca() {
    router.get(route('tracciabilita.index'), { lotto: ricerca.value }, { preserveState: true });
}

// Appiattisce l'albero (prodotto -> componenti -> semilavorati) per una vista indentata.
function flatten(nodo, depth = 0, acc = []) {
    if (!nodo) {
        return acc;
    }
    acc.push({ depth, tipo: 'prodotto', articolo: nodo.articolo, lotto: nodo.lotto, qta: nodo.quantita_prodotta, um: nodo.um });
    for (const c of nodo.componenti || []) {
        acc.push({
            depth: depth + 1,
            tipo: c.semilavorato ? 'semilavorato' : 'materia_prima',
            articolo: c.articolo,
            lotto: c.lotto,
            qta: c.quantita,
            um: c.um,
        });
        if (c.figlio) {
            flatten(c.figlio, depth + 2, acc);
        }
    }
    return acc;
}

const albero = computed(() => flatten(props.risultato?.nodo));
const movimenti = computed(() => props.risultato?.movimenti || []);
const trovato = computed(() => props.risultato?.trovato === true);

// In tabella mostriamo solo la data (l'orario resta nel file Omni).
const soloData = (d) => (d ? String(d).split(' ')[0] : '');

const colore = {
    prodotto: 'text-indigo-700 font-semibold',
    semilavorato: 'text-indigo-500',
    materia_prima: 'text-amber-700',
};
</script>

<template>
    <Head title="Tracciabilità lotto" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Tracciabilità lotto (gestionale)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm">
                    Inserisci il <strong>lotto del prodotto finito</strong>: il sistema recupera dal gestionale tutti i
                    <strong>carichi e scarichi</strong> e risale l'intera distinta con i lotti realmente utilizzati.
                </div>

                <form class="flex gap-2" @submit.prevent="cerca">
                    <input
                        v-model="ricerca"
                        type="text"
                        placeholder="Lotto del prodotto finito (es. 7352-23826110)"
                        class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500">Cerca</button>
                    <a
                        v-if="omniPronto && trovato"
                        :href="route('tracciabilita.omni', { lotto })"
                        class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500"
                    >
                        Scarica per Omni
                    </a>
                    <button
                        v-else
                        type="button"
                        disabled
                        :title="!omniPronto ? 'Tracciato Omni non ancora configurato' : 'Cerca prima un lotto valido'"
                        class="rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white opacity-40"
                    >
                        Scarica per Omni
                    </button>
                </form>

                <div v-if="lotto && !trovato" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Nessun movimento trovato per il lotto "{{ lotto }}". Verifica il codice lotto del prodotto finito.
                </div>

                <div v-if="trovato" class="space-y-6">
                    <!-- Albero distinta (cosa è stato usato) -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">Distinta risalita (cosa è stato usato)</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="(r, i) in albero" :key="i" :style="{ paddingLeft: r.depth * 16 + 'px' }">
                                <span :class="colore[r.tipo]">{{ r.articolo }}</span>
                                <span v-if="r.lotto" class="text-gray-500"> · lotto {{ r.lotto }}</span>
                                <span v-if="r.qta != null" class="text-gray-400"> ({{ r.qta }} {{ r.um }})</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Tutti i movimenti (carichi/scarichi) -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">Movimenti di magazzino ({{ movimenti.length }})</h3>
                        <div class="max-h-[34rem] overflow-auto rounded-lg border border-gray-100">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 z-10 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-2.5">Tipo</th>
                                        <th class="px-4 py-2.5">Articolo</th>
                                        <th class="px-4 py-2.5">Lotto</th>
                                        <th class="px-4 py-2.5 text-right">Quantità</th>
                                        <th class="px-4 py-2.5 text-center">Magazzino</th>
                                        <th class="px-4 py-2.5">Data</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(m, i) in movimenti" :key="i" class="hover:bg-gray-50">
                                        <td class="px-4 py-2">
                                            <span
                                                class="rounded px-2 py-0.5 text-xs font-semibold"
                                                :class="m.tipo === 'carico' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                                            >
                                                {{ m.tipo === 'carico' ? 'CARICO' : 'SCARICO' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 font-medium text-gray-800">{{ m.articolo }}</td>
                                        <td class="px-4 py-2 font-mono text-gray-600">{{ m.lotto }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ m.quantita }} {{ m.um }}</td>
                                        <td class="px-4 py-2 text-center">{{ m.magazzino }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ soloData(m.data) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
