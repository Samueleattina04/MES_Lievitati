<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    utenti: { type: Array, required: true },
    reparti: { type: Array, required: true },
    ruoli: { type: Array, required: true },
});

const flash = computed(() => usePage().props.flash);
const editingId = ref(null);
const mostraForm = ref(false);
const form = useForm({ name: '', ruolo: 'operatore', attivo: true, email: '', password: '', pin: '', reparti: [] });

const isOperatore = computed(() => form.ruolo === 'operatore');

function nuovo() {
    form.reset();
    form.clearErrors();
    editingId.value = null;
    mostraForm.value = true;
}
function modifica(u) {
    form.reset();
    form.clearErrors();
    form.name = u.name;
    form.ruolo = u.ruolo;
    form.attivo = u.attivo;
    form.email = u.email ?? '';
    form.reparti = [...u.reparti];
    editingId.value = u.id;
    mostraForm.value = true;
}
function toggleReparto(id) {
    const i = form.reparti.indexOf(id);
    i === -1 ? form.reparti.push(id) : form.reparti.splice(i, 1);
}
function salva() {
    const opts = { preserveScroll: true, onSuccess: () => { mostraForm.value = false; form.reset(); } };
    editingId.value
        ? form.put(route('admin.utenti.update', editingId.value), opts)
        : form.post(route('admin.utenti.store'), opts);
}
function elimina(u) {
    if (confirm(`Eliminare l'utente ${u.name}?`)) {
        router.delete(route('admin.utenti.destroy', u.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Utenti" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Utenti</h2>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="nuovo">+ Nuovo utente</button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ flash.success }}</div>
                <div v-if="flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ flash.error }}</div>

                <div v-if="mostraForm" class="rounded-lg bg-white p-5 shadow-sm">
                    <h3 class="mb-3 font-semibold">{{ editingId ? 'Modifica' : 'Nuovo' }} utente</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-gray-600">Nome</label>
                            <input v-model="form.name" class="mt-1 w-full rounded-md border-gray-300" />
                            <p v-if="form.errors.name" class="text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">Ruolo</label>
                            <select v-model="form.ruolo" class="mt-1 w-full rounded-md border-gray-300">
                                <option v-for="r in ruoli" :key="r.value" :value="r.value">{{ r.label }}</option>
                            </select>
                            <p v-if="form.errors.ruolo" class="text-xs text-red-600">{{ form.errors.ruolo }}</p>
                        </div>
                    </div>

                    <!-- OPERATORE: PIN + reparti -->
                    <div v-if="isOperatore" class="mt-3 space-y-3">
                        <div>
                            <label class="block text-sm text-gray-600">
                                PIN numerico <span class="text-gray-400">({{ editingId ? 'lascia vuoto per non cambiarlo' : '4–6 cifre' }})</span>
                            </label>
                            <input v-model="form.pin" type="text" inputmode="numeric" maxlength="6" class="mt-1 w-40 rounded-md border-gray-300" />
                            <p v-if="form.errors.pin" class="text-xs text-red-600">{{ form.errors.pin }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">Reparti abilitati</label>
                            <div class="mt-1 flex flex-wrap gap-2">
                                <label v-for="r in reparti" :key="r.id" class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-1 text-sm">
                                    <input type="checkbox" :checked="form.reparti.includes(r.id)" class="rounded border-gray-300" @change="toggleReparto(r.id)" />
                                    {{ r.descrizione }}
                                </label>
                            </div>
                            <p v-if="form.errors.reparti" class="text-xs text-red-600">{{ form.errors.reparti }}</p>
                        </div>
                    </div>

                    <!-- STAFF: email + password -->
                    <div v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm text-gray-600">Email</label>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-md border-gray-300" />
                            <p v-if="form.errors.email" class="text-xs text-red-600">{{ form.errors.email }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600">
                                Password <span class="text-gray-400">({{ editingId ? 'lascia vuoto per non cambiarla' : 'min 6' }})</span>
                            </label>
                            <input v-model="form.password" type="password" class="mt-1 w-full rounded-md border-gray-300" />
                            <p v-if="form.errors.password" class="text-xs text-red-600">{{ form.errors.password }}</p>
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
                            <tr><th class="px-4 py-3">Nome</th><th class="px-4 py-3">Ruolo</th><th class="px-4 py-3">Accesso</th><th class="px-4 py-3">Stato</th><th class="px-4 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="u in utenti" :key="u.id">
                                <td class="px-4 py-3 font-medium">{{ u.name }}</td>
                                <td class="px-4 py-3">{{ u.ruolo_label }}</td>
                                <td class="px-4 py-3 text-gray-500">
                                    <span v-if="u.email">{{ u.email }}</span>
                                    <span v-else-if="u.ha_pin">PIN ({{ u.reparti.length }} reparti)</span>
                                    <span v-else class="text-red-500">nessun accesso</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="u.attivo ? 'text-emerald-600' : 'text-gray-400'">{{ u.attivo ? 'Attivo' : 'Disattivo' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button class="mr-3 text-indigo-600 hover:underline" @click="modifica(u)">Modifica</button>
                                    <button class="text-red-600 hover:underline" @click="elimina(u)">Elimina</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
