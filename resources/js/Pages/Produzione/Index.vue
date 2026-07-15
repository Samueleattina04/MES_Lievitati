<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    ordini: { type: Array, required: true },
});

const flashError = computed(() => usePage().props.flash?.error);
const flashSuccess = computed(() => usePage().props.flash?.success);
</script>

<template>
    <Head title="Avanzamento produzione" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Avanzamento produzione</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div v-if="flashSuccess" class="rounded-lg bg-emerald-100 px-4 py-3 text-emerald-800">{{ flashSuccess }}</div>
                <div v-if="flashError" class="rounded-lg bg-red-100 px-4 py-3 text-red-800">{{ flashError }}</div>

                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                    Da qui il backoffice fa avanzare la produzione, senza vincolo di reparto. Due modalità:
                    <strong>chiusura massiva</strong> (tutte le fasi dell'ordine in blocco, bottom-up) oppure
                    <strong>avanzamento guidato</strong> fase per fase.
                    <Link :href="route('operatore.coda')" class="ml-1 font-semibold text-indigo-600 hover:underline">
                        Vai all'avanzamento guidato →
                    </Link>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Ordine</th>
                                <th class="px-4 py-3">Articolo</th>
                                <th class="px-4 py-3">Quantità</th>
                                <th class="px-4 py-3">Stato</th>
                                <th class="px-4 py-3">Avanzamento</th>
                                <th class="px-4 py-3 text-right">Azioni</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="o in ordini" :key="o.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-mono">{{ o.numero }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold">{{ o.articolo }}</div>
                                    <div class="text-xs text-gray-500">{{ o.descrizione }}</div>
                                </td>
                                <td class="px-4 py-3">{{ o.quantita }} {{ o.udm }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="o.stato === 'in_lavorazione' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                                    >
                                        {{ o.stato_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ o.fasi_chiuse }}/{{ o.fasi }} fasi</td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('produzione.chiusura-massiva', o.id)"
                                        class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                                    >
                                        Chiusura massiva
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="ordini.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    Nessun ordine aperto o in lavorazione.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
