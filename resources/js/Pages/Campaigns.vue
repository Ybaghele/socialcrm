<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    campaigns: { type: Array, required: true },
    stats: { type: Object, required: true },
    funnels: { type: Array, required: true },
    // Approved templates + opted-in audience size for the WhatsApp broadcast modal.
    broadcast: {
        type: Object,
        required: false,
        default: () => ({ templates: [], accounts: [], optedInCount: 0, pendingTemplates: 0 }),
    },
});
const page = usePage();
const toast = inject('toast', () => {});
const workspaceSlug = computed(() => page.props.workspace?.slug);

const showModal = ref(false);

const form = useForm({
    name: '',
    funnel: 'instagram_comment',
    message: '',
    keywords: '',
    template_id: null,
    template_variables: [],
    channel_account_id: null,
});

// Funnel types whose trigger is a keyword — the modal only asks for keywords then.
const isKeywordFunnel = computed(() => form.funnel === 'instagram_comment');
// WhatsApp broadcast: template-driven, needs an approved template + audience.
const isBroadcast = computed(() => form.funnel === 'whatsapp_broadcast');

const approvedTemplates = computed(() => props.broadcast.templates ?? []);
const selectedTemplate = computed(
    () => approvedTemplates.value.find((template) => template.id === form.template_id) ?? null,
);

const sendingAccount = computed(() => {
    const template = selectedTemplate.value;
    if (!template) return null;

    return (props.broadcast.accounts ?? []).find((account) => account.id === template.channelAccountId) ?? null;
});

// When the operator picks a template, size the variable inputs to its {{n}} count.
watch(selectedTemplate, (template) => {
    if (!template) {
        form.template_variables = [];

        return;
    }

    const current = Array.isArray(form.template_variables) ? form.template_variables : [];
    form.template_variables = Array.from({ length: template.variables }, (_, index) => current[index] ?? '');
    form.channel_account_id = template.channelAccountId;
});

// Live preview: show merge tags where a value has been typed.
const templatePreview = computed(() => {
    const template = selectedTemplate.value;
    if (!template) return '';

    return template.body.replace(/\{\{(\d+)\}\}/g, (match, n) => {
        const value = form.template_variables[Number(n) - 1];

        return value && String(value).trim() ? value : `{{${n}}}`;
    });
});

const mergeTags = ['{{first_name}}', '{{name}}', '{{phone}}', '{{city}}'];

// "{{1}}" for a zero-based variable index — the label on each input.
function token(index) {
    return `{{${index + 1}}}`;
}

function openModal() {
    form.clearErrors();
    form.reset();
    form.template_variables = [];
    showModal.value = true;
}

function insertTag(index, tag) {
    const values = [...form.template_variables];
    values[index] = (values[index] ?? '') + tag;
    form.template_variables = values;
}

function submit() {
    form.post(route('campaigns.store', { workspace: workspaceSlug.value }), {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    });
}

const channelMeta = {
    whatsapp: { icon: 'fa-brands fa-whatsapp', tile: 'bg-emerald-100 text-emerald-700' },
    instagram: { icon: 'fa-brands fa-instagram', tile: 'bg-rose-100 text-rose-700' },
    messenger: { icon: 'fa-brands fa-facebook-messenger', tile: 'bg-blue-100 text-blue-700' },
};

const meta = (channel) => channelMeta[channel] ?? { icon: 'fa-solid fa-bullhorn', tile: 'bg-slate-100 text-slate-600' };

const statusMeta = {
    active: { label: 'Live', class: 'bg-emerald-100 text-emerald-800' },
    scheduled: { label: 'Scheduled', class: 'bg-amber-100 text-amber-800' },
    paused: { label: 'Paused', class: 'bg-slate-200 text-slate-700' },
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-500' },
    completed: { label: 'Completed', class: 'bg-indigo-100 text-indigo-800' },
    failed: { label: 'Failed', class: 'bg-rose-100 text-rose-800' },
};

const status = (value) => statusMeta[value] ?? { label: value, class: 'bg-slate-100 text-slate-600' };

