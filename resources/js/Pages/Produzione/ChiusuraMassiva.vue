<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    ordine: { type: Object, required: true },
    fasi: { type: Array, required: true },
});

const flashError = computed(() => usePage().props.flash?.error);
const inviando = ref(false);

// Stato editabile per fase (modalità, lotto prodotto/stock, quantità, materiali con lotti).
const stato = reactive({});
props.fasi.forEach((f) => {
    stato[f.id] = {
        modalita: 'produzione',
        lotto_prodotto: f.lotto_uscita ?? '',
        lotto_stock: '',
        quantita_prodotta: f.quantita,
        materiali: {},
    };
    f.materiali.forEach((m) => {
        let lotti = [];
        if (m.gestione_lotto) {
            if (m.proposta_fifo && m.proposta_fifo.length) {
                lotti = m.proposta_fifo.map((l) => ({ lotto: l.lotto, quantita: l.quantita }));
            } else if (m.semilavorato && m.lotto_propagato) {
                lotti = [{ lotto: m.lotto_propagato, quantita: m.quantita_pianificata }];
            } else {
                lotti = [{ lotto: '', quantita: m.quantita_pianificata }];
            }
        }
        stato[f.id].materiali[m.id] = { quantita: m.quantita_pianificata, lotti };
    });
});

// Mappa "articolo prodotto -> lotto in uscita corrente" per la propagazione a catena (§5.3).
const lottiPerArticolo = computed(() => {
    const map = {};
    props.fasi.forEach((f) => {
        const s = stato[f.id];
        const val = s.modalita === 'stock' ? s.lotto_stock : s.lotto_prodotto;
        if (val && val.trim() !== '') {
            map[f.articolo] = val.trim();
        } else if (f.lotto_uscita) {
            map[f.articolo] = f.lotto_uscita;
        }
    });
    return map;
});

// Quantità prodotta corrente per articolo (per propagare la quantità consumata sui padri).
const quantitaProdottaPerArticolo = computed(() => {
    const map = {};
    props.fasi.forEach((f) => {
        const s = stato[f.id];
        const qta = s.modalita === 'stock' ? f.quantita : s.quantita_prodotta;
        if (qta !== '' && qta !== null && qta !== undefined) {
            map[f.articolo] = Number(qta);
        }
    });
    return map;
});

// Propaga il lotto del semilavorato prodotto sulle righe-componente dei padri. Il campo del padre
// resta SINCRONIZZATO col lotto sorgente (anche mentre lo digiti, quindi niente valori "congelati"
// a metà) finché non lo modifichi a mano: da quel momento non viene più sovrascritto (default
// modificabile, §5.3). Il tracciamento "auto" evita di ricalpestare una scelta manuale.
const lottoAuto = reactive({});
const qtaAuto = reactive({});
// Articoli prodotti da un nodo CONDIVISO: la loro quantità è ripartita tra più padri (split),
// quindi la quantità prodotta NON viene propagata (resta quella pianificata/di ripartizione).
const articoliCondivisi = new Set(props.fasi.filter((f) => f.condiviso).map((f) => f.articolo));
const chiaveMat = (faseId, matId) => `${faseId}:${matId}`;

// Raggruppa i lotti a stock per magazzino, per una vista più leggibile nel "preleva da stock".
function raggruppaStock(lotti) {
    const gruppi = [];
    const idx = {};
    (lotti || []).forEach((l) => {
        if (!(l.magazzino in idx)) {
            idx[l.magazzino] = gruppi.length;
            gruppi.push({ magazzino: l.magazzino, lotti: [], totale: 0 });
        }
        const g = gruppi[idx[l.magazzino]];
        g.lotti.push(l);
        g.totale += Number(l.quantita) || 0;
    });
    return gruppi;
}

