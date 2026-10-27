<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    campaign: { type: Object, default: null },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const step = ref(1);
const steps = [
    { id: 1, name: 'Platform' },
    { id: 2, name: 'Campaign Setup' },
    { id: 3, name: 'Ad Set Setup' },
    { id: 4, name: 'Ad Creative Setup' },
    { id: 5, name: 'Review & Publish' },
];

const form = useForm({
    platform: props.campaign?.platform || 'meta',
    ad_account_id: props.campaign?.ad_account_id || '',
    name: props.campaign?.name || '',
    objective: props.campaign?.objective || '',
    special_category: props.campaign?.special_category || '',
    budget_type: props.campaign?.budget_type || 'daily',
    budget_amount: props.campaign?.budget_amount || '',
    audience_json: props.campaign?.ad_sets?.[0]?.audience_json || { age_min: 18, age_max: 65, genders: [0], locations: [] },
    placements_json: props.campaign?.ad_sets?.[0]?.placements_json || { facebook: true, instagram: true, messenger: false, audience_network: false },
    optimization_goal: props.campaign?.ad_sets?.[0]?.optimization_goal || 'conversions',
    start_at: props.campaign?.ad_sets?.[0]?.start_at || '',
    end_at: props.campaign?.ad_sets?.[0]?.end_at || '',
    format: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.format || 'image',
    media_url: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.media_url || '',
    primary_text: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.primary_text || '',
    headline: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.headline || '',
    description: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.description || '',
    cta: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.cta || 'LEARN_MORE',
    destination_url: props.campaign?.ad_sets?.[0]?.creatives?.[0]?.destination_url || '',
    status: 'draft',
});

const billingWarning = ref(null);
const billingChecking = ref(false);

const checkBilling = async () => {
    if (!form.ad_account_id) return;
    billingChecking.value = true;
    try {
        const res = await axios.post(route('ads.billing.check', { workspace: workspaceSlug.value }), {
            ad_account_id: form.ad_account_id.replace(/^act_/, ''),
        });
        if (res.data && res.data.billing_ready === false) {
            billingWarning.value = {
                warning: res.data.warning || "Payment method is missing. Please add your client’s card",
                cta: res.data.cta || 'https://www.facebook.com/ads/manager/account_settings/account_billing/',
            };
        } else {
            billingWarning.value = null;
        }
    } catch (e) {
        billingWarning.value = {
            warning: "Payment method is missing. Please add your client’s card",
            cta: 'https://www.facebook.com/ads/manager/account_settings/account_billing/',
        };
    } finally {
        billingChecking.value = false;
    }
};

const objectives = [
    'OUTCOME_AWARENESS',
    'OUTCOME_TRAFFIC',
    'OUTCOME_ENGAGEMENT',
    'OUTCOME_LEADS',
    'OUTCOME_APP_PROMOTION',
    'OUTCOME_SALES',
];

const ctas = ['LEARN_MORE', 'SHOP_NOW', 'SIGN_UP', 'CONTACT_US', 'GET_QUOTE', 'BOOK_NOW'];

const errors = computed(() => form.errors);

const canProceed = computed(() => {
    if (step.value === 1) return ['meta'].includes(form.platform);
    if (step.value === 2) return form.name && form.objective && form.budget_type && form.budget_amount;
    if (step.value === 3) return form.optimization_goal;
    if (step.value === 4) return form.primary_text && form.destination_url && form.media_url;
    return true;
});

const saveDraft = () => {
    if (props.campaign?.id) {
        form.patch(route('ads.campaigns.update', { workspace: workspaceSlug.value, ad_campaign: props.campaign.id }), {
            preserveScroll: true,
        });
    } else {
        form.post(route('ads.campaigns.store', { workspace: workspaceSlug.value }), {
            preserveScroll: true,
        });
    }
};

const nextStep = () => {
    if (canProceed.value && step.value < 5) step.value++;
};

const prevStep = () => {
    if (step.value > 1) step.value--;
};

