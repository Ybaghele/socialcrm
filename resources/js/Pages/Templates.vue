<script setup>
import { computed, inject, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';
import TemplateCreatorModal from '@/Components/Templates/TemplateCreatorModal.vue';

const props = defineProps({
    templates: { type: Array, required: true },
    whatsappAccounts: { type: Array, required: true },
    counts: { type: Object, required: true },
});

const page = usePage();
const toast = inject('toast', () => {});
const workspaceSlug = computed(() => page.props.workspace?.slug);
const workspaceRole = computed(() => page.props.workspaceRole);

const syncing = ref(false);
const showCreator = ref(false);

const canCreate = computed(() => hasWhatsapp.value);

function sync() {
    if (syncing.value) {
        return;
    }

    syncing.value = true;

    router.post(
        route('templates.sync', { workspace: workspaceSlug.value }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
}

function openCreator() {
    if (!canCreate.value) {
        return;
    }
    showCreator.value = true;
}

const statusMeta = {
    approved: { label: 'Approved', class: 'bg-emerald-100 text-emerald-800', icon: 'fa-circle-check' },
    pending: { label: 'In review', class: 'bg-amber-100 text-amber-800', icon: 'fa-clock' },
    in_review: { label: 'In review', class: 'bg-amber-100 text-amber-800', icon: 'fa-clock' },
    paused: { label: 'Paused', class: 'bg-slate-200 text-slate-700', icon: 'fa-pause' },
    rejected: { label: 'Rejected', class: 'bg-rose-100 text-rose-800', icon: 'fa-circle-xmark' },
};

const status = (value) => statusMeta[value] ?? { label: value, class: 'bg-slate-100 text-slate-600', icon: 'fa-circle-info' };

const hasWhatsapp = computed(() => props.whatsappAccounts.length > 0);
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-5xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">WhatsApp Message Templates</h2>
                        <p class="mt-1 max-w-2xl text-xs text-slate-500">
                            Create templates here and submit them to Meta for approval, or sync approved templates from Meta.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="syncing || !hasWhatsapp"
                            @click="sync"
                        >
                            <i class="fa-solid text-xs" :class="syncing ? 'fa-circle-notch fa-spin' : 'fa-rotate'"></i>
                            <span>{{ syncing ? 'Syncing…' : 'Sync from Meta' }}</span>
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="!canCreate"
                            @click="openCreator"
                        >
                            <i class="fa-solid fa-plus text-xs"></i>
                            <span>Create template</span>
                        </button>
                    </div>
                </div>

                <!-- Not connected hint -->
                <div
                    v-if="!hasWhatsapp"
                    class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800"
                >
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <div>
                        <p class="font-semibold">No WhatsApp number connected yet.</p>
                        <p class="mt-0.5">
                            Connect one on the
                            <Link :href="route('channels.index', { workspace: workspaceSlug })" class="font-semibold underline">
                                Channels
                            </Link>
                            screen, then come back and sync your templates.
                        </p>
                    </div>
                </div>

                <!-- Counts -->
                <div v-else class="grid grid-cols-3 gap-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Approved</div>
                        <div class="mt-1 text-2xl font-black text-emerald-600">{{ counts.approved }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">In review</div>
                        <div class="mt-1 text-2xl font-black text-amber-500">{{ counts.pending }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Rejected</div>
                        <div class="mt-1 text-2xl font-black text-rose-500">{{ counts.rejected }}</div>
                    </div>
                </div>

                <!-- Template list -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-800">Your Templates</h3>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <div v-for="template in templates" :key="template.id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="font-mono text-sm font-bold text-slate-900">{{ template.name }}</h4>
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="status(template.status).class">
                                        <i class="fa-solid mr-1" :class="status(template.status).icon"></i>{{ status(template.status).label }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">
                                        {{ template.category }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">
                                        {{ template.language }}
                                    </span>
                                </div>
                                <p class="mt-2 whitespace-pre-line text-xs leading-relaxed text-slate-600">{{ template.body || '—' }}</p>
                                <p v-if="template.rejectionReason" class="mt-1 text-[11px] font-medium text-rose-600">
                                    Reason: {{ template.rejectionReason }}
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-400">
                                    <span v-if="template.channelAccount">
                                        <i class="fa-brands fa-whatsapp mr-1 text-emerald-500"></i>{{ template.channelAccount }}
                                    </span>
                                    <span v-if="template.variables">
                                        {{ template.variables }} variable{{ template.variables === 1 ? '' : 's' }}
                                    </span>
                                </div>
                            </div>

                            <span
                                v-if="template.status !== 'approved'"
                                class="shrink-0 self-start rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-400"
                                title="Only approved templates can be broadcast"
                            >
                                Not broadcastable
                            </span>
                        </div>

                        <!-- Empty state -->
                        <div v-if="!templates.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-file-lines text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No templates yet</p>
                            <p class="max-w-md text-xs text-slate-400">
                                Create a template in Meta Business Manager (WhatsApp Manager &rarr; Message Templates), then
                                hit <strong>Sync from Meta</strong> — it will appear here once Meta has reviewed it.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