function propagaLotti() {
    const mapLotto = lottiPerArticolo.value;
    const mapQta = quantitaProdottaPerArticolo.value;
    props.fasi.forEach((f) => {
        f.materiali.forEach((m) => {
            if (!m.semilavorato) {
                return;
            }
            const riga = stato[f.id]?.materiali?.[m.id]?.lotti?.[0];
            if (!riga) {
                return;
            }
            const k = chiaveMat(f.id, m.id);

            // Lotto: sincronizzato col lotto in uscita del figlio finché non modificato a mano.
            const nuovoLotto = mapLotto[m.articolo];
            const lottoVuoto = !riga.lotto || riga.lotto.trim() === '';
            if (nuovoLotto && (lottoVuoto || lottoAuto[k])) {
                riga.lotto = nuovoLotto;
                lottoAuto[k] = true;
            }

            // Quantità: segue la quantità prodotta dal figlio (default modificabile). Esclusi i nodi
            // condivisi, la cui quantità è ripartita tra più padri con lo split.
            const nuovaQta = mapQta[m.articolo];
            if (nuovaQta !== undefined && ! articoliCondivisi.has(m.articolo) && qtaAuto[k] !== false) {
                riga.quantita = nuovaQta;
                qtaAuto[k] = true;
            }
        });
    });
}

// L'utente modifica a mano lotto/quantità di un componente: smette di essere auto-propagato.
function lottoModificatoAMano(faseId, m) {
    if (m.semilavorato) {
        lottoAuto[chiaveMat(faseId, m.id)] = false;
    }
}
function qtaModificataAMano(faseId, m) {
    if (m.semilavorato) {
        qtaAuto[chiaveMat(faseId, m.id)] = false;
    }
}

watch([lottiPerArticolo, quantitaProdottaPerArticolo], () => propagaLotti(), { deep: true, immediate: true });

const sommaLotti = (faseId, matId) =>
    (stato[faseId].materiali[matId].lotti || []).reduce((acc, r) => acc + (parseFloat(r.quantita) || 0), 0);
const aggiungiLotto = (faseId, matId) => stato[faseId].materiali[matId].lotti.push({ lotto: '', quantita: 0 });
const rimuoviLotto = (faseId, matId, i) => stato[faseId].materiali[matId].lotti.splice(i, 1);

// Sceglie un lotto tra quelli disponibili sul mag.06: riempie la riga vuota o ne aggiunge una nuova.
const scegliLotto = (faseId, m, lotto) => {
    const righe = stato[faseId].materiali[m.id].lotti;
    if (!righe.length) {
        righe.push({ lotto, quantita: m.quantita_pianificata });
        return;
    }
    if (righe.some((r) => (r.lotto || '').trim() === lotto)) {
        return;
    }
    const ultima = righe[righe.length - 1];
    if (!(ultima.lotto || '').trim()) {
        ultima.lotto = lotto;
    } else {
        righe.push({ lotto, quantita: 0 });
    }
};

const daChiudere = computed(() => props.fasi.filter((f) => !f.gia_chiusa));

// Card collassabili: di default tutte chiuse, l'utente apre quella che gli serve.
const espansa = reactive({});
const toggleFase = (id) => {
    espansa[id] = !espansa[id];
};
const espandiTutte = (val) => props.fasi.forEach((f) => {
    espansa[f.id] = val;
});

// Fase "pronta": ha gia' il lotto necessario impostato (per l'indicatore nell'intestazione).
function faseCompleta(f) {
    if (f.gia_chiusa) {
        return true;
    }
    const s = stato[f.id];
    if (s.modalita === 'stock') {
        return (s.lotto_stock || '').trim() !== '';
    }
    if (f.richiede_lotto_uscita) {
        return (s.lotto_prodotto || '').trim() !== '';
    }
    return true;
}

