<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    lotto: { type: String, default: '' },
    aRitroso: { type: Array, default: null },
    inAvanti: { type: Array, default: null },
});

const ricerca = ref(props.lotto);

function cerca() {
    router.get(route('genealogia.index'), { lotto: ricerca.value }, { preserveState: true });
}

function flattenRitroso(nodi, depth = 0, acc = []) {
    for (const n of nodi || []) {
        acc.push({ depth, tipo: 'prodotto', label: `${n.articolo}`, lotto: n.lotto, qta: n.quantita_prodotta });
        for (const c of n.consumi || []) {
            acc.push({ depth: depth + 1, tipo: c.tipo, label: c.articolo, lotto: c.lotto, qta: c.quantita });
            if (c.origine) {
                flattenRitroso([c.origine], depth + 2, acc);
            }
        }
    }
    return acc;
}

function flattenAvanti(usi, depth = 0, acc = []) {
    for (const u of usi || []) {
        acc.push({ depth, tipo: 'uso', label: `${u.articolo} (consuma ${u.consumato_come})`, qta: u.quantita });
        for (const p of u.prodotti || []) {
            acc.push({ depth: depth + 1, tipo: 'lotto', label: 'lotto prodotto', lotto: p.lotto });
            flattenAvanti(p.usato_in || [], depth + 2, acc);
        }
    }
    return acc;
}

const righeRitroso = computed(() => flattenRitroso(props.aRitroso));
const righeAvanti = computed(() => flattenAvanti(props.inAvanti));

const colore = {
    prodotto: 'text-indigo-700',
    semilavorato: 'text-indigo-500',
    materia_prima: 'text-amber-700',
    uso: 'text-emerald-700',
    lotto: 'text-gray-500',
};
</script>

<template>
    <Head title="Genealogia lotti" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Genealogia lotti</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <form class="flex gap-2" @submit.prevent="cerca">
                    <input
                        v-model="ricerca"
                        type="text"
                        placeholder="Inserisci un lotto (materia prima o prodotto)"
                        class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500">Cerca</button>
                </form>

                <div v-if="lotto" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">A ritroso (cosa e stato consumato)</h3>
                        <div v-if="righeRitroso.length === 0" class="text-sm text-gray-400">Nessun risultato per "{{ lotto }}".</div>
                        <ul class="space-y-1 text-sm">
                            <li v-for="(r, i) in righeRitroso" :key="i" :style="{ paddingLeft: r.depth * 16 + 'px' }">
                                <span :class="colore[r.tipo]">{{ r.label }}</span>
                                <span v-if="r.lotto" class="text-gray-500"> · lotto {{ r.lotto }}</span>
                                <span v-if="r.qta != null" class="text-gray-400"> ({{ r.qta }})</span>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">In avanti (dove e finito)</h3>
                        <div v-if="righeAvanti.length === 0" class="text-sm text-gray-400">Nessun utilizzo trovato per "{{ lotto }}".</div>
                        <ul class="space-y-1 text-sm">
                            <li v-for="(r, i) in righeAvanti" :key="i" :style="{ paddingLeft: r.depth * 16 + 'px' }">
                                <span :class="colore[r.tipo]">{{ r.label }}</span>
                                <span v-if="r.lotto" class="text-gray-500"> · {{ r.lotto }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
