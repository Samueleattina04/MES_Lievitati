<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    lunghezzaMin: { type: Number, default: 4 },
    lunghezzaMax: { type: Number, default: 6 },
});

const form = useForm({ pin: '' });

const digits = computed(() => form.pin.split(''));

function premi(n) {
    if (form.pin.length < props.lunghezzaMax) {
        form.pin += String(n);
    }
}
function cancella() {
    form.pin = form.pin.slice(0, -1);
}
function azzera() {
    form.pin = '';
}
function entra() {
    if (form.pin.length < props.lunghezzaMin) {
        return;
    }
    form.post(route('operatore.pin-login'), {
        onError: () => azzera(),
    });
}
</script>

<template>
    <Head title="Accesso operatore" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-slate-900 p-6 text-slate-100">
        <h1 class="mb-2 text-2xl font-bold">Accesso reparto</h1>
        <p class="mb-6 text-slate-400">Inserisci il tuo PIN</p>

        <!-- Indicatori PIN -->
        <div class="mb-4 flex gap-3">
            <span
                v-for="i in lunghezzaMax"
                :key="i"
                class="h-4 w-4 rounded-full border-2 border-slate-500"
                :class="digits[i - 1] !== undefined ? 'bg-emerald-400 border-emerald-400' : ''"
            />
        </div>

        <p v-if="form.errors.pin" class="mb-4 rounded-lg bg-red-600 px-4 py-2 text-lg font-semibold">
            {{ form.errors.pin }}
        </p>

        <!-- Tastierino numerico grande (§8) -->
        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]"
                :key="n"
                type="button"
                class="h-20 w-20 rounded-2xl bg-slate-700 text-3xl font-bold active:bg-slate-600"
                @click="premi(n)"
            >
                {{ n }}
            </button>
            <button type="button" class="h-20 w-20 rounded-2xl bg-slate-800 text-lg font-semibold active:bg-slate-700" @click="azzera">
                C
            </button>
            <button type="button" class="h-20 w-20 rounded-2xl bg-slate-700 text-3xl font-bold active:bg-slate-600" @click="premi(0)">
                0
            </button>
            <button type="button" class="h-20 w-20 rounded-2xl bg-slate-800 text-2xl font-semibold active:bg-slate-700" @click="cancella">
                ⌫
            </button>
        </div>

        <button
            type="button"
            class="mt-6 h-16 w-64 rounded-2xl bg-emerald-600 text-2xl font-bold active:bg-emerald-500 disabled:opacity-40"
            :disabled="form.pin.length < lunghezzaMin || form.processing"
            @click="entra"
        >
            Entra
        </button>
    </div>
</template>
