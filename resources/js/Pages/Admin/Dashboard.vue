<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@ziggy';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    stats: { type: Object, required: true },
    recentWorkspaces: { type: Array, required: true },
    recentEvents: { type: Array, required: true },
});

const rupee = (paise) =>
    '₹' + (Number(paise ?? 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 });

const number = (value) => Number(value ?? 0).toLocaleString('en-IN');

const cards = computed(() => [
    {
        label: 'Workspaces',
        value: number(props.stats.workspaces.total),
        sub: `${number(props.stats.workspaces.active)} active · ${number(props.stats.workspaces.suspended)} suspended`,
        icon: 'fa-building',
        tile: 'bg-brand-50 text-brand-600',
    },
    {
        label: 'Monthly recurring',
        value: rupee(props.stats.mrrPaise),
        sub: `${number(props.stats.subscriptions.active)} active · ${number(props.stats.subscriptions.trialing)} trialing`,
        icon: 'fa-indian-rupee-sign',
        tile: 'bg-emerald-50 text-emerald-600',
    },
    {
        label: 'Wallet float',
        value: rupee(props.stats.walletTotalPaise),
        sub: `${number(props.stats.walletLowCount)} wallet(s) running low`,
        icon: 'fa-wallet',
        tile: 'bg-amber-50 text-amber-600',
    },
    {
        label: 'Contacts',
        value: number(props.stats.contacts),
        sub: `${number(props.stats.conversations)} conversations`,
        icon: 'fa-address-book',
        tile: 'bg-sky-50 text-sky-600',
    },
    {
        label: 'Messages · 30 days',
        value: number(props.stats.messages30d),
        sub: 'WhatsApp · Instagram · Messenger',
        icon: 'fa-comments',
        tile: 'bg-violet-50 text-violet-600',
    },
    {
        label: 'Users',
        value: number(props.stats.users.total),
        sub: `${number(props.stats.users.staff)} platform staff`,
        icon: 'fa-users',
        tile: 'bg-slate-100 text-slate-600',
    },
    {
        label: 'Webhooks pending',
        value: number(props.stats.webhooks.pending),
        sub: `${number(props.stats.webhooks.failed)} failed · ${number(props.stats.webhooks.processed24h)} processed 24h`,
        icon: 'fa-satellite-dish',
        tile: 'bg-rose-50 text-rose-600',
        href: route('admin.webhooks.index'),
    },
    {
        label: 'Past due',
        value: number(props.stats.subscriptions.pastDue),
        sub: `${number(props.stats.subscriptions.cancelled)} cancelled`,
        icon: 'fa-triangle-exclamation',
        tile: 'bg-orange-50 text-orange-600',
    },
]);

const eventTone = {
    processed: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
    ignored: 'bg-slate-100 text-slate-500',
};

const workspaceTone = {
    active: 'bg-emerald-100 text-emerald-700',
    suspended: 'bg-rose-100 text-rose-700',
};
</script>

<template>
    <AdminLayout>
        <div class="mx-auto w-full max-w-7xl p-4 sm:p-8">
            <div class="mb-6">
                <h1 class="text-xl font-bold text-slate-900">Platform overview</h1>
                <p class="mt-1 text-xs text-slate-500">
                    Every tenant, subscription, wallet and inbound webhook in one place.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <component
                    :is="card.href ? Link : 'div'"
                    v-for="card in cards"
                    :key="card.label"
                    :href="card.href"
                    class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition"
                    :class="card.href && 'hover:border-brand-300 hover:shadow'"
                >
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ card.label }}</p>
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg text-sm" :class="card.tile">
                            <i class="fa-solid" :class="card.icon"></i>
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ card.value }}</p>
                    <p class="mt-1 text-[11px] font-medium text-slate-400">{{ card.sub }}</p>
                </component>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Newest workspaces</h2>
                        <Link :href="route('admin.workspaces.index')" class="text-[11px] font-semibold text-brand-600 hover:underline">
                            View all
                        </Link>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <Link
                            v-for="workspace in recentWorkspaces"
                            :key="workspace.id"
                            :href="route('admin.workspaces.show', { workspace: workspace.id })"
                            class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-slate-50"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">{{ workspace.name }}</p>
                                <p class="truncate text-[11px] text-slate-400">
                                    {{ workspace.owner?.email ?? '—' }} · {{ workspace.plan ?? 'No plan' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-xs font-semibold text-slate-500">{{ rupee(workspace.walletPaise) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="workspaceTone[workspace.status]">
                                    {{ workspace.status }}
                                </span>
                            </div>
                        </Link>

                        <p v-if="!recentWorkspaces.length" class="px-5 py-10 text-center text-xs text-slate-400">
                            No workspaces yet.
                        </p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                        <h2 class="text-sm font-bold text-slate-800">Latest webhook events</h2>
                        <Link :href="route('admin.webhooks.index')" class="text-[11px] font-semibold text-brand-600 hover:underline">
                            View all
                        </Link>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="event in recentEvents"
                            :key="event.id"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">
                                    {{ event.eventType ?? 'event' }}
                                    <span class="font-normal text-slate-400">#{{ event.id }}</span>
                                </p>
                                <p class="truncate text-[11px] text-slate-400">
                                    {{ event.provider }} · {{ event.channelAccount ?? 'unmapped' }} · {{ event.createdAt }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold" :class="eventTone[event.status] ?? 'bg-slate-100 text-slate-500'">
                                {{ event.status }}
                            </span>
                        </div>

                        <p v-if="!recentEvents.length" class="px-5 py-10 text-center text-xs text-slate-400">
                            No webhook events yet.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