function invia() {
    const payload = {
        fasi: daChiudere.value.map((f) => {
            const s = stato[f.id];
            if (s.modalita === 'stock') {
                return { fase_id: f.id, modalita: 'stock', lotto_stock: s.lotto_stock };
            }
            return {
                fase_id: f.id,
                modalita: 'produzione',
                lotto_prodotto: s.lotto_prodotto,
                quantita_prodotta: s.quantita_prodotta,
                materiali: f.materiali.map((m) => {
                    const ms = s.materiali[m.id];
                    if (!m.gestione_lotto) {
                        return { materiale_id: m.id, quantita_effettiva: ms.quantita };
                    }
                    let lotti = (ms.lotti || []).filter((r) => r.lotto && r.lotto.trim() !== '');
                    // Fallback propagazione: se semilavorato senza lotto, usa quello della fase produttrice.
                    if (m.semilavorato && lotti.length === 0 && lottiPerArticolo.value[m.articolo]) {
                        lotti = [{ lotto: lottiPerArticolo.value[m.articolo], quantita: m.quantita_pianificata }];
                    }
                    return { materiale_id: m.id, lotti };
                }),
            };
        }),
    };

    inviando.value = true;
    router.post(route('produzione.chiudi-massivo', props.ordine.id), payload, {
        onFinish: () => (inviando.value = false),
    });
}
</script>

