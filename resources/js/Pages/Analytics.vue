<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    range: { type: Number, required: true },
    ranges: { type: Array, required: true },
    totals: { type: Object, required: true },
    series: { type: Array, required: true },
    channels: { type: Array, required: true },
    campaigns: { type: Array, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

// The tallest single-day bar sets the 100% line; never divide by zero.
const maxBar = computed(() => Math.max(1, ...props.series.map((day) => Math.max(day.inbound, day.outbound))));
const barHeight = (value) => `${Math.round((value / maxBar.value) * 100)}%`;

// With 30+ columns the per-day labels would collide, so show every Nth.
const labelStep = computed(() => Math.max(1, Math.ceil(props.series.length / 10)));
const showLabel = (index) => index % labelStep.value === 0;

const channelMeta = {
    whatsapp: { icon: 'fa-brands fa-whatsapp', tile: 'bg-emerald-100 text-emerald-700' },
    instagram: { icon: 'fa-brands fa-instagram', tile: 'bg-rose-100 text-rose-700' },
    messenger: { icon: 'fa-brands fa-facebook-messenger', tile: 'bg-blue-100 text-blue-700' },
};
const meta = (channel) => channelMeta[channel] ?? { icon: 'fa-solid fa-comment', tile: 'bg-slate-100 text-slate-600' };

const money = (paise) => `₹${(paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function duration(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    }

    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

const statusLabel = {
    active: 'Live',
    scheduled: 'Scheduled',
    paused: 'Paused',
    completed: 'Completed',
    draft: 'Draft',
    failed: 'Failed',
};
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Analytics</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Messaging, audience and spend for the last {{ range }} days.
                        </p>
                    </div>
                    <div class="flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1">
                        <Link
                            v-for="option in ranges"
                            :key="option"
                            :href="route('analytics.index', { workspace: workspaceSlug, range: option })"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                            :class="option === range ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                        >
                            {{ option }}d
                        </Link>
                    </div>
                </div>

                <!-- KPI tiles -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Messages Out</span>
                            <i class="fa-solid fa-paper-plane text-sm text-brand-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ totals.outbound.toLocaleString() }}</div>
                        <div class="mt-1 text-[11px] text-slate-400">{{ totals.broadcastSends.toLocaleString() }} from broadcasts</div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Messages In</span>
                            <i class="fa-solid fa-inbox text-sm text-emerald-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ totals.inbound.toLocaleString() }}</div>
                        <div class="mt-1 text-[11px] text-slate-400">{{ totals.replyRate }}% reply ratio</div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">First Response</span>
                            <i class="fa-solid fa-stopwatch text-sm text-indigo-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ duration(totals.avgFirstResponseSeconds) }}</div>
                        <div class="mt-1 text-[11px] text-slate-400">average, conversations in window</div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Wallet Spend</span>
                            <i class="fa-solid fa-wallet text-sm text-amber-600"></i>
                        </div>
                        <div class="text-2xl font-black text-slate-900">{{ money(totals.spendPaise) }}</div>
                        <div class="mt-1 text-[11px] text-slate-400">template messages billed</div>
                    </div>
                </div>

                <!-- Volume chart -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-bold text-slate-800">Message Volume</h3>
                        <div class="flex items-center gap-4 text-[11px] font-semibold text-slate-500">
                            <span class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-sm bg-emerald-400"></span> Inbound
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-sm bg-brand-500"></span> Outbound
                            </span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <div class="min-w-[560px]">
                            <div class="flex h-40 items-end gap-1">
                                <div
                                    v-for="day in series"
                                    :key="day.day"
                                    class="flex h-full min-w-[12px] flex-1 items-end justify-center gap-[2px]"
                                >
                                    <div
                                        class="min-h-[2px] w-1/2 rounded-t bg-emerald-400"
                                        :style="{ height: barHeight(day.inbound) }"
                                        :title="`${day.label}: ${day.inbound} in`"
                                    ></div>
                                    <div
                                        class="min-h-[2px] w-1/2 rounded-t bg-brand-500"
                                        :style="{ height: barHeight(day.outbound) }"
                                        :title="`${day.label}: ${day.outbound} out`"
                                    ></div>
                                </div>
                            </div>
                            <div class="mt-1.5 flex gap-1">
                                <span
                                    v-for="(day, index) in series"
                                    :key="day.day"
                                    class="min-w-[12px] flex-1 text-center text-[9px] font-medium text-slate-400"
                                >
                                    {{ showLabel(index) ? day.label : '' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <!-- Audience -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-bold text-slate-800">Audience</h3>
                        <div class="mt-4 space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-500">New contacts</span>
                                <span class="text-sm font-bold text-slate-800">{{ totals.newContacts.toLocaleString() }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-500">Conversations opened</span>
                                <span class="text-sm font-bold text-slate-800">{{ totals.conversations.toLocaleString() }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-slate-500">Still open</span>
                                <span class="text-sm font-bold text-slate-800">{{ totals.activeConversations.toLocaleString() }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                                <span class="text-xs text-slate-500">Opted-in contacts</span>
                                <span class="text-sm font-bold text-emerald-700">{{ totals.optedIn.toLocaleString() }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Channel mix -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                        <h3 class="text-sm font-bold text-slate-800">Channel Mix</h3>
                        <div v-if="channels.length" class="mt-4 space-y-4">
                            <div v-for="row in channels" :key="row.channel">
                                <div class="mb-1.5 flex items-center justify-between text-xs">
                                    <span class="flex items-center gap-2 font-semibold text-slate-600">
                                        <span class="flex h-6 w-6 items-center justify-center rounded-md" :class="meta(row.channel).tile">
                                            <i :class="meta(row.channel).icon" class="text-[11px]"></i>
                                        </span>
                                        <span class="capitalize">{{ row.channel }}</span>
                                    </span>
                                    <span class="font-bold text-slate-700">{{ row.total.toLocaleString() }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-brand-500" :style="{ width: row.percent + '%' }"></div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-6 text-xs text-slate-400">No conversations in this window yet.</p>
                    </div>
                </div>

                <!-- Top broadcasts -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-800">Top Broadcasts</h3>
                    </div>

                    <div v-if="campaigns.length" class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-xs">
                            <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th class="px-4 py-2.5 font-semibold">Campaign</th>
                                    <th class="px-4 py-2.5 font-semibold">Status</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">Enrolled</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">Delivered</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">Replied</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">Won</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="campaign in campaigns" :key="campaign.id" class="hover:bg-slate-50/60">
                                    <td class="px-4 py-3 font-semibold text-slate-800">{{ campaign.name }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ statusLabel[campaign.status] ?? campaign.status }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ campaign.enrolled.toLocaleString() }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-800">{{ campaign.delivered.toLocaleString() }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ campaign.replied.toLocaleString() }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-brand-600">{{ campaign.won.toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-else class="px-4 py-10 text-center text-xs text-slate-400">
                        No broadcasts have been sent yet.
                    </p>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
