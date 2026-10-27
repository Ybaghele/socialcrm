<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    campaign: { type: Object, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-4xl space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">{{ campaign.name }}</h2>
                        <p class="mt-1 text-xs text-slate-500">Campaign details</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('ads.campaigns.edit', { workspace: workspaceSlug, ad_campaign: campaign.id })" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">Edit</Link>
                        <Link :href="route('ads.campaigns.index', { workspace: workspaceSlug })" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">Back</Link>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-3 text-xs">
                    <div class="grid grid-cols-2 gap-2">
                        <div><span class="font-semibold text-slate-700">Platform:</span> {{ campaign.platform }}</div>
                        <div><span class="font-semibold text-slate-700">Status:</span> {{ campaign.status }}</div>
                        <div><span class="font-semibold text-slate-700">Objective:</span> {{ campaign.objective || '—' }}</div>
                        <div><span class="font-semibold text-slate-700">Budget:</span> {{ campaign.budget_type }} {{ campaign.budget_amount || '—' }}</div>
                        <div><span class="font-semibold text-slate-700">Ad Account:</span> {{ campaign.ad_account_id || '—' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
