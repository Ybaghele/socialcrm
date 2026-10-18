<script setup>
import { computed, inject, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    accounts: { type: Array, required: true },
    channelOptions: { type: Array, required: true },
    webhook: { type: Object, required: true },
    // WhatsApp Embedded Signup — absent/empty while the Meta app is not approved.
    oauth: { type: Object, required: false, default: () => ({ enabled: false, connectUrl: null }) },
});

const page = usePage();
const toast = inject('toast', () => {});
const workspaceSlug = computed(() => page.props.workspace?.slug);

/* ------------------------------------------------------------------ *
 * Channel + status presentation
 * ------------------------------------------------------------------ */
const channelMeta = {
    whatsapp: { label: 'WhatsApp', icon: 'fa-brands fa-whatsapp', chip: 'text-emerald-700 bg-emerald-50 border-emerald-200', dot: 'bg-emerald-500' },
    instagram: { label: 'Instagram', icon: 'fa-brands fa-instagram', chip: 'text-rose-700 bg-rose-50 border-rose-200', dot: 'bg-rose-500' },
    messenger: { label: 'Messenger', icon: 'fa-brands fa-facebook-messenger', chip: 'text-blue-700 bg-blue-50 border-blue-200', dot: 'bg-blue-500' },
};

const meta = (channel) =>
    channelMeta[channel] ?? { label: channel, icon: 'fa-solid fa-comment', chip: 'text-slate-600 bg-slate-50 border-slate-200', dot: 'bg-slate-400' };

const statusMeta = {
    connected: { label: 'Connected', chip: 'text-emerald-700 bg-emerald-50 border-emerald-200', dot: 'bg-emerald-500' },
    disconnected: { label: 'Disconnected', chip: 'text-slate-600 bg-slate-100 border-slate-200', dot: 'bg-slate-400' },
    error: { label: 'Needs reconnect', chip: 'text-amber-700 bg-amber-50 border-amber-200', dot: 'bg-amber-500' },
};

const status = (value) => statusMeta[value] ?? statusMeta.disconnected;

/** What the "account id" field means, per channel. */
const placeholders = {
    whatsapp: {
        external_id: 'Phone number ID, e.g. 123456789012345',
        token: 'Permanent System User access token',
    },
    instagram: {
        external_id: 'Instagram business account ID, e.g. 17841400000000000',
        token: 'Page access token with instagram_manage_messages',
    },
    messenger: {
        external_id: 'Facebook Page ID, e.g. 102938475610293',
        token: 'Page access token with pages_messaging',
    },
};

const channelCounts = computed(() => {
    const counts = { whatsapp: 0, instagram: 0, messenger: 0 };

    props.accounts.forEach((account) => {
        counts[account.channel] = (counts[account.channel] ?? 0) + 1;
    });

    return counts;
});

const connectedCount = computed(() => props.accounts.filter((account) => account.status === 'connected').length);

/* ------------------------------------------------------------------ *
 * Connect / edit modal
 * ------------------------------------------------------------------ */
const showModal = ref(false);
const editing = ref(null); // the account being edited, or null when connecting
const revealToken = ref(false);

const form = useForm({
    channel: 'whatsapp',
    name: '',
    external_id: '',
    handle: '',
    business_account_id: '',
    access_token: '',
    token_expires_at: '',
});

const selectedChannel = computed(() => props.channelOptions.find((channel) => channel.value === form.channel));
const limitReached = computed(() => !editing.value && selectedChannel.value && !selectedChannel.value.canConnect);
const placeholder = computed(() => placeholders[form.channel] ?? placeholders.whatsapp);

function openConnect(channel = 'whatsapp') {
    editing.value = null;
    revealToken.value = false;
    form.clearErrors();
    form.reset();
    form.channel = channel;
    showModal.value = true;
}

function openEdit(account) {
    editing.value = account;
    revealToken.value = false;
    form.clearErrors();
    form.reset();
    form.channel = account.channel;
    form.name = account.name ?? '';
    form.external_id = account.externalId ?? '';
    form.handle = account.handle ?? '';
    form.business_account_id = account.businessAccountId ?? '';
    form.access_token = '';
    form.token_expires_at = account.tokenExpiresAt ? account.tokenExpiresAt.slice(0, 10) : '';
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editing.value = null;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            form.reset();
        },
    };

    if (editing.value) {
        form.patch(route('channels.update', { workspace: workspaceSlug.value, channelAccount: editing.value.id }), options);
    } else {
        form.post(route('channels.store', { workspace: workspaceSlug.value }), options);
    }
}

