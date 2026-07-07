<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// NB: non usare 'data' come nome di campo: e' un metodo riservato di useForm (form.data()),
// altrimenti .post() fallisce con "this.data is not a function". Usiamo 'data_ordine' e lo
// rimappiamo su 'data' con transform() prima dell'invio (il backend resta invariato).
const form = useForm({
    articolo_finito_codice: '',
    quantita: '',
    data_ordine: new Date().toISOString().slice(0, 10),
    note: '',
});

const ricerca = ref('');
const risultati = ref([]);
const cercando = ref(false);
const descrizioneSelezionata = ref('');
let timer = null;

const flashError = computed(() => usePage().props.flash?.error);

function onRicerca() {
    clearTimeout(timer);
    const q = ricerca.value.trim();
    if (q.length < 1) {
        risultati.value = [];
        return;
    }
    timer = setTimeout(async () => {
        cercando.value = true;
        try {
            const { data } = await window.axios.get(route('ordini.cerca-articoli'), { params: { q } });
            risultati.value = data;
        } catch (e) {
            risultati.value = [];
        } finally {
            cercando.value = false;
        }
    }, 300);
}

function seleziona(articolo) {
    form.articolo_finito_codice = articolo.codice;
    descrizioneSelezionata.value = articolo.descrizione || '';
    ricerca.value = `${articolo.codice}${articolo.descrizione ? ' - ' + articolo.descrizione : ''}`;
    risultati.value = [];
}

function submit() {
    form
        .transform((d) => ({
            articolo_finito_codice: d.articolo_finito_codice,
            quantita: d.quantita,
            data: d.data_ordine,
            note: d.note,
        }))
        .post(route('ordini.store'));
}
</script>

<template>
    <Head title="Nuovo ordine" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Nuovo ordine di produzione</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div v-if="flashError" class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700">
                    {{ flashError }}
                </div>

                <form @submit.prevent="submit" class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
                    <div class="relative">
                        <InputLabel for="articolo" value="Articolo da produrre" />
                        <TextInput
                            id="articolo"
                            v-model="ricerca"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Cerca per codice o descrizione (es. ASSPAN01)"
                            autocomplete="off"
                            @input="onRicerca"
                        />
                        <p v-if="cercando" class="mt-1 text-xs text-gray-400">Ricerca in corso...</p>
                        <ul v-if="risultati.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                            <li
                                v-for="a in risultati"
                                :key="a.codice"
                                class="cursor-pointer px-3 py-2 text-sm hover:bg-indigo-50"
                                @click="seleziona(a)"
                            >
                                <span class="font-medium">{{ a.codice }}</span>
                                <span class="text-gray-500"> — {{ a.descrizione }}</span>
                            </li>
                        </ul>
                        <InputError class="mt-2" :message="form.errors.articolo_finito_codice" />
                        <p v-if="form.articolo_finito_codice" class="mt-1 text-xs text-emerald-700">
                            Selezionato: <strong>{{ form.articolo_finito_codice }}</strong> {{ descrizioneSelezionata }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="quantita" value="Quantita da produrre" />
                            <TextInput id="quantita" v-model="form.quantita" type="number" step="0.000001" min="0" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.quantita" />
                        </div>
                        <div>
                            <InputLabel for="data" value="Data ordine" />
                            <TextInput id="data" v-model="form.data_ordine" type="date" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.data" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="note" value="Note (opzionale)" />
                        <textarea id="note" v-model="form.note" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        <InputError class="mt-2" :message="form.errors.note" />
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <Link :href="route('ordini.index')" class="text-sm text-gray-600 hover:underline">Annulla</Link>
                        <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing || !form.articolo_finito_codice">
                            Crea ordine ed esplodi distinta
                        </PrimaryButton>
                    </div>
                </form>

                <p class="mt-4 text-center text-xs text-gray-400">
                    Alla creazione, la distinta viene esplosa dal gestionale e congelata: vengono generate le fasi di lavorazione.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
