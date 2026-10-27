<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    campaigns: { type: Array, default: () => [] },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const search = ref('');
const statusFilter = ref('all');

const filteredCampaigns = computed(() => {
    let items = props.campaigns;
    if (statusFilter.value !== 'all') {
        items = items.filter((c) => c.status === statusFilter.value);
    }
    if (search.value.trim()) {
        const q = search.value.toLowerCase();
        items = items.filter((c) => c.name?.toLowerCase().includes(q));
    }
    return items;
});

const statusMeta = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-600' },
    processing: { label: 'Processing', class: 'bg-amber-100 text-amber-800' },
    active: { label: 'Active', class: 'bg-emerald-100 text-emerald-800' },
    paused: { label: 'Paused', class: 'bg-slate-200 text-slate-700' },
    archived: { label: 'Archived', class: 'bg-slate-100 text-slate-500' },
    failed: { label: 'Failed', class: 'bg-rose-100 text-rose-800' },
};

const platformIcon = (p) => {
    if (p === 'meta') return 'fa-brands fa-meta';
    if (p === 'google') return 'fa-brands fa-google';
    if (p === 'linkedin') return 'fa-brands fa-linkedin';
    return 'fa-solid fa-bullseye';
};
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Ad Manager</h2>
                        <p class="mt-1 text-xs text-slate-500">Meta Ads Campaign Manager — Phase 1 drafts.</p>
                    </div>
                    <Link
                        :href="route('ads.campaigns.create', { workspace: workspaceSlug })"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                    >
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Create Campaign</span>
                    </Link>
                </div>

                <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative w-full sm:max-w-xs">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search campaigns..."
                            class="w-full rounded-lg border border-slate-200 pl-8 pr-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                    </div>
                    <select
                        v-model="statusFilter"
                        class="rounded-lg border border-slate-200 px-3 py-2 text-xs"
                    >
                        <option value="all">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="processing">Processing</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="archived">Archived</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Campaign</th>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Platform</th>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Objective</th>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Budget</th>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Status</th>
                                    <th class="px-4 py-2.5 font-semibold text-slate-600">Ad Sets</th>
                                    <th class="px-4 py-2.5 text-right font-semibold text-slate-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="campaign in filteredCampaigns" :key="campaign.id" class="hover:bg-slate-50/60">
                                    <td class="px-4 py-3 font-medium text-slate-900">{{ campaign.name }}</td>
                                    <td class="px-4 py-3 text-slate-600">
                                        <div class="flex items-center gap-1.5">
                                            <i :class="platformIcon(campaign.platform)"></i>
                                            <span class="capitalize">{{ campaign.platform }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ campaign.objective || '—' }}</td>
                                    <td class="px-4 py-3 text-slate-600">
                                        <span v-if="campaign.budget_amount">{{ campaign.budget_type === 'lifetime' ? 'Lifetime' : 'Daily' }} ₹{{ campaign.budget_amount }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase" :class="(statusMeta[campaign.status] || statusMeta.draft).class">
                                            {{ (statusMeta[campaign.status] || statusMeta.draft).label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ campaign.ad_sets_count }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <Link :href="route('ads.campaigns.show', { workspace: workspaceSlug, ad_campaign: campaign.id })" class="text-slate-500 hover:text-slate-700">
                                                <i class="fa-solid fa-eye"></i>
                                            </Link>
                                            <Link :href="route('ads.campaigns.edit', { workspace: workspaceSlug, ad_campaign: campaign.id })" class="text-slate-500 hover:text-slate-700">
                                                <i class="fa-solid fa-pen"></i>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!filteredCampaigns.length">
                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400">No campaigns found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