/* ------------------------------------------------------------------ *
 * Row actions
 * ------------------------------------------------------------------ */
const busyId = ref(null);

function toggleStatus(account) {
    busyId.value = account.id;

    router.patch(
        route('channels.update', { workspace: workspaceSlug.value, channelAccount: account.id }),
        { status: account.status === 'connected' ? 'disconnected' : 'connected' },
        { preserveScroll: true, onFinish: () => (busyId.value = null) },
    );
}

function testConnection(account) {
    busyId.value = account.id;

    router.post(
        route('channels.verify', { workspace: workspaceSlug.value, channelAccount: account.id }),
        {},
        { preserveScroll: true, onFinish: () => (busyId.value = null) },
    );
}

const confirming = ref(null);

function destroy(account) {
    router.delete(route('channels.destroy', { workspace: workspaceSlug.value, channelAccount: account.id }), {
        preserveScroll: true,
        onFinish: () => (confirming.value = null),
    });
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */
function relativeTime(iso) {
    if (!iso) {
        return 'never';
    }

    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60_000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;

    return new Date(iso).toLocaleDateString([], { day: '2-digit', month: 'short' });
}

function copy(value, label) {
    if (!value) {
        return;
    }

    navigator.clipboard
        ?.writeText(value)
        .then(() => toast(`${label} copied.`, 'success'))
        .catch(() => toast('Copy failed — select and copy manually.', 'error'));
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Connected Channels</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Link the WhatsApp, Instagram and Messenger assets that feed your unified inbox and campaigns.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                        @click="openConnect()"
                    >
                        <i class="fa-solid fa-plug-circle-plus text-xs"></i>
                        <span>Connect channel</span>
                    </button>
                </div>

                <!-- Self-service connect, once Meta has approved the app for it. -->
                <div
                    v-if="oauth.enabled && oauth.connectUrl"
                    class="flex flex-col gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p class="flex items-center gap-2 text-sm font-bold text-emerald-900">
                            <i class="fa-brands fa-whatsapp text-base"></i>
                            Connect WhatsApp automatically
                        </p>
                        <p class="mt-0.5 text-xs text-emerald-800">
                            Meta will walk you through creating or picking a WhatsApp Business
                            Account and phone number. Nothing to copy by hand.
                        </p>
                    </div>
                    <a
                        :href="oauth.connectUrl"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                    >
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                        <span>Connect with Meta</span>
                    </a>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Connected</p>
                        <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ connectedCount }}<span class="text-sm font-semibold text-slate-400"> / {{ accounts.length }}</span></p>
                    </div>
                    <div v-for="channel in channelOptions" :key="channel.value" class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-400">
                            <i :class="[meta(channel.value).icon, meta(channel.value).chip.split(' ')[0]]"></i>
                            {{ meta(channel.value).label }}
                        </p>
                        <p class="mt-1 text-2xl font-extrabold text-slate-900">
                            {{ channelCounts[channel.value] ?? 0 }}
                            <span class="text-sm font-semibold text-slate-400">/ {{ channel.max ?? '∞' }}</span>
                        </p>
                    </div>
                </div>

                <!-- Webhook configuration -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-100 p-4">
                        <i class="fa-solid fa-tower-broadcast text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">Meta webhook</h3>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-500">
                            one app · all workspaces
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-400">Callback URL</label>
                            <div class="flex items-center gap-2">
                                <input
                                    :value="webhook.callbackUrl"
                                    readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-600"
                                />
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-2 text-xs text-slate-600 transition hover:bg-slate-50"
                                    title="Copy callback URL"
                                    @click="copy(webhook.callbackUrl, 'Callback URL')"
                                >
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-400">Verify token</label>
                            <div v-if="webhook.verifyToken" class="flex items-center gap-2">
                                <input
                                    :value="revealToken ? webhook.verifyToken : '••••••••••••••••'"
                                    readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-600"
                                />
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-2 text-xs text-slate-600 transition hover:bg-slate-50"
                                    title="Reveal"
                                    @click="revealToken = !revealToken"
                                >
                                    <i class="fa-solid" :class="revealToken ? 'fa-eye-slash' : 'fa-eye'"></i>
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-2 text-xs text-slate-600 transition hover:bg-slate-50"
                                    title="Copy verify token"
                                    @click="copy(webhook.verifyToken, 'Verify token')"
                                >
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                            <div v-else class="rounded-lg border border-dashed border-slate-300 px-3 py-2 text-[11px] text-slate-500">
                                Platform-managed. Ask an administrator for the verify token when configuring Meta.
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <ol class="space-y-1.5 text-[11px] leading-relaxed text-slate-500">
                                <li>
                                    <span class="font-semibold text-slate-600">1.</span>
                                    In the Meta App dashboard → <span class="font-semibold text-slate-600">Webhooks</span>, paste the callback URL and verify token above, then subscribe to the
                                    <span class="font-mono text-slate-600">messages</span> field.
                                </li>
                                <li>
                                    <span class="font-semibold text-slate-600">2.</span>
                                    Connect each asset below using its ID and an access token.
                                    <span v-if="webhook.appSecretConfigured" class="text-emerald-600">App secret is configured, so signatures verify.</span>
                                    <span v-else class="text-rose-600">Set <span class="font-mono">META_APP_SECRET</span> or incoming payloads will be rejected.</span>
                                </li>
                                <li>
                                    <span class="font-semibold text-slate-600">3.</span>
                                    Graph API <span class="font-mono text-slate-600">{{ webhook.graphVersion }}</span> — use the same version when you copy ids and tokens.
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Accounts -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-900">Your accounts</h3>
                        <span class="text-xs text-slate-500"><strong class="text-slate-700">{{ accounts.length }}</strong> connected</span>
                    </div>

                    <div v-if="accounts.length" class="divide-y divide-slate-100">
                        <div v-for="account in accounts" :key="account.id" class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border" :class="meta(account.channel).chip">
                                    <i :class="meta(account.channel).icon"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="truncate text-sm font-bold text-slate-900">{{ account.name }}</span>
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold" :class="status(account.status).chip">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="status(account.status).dot"></span>
                                            {{ status(account.status).label }}
                                        </span>
                                        <span v-if="account.tokenExpired" class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">
                                            Token expired
                                        </span>
                                        <span v-else-if="!account.hasToken" class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">
                                            No token — inbound only
                                        </span>
                                        <span v-if="account.qualityRating" class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-500">
                                            Quality {{ account.qualityRating }}
                                        </span>
                                    </div>

                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
                                        <span class="font-mono text-slate-400">ID {{ account.externalId }}</span>
                                        <span v-if="account.handle" class="font-medium text-slate-500">{{ account.handle }}</span>
                                        <span v-if="account.businessAccountId" class="font-mono text-slate-400">WABA {{ account.businessAccountId }}</span>
                                    </div>

                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                                        <span><i class="fa-solid fa-comments mr-1"></i>{{ account.conversations }} conversations</span>
                                        <span><i class="fa-solid fa-bullhorn mr-1"></i>{{ account.campaigns }} campaigns</span>
                                        <span><i class="fa-solid fa-inbox mr-1"></i>last message {{ relativeTime(account.lastWebhookAt) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                <button
                                    v-if="account.hasToken && account.status !== 'disconnected'"
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                                    :disabled="busyId === account.id"
                                    @click="testConnection(account)"
                                >
                                    <i class="fa-solid mr-1" :class="busyId === account.id ? 'fa-circle-notch fa-spin' : 'fa-vial'"></i> Test
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50"
                                    @click="openEdit(account)"
                                >
                                    <i class="fa-solid fa-pen mr-1"></i> Edit
                                </button>
                                <!-- A dead token needs a new one, so send that case to the edit form
                                     rather than merely flipping the status flag back on. -->
                                <button
                                    v-if="account.status !== 'error'"
                                    type="button"
                                    class="rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold transition disabled:opacity-50"
                                    :class="account.status === 'connected' ? 'border-slate-200 text-slate-600 hover:bg-slate-50' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'"
                                    :disabled="busyId === account.id"
                                    @click="toggleStatus(account)"
                                >
                                    <i class="fa-solid mr-1" :class="account.status === 'connected' ? 'fa-power-off' : 'fa-plug'"></i>
                                    {{ account.status === 'connected' ? 'Disconnect' : 'Reconnect' }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100"
                                    @click="openEdit(account)"
                                >
                                    <i class="fa-solid fa-key mr-1"></i> Fix token
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-rose-200 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-50"
                                    @click="confirming = account"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                        <i class="fa-solid fa-plug-circle-exclamation text-3xl text-slate-300"></i>
                        <p class="text-sm font-semibold text-slate-600">No channels connected yet</p>
                        <p class="max-w-md text-xs text-slate-400">
                            Until you connect an account, inbound messages from Meta cannot be matched to this workspace. You can still explore with the
                            <span class="font-semibold text-slate-500">Simulate inbound</span> button in the header.
                        </p>
                        <button
                            type="button"
                            class="mt-1 flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700"
                            @click="openConnect()"
                        >
                            <i class="fa-solid fa-plug-circle-plus"></i> Connect your first channel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connect / edit modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="my-8 w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-plug-circle-plus text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">{{ editing ? 'Edit channel' : 'Connect a channel' }}</h3>
                    </div>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="closeModal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form class="space-y-3 text-xs" @submit.prevent="submit">
                    <!-- A nudge towards the automatic flow when it is available -->
                    <p
                        v-if="!editing && oauth.enabled && oauth.connectUrl"
                        class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800"
                    >
                        Prefer
                        <a :href="oauth.connectUrl" class="font-bold underline">Connect with Meta</a>
                        — it fills in every id and token for you. Use this form only
                        if you already have a phone number ID and a System User token.
                    </p>
                    <!-- Channel picker -->
                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Channel<span class="text-rose-500"> *</span></label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="channel in channelOptions"
                                :key="channel.value"
                                type="button"
                                class="flex flex-col items-center gap-1 rounded-lg border px-2 py-2.5 font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
                                :class="
                                    form.channel === channel.value
                                        ? 'border-brand-500 bg-brand-50 text-brand-700'
                                        : 'border-slate-200 text-slate-500 hover:border-slate-300'
                                "
                                :disabled="!!editing || (!channel.canConnect && channel.value !== form.channel)"
                                @click="form.channel = channel.value"
                            >
                                <i :class="meta(channel.value).icon" class="text-base"></i>
                                <span class="text-[11px]">{{ meta(channel.value).label }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.channel" class="mt-1 text-[11px] text-rose-600">{{ form.errors.channel }}</p>
                    </div>

                    <div v-if="limitReached" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[11px] text-amber-700">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <span>
                            Your plan allows {{ selectedChannel.max }} {{ meta(form.channel).label }} account(s) and you have used {{ selectedChannel.used }}.
                            <a :href="route('billing', { workspace: workspaceSlug })" class="font-semibold underline">Upgrade your plan</a> to add more.
                        </span>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Display name<span class="text-rose-500"> *</span></label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g., Support line"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Account ID<span class="text-rose-500"> *</span></label>
                        <input
                            v-model="form.external_id"
                            type="text"
                            :readonly="!!editing"
                            :placeholder="placeholder.external_id"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 read-only:bg-slate-50 read-only:text-slate-500"
                        />
                        <p class="mt-1 text-[11px] text-slate-400">
                            The id Meta sends in the webhook: <span class="font-mono">{{ form.channel === 'whatsapp' ? 'metadata.phone_number_id' : form.channel === 'instagram' ? 'entry.id (IG user id)' : 'entry.id (page id)' }}</span>.
                        </p>
                        <p v-if="form.errors.external_id" class="mt-1 text-[11px] text-rose-600">{{ form.errors.external_id }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Handle</label>
                            <input
                                v-model="form.handle"
                                type="text"
                                placeholder="+91… or @brand"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            />
                            <p v-if="form.errors.handle" class="mt-1 text-[11px] text-rose-600">{{ form.errors.handle }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Business account ID</label>
                            <input
                                v-model="form.business_account_id"
                                type="text"
                                placeholder="WABA / business id"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            />
                            <p v-if="form.errors.business_account_id" class="mt-1 text-[11px] text-rose-600">{{ form.errors.business_account_id }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Access token</label>
                        <textarea
                            v-model="form.access_token"
                            rows="2"
                            :placeholder="editing ? 'Leave blank to keep the current token' : placeholder.token"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-[11px] focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        ></textarea>
                        <p class="mt-1 text-[11px] text-slate-400">Stored encrypted and cleaned up on save. Paste it as a single line — a WhatsApp/System User token starts with <span class="font-mono">EAA</span>, with no quotes or "Bearer" prefix.</p>
                        <p v-if="form.errors.access_token" class="mt-1 text-[11px] text-rose-600">{{ form.errors.access_token }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Token expiry</label>
                        <input
                            v-model="form.token_expires_at"
                            type="date"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <p v-if="form.errors.token_expires_at" class="mt-1 text-[11px] text-rose-600">{{ form.errors.token_expires_at }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                            @click="closeModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                            :disabled="form.processing || limitReached"
                        >
                            <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-plug'"></i>
                            {{ editing ? 'Save changes' : 'Connect channel' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete confirmation -->
        <div v-if="confirming" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Remove {{ confirming.name }}?</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            This only works for accounts with no conversation history. If it has history, disconnect it instead — that keeps the data and stops new messages.
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" @click="confirming = null">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-rose-700"
                        @click="destroy(confirming)"
                    >
                        <i class="fa-solid fa-trash"></i> Remove
                    </button>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
