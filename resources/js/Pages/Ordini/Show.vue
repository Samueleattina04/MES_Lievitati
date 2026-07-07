<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    ordine: { type: Object, required: true },
    fasi: { type: Array, required: true },
});

const flashSuccess = computed(() => usePage().props.flash?.success);
const espanse = ref({});
const toggle = (id) => (espanse.value[id] = !espanse.value[id]);

const statoColore = {
    da_lavorare: 'bg-gray-100 text-gray-700',
    in_corso: 'bg-amber-100 text-amber-800',
    chiusa: 'bg-emerald-100 text-emerald-800',
};
</script>

<template>
    <Head :title="`Ordine ${ordine.numero}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Ordine {{ ordine.numero }}
                </h2>
                <Link :href="route('ordini.index')" class="text-sm text-gray-600 hover:underline">← Tutti gli ordini</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="flashSuccess" class="rounded-md bg-emerald-50 p-4 text-sm text-emerald-700">
                    {{ flashSuccess }}
                </div>

                <div class="grid grid-cols-2 gap-4 rounded-lg bg-white p-6 shadow-sm sm:grid-cols-4">
                    <div>
                        <div class="text-xs uppercase text-gray-400">Articolo</div>
                        <div class="font-semibold text-gray-800">{{ ordine.articolo }}</div>
                        <div class="text-xs text-gray-500">{{ ordine.descrizione }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-400">Quantita</div>
                        <div class="font-semibold text-gray-800">{{ ordine.quantita }} {{ ordine.udm }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-400">Data</div>
                        <div class="font-semibold text-gray-800">{{ ordine.data }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-400">Stato</div>
                        <div class="font-semibold text-gray-800">{{ ordine.stato_label }}</div>
                    </div>
                </div>

                <div>
                    <h3 class="mb-3 text-lg font-semibold text-gray-800">
                        Fasi di lavorazione ({{ fasi.length }})
                        <span class="ml-2 text-sm font-normal text-gray-500">ordinate bottom-up: prima i componenti, poi i padri</span>
                    </h3>

                    <div class="space-y-3">
                        <div v-for="f in fasi" :key="f.id" class="rounded-lg bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-800">{{ f.articolo }}</span>
                                        <span v-if="f.condiviso" class="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700" title="Nodo condiviso: richiede split">CONDIVISO</span>
                                    </div>
                                    <div class="text-xs text-gray-500">{{ f.descrizione }}</div>
                                </div>
                                <div class="flex items-center gap-3 text-sm">
                                    <span class="text-gray-600">{{ f.quantita }} {{ f.udm }}</span>
                                    <span v-if="f.reparto" class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ f.reparto }}</span>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statoColore[f.stato]">{{ f.stato_label }}</span>
                                </div>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                <span v-if="f.steps.length">
                                    Step: <span v-for="(s, i) in f.steps" :key="i">{{ s.reparto }}<span v-if="i < f.steps.length - 1"> → </span></span>
                                </span>
                                <span v-if="f.dipende_da.length">
                                    Dipende da: {{ f.dipende_da.join(', ') }}
                                </span>
                                <button class="text-indigo-600 hover:underline" @click="toggle(f.id)">
                                    {{ espanse[f.id] ? 'Nascondi' : 'Mostra' }} materiali ({{ f.materiali.length }})
                                </button>
                            </div>

                            <table v-if="espanse[f.id]" class="mt-3 w-full text-sm">
                                <thead class="text-left text-xs uppercase text-gray-400">
                                    <tr>
                                        <th class="py-1">Materiale</th>
                                        <th class="py-1 text-right">Qta pianificata</th>
                                        <th class="py-1 text-center">Lotto</th>
                                        <th class="py-1 text-center">Tipo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="m in f.materiali" :key="m.articolo">
                                        <td class="py-1">
                                            <span class="font-medium text-gray-700">{{ m.articolo }}</span>
                                            <span class="text-gray-400"> {{ m.descrizione }}</span>
                                        </td>
                                        <td class="py-1 text-right">{{ m.quantita }} {{ m.udm }}</td>
                                        <td class="py-1 text-center">
                                            <span v-if="m.flag_lotto" class="text-amber-600">richiesto</span>
                                            <span v-else class="text-gray-300">—</span>
                                        </td>
                                        <td class="py-1 text-center">
                                            <span v-if="m.semilavorato" class="text-indigo-600">semilavorato</span>
                                            <span v-else class="text-gray-400">materia prima</span>
                                        </td>
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
