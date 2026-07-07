<script setup>
import OperatorLayout from '@/Layouts/OperatorLayout.vue';
import { azione } from '@/offline/sync';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    fase: { type: Object, required: true },
    destinazioni: { type: Array, required: true },
});

const assegnazioni = reactive(
    props.destinazioni.map((d) => ({
        fase_destinazione_id: d.fase_destinazione_id,
        quantita: d.quota_suggerita,
    })),
);
const avviso = ref('');
const inCorso = ref(false);

const totale = computed(() => assegnazioni.reduce((acc, a) => acc + (parseFloat(a.quantita) || 0), 0));
const quadra = computed(() => Math.abs(totale.value - props.fase.quantita_da_ripartire) <= 0.01 + 1e-9);

async function submit() {
    avviso.value = '';
    inCorso.value = true;
    const r = await azione('split', { fase_id: props.fase.id, assegnazioni: [...assegnazioni] });
    inCorso.value = false;
    if (r.stato === 'ok' || r.stato === 'accodata') {
        router.visit(route('operatore.coda'));
    } else {
        avviso.value = r.messaggio || 'Ripartizione non riuscita.';
    }
}
</script>

<template>
    <Head title="Ripartizione semilavorato" />

    <OperatorLayout>
        <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl bg-slate-800 p-5">
                <h1 class="text-2xl font-bold">Ripartizione: {{ fase.articolo }}</h1>
                <p class="text-slate-400">{{ fase.descrizione }}</p>
                <p class="mt-2 text-lg">
                    Quantita prodotta da ripartire:
                    <strong class="text-emerald-300">{{ fase.quantita_da_ripartire }} {{ fase.udm }}</strong>
                </p>
            </div>

            <div class="mt-4 space-y-3">
                <div
                    v-for="(a, i) in assegnazioni"
                    :key="a.fase_destinazione_id"
                    class="flex items-center justify-between rounded-xl bg-slate-800 p-4"
                >
                    <div>
                        <div class="text-lg font-semibold">{{ destinazioni[i].articolo }}</div>
                        <div class="text-sm text-slate-400">{{ destinazioni[i].descrizione }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="a.quantita"
                            type="number"
                            step="0.000001"
                            min="0"
                            class="w-36 rounded-lg border-0 bg-slate-900 px-3 py-3 text-right text-xl text-white"
                        />
                        <span class="w-10 text-slate-400">{{ fase.udm }}</span>
                    </div>
                </div>
            </div>

            <div
                class="mt-4 flex items-center justify-between rounded-xl p-4 text-lg font-bold"
                :class="quadra ? 'bg-emerald-700' : 'bg-amber-700'"
            >
                <span>Totale assegnato</span>
                <span>{{ totale.toFixed(3) }} / {{ fase.quantita_da_ripartire }} {{ fase.udm }}</span>
            </div>
            <p v-if="!quadra" class="mt-1 text-sm text-amber-300">
                La somma deve coincidere con la quantita prodotta (tolleranza +/-0,01).
            </p>
            <p v-if="avviso" class="mt-1 text-sm text-red-400">{{ avviso }}</p>

            <button
                type="button"
                class="mt-5 h-16 w-full rounded-2xl bg-emerald-600 text-2xl font-bold active:bg-emerald-500 disabled:opacity-40"
                :disabled="!quadra || inCorso"
                @click="submit"
            >
                ✓ Conferma ripartizione
            </button>
        </div>
    </OperatorLayout>
</template>
