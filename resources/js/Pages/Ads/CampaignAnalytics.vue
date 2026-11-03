<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';
import AdPerformanceChart from '@/Components/AdPerformanceChart.vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    range: { type: Number, required: true },
    ranges: { type: Array, required: true },
    report: { type: Object, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const statusMeta = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-600' },
    processing: { label: 'Processing', class: 'bg-amber-100 text-amber-800' },
    active: { label: 'Active', class: 'bg-emerald-100 text-emerald-800' },
    paused: { label: 'Paused', class: 'bg-slate-200 text-slate-700' },
    archived: { label: 'Archived', class: 'bg-slate-100 text-slate-500' },
    failed: { label: 'Failed', class: 'bg-rose-100 text-rose-800' },
};

const status = computed(() => statusMeta[props.campaign.status] ?? statusMeta.draft);

const numberFormatter = new Intl.NumberFormat('en-IN');
const compactFormatter = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });

const formatNumber = (value) => numberFormatter.format(Number(value ?? 0));
const formatCompact = (value) => compactFormatter.format(Number(value ?? 0));
const formatPercent = (value) => (value === null || value === undefined ? '—' : `${Number(value).toFixed(1)}%`);
const formatCurrency = (value) => {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: props.report.currency || 'INR',
        maximumFractionDigits: 2,
    }).format(Number(value));
};

function changeRange(event) {
    const value = Number(event.target.value);

    if (value === props.range) {
        return;
    }

    router.get(
        route('ads.campaigns.analytics', { workspace: workspaceSlug.value, campaign: props.campaign.id }),
        { range: value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <Link
                            :href="route('ads.campaigns.index', { workspace: workspaceSlug })"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-700"
                        >
                            <i class="fa-solid fa-arrow-left text-[10px]"></i>
                            <span>Ad Manager</span>
                        </Link>

                        <h2 class="mt-2 text-xl font-bold text-slate-900">{{ campaign.name }}</h2>

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase" :class="status.class">
                                {{ status.label }}
                            </span>
                            <span class="capitalize">{{ campaign.platform }}</span>
                            <span v-if="campaign.ad_account_id">· {{ campaign.ad_account_id }}</span>
                            <span v-if="report.is_mock" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800">
                                Sample data
                            </span>
                            <span v-if="report.source === 'placeholder'" class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                                Coming soon
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label for="analytics-range" class="text-xs font-semibold text-slate-500">Window</label>
                        <select
                            id="analytics-range"
                            :value="range"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            @change="changeRange"
                        >
                            <option v-for="days in ranges" :key="days" :value="days">{{ days }} days</option>
                        </select>

                        <Link
                            :href="route('ads.campaigns.show', { workspace: workspaceSlug, campaign: campaign.id })"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                        >
                            View campaign
                        </Link>
                    </div>
                </div>

                <div v-if="report.is_mock" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-flask mt-0.5 text-amber-600"></i>
                        <div>
                            <p class="font-semibold">Sandbox reporting mode</p>
                            <p class="mt-1 text-amber-800">{{ report.message }}</p>
                        </div>
                    </div>
                </div>

                <div v-else class="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-600">
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-circle-info mt-0.5 text-slate-400"></i>
                        <div>
                            <p class="font-semibold text-slate-800">
                                {{ report.source === 'placeholder' ? 'Platform connection coming soon' : 'Live insights are not connected yet' }}
                            </p>
                            <p class="mt-1">{{ report.message }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Impressions</p>
                        <p class="mt-1 text-lg font-bold text-slate-900">{{ formatCompact(report.totals.impressions) }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ report.window.days }} day window</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Reach</p>
                        <p class="mt-1 text-lg font-bold text-slate-900">{{ formatCompact(report.totals.reach) }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ formatNumber(report.totals.frequency) }}x frequency</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Clicks</p>
                        <p class="mt-1 text-lg font-bold text-slate-900">{{ formatCompact(report.totals.clicks) }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ formatPercent(report.totals.ctr) }} CTR</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Leads</p>
                        <p class="mt-1 text-lg font-bold text-slate-900">{{ formatCompact(report.totals.conversions) }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ formatPercent(report.totals.conversion_rate) }} conversion</p>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">Performance summary</h3>
                            <p class="mt-1 text-xs text-slate-500">Daily impressions vs clicks · {{ report.window.label }}</p>
                        </div>

                        <div class="flex items-center gap-3 text-[10px] font-semibold text-slate-500">
                            <span class="flex items-center gap-1">
                                <span class="h-2 w-2 rounded-full bg-brand-500"></span>
                                Impressions (left)
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                Clicks (right)
                            </span>
                        </div>
                    </div>

                    <div v-if="report.series.length" class="mt-5">
                        <AdPerformanceChart :series="report.series" />
                    </div>

                    <div v-else class="mt-5 rounded-lg border border-dashed border-slate-200 bg-slate-50 p-10 text-center text-xs text-slate-500">
                        No daily rows are available for this window.
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Financial grid</h3>
                        <p class="mt-1 text-xs text-slate-500">Spend efficiency across the selected window.</p>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="rounded-xl bg-brand-50 p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-brand-700">Total spend</p>
                                <i class="fa-solid fa-wallet text-brand-500"></i>
                            </div>
                            <p class="mt-2 text-2xl font-bold text-brand-900">{{ formatCurrency(report.totals.spend) }}</p>
                            <p class="mt-1 text-[11px] text-brand-700">{{ report.window.days }} days · {{ report.currency }}</p>
                        </div>

                        <div class="rounded-xl bg-emerald-50 p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">Cost per lead (CPA)</p>
                                <i class="fa-solid fa-user-plus text-emerald-500"></i>
                            </div>
                            <p class="mt-2 text-2xl font-bold text-emerald-900">{{ formatCurrency(report.totals.cpl) }}</p>
                            <p class="mt-1 text-[11px] text-emerald-700">{{ formatNumber(report.totals.conversions) }} leads generated</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Click-through rate</p>
                            <p class="mt-2 text-xl font-bold text-slate-900">{{ formatPercent(report.totals.ctr) }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">{{ formatNumber(report.totals.clicks) }} clicks</p>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Lead conversion rate</p>
                            <p class="mt-2 text-xl font-bold text-slate-900">{{ formatPercent(report.totals.conversion_rate) }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">Clicks that became leads</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