const publish = () => {
    form.status = 'processing';
    if (props.campaign?.id) {
        form.patch(route('ads.campaigns.update', { workspace: workspaceSlug.value, ad_campaign: props.campaign.id }), {
            preserveScroll: true,
            onSuccess: () => router.visit(route('ads.campaigns.index', { workspace: workspaceSlug.value })),
        });
    } else {
        form.post(route('ads.campaigns.store', { workspace: workspaceSlug.value }), {
            preserveScroll: true,
            onSuccess: () => router.visit(route('ads.campaigns.index', { workspace: workspaceSlug.value })),
        });
    }
};
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-4xl space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">{{ campaign ? 'Edit Ad Campaign' : 'Create Ad Campaign' }}</h2>
                        <p class="mt-1 text-xs text-slate-500">Step-by-step wizard with draft saving.</p>
                    </div>
                    <button type="button" class="text-xs font-semibold text-slate-600 hover:underline" @click="saveDraft">Save as Draft</button>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-6 py-4">
                        <div v-for="s in steps" :key="s.id" class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold" :class="step >= s.id ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-400'">{{ s.id }}</div>
                            <span class="text-xs font-medium" :class="step >= s.id ? 'text-slate-900' : 'text-slate-400'">{{ s.name }}</span>
                            <i v-if="s.id < 5" class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                        </div>
                    </div>

                    <div class="space-y-4 px-6 py-5">
                        <!-- Step 1 -->
                        <div v-if="step === 1" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Select Platform</label>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                    <button type="button" class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="form.platform === 'meta' ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'" @click="form.platform = 'meta'">
                                        <i class="fa-brands fa-meta"></i> Meta
                                    </button>
                                    <button type="button" class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="form.platform === 'google' ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'" @click="form.platform = 'google'">
                                        <i class="fa-brands fa-google"></i> Google <span class="text-[10px] text-slate-400">(Future)</span>
                                    </button>
                                    <button type="button" class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="form.platform === 'linkedin' ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600'" @click="form.platform = 'linkedin'">
                                        <i class="fa-brands fa-linkedin"></i> LinkedIn <span class="text-[10px] text-slate-400">(Future)</span>
                                    </button>
                                </div>
                                <p v-if="errors.platform" class="mt-1 text-[11px] text-rose-600">{{ errors.platform }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Ad Account ID (Meta)</label>
                                <div class="flex gap-2">
                                    <input v-model="form.ad_account_id" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" placeholder="act_1234567890" @blur="checkBilling" />
                                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600 hover:bg-slate-50" :disabled="billingChecking" @click="checkBilling">
                                        {{ billingChecking ? 'Checking...' : 'Check Billing' }}
                                    </button>
                                </div>
                            </div>
                            <div v-if="billingWarning" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                                <div class="flex items-start gap-2">
                                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                                    <div class="flex-1">
                                        <p class="font-semibold">{{ billingWarning.warning }}</p>
                                        <a :href="billingWarning.cta" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-semibold text-amber-900 underline">Go to Meta Billing Settings</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div v-if="step === 2" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Campaign Name</label>
                                <input v-model="form.name" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" placeholder="Summer Sale Campaign" />
                                <p v-if="errors.name" class="mt-1 text-[11px] text-rose-600">{{ errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Objective</label>
                                <select v-model="form.objective" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                    <option value="" disabled>Select objective</option>
                                    <option v-for="o in objectives" :key="o" :value="o">{{ o }}</option>
                                </select>
                                <p v-if="errors.objective" class="mt-1 text-[11px] text-rose-600">{{ errors.objective }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">Budget Strategy</label>
                                    <select v-model="form.budget_type" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                        <option value="daily">Daily Budget</option>
                                        <option value="lifetime">Lifetime Budget</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">Budget Amount (₹)</label>
                                    <input v-model="form.budget_amount" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                                    <p v-if="errors.budget_amount" class="mt-1 text-[11px] text-rose-600">{{ errors.budget_amount }}</p>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Special Ad Category (optional)</label>
                                <input v-model="form.special_category" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" placeholder="CREDIT, EMPLOYMENT, HOUSING, SOCIAL_ISSUES_ELECTIONS_POLITICS" />
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div v-if="step === 3" class="space-y-4">
                            <div class="rounded-lg border border-slate-200 p-3">
                                <p class="mb-2 text-xs font-semibold text-slate-700">Audience (JSON builder)</p>
                                <textarea v-model="form.audience_json" rows="4" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs font-mono" @input="form.audience_json = JSON.parse($event.target.value)" :value="JSON.stringify(form.audience_json, null, 2)"></textarea>
                            </div>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <p class="mb-2 text-xs font-semibold text-slate-700">Placements</p>
                                <div class="grid grid-cols-2 gap-2 text-xs text-slate-600">
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.placements_json.facebook" /> Facebook</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.placements_json.instagram" /> Instagram</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.placements_json.messenger" /> Messenger</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" v-model="form.placements_json.audience_network" /> Audience Network</label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">Optimization Goal</label>
                                    <input v-model="form.optimization_goal" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">Start Date</label>
                                    <input v-model="form.start_at" type="datetime-local" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                                </div>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div v-if="step === 4" class="space-y-4">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <p class="mb-2 text-xs font-semibold text-slate-700">Media Preview Box</p>
                                <div class="flex h-40 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white text-xs text-slate-400">
                                    <span v-if="!form.media_url">Upload or paste media URL</span>
                                    <img v-else :src="form.media_url" class="h-full w-full object-cover" />
                                </div>
                                <input v-model="form.media_url" type="text" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" placeholder="https://example.com/image.jpg" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Primary Text</label>
                                <textarea v-model="form.primary_text" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea>
                                <p v-if="errors.primary_text" class="mt-1 text-[11px] text-rose-600">{{ errors.primary_text }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">Headline</label>
                                    <input v-model="form.headline" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-700">CTA</label>
                                    <select v-model="form.cta" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                        <option v-for="c in ctas" :key="c" :value="c">{{ c }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">Destination URL</label>
                                <input v-model="form.destination_url" type="url" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" placeholder="https://yourstore.com/product" />
                                <p v-if="errors.destination_url" class="mt-1 text-[11px] text-rose-600">{{ errors.destination_url }}</p>
                            </div>
                        </div>

                        <!-- Step 5 -->
                        <div v-if="step === 5" class="space-y-4">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p class="mb-2 text-xs font-semibold text-slate-700">Review Summary</p>
                                <div class="grid grid-cols-2 gap-2 text-xs text-slate-600">
                                    <div><span class="font-medium text-slate-700">Platform:</span> {{ form.platform }}</div>
                                    <div><span class="font-medium text-slate-700">Campaign:</span> {{ form.name }}</div>
                                    <div><span class="font-medium text-slate-700">Objective:</span> {{ form.objective }}</div>
                                    <div><span class="font-medium text-slate-700">Budget:</span> {{ form.budget_type }} ₹{{ form.budget_amount }}</div>
                                    <div class="col-span-2"><span class="font-medium text-slate-700">Primary Text:</span> {{ form.primary_text }}</div>
                                    <div class="col-span-2"><span class="font-medium text-slate-700">Destination:</span> {{ form.destination_url }}</div>
                                </div>
                                <div v-if="!form.billing_info" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-800">
                                    Missing billing information. Add billing details before publishing.
                                </div>
                                <div v-if="!canProceed" class="mt-2 rounded-lg border border-rose-200 bg-rose-50 p-2 text-[11px] text-rose-800">
                                    Missing required parameters or empty creative fields.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 px-6 py-3">
                        <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100" :disabled="step === 1" @click="prevStep">Back</button>
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100" @click="saveDraft">Save as Draft</button>
                            <button v-if="step < 5" type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-60" :disabled="!canProceed" @click="nextStep">Next</button>
                            <button v-if="step === 5" type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700" @click="publish">Publish</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
