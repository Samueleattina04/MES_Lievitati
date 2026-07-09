<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    ordini: { type: Object, required: true },
});

const puoCreare = computed(() => usePage().props.auth.can?.gestireOrdini === true);
const isAdmin = computed(() => usePage().props.auth.ruolo === 'admin');

// Cancellabile: sempre gli "aperti"; l'admin puo' cancellare anche gli "in lavorazione".
function cancellabile(o) {
    return o.stato === 'aperto' || (isAdmin.value && o.stato === 'in_lavorazione');
}

function cancella(o) {
    if (!cancellabile(o)) {
        return;
    }
    const messaggio = o.stato === 'in_lavorazione'
        ? `L'ordine ${o.numero} è IN LAVORAZIONE: verranno eliminati anche i dati di avanzamento già registrati (consumi, lotti, split). Confermare la cancellazione?`
        : `Cancellare l'ordine ${o.numero}? L'operazione è irreversibile.`;
    if (confirm(messaggio)) {
        router.delete(route('ordini.destroy', o.id), { preserveScroll: true });
    }
}

const statoColore = {
    aperto: 'bg-gray-100 text-gray-700',
    in_lavorazione: 'bg-amber-100 text-amber-800',
    completato: 'bg-emerald-100 text-emerald-800',
    esportato: 'bg-sky-100 text-sky-800',
};
</script>

<template>
    <Head title="Ordini di produzione" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Ordini di produzione
                </h2>
                <Link
                    v-if="puoCreare"
                    :href="route('ordini.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    + Nuovo ordine
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Numero</th>
                                <th class="px-4 py-3">Articolo</th>
                                <th class="px-4 py-3 text-right">Quantita</th>
                                <th class="px-4 py-3">Data</th>
                                <th class="px-4 py-3">Avanzamento</th>
                                <th class="px-4 py-3">Stato</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="o in ordini.data" :key="o.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium">
                                    <Link :href="route('ordini.show', o.id)" class="text-indigo-600 hover:underline">
                                        {{ o.numero }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800">{{ o.articolo }}</div>
                                    <div class="text-xs text-gray-500">{{ o.descrizione }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">{{ o.quantita }} {{ o.udm }}</td>
                                <td class="px-4 py-3">{{ o.data }}</td>
                                <td class="px-4 py-3">{{ o.fasi_chiuse }} / {{ o.fasi }} fasi</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statoColore[o.stato]">
                                        {{ o.stato_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="puoCreare && cancellabile(o)"
                                        type="button"
                                        class="text-red-600 hover:underline"
                                        @click="cancella(o)"
                                    >
                                        Cancella
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="ordini.data.length === 0">
                                <td colspan="7" class="px-4 py-10 text-center text-gray-500">
                                    Nessun ordine. <Link v-if="puoCreare" :href="route('ordini.create')" class="text-indigo-600 hover:underline">Crea il primo ordine</Link>.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="ordini.links && ordini.links.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="(link, i) in ordini.links"
                        :key="i"
                        :href="link.url || ''"
                        v-html="link.label"
                        class="rounded px-3 py-1 text-sm"
                        :class="[link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600', !link.url && 'pointer-events-none opacity-50']"
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
