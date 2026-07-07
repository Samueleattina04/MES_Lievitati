<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    tipiFase: { type: Array, required: true },
    reparti: { type: Array, required: true },
});

const flash = computed(() => usePage().props.flash);
const editingId = ref(null);
const mostraForm = ref(false);
const form = useForm({ codice: '', descrizione: '', steps: [] });

function stepVuoto() {
    return { reparto_id: props.reparti[0]?.id ?? null, descrizione: '', consuma_materiali: form.steps.length === 0 };
}
function nuovo() {
    form.reset();
    form.clearErrors();
    form.steps = [stepVuoto()];
    editingId.value = null;
    mostraForm.value = true;
}
function modifica(t) {
    form.codice = t.codice;
    form.descrizione = t.descrizione;
    form.steps = t.steps.map((s) => ({ reparto_id: s.reparto_id, descrizione: s.descrizione, consuma_materiali: s.consuma_materiali }));
    editingId.value = t.id;
    mostraForm.value = true;
}
function aggiungiStep() {
    form.steps.push(stepVuoto());
}
function rimuoviStep(i) {
    form.steps.splice(i, 1);
}
function salva() {
    const opts = { preserveScroll: true, onSuccess: () => { mostraForm.value = false; form.reset(); } };
    editingId.value
        ? form.put(route('admin.tipi-fase.update', editingId.value), opts)
        : form.post(route('admin.tipi-fase.store'), opts);
}
function elimina(t) {
    if (confirm(`Eliminare il tipo fase ${t.codice}?`)) {
        router.delete(route('admin.tipi-fase.destroy', t.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Tipi fase" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Tipi fase (step / reparti)</h2>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="nuovo">+ Nuovo tipo fase</button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ flash.success }}</div>
                <div v-if="flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ flash.error }}</div>

                <div v-if="mostraForm" class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold">{{ editingId ? 'Modifica' : 'Nuovo' }} tipo fase</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-gray-600">Codice</label>
                            <input v-model="form.codice" class="mt-1 w-full rounded-md border-gray-300" />
                            <p v-if="form.errors.codice" class="text-xs text-red-600">{{ form.errors.codice }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">Descrizione</label>
                            <input v-model="form.descrizione" class="mt-1 w-full rounded-md border-gray-300" />
                            <p v-if="form.errors.descrizione" class="text-xs text-red-600">{{ form.errors.descrizione }}</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-700">Step (in ordine di lavorazione)</span>
                            <button class="rounded bg-gray-100 px-3 py-1 text-sm" @click="aggiungiStep">+ Step</button>
                        </div>
                        <p v-if="form.errors.steps" class="mb-2 text-xs text-red-600">{{ form.errors.steps }}</p>
                        <div v-for="(s, i) in form.steps" :key="i" class="mb-2 flex flex-wrap items-center gap-2 rounded border border-gray-100 p-2">
                            <span class="w-6 text-center text-sm text-gray-400">{{ i + 1 }}</span>
                            <select v-model="s.reparto_id" class="rounded-md border-gray-300 text-sm">
                                <option v-for="r in reparti" :key="r.id" :value="r.id">{{ r.descrizione }}</option>
                            </select>
                            <input v-model="s.descrizione" placeholder="Descrizione step (opz.)" class="flex-1 rounded-md border-gray-300 text-sm" />
                            <label class="inline-flex items-center gap-1 text-xs text-gray-600">
                                <input v-model="s.consuma_materiali" type="checkbox" class="rounded border-gray-300" /> consuma materiali
                            </label>
                            <button class="rounded bg-red-50 px-2 py-1 text-xs text-red-600" @click="rimuoviStep(i)">✕</button>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing" @click="salva">Salva</button>
                        <button class="rounded-md bg-gray-100 px-4 py-2 text-sm" @click="mostraForm = false">Annulla</button>
                    </div>
                </div>

                <div class="space-y-3">
                    <div v-for="t in tipiFase" :key="t.id" class="rounded-lg bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="font-semibold text-gray-800">{{ t.codice }}</div>
                                <div class="text-sm text-gray-500">{{ t.descrizione }}</div>
                                <div class="mt-2 flex flex-wrap gap-1 text-xs">
                                    <span v-for="(s, i) in t.steps" :key="i" class="rounded bg-slate-100 px-2 py-0.5 text-slate-700">
                                        {{ i + 1 }}. {{ s.reparto }}<span v-if="s.consuma_materiali"> ·mat</span>
                                    </span>
                                </div>
                            </div>
                            <div class="text-right text-sm">
                                <button class="mr-3 text-indigo-600 hover:underline" @click="modifica(t)">Modifica</button>
                                <button class="text-red-600 hover:underline" @click="elimina(t)">Elimina</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
