<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { inSospeso, online } from '@/offline/sync';

const page = usePage();
const nome = computed(() => page.props.auth?.user?.name ?? 'Operatore');
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
</script>

<template>
    <div class="min-h-screen bg-slate-900 text-slate-100">
        <header class="flex items-center justify-between border-b border-slate-700 bg-slate-800 px-4 py-3">
            <Link :href="route('operatore.coda')" class="text-lg font-bold tracking-wide">
                MES · Reparto
            </Link>
            <div class="flex items-center gap-3">
                <!-- Indicatore stato connessione + azioni in coda da sincronizzare (§8). -->
                <span
                    v-if="online"
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="inSospeso > 0 ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-600/20 text-emerald-300'"
                >
                    ● Online<span v-if="inSospeso > 0"> · {{ inSospeso }} in sync</span>
                </span>
                <span v-else class="rounded-full bg-red-600/30 px-3 py-1 text-sm font-medium text-red-200">
                    ● Offline<span v-if="inSospeso > 0"> · {{ inSospeso }} in coda</span>
                </span>
                <span class="text-sm text-slate-300">{{ nome }}</span>
                <Link
                    :href="route('operatore.logout')"
                    method="post"
                    as="button"
                    class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-semibold hover:bg-slate-600"
                >
                    Esci
                </Link>
            </div>
        </header>

        <div v-if="flashSuccess" class="mx-4 mt-3 rounded-lg bg-emerald-600 px-4 py-3 text-lg font-semibold">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="mx-4 mt-3 rounded-lg bg-red-600 px-4 py-3 text-lg font-semibold">
            {{ flashError }}
        </div>

        <main class="p-4">
            <slot />
        </main>
    </div>
</template>
