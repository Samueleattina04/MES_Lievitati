<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    kpi: { type: Object, required: true },
    fasiFerme: { type: Array, default: () => [] },
    caricoReparto: { type: Array, default: () => [] },
    tempiReparto: { type: Array, default: () => [] },
    prontiExport: { type: Array, default: () => [] },
});

const csrf = computed(() => usePage().props.csrf_token);
const puoEsportare = computed(() => usePage().props.auth.can?.esportare === true);
const flashError = computed(() => usePage().props.flash?.error);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard produzione</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="flashError" class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ flashError }}</div>

                <!-- KPI ordini per stato -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-400">Aperti</div>
                        <div class="text-2xl font-bold text-gray-800">{{ kpi.ordini_per_stato.aperto }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-400">In lavorazione</div>
                        <div class="text-2xl font-bold text-amber-600">{{ kpi.ordini_per_stato.in_lavorazione }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-400">Completati</div>
                        <div class="text-2xl font-bold text-emerald-600">{{ kpi.ordini_per_stato.completato }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <div class="text-xs uppercase text-gray-400">Esportati</div>
                        <div class="text-2xl font-bold text-sky-600">{{ kpi.ordini_per_stato.esportato }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Carico per reparto -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">Carico per reparto (step aperti)</h3>
                        <div v-if="caricoReparto.length === 0" class="text-sm text-gray-400">Nessun carico.</div>
                        <div v-for="r in caricoReparto" :key="r.descrizione" class="mb-2 flex items-center gap-3">
                            <span class="w-40 text-sm text-gray-700">{{ r.descrizione }}</span>
                            <div class="h-3 flex-1 rounded bg-gray-100">
                                <div class="h-3 rounded bg-indigo-500" :style="{ width: Math.min(100, r.n * 12) + '%' }" />
                            </div>
                            <span class="w-8 text-right text-sm font-semibold">{{ r.n }}</span>
                        </div>
                    </div>

                    <!-- Tempi medi + scostamento -->
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="mb-3 font-semibold text-gray-800">Tempo medio per reparto</h3>
                        <div v-if="tempiReparto.length === 0" class="text-sm text-gray-400">Nessun dato ancora.</div>
                        <ul class="mb-4 space-y-1 text-sm">
                            <li v-for="t in tempiReparto" :key="t.reparto" class="flex justify-between">
                                <span class="text-gray-700">{{ t.reparto }}</span>
                                <span class="font-semibold">{{ t.minuti }} min</span>
                            </li>
                        </ul>
                        <div class="rounded bg-gray-50 p-3 text-sm">
                            Fasi con quantita modificata vs teorico:
                            <strong>{{ kpi.perc_scostamento }}%</strong>
                        </div>
                    </div>
                </div>

                <!-- Colli di bottiglia -->
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold text-gray-800">
                        Fasi ferme da oltre {{ kpi.soglia_ore }}h ({{ kpi.fasi_ferme_count }})
                    </h3>
                    <div v-if="fasiFerme.length === 0" class="text-sm text-gray-400">Nessuna fase ferma. 👍</div>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-400">
                            <tr><th class="py-1">Ordine</th><th>Articolo</th><th>Reparto</th><th class="text-right">Da (ore)</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(f, i) in fasiFerme" :key="i">
                                <td class="py-1">{{ f.ordine }}</td>
                                <td>{{ f.articolo }}</td>
                                <td>{{ f.reparto }}</td>
                                <td class="text-right font-semibold text-red-600">{{ f.da_ore }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ordini pronti per l'export -->
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold text-gray-800">Ordini pronti per l'export ({{ prontiExport.length }})</h3>
                    <div v-if="prontiExport.length === 0" class="text-sm text-gray-400">Nessun ordine completato da esportare.</div>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-400">
                            <tr><th class="py-1">Numero</th><th>Articolo</th><th class="text-right">Quantita</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="o in prontiExport" :key="o.id">
                                <td class="py-2 font-medium">{{ o.numero }}</td>
                                <td>{{ o.articolo }}</td>
                                <td class="text-right">{{ o.quantita }} {{ o.udm }}</td>
                                <td class="text-right">
                                    <form v-if="puoEsportare" :action="route('export.esporta', o.id)" method="post">
                                        <input type="hidden" name="_token" :value="csrf" />
                                        <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                                            Esporta (ZIP)
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
