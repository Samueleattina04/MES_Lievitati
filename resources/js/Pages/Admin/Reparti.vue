<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({ reparti: { type: Array, required: true } });

const flash = computed(() => usePage().props.flash);
const editingId = ref(null);
const mostraForm = ref(false);
const form = useForm({ codice: '', descrizione: '', attivo: true });

function nuovo() {
    form.reset();
    form.clearErrors();
    editingId.value = null;
    mostraForm.value = true;
}
function modifica(r) {
    form.codice = r.codice;
    form.descrizione = r.descrizione;
    form.attivo = r.attivo;
    editingId.value = r.id;
    mostraForm.value = true;
}
function salva() {
    const opts = { preserveScroll: true, onSuccess: () => { mostraForm.value = false; form.reset(); } };
    if (editingId.value) {
        form.put(route('admin.reparti.update', editingId.value), opts);
    } else {
        form.post(route('admin.reparti.store'), opts);
    }
}
function elimina(r) {
    if (confirm(`Eliminare il reparto ${r.codice}?`)) {
        router.delete(route('admin.reparti.destroy', r.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Reparti" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Reparti</h2>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="nuovo">+ Nuovo reparto</button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ flash.success }}</div>
                <div v-if="flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ flash.error }}</div>

                <div v-if="mostraForm" class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold">{{ editingId ? 'Modifica' : 'Nuovo' }} reparto</h3>
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
                    <label class="mt-3 inline-flex items-center gap-2 text-sm">
                        <input v-model="form.attivo" type="checkbox" class="rounded border-gray-300" /> Attivo
                    </label>
                    <div class="mt-4 flex gap-2">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing" @click="salva">Salva</button>
                        <button class="rounded-md bg-gray-100 px-4 py-2 text-sm" @click="mostraForm = false">Annulla</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Codice</th><th class="px-4 py-3">Descrizione</th><th class="px-4 py-3">Stato</th><th class="px-4 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in reparti" :key="r.id">
                                <td class="px-4 py-3 font-medium">{{ r.codice }}</td>
                                <td class="px-4 py-3">{{ r.descrizione }}</td>
                                <td class="px-4 py-3">
                                    <span :class="r.attivo ? 'text-emerald-600' : 'text-gray-400'">{{ r.attivo ? 'Attivo' : 'Disattivo' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button class="mr-3 text-indigo-600 hover:underline" @click="modifica(r)">Modifica</button>
                                    <button class="text-red-600 hover:underline" @click="elimina(r)">Elimina</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
