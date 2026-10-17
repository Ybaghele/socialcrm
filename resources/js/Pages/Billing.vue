<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    plan: { type: Object, default: null },
    subscription: { type: Object, default: null },
    usage: { type: Object, required: true },
    addons: { type: Object, required: true },
    wallet: { type: Object, required: true },
    transactions: { type: Array, required: true },
    payments: { type: Array, required: true },
    topUpPresets: { type: Array, required: true },
    gateway: { type: Object, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const showTopUp = ref(false);

const form = useForm({ amount: 2500 });

function openTopUp() {
    form.clearErrors();
    showTopUp.value = true;
}

function pay() {
    form.post(route('billing.top-up', { workspace: workspaceSlug.value }), {
        preserveScroll: true,
        onSuccess: () => {
            showTopUp.value = false;
        },
    });
}

const rupees = (paise) => {
    const value = (paise ?? 0) / 100;

    return value.toLocaleString('en-IN', {
        minimumFractionDigits: 0,
        maximumFractionDigits: Number.isInteger(value) ? 0 : 2,
    });
};

const shortDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const statusMeta = {
    trialing: { label: 'Trial', class: 'bg-amber-50 text-amber-700 border-amber-200' },
    active: { label: 'Active Subscription', class: 'bg-brand-50 text-brand-700 border-brand-200' },
    past_due: { label: 'Past due', class: 'bg-rose-50 text-rose-700 border-rose-200' },
    cancelled: { label: 'Cancelled', class: 'bg-slate-100 text-slate-600 border-slate-200' },
};

const subscriptionBadge = computed(
    () => statusMeta[props.subscription?.status] ?? statusMeta.active,
);

const periodLine = computed(() => {
    const sub = props.subscription;

    if (!sub) {
        return 'No active subscription';
    }

    if (sub.onTrial) {
        return `Trial ends ${shortDate(sub.trialEndsAt)}`;
    }

    if (sub.currentPeriodEnd) {
        return `Renews ${shortDate(sub.currentPeriodEnd)}`;
    }

    return 'Billed monthly via Razorpay Subscriptions';
});
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-4xl space-y-6">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Subscription &amp; WhatsApp Wallet</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        Manage your plan limits, active lead quotas, and WhatsApp template messaging credits.
                    </p>
                </div>

                <!-- Subscription plan card -->
                <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="pointer-events-none absolute -right-6 -top-6 h-28 w-28 rounded-full bg-brand-50"></div>

                    <div class="relative mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <span
                                class="rounded-lg border px-2.5 py-1 text-xs font-bold uppercase tracking-wider"
                                :class="subscriptionBadge.class"
                            >
                                {{ subscriptionBadge.label }}
                            </span>
                            <h3 class="mt-2 text-2xl font-extrabold text-slate-900">{{ plan?.name ?? 'No plan' }}</h3>
                            <p class="text-xs text-slate-500">{{ periodLine }}</p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-black text-slate-900">
                                ₹{{ plan ? rupees(plan.pricePaise) : '—' }}
                                <span class="text-sm font-medium text-slate-400">/{{ plan?.billingInterval ?? 'month' }}</span>
                            </div>
                            <div class="text-xs text-slate-400">Excl. {{ plan?.gstPercent ?? 18 }}% GST</div>
                        </div>
                    </div>

                    <!-- Usage limits -->
                    <div class="space-y-4 border-t border-slate-100 pt-4">
                        <div>
                            <div class="mb-1.5 flex justify-between text-xs font-semibold">
                                <span class="text-slate-700">Monthly Active Leads (MAC Quota)</span>
                                <span class="text-slate-900">
                                    {{ usage.used.toLocaleString() }} of
                                    {{ usage.limit ? usage.limit.toLocaleString() : '∞' }} leads used ({{ usage.percent }}%)
                                </span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="usage.percent >= 90 ? 'bg-rose-500' : 'bg-brand-600'"
                                    :style="{ width: usage.percent + '%' }"
                                ></div>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-400">
                                Overage rule: ₹{{ plan ? rupees(plan.overagePricePaise) : '1,500' }} per additional block of
                                {{ plan?.overageBlockSize ?? 500 }} active contacts.
                                <span v-if="usage.overageBlocks" class="font-semibold text-amber-600">
                                    {{ usage.overageBlocks }} block(s) billed this period (₹{{ rupees(usage.overagePaise) }}).
                                </span>
                            </p>
                        </div>

                        <!-- Add-ons -->
                        <div class="grid grid-cols-1 gap-3 pt-2 sm:grid-cols-3">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[11px] font-medium text-slate-500">Instagram Accounts</div>
                                <div class="text-sm font-bold text-slate-800">
                                    {{ addons.instagram.used }} of {{ addons.instagram.max ?? '∞' }} Connected
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[11px] font-medium text-slate-500">Team Agent Seats</div>
                                <div class="text-sm font-bold text-slate-800">
                                    {{ addons.seats.used }} of {{ addons.seats.max ?? '∞' }} Seats In Use
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[11px] font-medium text-slate-500">Connected Channels</div>
                                <div class="text-sm font-bold text-slate-800">{{ addons.channels.used }} channels live</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp wallet card -->
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div>
                            <div class="flex items-center gap-2">
                                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                                    <i class="fa-brands fa-whatsapp text-sm"></i>
                                </div>
                                <h3 class="text-base font-bold text-slate-900">WhatsApp Broadcast Balance</h3>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Official Meta template fees (~₹{{ rupees(wallet.perMessagePaise) }}/marketing conversation) are
                                deducted automatically from this prepaid balance.
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-right">
                                <div class="text-xl font-extrabold text-slate-900">₹{{ rupees(wallet.balancePaise) }}</div>
                                <span class="text-[11px] font-semibold text-emerald-600">
                                    Ready to send ~{{ wallet.estimatedMessages.toLocaleString() }} messages
                                </span>
                            </div>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                                @click="openTopUp"
                            >
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>Add Balance</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Wallet activity -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-800">Wallet Activity</h3>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="tx in transactions"
                            :key="tx.id"
                            class="flex items-center justify-between gap-4 px-5 py-3 text-xs"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-7 w-7 items-center justify-center rounded-full text-[11px]"
                                    :class="tx.type === 'credit' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'"
                                >
                                    <i class="fa-solid" :class="tx.type === 'credit' ? 'fa-arrow-down' : 'fa-arrow-up'"></i>
                                </span>
                                <div>
                                    <div class="font-semibold text-slate-700">{{ tx.description || tx.reason }}</div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ tx.reason }} • {{ shortDate(tx.createdAt) }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold" :class="tx.type === 'credit' ? 'text-emerald-600' : 'text-slate-700'">
                                    {{ tx.type === 'credit' ? '+' : '−' }}₹{{ rupees(tx.amountPaise) }}
                                </div>
                                <div class="text-[11px] text-slate-400">bal ₹{{ rupees(tx.balanceAfterPaise) }}</div>
                            </div>
                        </div>

                        <div v-if="!transactions.length" class="px-5 py-10 text-center">
                            <i class="fa-solid fa-receipt text-xl text-slate-300"></i>
                            <p class="mt-2 text-xs text-slate-400">No wallet activity yet — add balance to start sending broadcasts.</p>
                        </div>
                    </div>
                </div>

                <!-- Invoices -->
                <div v-if="payments.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-800">Invoices &amp; Payments</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="border-b border-slate-100 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th class="px-5 py-3">Invoice</th>
                                    <th class="px-4 py-3">Purpose</th>
                                    <th class="px-4 py-3">Amount</th>
                                    <th class="px-4 py-3">GST</th>
                                    <th class="px-4 py-3">Method</th>
                                    <th class="px-5 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="payment in payments" :key="payment.id">
                                    <td class="px-5 py-3 font-mono text-[11px] text-slate-500">{{ payment.invoiceNumber }}</td>
                                    <td class="px-4 py-3 capitalize">{{ payment.purpose.replace(/_/g, ' ') }}</td>
                                    <td class="px-4 py-3 font-semibold text-slate-800">₹{{ rupees(payment.amountPaise) }}</td>
                                    <td class="px-4 py-3 text-slate-400">₹{{ rupees(payment.gstPaise) }}</td>
                                    <td class="px-4 py-3 uppercase text-slate-500">{{ payment.method ?? '—' }}</td>
                                    <td class="px-5 py-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                            :class="
                                                payment.status === 'captured'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : payment.status === 'failed'
                                                      ? 'bg-rose-100 text-rose-800'
                                                      : 'bg-slate-100 text-slate-600'
                                            "
                                        >
                                            {{ payment.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add balance modal -->
        <div v-if="showTopUp" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">Top-Up WhatsApp Credits</h3>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="showTopUp = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <p class="text-xs text-slate-500">
                    Select an amount to top up your prepaid broadcast wallet via Razorpay / UPI:
                </p>

                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="preset in topUpPresets"
                        :key="preset"
                        type="button"
                        class="rounded-lg border p-2 text-xs transition"
                        :class="
                            form.amount === preset
                                ? 'border-brand-600 bg-brand-50 font-bold text-brand-700'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="form.amount = preset"
                    >
                        ₹{{ preset.toLocaleString('en-IN') }}
                    </button>
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold text-slate-600">Or enter a custom amount (₹)</label>
                    <input
                        v-model.number="form.amount"
                        type="number"
                        min="100"
                        max="500000"
                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                    />
                    <p v-if="form.errors.amount" class="mt-1 text-[11px] text-rose-600">{{ form.errors.amount }}</p>
                </div>

                <div
                    v-if="!gateway.configured"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[11px] text-amber-700"
                >
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                    Razorpay isn't connected in this environment, so this credits the wallet instantly for testing.
                </div>

                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 py-2.5 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    :disabled="form.processing"
                    @click="pay"
                >
                    <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-lock'"></i>
                    Pay ₹{{ (form.amount || 0).toLocaleString('en-IN') }} with UPI / Card
                </button>
            </div>
        </div>
    </OmniLayout>
</template>
