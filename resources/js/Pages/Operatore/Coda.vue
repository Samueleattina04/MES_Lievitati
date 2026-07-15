<script setup>
import OperatorLayout from '@/Layouts/OperatorLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    operatore: { type: Object, required: true },
    cards: { type: Array, required: true },
    splitPendenti: { type: Array, default: () => [] },
});

function apri(card) {
    if (card.lavorabile) {
        router.get(route('operatore.fase', card.step_id));
    }
}
</script>

<template>
    <Head title="Coda di lavoro" />

    <OperatorLayout>
        <div class="mb-4">
            <h1 class="text-2xl font-bold">Coda di lavoro</h1>
            <p v-if="operatore.tutti_reparti" class="text-slate-400">Backoffice · tutti i reparti</p>
            <p v-else class="text-slate-400">Reparti: {{ operatore.reparti.join(', ') }}</p>
        </div>

        <!-- Ripartizioni da registrare (§5-bis): sbloccano le fasi successive -->
        <div v-if="splitPendenti.length" class="mb-5">
            <h2 class="mb-2 text-lg font-bold text-red-300">Ripartizioni da registrare</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="s in splitPendenti"
                    :key="s.fase_id"
                    type="button"
                    class="rounded-2xl border-l-8 border-red-400 bg-slate-800 p-5 text-left hover:bg-slate-700 active:bg-slate-600"
                    @click="router.get(route('operatore.split', s.fase_id))"
                >
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ s.ordine_numero }}</div>
                    <div class="mt-1 text-xl font-bold">{{ s.articolo }}</div>
                    <div class="text-sm text-slate-400">{{ s.descrizione }}</div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-lg font-semibold">{{ s.quantita }} {{ s.udm }}</span>
                        <span class="rounded-full bg-red-500/20 px-3 py-1 text-sm font-semibold text-red-300">Ripartisci →</span>
                    </div>
                </button>
            </div>
        </div>

        <div v-if="cards.length === 0 && splitPendenti.length === 0" class="rounded-xl bg-slate-800 p-8 text-center text-xl text-slate-400">
            Nessuna fase da lavorare al momento.
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="card in cards"
                :key="card.step_id"
                type="button"
                :disabled="!card.lavorabile"
                class="rounded-2xl border-l-8 p-5 text-left transition"
                :class="[
                    card.stato === 'in_corso' ? 'border-amber-400 bg-slate-800' : 'border-slate-500 bg-slate-800',
                    card.lavorabile ? 'hover:bg-slate-700 active:bg-slate-600' : 'cursor-not-allowed opacity-50',
                ]"
                @click="apri(card)"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wide text-slate-400">{{ card.ordine_numero }}</span>
                    <span v-if="card.condiviso" class="rounded bg-red-500/20 px-2 py-0.5 text-xs font-semibold text-red-300">
                        CONDIVISO
                    </span>
                </div>
                <div class="mt-1 text-xl font-bold">{{ card.articolo }}</div>
                <div class="text-sm text-slate-400">{{ card.descrizione }}</div>
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-lg font-semibold">{{ card.quantita }} {{ card.udm }}</span>
                    <span
                        class="rounded-full px-3 py-1 text-sm font-semibold"
                        :class="card.stato === 'in_corso' ? 'bg-amber-500/20 text-amber-300' : 'bg-slate-600 text-slate-200'"
                    >
                        {{ card.stato === 'in_corso' ? 'In corso' : 'Da lavorare' }}
                    </span>
                </div>
                <div class="mt-2 text-sm text-slate-400">
                    {{ card.reparto }}<span v-if="card.step_descrizione"> · {{ card.step_descrizione }}</span>
                </div>
                <div v-if="!card.lavorabile" class="mt-2 rounded bg-slate-900/60 px-2 py-1 text-xs text-amber-300">
                    🔒 {{ card.motivo }}
                </div>
            </button>
        </div>
    </OperatorLayout>
</template>
