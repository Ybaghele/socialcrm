<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { route } from '@ziggy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    workspace: { type: Object, required: true },
    owner: { type: Object, default: null },
    plan: { type: Object, default: null },
    subscription: { type: Object, default: null },
    usage: { type: Object, required: true },
    wallet: { type: Object, required: true },
    transactions: { type: Array, required: true },
    payments: { type: Array, required: true },
    counts: { type: Object, required: true },
    members: { type: Array, required: true },
    events: { type: Array, required: true },
});

const showSuspend = ref(false);
const showExtend = ref(false);
const showLimits = ref(false);
const showCredit = ref(false);

const suspendForm = useForm({ reason: '' });
const extendForm = useForm({ days: 30 });
const limitsForm = useForm({
    monthly_active_contacts: props.workspace.limitsOverride?.monthly_active_contacts ?? '',
    max_agent_seats: props.workspace.limitsOverride?.max_agent_seats ?? '',
    max_instagram_accounts: props.workspace.limitsOverride?.max_instagram_accounts ?? '',
});
const creditForm = useForm({ amount: 1000, description: '' });

const rupee = (paise) =>
    '₹' + (Number(paise ?? 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 });

const number = (value) => Number(value ?? 0).toLocaleString('en-IN');

const dateTime = (iso) =>
    iso
        ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '—';

const isSuspended = computed(() => props.workspace.status === 'suspended');

const usageCards = computed(() => [
    { label: 'Active leads', used: props.usage.contactsUsed, limit: props.usage.contactsLimit, percent: props.usage.contactsPercent },
    { label: 'Agent seats', used: props.usage.seatsUsed, limit: props.usage.seatLimit, percent: props.usage.seatLimit ? Math.min(100, Math.round((props.usage.seatsUsed / props.usage.seatLimit) * 100)) : 0 },
    { label: 'Instagram accounts', used: props.usage.instagramUsed, limit: props.usage.instagramLimit, percent: props.usage.instagramLimit ? Math.min(100, Math.round((props.usage.instagramUsed / props.usage.instagramLimit) * 100)) : 0 },
]);

const countCards = computed(() => [
    { label: 'Contacts', value: props.counts.contacts, icon: 'fa-address-book' },
    { label: 'Opted in', value: props.counts.optedIn, icon: 'fa-circle-check' },
    { label: 'Conversations', value: props.counts.conversations, icon: 'fa-comments' },
    { label: 'Open', value: props.counts.openConversations, icon: 'fa-inbox' },
    { label: 'Messages', value: props.counts.messages, icon: 'fa-paper-plane' },
    { label: 'Campaigns', value: props.counts.campaigns, icon: 'fa-bullhorn' },
    { label: 'Templates', value: props.counts.templates, icon: 'fa-file-lines' },
    { label: 'Automations', value: props.counts.automations, icon: 'fa-bolt' },
]);

const statusTone = {
    active: 'bg-emerald-100 text-emerald-700',
    suspended: 'bg-rose-100 text-rose-700',
};

const eventTone = {
    processed: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
    ignored: 'bg-slate-100 text-slate-500',
};

function suspend() {
    suspendForm.post(route('admin.workspaces.suspend', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showSuspend.value = false;
            suspendForm.reset();
        },
    });
}

function activate() {
    router.post(route('admin.workspaces.activate', { workspace: props.workspace.id }), {}, { preserveScroll: true });
}

function extend() {
    extendForm.post(route('admin.workspaces.extend', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => (showExtend.value = false),
    });
}

function saveLimits() {
    limitsForm.patch(route('admin.workspaces.limits', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => (showLimits.value = false),
    });
}

function credit() {
    creditForm.post(route('admin.workspaces.credit', { workspace: props.workspace.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showCredit.value = false;
            creditForm.reset();
        },
    });
}
</script>

