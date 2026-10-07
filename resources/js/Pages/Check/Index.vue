<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    risultato: { type: Object, default: null },
    errore: { type: String, default: null },
});

const messaggioErrore = computed(() => props.errore || usePage().props.flash?.error);
const soloEsolver = computed(() => props.risultato?.solo_esolver || []);
const soloOmni = computed(() => props.risultato?.solo_omni || []);
const anteprima = computed(() => props.risultato?.anteprima ?? 0);

// Vero se l'elenco è stato troncato in anteprima (il file Excel contiene comunque tutto).
const troncatoEsolver = computed(() => (props.risultato?.solo_esolver_totale || 0) > soloEsolver.value.length);
const troncatoOmni = computed(() => (props.risultato?.solo_omni_totale || 0) > soloOmni.value.length);
</script>

<template>
    <Head title="Check anagrafiche" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Check anagrafiche (ESOLVER ↔ Omni)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="messaggioErrore" class="rounded-lg bg-red-100 px-4 py-3 text-red-800">{{ messaggioErrore }}</div>

                <div class="flex items-center justify-between gap-4 rounded-lg bg-white p-4 text-sm text-gray-600 shadow-sm">
                    <p>
                        Confronta <strong>tutti gli articoli</strong> presenti su ESOLVER e su Omni. Il file Excel ha due fogli:
                        <strong>articoli solo su ESOLVER</strong> e <strong>articoli solo su Omni</strong>.
                    </p>
                    <a
                        v-if="risultato"
                        :href="route('check.scarica')"
                        class="shrink-0 rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500"
                    >
                        Scarica Excel
                    </a>
                </div>

                <div v-if="risultato" class="grid gap-6 md:grid-cols-2">
                    <!-- Solo su ESOLVER -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="mb-3 flex items-baseline justify-between">
                            <h3 class="font-semibold text-gray-800">Solo su ESOLVER</h3>
                            <span class="rounded-full bg-amber-100 px-3 py-0.5 text-sm font-semibold text-amber-800">
                                {{ risultato.solo_esolver_totale }}
                            </span>
                        </div>
                        <div class="max-h-[32rem] overflow-auto rounded-lg border border-gray-100">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Codice</th>
                                        <th class="px-3 py-2">Descrizione</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(a, i) in soloEsolver" :key="i" class="hover:bg-gray-50">
                                        <td class="px-3 py-1.5 font-mono text-gray-800">{{ a.codice }}</td>
                                        <td class="px-3 py-1.5 text-gray-600">{{ a.descrizione }}</td>
                                    </tr>
                                    <tr v-if="soloEsolver.length === 0">
                                        <td colspan="2" class="px-3 py-3 text-center text-gray-400">Nessun articolo</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="troncatoEsolver" class="mt-2 text-xs text-gray-400">
                            Anteprima primi {{ anteprima }} — scarica l'Excel per l'elenco completo.
                        </p>
                    </div>

                    <!-- Solo su Omni -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <div class="mb-3 flex items-baseline justify-between">
                            <h3 class="font-semibold text-gray-800">Solo su Omni</h3>
                            <span class="rounded-full bg-indigo-100 px-3 py-0.5 text-sm font-semibold text-indigo-800">
                                {{ risultato.solo_omni_totale }}
                            </span>
                        </div>
                        <div class="max-h-[32rem] overflow-auto rounded-lg border border-gray-100">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Codice</th>
                                        <th class="px-3 py-2">Descrizione</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(a, i) in soloOmni" :key="i" class="hover:bg-gray-50">
                                        <td class="px-3 py-1.5 font-mono text-gray-800">{{ a.codice }}</td>
                                        <td class="px-3 py-1.5 text-gray-600">{{ a.descrizione }}</td>
                                    </tr>
                                    <tr v-if="soloOmni.length === 0">
                                        <td colspan="2" class="px-3 py-3 text-center text-gray-400">Nessun articolo</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="troncatoOmni" class="mt-2 text-xs text-gray-400">
                            Anteprima primi {{ anteprima }} — scarica l'Excel per l'elenco completo.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
