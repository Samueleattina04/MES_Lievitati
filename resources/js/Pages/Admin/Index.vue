<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    conteggi: { type: Object, required: true },
});

const sezioni = [
    { titolo: 'Reparti', desc: 'Unità produttive', chiave: 'reparti', route: 'admin.reparti.index' },
    { titolo: 'Tipi fase', desc: 'Sequenze di step/reparti', chiave: 'tipi_fase', route: 'admin.tipi-fase.index' },
    { titolo: 'Articoli → reparto', desc: 'Mappatura per il planner', chiave: 'configurazioni', route: 'admin.articoli-config.index' },
    { titolo: 'Utenti', desc: 'Staff e operatori (PIN)', chiave: 'utenti', route: 'admin.utenti.index' },
];
</script>

<template>
    <Head title="Amministrazione" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Amministrazione</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-5xl grid-cols-1 gap-4 sm:grid-cols-2 sm:px-6 lg:px-8">
                <Link
                    v-for="s in sezioni"
                    :key="s.chiave"
                    :href="route(s.route)"
                    class="rounded-lg bg-white p-6 shadow-sm transition hover:shadow-md"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">{{ s.titolo }}</h3>
                        <span class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-bold text-indigo-700">{{ conteggi[s.chiave] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">{{ s.desc }}</p>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