function setStatus(campaign, next) {
    router.patch(
        route('campaigns.update', { workspace: workspaceSlug.value, campaign: campaign.id }),
        { status: next },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Campaigns &amp; Inbound Growth Triggers</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Run WhatsApp pre-approved broadcasts and automatic Instagram comment-to-DM funnels.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                        @click="openModal"
                    >
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Create New Campaign</span>
                    </button>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Broadcasts Sent</span>
                            <i class="fa-solid fa-paper-plane text-sm text-brand-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ stats.broadcastsSent.toLocaleString() }}</div>
                        <div class="mt-1 text-[11px] font-semibold text-slate-400">
                            {{ stats.activeCount }} live automation{{ stats.activeCount === 1 ? '' : 's' }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Avg. DM Reply Rate</span>
                            <i class="fa-solid fa-comments text-sm text-emerald-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ stats.replyRate }}%</div>
                        <div class="mt-1 text-[11px] text-slate-400">Industry benchmark: 15–20%</div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Captured Leads</span>
                            <i class="fa-solid fa-user-check text-sm text-indigo-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ stats.capturedLeads.toLocaleString() }}</div>
                        <div class="mt-1 text-[11px] font-semibold text-emerald-600">
                            ₹{{ stats.costPerAcquisition.toFixed(2) }} cost per acquisition
                        </div>
                    </div>
                </div>

                <!-- Campaign list -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-800">Active Automations &amp; Sequences</h3>
                        <span class="text-xs text-slate-400">All triggers running via Laravel Queues</span>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="campaign in campaigns"
                            :key="campaign.id"
                            class="flex flex-col justify-between gap-4 p-5 transition hover:bg-slate-50/60 md:flex-row md:items-center"
                        >
                            <div class="flex items-start gap-3.5">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="meta(campaign.channel).tile">
                                    <i :class="meta(campaign.channel).icon" class="text-lg"></i>
                                </div>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-sm font-bold text-slate-900">{{ campaign.name }}</h4>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                            :class="status(campaign.status).class"
                                        >
                                            {{ status(campaign.status).label }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ campaign.description }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-medium text-slate-400">
                                        <span>Trigger: <strong class="text-slate-700">{{ campaign.trigger }}</strong></span>
                                        <span>•</span>
                                        <span>Target: <strong class="text-slate-700">{{ campaign.target }}</strong></span>
                                        <template v-if="campaign.template">
                                            <span>•</span>
                                            <span>
                                                Template:
                                                <strong class="font-mono text-slate-700">{{ campaign.template }}</strong>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-6 border-t border-slate-100 pt-2 text-xs md:border-t-0 md:pt-0">
                                <div v-if="campaign.enrolled">
                                    <div class="text-[10px] font-bold uppercase text-slate-400">Enrolled</div>
                                    <div class="text-sm font-bold text-slate-800">{{ campaign.enrolled.toLocaleString() }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-bold uppercase text-slate-400">Delivered</div>
                                    <div class="text-sm font-bold text-slate-800">{{ campaign.delivered.toLocaleString() }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-bold uppercase text-slate-400">Responses</div>
                                    <div class="text-sm font-bold text-slate-800">
                                        {{ campaign.replied.toLocaleString() }}
                                        <span class="font-normal text-emerald-600">({{ campaign.replyRate }})</span>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-bold uppercase text-slate-400">Leads Won</div>
                                    <div class="text-sm font-bold text-brand-600">{{ campaign.leadsWon.toLocaleString() }}</div>
                                </div>

                                <button
                                    v-if="campaign.status === 'active'"
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100"
                                    title="Pause this campaign"
                                    @click="setStatus(campaign, 'paused')"
                                >
                                    <i class="fa-solid fa-pause"></i>
                                </button>
                                <button
                                    v-else-if="campaign.status === 'paused'"
                                    type="button"
                                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100"
                                    title="Resume this campaign"
                                    @click="setStatus(campaign, 'active')"
                                >
                                    <i class="fa-solid fa-play"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Empty state -->
                        <div v-if="!campaigns.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-bullhorn text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No campaigns yet</p>
                            <p class="text-xs text-slate-400">Create your first broadcast or comment-to-DM funnel to start capturing leads.</p>
                            <button
                                type="button"
                                class="mt-2 rounded-lg bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700"
                                @click="openModal"
                            >
                                Create New Campaign
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create campaign modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-bullhorn text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">Create Social Campaign Automation</h3>
                    </div>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="showModal = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form class="space-y-3 text-xs" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Campaign Title</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g., Diwali Flash Sale DM Funnel"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Channel &amp; Funnel Type</label>
                        <select v-model="form.funnel" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option value="instagram_comment">Instagram: Auto DM on Post Comment keyword</option>
                            <option value="whatsapp_broadcast">WhatsApp Cloud: Pre-approved Template Broadcast</option>
                            <option value="messenger_lead">Facebook Messenger: Ad Click-to-Message Welcome</option>
                        </select>
                        <p v-if="form.errors.funnel" class="mt-1 text-[11px] text-rose-600">{{ form.errors.funnel }}</p>
                    </div>

                    <div v-if="isKeywordFunnel">
                        <label class="mb-1 block font-semibold text-slate-700">Trigger Keywords (comma-separated)</label>
                        <input
                            v-model="form.keywords"
                            type="text"
                            placeholder="PRICE, LINK, BUY, CODE"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <p v-if="form.errors.keywords" class="mt-1 text-[11px] text-rose-600">{{ form.errors.keywords }}</p>
                    </div>

                    <!-- WhatsApp broadcast: pick an approved template + audience -->
                    <template v-if="isBroadcast">
                        <div v-if="!approvedTemplates.length" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[11px] text-amber-800">
                            <p class="flex items-center gap-1.5 font-semibold">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                No approved template yet
                            </p>
                            <p class="mt-1 leading-relaxed">
                                <template v-if="broadcast.pendingTemplates">
                                    You have {{ broadcast.pendingTemplates }} template(s) still in review at Meta. A broadcast can only
                                    use an approved one.
                                </template>
                                <template v-else>
                                    Create a template in Meta Business Manager, then sync it here.
                                </template>
                                <Link :href="route('templates.index', { workspace: workspaceSlug })" class="font-semibold underline">
                                    Open Templates
                                </Link>
                            </p>
                        </div>

                        <template v-else>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Approved Template</label>
                                <select
                                    v-model="form.template_id"
                                    class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"
                                >
                                    <option :value="null" disabled>Choose an approved template…</option>
                                    <option v-for="template in approvedTemplates" :key="template.id" :value="template.id">
                                        {{ template.name }} · {{ template.language }} · {{ template.category }}
                                    </option>
                                </select>
                                <p v-if="form.errors.template_id" class="mt-1 text-[11px] text-rose-600">{{ form.errors.template_id }}</p>
                                <p v-if="sendingAccount" class="mt-1 text-[11px] text-slate-400">
                                    Sends from <strong class="text-slate-600">{{ sendingAccount.name }}</strong>
                                </p>
                            </div>

                            <div v-if="selectedTemplate" class="space-y-2">
                                <div v-for="(value, index) in form.template_variables" :key="index">
                                    <label class="mb-1 block font-semibold text-slate-700">
                                        Variable {{ Number(index) + 1 }}
                                        <span class="font-normal text-slate-400">({{ token(Number(index)) }})</span>
                                    </label>
                                    <input
                                        v-model="form.template_variables[index]"
                                        type="text"
                                        :placeholder="index === 0 ? 'e.g., {{first_name}} or a fixed value' : 'Value or merge tag'"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                                    />
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <button
                                            v-for="tag in mergeTags"
                                            :key="tag"
                                            type="button"
                                            class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 transition hover:bg-slate-100"
                                            @click="insertTag(Number(index), tag)"
                                        >
                                            {{ tag }}
                                        </button>
                                    </div>
                                </div>
                                <p v-if="form.errors.channel_account_id" class="text-[11px] text-rose-600">
                                    {{ form.errors.channel_account_id }}
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Preview</label>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-600">
                                    <p class="whitespace-pre-line">{{ templatePreview || 'Select a template to preview it.' }}</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
                                <i class="fa-solid fa-users mr-1 text-brand-600"></i>
                                <strong class="text-slate-700">Audience:</strong> all opted-in subscribers
                                ({{ broadcast.optedInCount.toLocaleString() }} contact{{ broadcast.optedInCount === 1 ? '' : 's' }}).
                                Each send is charged to your wallet.
                            </div>
                        </template>
                    </template>

                    <!-- Comment-to-DM / ad-click funnels: free-form message -->
                    <div v-else>
                        <label class="mb-1 block font-semibold text-slate-700">Message Content / Template</label>
                        <textarea
                            v-model="form.message"
                            rows="3"
                            placeholder="Hey {{name}}, thanks for showing interest! Here is your exclusive 20% link: …"
                            class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        ></textarea>
                        <p v-if="form.errors.message" class="mt-1 text-[11px] text-rose-600">{{ form.errors.message }}</p>
                    </div>

                    <div v-if="!isBroadcast" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
                        <i class="fa-solid fa-circle-check mr-1 text-emerald-600"></i>
                        Compliance Guard: automatically respects Meta's official 24-hour service window guidelines.
                    </div>
                    <div v-else class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
                        <i class="fa-solid fa-circle-check mr-1 text-emerald-600"></i>
                        Only Meta-approved templates can be broadcast, and only opted-in contacts are enrolled.
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                            @click="showModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                            :disabled="form.processing || (isBroadcast && (!form.template_id || !approvedTemplates.length))"
                        >
                            <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-rocket'"></i>
                            Launch Campaign
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </OmniLayout>
</template>
