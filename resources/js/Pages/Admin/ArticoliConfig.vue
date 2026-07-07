<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    configurazioni: { type: Array, required: true },
    reparti: { type: Array, required: true },
    tipiFase: { type: Array, required: true },
});

const flash = computed(() => usePage().props.flash);
const editingId = ref(null);
const mostraForm = ref(false);
const form = useForm({ articolo_codice: '', reparto_default_id: null, tipo_fase_id: null, flag_lotto_override: false, note: '' });

function nuovo() {
    form.reset();
    form.clearErrors();
    editingId.value = null;
    mostraForm.value = true;
}
function modifica(c) {
    form.articolo_codice = c.articolo_codice;
    form.reparto_default_id = c.reparto_default_id;
    form.tipo_fase_id = c.tipo_fase_id;
    form.flag_lotto_override = c.flag_lotto_override ?? false;
    form.note = c.note ?? '';
    editingId.value = c.id;
    mostraForm.value = true;
}
function salva() {
    const opts = { preserveScroll: true, onSuccess: () => { mostraForm.value = false; form.reset(); } };
    editingId.value
        ? form.put(route('admin.articoli-config.update', editingId.value), opts)
        : form.post(route('admin.articoli-config.store'), opts);
}
function elimina(c) {
    if (confirm(`Eliminare la configurazione di ${c.articolo_codice}?`)) {
        router.delete(route('admin.articoli-config.destroy', c.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Mappatura articoli" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Mappatura articolo → reparto / tipo fase</h2>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="nuovo">+ Nuova mappatura</button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ flash.success }}</div>
                <div v-if="flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ flash.error }}</div>

                <div v-if="mostraForm" class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold">{{ editingId ? 'Modifica' : 'Nuova' }} mappatura</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-gray-600">Codice articolo prodotto</label>
                            <input v-model="form.articolo_codice" class="mt-1 w-full rounded-md border-gray-300" placeholder="es. IMPASTOCOLOMBE/PANETTONI" />
                            <p v-if="form.errors.articolo_codice" class="text-xs text-red-600">{{ form.errors.articolo_codice }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">Tipo fase (sequenza reparti)</label>
                            <select v-model="form.tipo_fase_id" class="mt-1 w-full rounded-md border-gray-300">
                                <option :value="null">— nessuno —</option>
                                <option v-for="t in tipiFase" :key="t.id" :value="t.id">{{ t.codice }} ({{ t.descrizione }})</option>
                            </select>
                            <p v-if="form.errors.tipo_fase_id" class="text-xs text-red-600">{{ form.errors.tipo_fase_id }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">Reparto di default (se nessun tipo fase)</label>
                            <select v-model="form.reparto_default_id" class="mt-1 w-full rounded-md border-gray-300">
                                <option :value="null">— nessuno —</option>
                                <option v-for="r in reparti" :key="r.id" :value="r.id">{{ r.descrizione }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mt-6 inline-flex items-center gap-2 text-sm">
                                <input v-model="form.flag_lotto_override" type="checkbox" class="rounded border-gray-300" /> Richiede lotto (override)
                            </label>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing" @click="salva">Salva</button>
                        <button class="rounded-md bg-gray-100 px-4 py-2 text-sm" @click="mostraForm = false">Annulla</button>
                    </div>
                    <p class="mt-2 text-xs text-gray-400">Priorità: se è impostato un Tipo fase, i suoi step prevalgono sul reparto di default.</p>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Articolo</th><th class="px-4 py-3">Tipo fase</th><th class="px-4 py-3">Reparto default</th><th class="px-4 py-3">Lotto</th><th class="px-4 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in configurazioni" :key="c.id">
                                <td class="px-4 py-3 font-medium">{{ c.articolo_codice }}</td>
                                <td class="px-4 py-3">{{ c.tipo_fase || '—' }}</td>
                                <td class="px-4 py-3">{{ c.reparto || '—' }}</td>
                                <td class="px-4 py-3">{{ c.flag_lotto_override === null ? '—' : (c.flag_lotto_override ? 'sì' : 'no') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button class="mr-3 text-indigo-600 hover:underline" @click="modifica(c)">Modifica</button>
                                    <button class="text-red-600 hover:underline" @click="elimina(c)">Elimina</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