<template>
    <AdminLayout>
        <div class="mx-auto w-full max-w-7xl p-4 sm:p-8">
            <Link :href="route('admin.workspaces.index')" class="mb-3 inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 transition hover:text-slate-700">
                <i class="fa-solid fa-arrow-left text-[10px]"></i> All workspaces
            </Link>

            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-900 text-lg font-bold text-white">
                        {{ workspace.name.charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-slate-900">{{ workspace.name }}</h1>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusTone[workspace.status]">
                                {{ workspace.status }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">
                            /{{ workspace.slug }} · {{ workspace.timezone }} · created {{ dateTime(workspace.createdAt) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('inbox', { workspace: workspace.slug })"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                    >
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i> Open tenant app
                    </Link>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                        @click="showExtend = true"
                    >
                        <i class="fa-solid fa-calendar-plus text-[11px]"></i> Extend
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                        @click="showLimits = true"
                    >
                        <i class="fa-solid fa-sliders text-[11px]"></i> Limits
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100"
                        @click="showCredit = true"
                    >
                        <i class="fa-solid fa-plus text-[11px]"></i> Credit wallet
                    </button>
                    <button
                        v-if="!isSuspended"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                        @click="showSuspend = true"
                    >
                        <i class="fa-solid fa-ban text-[11px]"></i> Suspend
                    </button>
                    <button
                        v-else
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700"
                        @click="activate"
                    >
                        <i class="fa-solid fa-play text-[11px]"></i> Reactivate
                    </button>
                </div>
            </div>

            <div
                v-if="isSuspended"
                class="mt-4 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-sm text-rose-500"></i>
                <div class="text-xs text-rose-700">
                    <p class="font-semibold">Suspended {{ dateTime(workspace.suspendedAt) }}</p>
                    <p v-if="workspace.suspensionReason">{{ workspace.suspensionReason }}</p>
                    <p v-else class="text-rose-600/80">No reason recorded.</p>
                </div>
            </div>

            <!-- Plan + subscription -->
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800">Plan &amp; subscription</h2>
                    <dl class="mt-3 space-y-2 text-xs">
                        <div class="flex justify-between"><dt class="text-slate-400">Plan</dt><dd class="font-semibold text-slate-700">{{ plan?.name ?? 'No plan' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">Price</dt><dd class="font-semibold text-slate-700">{{ plan ? rupee(plan.pricePaise) : '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">Status</dt><dd class="font-semibold text-slate-700">{{ subscription?.status ?? 'none' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">Access</dt>
                            <dd class="font-semibold" :class="subscription?.hasAccess ? 'text-emerald-600' : 'text-rose-600'">
                                {{ subscription?.hasAccess ? 'Allowed' : 'Blocked' }}
                            </dd>
                        </div>
                        <div class="flex justify-between"><dt class="text-slate-400">Trial ends</dt><dd class="font-medium text-slate-600">{{ dateTime(subscription?.trialEndsAt) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">Period ends</dt><dd class="font-medium text-slate-600">{{ dateTime(subscription?.currentPeriodEnd) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-400">Owner</dt><dd class="font-medium text-slate-600">{{ owner?.email ?? '—' }}</dd></div>
                    </dl>
                    <div v-if="Object.keys(workspace.limitsOverride).length" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium text-amber-700">
                        Custom limits override the plan:
                        <span v-for="(value, key) in workspace.limitsOverride" :key="key" class="ml-1 font-bold">{{ key }}={{ value }}</span>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800">Usage this period</h2>
                    <div class="mt-4 space-y-4">
                        <div v-for="card in usageCards" :key="card.label">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium text-slate-600">{{ card.label }}</span>
                                <span class="font-semibold text-slate-700">
                                    {{ number(card.used) }} <span class="text-slate-400">/ {{ card.limit ? number(card.limit) : '∞' }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-brand-600" :class="card.percent >= 90 && 'bg-rose-500'" :style="{ width: Math.min(100, card.percent || 0) + '%' }"></div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3 text-xs">
                            <span class="font-medium text-slate-600">Overage blocks</span>
                            <span class="font-semibold text-slate-700">{{ number(usage.overageBlocks) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-slate-600">Connected channels</span>
                            <span class="font-semibold text-slate-700">{{ number(usage.channels) }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800">Wallet</h2>
                    <p class="mt-3 text-2xl font-bold text-slate-900">{{ rupee(wallet.balancePaise) }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">
                        ≈ {{ number(wallet.estimatedMessages) }} template messages at {{ rupee(wallet.perMessagePaise) }} each
                    </p>
                    <button
                        type="button"
                        class="mt-4 w-full rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100"
                        @click="showCredit = true"
                    >
                        <i class="fa-solid fa-plus text-[10px]"></i> Add balance
                    </button>
                </section>
            </div>

            <!-- Counts -->
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                <div v-for="card in countCards" :key="card.label" class="rounded-xl border border-slate-200 bg-white p-3 text-center shadow-sm">
                    <i class="fa-solid text-sm text-slate-400" :class="card.icon"></i>
                    <p class="mt-1.5 text-lg font-bold text-slate-900">{{ number(card.value) }}</p>
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ card.label }}</p>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- Team -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Team · {{ members.length }}</h2>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div v-for="member in members" :key="member.id" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">{{ member.name }}</p>
                                <p class="truncate text-[11px] text-slate-400">{{ member.email }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                                {{ member.role }}
                            </span>
                        </div>
                        <p v-if="!members.length" class="px-5 py-8 text-center text-xs text-slate-400">No members.</p>
                    </div>
                </section>

                <!-- Recent webhook events -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Recent webhook events</h2>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div v-for="event in events" :key="event.id" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">
                                    {{ event.eventType ?? 'event' }} <span class="font-normal text-slate-400">#{{ event.id }}</span>
                                </p>
                                <p class="text-[11px] text-slate-400">{{ event.provider }} · {{ dateTime(event.createdAt) }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold" :class="eventTone[event.status] ?? 'bg-slate-100 text-slate-500'">
                                {{ event.status }}
                            </span>
                        </div>
                        <p v-if="!events.length" class="px-5 py-8 text-center text-xs text-slate-400">No events for this workspace.</p>
                    </div>
                </section>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
                <!-- Wallet ledger -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Wallet ledger</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[28rem] text-left text-xs">
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="tx in transactions" :key="tx.id">
                                    <td class="px-5 py-2.5">
                                        <p class="font-semibold text-slate-700">{{ tx.reason }}</p>
                                        <p class="text-[11px] text-slate-400">{{ tx.description ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-2.5 text-[11px] text-slate-400">{{ dateTime(tx.createdAt) }}</td>
                                    <td class="px-5 py-2.5 text-right font-bold" :class="tx.type === 'credit' ? 'text-emerald-600' : 'text-rose-600'">
                                        {{ tx.type === 'credit' ? '+' : '−' }}{{ rupee(tx.amountPaise) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="!transactions.length" class="px-5 py-8 text-center text-xs text-slate-400">No ledger entries.</p>
                    </div>
                </section>

                <!-- Payments -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Payments</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[28rem] text-left text-xs">
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="payment in payments" :key="payment.id">
                                    <td class="px-5 py-2.5">
                                        <p class="font-semibold text-slate-700">{{ payment.invoiceNumber ?? '—' }}</p>
                                        <p class="text-[11px] text-slate-400">{{ payment.purpose }} · {{ payment.status }}</p>
                                    </td>
                                    <td class="px-5 py-2.5 text-[11px] text-slate-400">{{ dateTime(payment.paidAt) }}</td>
                                    <td class="px-5 py-2.5 text-right font-bold text-slate-700">{{ rupee(payment.amountPaise) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="!payments.length" class="px-5 py-8 text-center text-xs text-slate-400">No payments.</p>
                    </div>
                </section>
            </div>
        </div>

        <!-- Suspend modal -->
        <Modal :show="showSuspend" max-width="md" @close="showSuspend = false">
            <div class="p-6">
                <h3 class="text-sm font-bold text-slate-900">Suspend {{ workspace.name }}</h3>
                <p class="mt-1 text-xs text-slate-500">
                    The tenant's agents are locked out immediately (they get a 403). Data is untouched and access is
                    restored by reactivating.
                </p>
                <textarea
                    v-model="suspendForm.reason"
                    rows="3"
                    placeholder="Reason (optional, shown on the tenant's block page)"
                    class="mt-4 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400"
                ></textarea>
                <p v-if="suspendForm.errors.reason" class="mt-1 text-[11px] text-rose-600">{{ suspendForm.errors.reason }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="showSuspend = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-rose-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
                        :disabled="suspendForm.processing"
                        @click="suspend"
                    >
                        {{ suspendForm.processing ? 'Suspending…' : 'Suspend workspace' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Extend modal -->
        <Modal :show="showExtend" max-width="sm" @close="showExtend = false">
            <div class="p-6">
                <h3 class="text-sm font-bold text-slate-900">Extend access</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Adds days to the current trial (while trialing) or the paid period. A lapsed trial extends from now.
                </p>
                <label class="mt-4 block text-[11px] font-semibold text-slate-600">Days</label>
                <input
                    v-model="extendForm.days"
                    type="number"
                    min="1"
                    max="365"
                    class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400"
                />
                <p v-if="extendForm.errors.days" class="mt-1 text-[11px] text-rose-600">{{ extendForm.errors.days }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="showExtend = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="extendForm.processing"
                        @click="extend"
                    >
                        {{ extendForm.processing ? 'Extending…' : 'Extend' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Limits modal -->
        <Modal :show="showLimits" max-width="md" @close="showLimits = false">
            <div class="p-6">
                <h3 class="text-sm font-bold text-slate-900">Limit overrides</h3>
                <p class="mt-1 text-xs text-slate-500">Leave a field blank to fall back to the plan's limit. Overrides apply immediately.</p>
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Monthly active contacts</label>
                        <input v-model="limitsForm.monthly_active_contacts" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="limitsForm.errors.monthly_active_contacts" class="mt-1 text-[11px] text-rose-600">{{ limitsForm.errors.monthly_active_contacts }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Agent seats</label>
                        <input v-model="limitsForm.max_agent_seats" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="limitsForm.errors.max_agent_seats" class="mt-1 text-[11px] text-rose-600">{{ limitsForm.errors.max_agent_seats }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Instagram accounts</label>
                        <input v-model="limitsForm.max_instagram_accounts" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="limitsForm.errors.max_instagram_accounts" class="mt-1 text-[11px] text-rose-600">{{ limitsForm.errors.max_instagram_accounts }}</p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="showLimits = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="limitsForm.processing"
                        @click="saveLimits"
                    >
                        {{ limitsForm.processing ? 'Saving…' : 'Save limits' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Credit modal -->
        <Modal :show="showCredit" max-width="sm" @close="showCredit = false">
            <div class="p-6">
                <h3 class="text-sm font-bold text-slate-900">Credit wallet</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Writes a real ledger entry (reason: adjustment) so the tenant's Billing screen shows it.
                </p>
                <label class="mt-4 block text-[11px] font-semibold text-slate-600">Amount (₹)</label>
                <input
                    v-model="creditForm.amount"
                    type="number"
                    min="1"
                    class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400"
                />
                <p v-if="creditForm.errors.amount" class="mt-1 text-[11px] text-rose-600">{{ creditForm.errors.amount }}</p>
                <label class="mt-3 block text-[11px] font-semibold text-slate-600">Note</label>
                <input
                    v-model="creditForm.description"
                    type="text"
                    placeholder="Goodwill credit by platform support"
                    class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400"
                />
                <p v-if="creditForm.errors.description" class="mt-1 text-[11px] text-rose-600">{{ creditForm.errors.description }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="showCredit = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        :disabled="creditForm.processing"
                        @click="credit"
                    >
                        {{ creditForm.processing ? 'Crediting…' : 'Credit wallet' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
