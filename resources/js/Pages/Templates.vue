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
const busyId = ref(null);
const showCreator = ref(false);

const canCreate = computed(() => hasWhatsapp.value);
const canManage = computed(() => ['owner', 'admin'].includes(workspaceRole.value));

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

function toggleActive(template) {
    if (busyId.value) {
        return;
    }

    busyId.value = template.id;

    router.patch(
        route('templates.update', { workspace: workspaceSlug.value, template: template.id }),
        { is_active: !template.active },
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

function removeTemplate(template) {
    if (busyId.value || !window.confirm(`Delete “${template.name}” from Meta and this workspace? This cannot be undone.`)) {
        return;
    }

    busyId.value = template.id;

    router.delete(route('templates.destroy', { workspace: workspaceSlug.value, template: template.id }), {
        preserveScroll: true,
        onFinish: () => {
            busyId.value = null;
        },
    });
}

const buttonMeta = {
    QUICK_REPLY: { label: 'Reply', icon: 'fa-reply' },
    URL: { label: 'Link', icon: 'fa-arrow-up-right-from-square' },
    PHONE_NUMBER: { label: 'Call', icon: 'fa-phone' },
};

const buttonInfo = (value) => buttonMeta[value] ?? { label: value, icon: 'fa-hand-pointer' };

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
                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Paused</div>
                        <div class="mt-1 text-2xl font-black text-slate-500">{{ counts.disabled }}</div>
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
                                    <span v-if="!template.active" class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600" title="Paused for this workspace">
                                        <i class="fa-solid fa-pause mr-1"></i>Paused
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">
                                        {{ template.category }}
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500">
                                        {{ template.language }}
                                    </span>
                                    <span v-if="template.requiresImage" class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                        <i class="fa-solid fa-image mr-1"></i>Image header
                                    </span>
                                </div>

                                <div class="mt-2 max-w-2xl overflow-hidden rounded-lg bg-[#efeae2] text-xs text-slate-700">
                                    <img
                                        v-if="template.headerImageUrl"
                                        :src="template.headerImageUrl"
                                        alt="Template sample"
                                        class="max-h-40 w-full object-cover"
                                    />
                                    <div class="px-3 py-2">
                                        <p v-if="template.requiresImage && !template.headerImageUrl" class="mb-1 text-[11px] font-semibold text-sky-700">
                                            This template needs an image. Campaigns can upload one.
                                        </p>
                                        <p v-if="template.header" class="mb-1 whitespace-pre-line font-bold text-slate-800">{{ template.header }}</p>
                                        <p class="whitespace-pre-line leading-relaxed">{{ template.body || '—' }}</p>
                                        <p v-if="template.footer" class="mt-1 whitespace-pre-line text-[11px] text-slate-500">{{ template.footer }}</p>
                                    </div>
                                </div>

                                <div v-if="template.buttons.length" class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="(templateButton, index) in template.buttons"
                                        :key="`${template.id}-${index}`"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-emerald-100 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700"
                                        :title="buttonInfo(templateButton.type).label"
                                    >
                                        <i class="fa-solid" :class="buttonInfo(templateButton.type).icon"></i>{{ templateButton.text }}
                                    </span>
                                </div>

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

                            <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:flex-col sm:items-end">
                                <span
                                    v-if="template.status !== 'approved' || !template.active"
                                    class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-400"
                                    title="Only active, approved templates can be broadcast"
                                >
                                    Not broadcastable
                                </span>
                                <div v-if="canManage" class="flex flex-wrap items-center justify-end gap-2">
                                    <button
                                        v-if="template.status === 'approved'"
                                        type="button"
                                        class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                                        :disabled="busyId === template.id"
                                        @click="toggleActive(template)"
                                    >
                                        <i class="fa-solid mr-1" :class="busyId === template.id ? 'fa-circle-notch fa-spin' : template.active ? 'fa-pause' : 'fa-play'"></i>
                                        {{ template.active ? 'Pause' : 'Resume' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-rose-200 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-50"
                                        :disabled="busyId === template.id"
                                        title="Delete from Meta and this workspace"
                                        @click="removeTemplate(template)"
                                    >
                                        <i class="fa-solid" :class="busyId === template.id ? 'fa-circle-notch fa-spin' : 'fa-trash'"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div v-if="!templates.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-file-lines text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No templates yet</p>
                            <p class="max-w-md text-xs text-slate-400">
                                Create your first template with the button above, or use <strong>Sync from Meta</strong> to
                                import templates you already built in WhatsApp Manager.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add this right before </OmniLayout> -->
        <TemplateCreatorModal 
            :show="showCreator" 
            :whatsapp-accounts="whatsappAccounts"
            @close="showCreator = false" 
        />
    </OmniLayout>
</template>