<template>
    <Head :title="`Chiusura massiva ${ordine.numero}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Chiusura massiva — {{ ordine.numero }}
                </h2>
                <Link :href="route('produzione.index')" class="text-sm text-indigo-600 hover:underline">← Ordini</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div v-if="flashError" class="rounded-lg bg-red-100 px-4 py-3 text-red-800">{{ flashError }}</div>

                <div class="rounded-lg bg-white p-4 text-sm text-gray-600 shadow">
                    <div class="font-semibold text-gray-800">{{ ordine.articolo }} · {{ ordine.quantita }} {{ ordine.udm }}</div>
                    <p class="mt-1">
                        Fasi ordinate bottom-up (prima i semilavorati, poi i prodotti). Inserisci i lotti (materie prime
                        e semilavorati, propagati automaticamente) oppure indica un <strong>lotto esistente</strong> per
                        prelevare un semilavorato da stock. Alla conferma vengono chiuse tutte le fasi in blocco,
                        rispettando precedenze e validazioni (giacenza, obbligo lotto, somme).
                    </p>
                </div>

                <div class="flex items-center justify-end gap-3 text-sm">
                    <button type="button" class="text-indigo-600 hover:underline" @click="espandiTutte(true)">Espandi tutte</button>
                    <span class="text-gray-300">·</span>
                    <button type="button" class="text-indigo-600 hover:underline" @click="espandiTutte(false)">Comprimi tutte</button>
                </div>

                <div
                    v-for="f in fasi"
                    :key="f.id"
                    class="overflow-hidden rounded-lg bg-white shadow"
                    :class="{ 'opacity-70': f.gia_chiusa }"
                >
                    <!-- Intestazione cliccabile: apre/chiude la card -->
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-5 py-3 text-left hover:bg-gray-50"
                        @click="toggleFase(f.id)"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Liv. {{ f.livello }}</span>
                                <span class="font-bold text-gray-800">{{ f.articolo }}</span>
                                <span v-if="f.condiviso" class="rounded bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">CONDIVISO</span>
                                <span v-if="f.gia_chiusa" class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                    {{ f.completata_da_stock ? 'Chiusa (stock)' : 'Chiusa' }}
                                </span>
                                <span v-else-if="faseCompleta(f)" class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">✓ pronta</span>
                                <span v-else class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">da compilare</span>
                            </div>
                            <div class="truncate text-sm text-gray-500">{{ f.descrizione }}</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="whitespace-nowrap text-sm font-semibold text-gray-700">{{ f.quantita }} {{ f.udm }}</span>
                            <svg
                                class="h-5 w-5 text-gray-400 transition-transform"
                                :class="{ 'rotate-180': espansa[f.id] }"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </button>

                    <!-- Corpo collassabile -->
                    <div v-if="espansa[f.id]" class="border-t border-gray-100 px-5 pb-5 pt-3">
                        <div class="mb-2 text-xs text-gray-400">Reparti: {{ f.reparti.join(' → ') || 'n/d' }}</div>

                        <div v-if="f.gia_chiusa" class="text-sm text-gray-500">
                            Fase già chiusa<span v-if="f.lotto_uscita"> — lotto {{ f.lotto_uscita }}</span>.
                        </div>

                        <div v-else class="pt-3">
                        <!-- Scelta modalità -->
                        <div v-if="f.permetti_da_stock" class="mb-3 flex flex-wrap gap-4 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input v-model="stato[f.id].modalita" type="radio" value="produzione" /> Produci in quest'ordine
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="stato[f.id].modalita" type="radio" value="stock" /> Preleva da stock (lotto esistente)
                            </label>
                        </div>

                        <!-- Modalità STOCK -->
                        <div v-if="stato[f.id].modalita === 'stock'" class="rounded-lg bg-indigo-50 p-3">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Lotto esistente del semilavorato</label>
                            <input
                                v-model="stato[f.id].lotto_stock"
                                type="text"
                                placeholder="Lotto già a sistema"
                                class="w-72 rounded-lg border-gray-300 text-sm"
                                @input="propagaLotti"
                            />
                            <!-- Lotti a giacenza su tutti i magazzini (change #2), raggruppati per magazzino. -->
                            <div v-if="f.lotti_stock && f.lotti_stock.length" class="mt-2 space-y-2">
                                <div v-for="grp in raggruppaStock(f.lotti_stock)" :key="grp.magazzino" class="overflow-hidden rounded-lg border border-indigo-100 bg-white">
                                    <div class="flex items-center justify-between bg-indigo-100/60 px-3 py-1.5">
                                        <span class="rounded bg-indigo-600 px-2 py-0.5 text-xs font-bold text-white">Mag. {{ grp.magazzino }}</span>
                                        <span class="text-xs text-gray-500">{{ grp.lotti.length }} lotti · {{ grp.totale.toFixed(3) }}</span>
                                    </div>
                                    <div class="divide-y divide-gray-100">
                                        <button
                                            v-for="l in grp.lotti"
                                            :key="l.lotto"
                                            type="button"
                                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition"
                                            :class="stato[f.id].lotto_stock === l.lotto ? 'bg-indigo-600 text-white' : 'hover:bg-indigo-50'"
                                            @click="stato[f.id].lotto_stock = l.lotto; propagaLotti()"
                                        >
                                            <span class="font-mono">{{ l.lotto }}</span>
                                            <span class="font-semibold tabular-nums">{{ Number(l.quantita).toFixed(3) }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-1 text-xs text-gray-400">Nessun lotto a giacenza nei magazzini per questo articolo.</p>
                            <p class="mt-1 text-xs text-gray-500">La fase verrà chiusa senza consumare i componenti.</p>
                        </div>

                        <!-- Modalità PRODUZIONE -->
                        <div v-else>
                            <div v-if="f.materiali.length" class="mb-3 space-y-2">
                                <div
                                    v-for="m in f.materiali"
                                    :key="m.id"
                                    class="rounded-lg border border-gray-100 bg-gray-50 p-3"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-sm">
                                            <span class="font-semibold text-gray-800">{{ m.articolo }}</span>
                                            <span v-if="m.semilavorato" class="ml-1 text-xs text-indigo-600">(semilavorato)</span>
                                            <span class="ml-1 text-gray-500">— {{ m.quantita_pianificata }} {{ m.udm }}</span>
                                            <span v-if="m.giacenza_mag06 !== null && m.giacenza_mag06 !== undefined" class="ml-2 text-xs text-gray-400">
                                                giac.06: {{ m.giacenza_mag06 }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Materiale NON a lotto: sola quantità -->
                                    <div v-if="!m.gestione_lotto" class="mt-2 flex items-center gap-2">
                                        <input
                                            v-model="stato[f.id].materiali[m.id].quantita"
                                            type="number"
                                            step="0.000001"
                                            min="0"
                                            class="w-32 rounded-lg border-gray-300 text-right text-sm"
                                        />
                                        <span class="text-sm text-gray-500">{{ m.udm }}</span>
                                    </div>

                                    <!-- Materiale a lotto (materia prima FIFO o semilavorato propagato) -->
                                    <div v-else class="mt-2">
                                        <p v-if="m.semilavorato" class="mb-1 text-xs text-indigo-600">
                                            Lotto propagato dalla fase produttrice — modificabile.
                                        </p>
                                        <div
                                            v-for="(riga, i) in stato[f.id].materiali[m.id].lotti"
                                            :key="i"
                                            class="mb-1 flex items-center gap-2"
                                        >
                                            <input v-model="riga.lotto" type="text" placeholder="Lotto" class="flex-1 rounded-lg border-gray-300 text-sm" @input="lottoModificatoAMano(f.id, m)" />
                                            <input v-model="riga.quantita" type="number" step="0.000001" min="0" class="w-28 rounded-lg border-gray-300 text-right text-sm" @input="qtaModificataAMano(f.id, m)" />
                                            <button type="button" class="rounded bg-gray-200 px-2 py-1 text-xs" @click="rimuoviLotto(f.id, m.id, i)">✕</button>
                                        </div>
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <button type="button" class="rounded bg-gray-200 px-2 py-1 font-semibold" @click="aggiungiLotto(f.id, m.id)">+ Lotto</button>
                                            <span>Totale: {{ sommaLotti(f.id, m.id).toFixed(3) }} {{ m.udm }}</span>
                                        </div>

                                        <!-- Lotti disponibili sul mag.06: clic per usarli. -->
                                        <div v-if="m.lotti_disponibili && m.lotti_disponibili.length" class="mt-2">
                                            <p class="mb-1 text-xs text-gray-500">Lotti in mag.06 (clic per usarli):</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    v-for="l in m.lotti_disponibili"
                                                    :key="l.lotto"
                                                    type="button"
                                                    class="rounded border border-gray-300 bg-white px-2 py-1 text-xs hover:bg-gray-100"
                                                    @click="scegliLotto(f.id, m, l.lotto)"
                                                >
                                                    {{ l.lotto }} · {{ Number(l.quantita).toFixed(3) }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Lotto prodotto in uscita -->
                            <div class="flex flex-wrap items-end gap-3">
                                <div v-if="f.richiede_lotto_uscita">
                                    <label class="mb-1 block text-xs font-medium text-gray-700">Lotto prodotto *</label>
                                    <input
                                        v-model="stato[f.id].lotto_prodotto"
                                        type="text"
                                        placeholder="Lotto in uscita"
                                        class="w-60 rounded-lg border-gray-300 text-sm"
                                        @input="propagaLotti"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-700">Quantità prodotta</label>
                                    <input
                                        v-model="stato[f.id].quantita_prodotta"
                                        type="number"
                                        step="0.000001"
                                        min="0"
                                        class="w-40 rounded-lg border-gray-300 text-right text-sm"
                                        @input="propagaLotti"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>

                <div class="sticky bottom-0 flex items-center justify-between rounded-lg bg-white p-4 shadow-lg">
                    <span class="text-sm text-gray-500">{{ daChiudere.length }} fasi da chiudere</span>
                    <button
                        type="button"
                        class="rounded-lg bg-emerald-600 px-6 py-3 font-semibold text-white hover:bg-emerald-500 disabled:opacity-40"
                        :disabled="inviando || daChiudere.length === 0"
                        @click="invia"
                    >
                        {{ inviando ? 'Chiusura in corso…' : 'Chiudi tutte le fasi' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
