<template>
    <Modal :show="show" maxWidth="7xl" @close="close">
        <div class="flex max-h-[90vh] flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Create WhatsApp Template</h2>
                    <p class="text-xs text-slate-500">Submit directly to Meta for approval</p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="grid flex-1 grid-cols-1 overflow-auto lg:grid-cols-[1fr_360px]">
                <div class="overflow-auto px-6 py-6">
                    <form @submit.prevent="submit" class="space-y-4">
                        <div v-if="Object.keys(errors).length" class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">
                            <div v-for="(m, k) in errors" :key="k">{{ m }}</div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-700">Template name *</label>
                                <input v-model="name" @input="name = sanitize(name)" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-mono" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-700">Category *</label>
                                <select v-model="category" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option value="marketing">Marketing (88 paise)</option>
                                    <option value="utility">Utility (30 paise)</option>
                                    <option value="authentication">Authentication (30 paise)</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-700">Language *</label>
                                <select v-model="language" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option value="en_US">English (US)</option>
                                    <option value="hi_IN">Hindi</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-700">WhatsApp account *</label>
                                <select v-model="accountId" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option :value="null">Select</option>
                                    <option v-for="a in whatsappAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold text-slate-700">Body *</label>
                                <button type="button" class="text-[11px] font-semibold text-brand-700" @click="addVar">+ Add variable</button>
                            </div>
                            <textarea v-model="body" rows="6" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Hi {{1}}, enjoy {{2}} off."></textarea>
                            <p class="mt-1 text-[11px] text-slate-400">Variables must be sequential starting at {{1}}.</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs" @click="close">Cancel</button>
                            <button type="submit" :disabled="submitting" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">{{ submitting ? 'Submitting....' : 'Submit to Meta' }}</button>
                        </div>
                    </form>
                </div>
                <div class="hidden border-l border-slate-100 bg-slate-50 p-6 lg:block">
                    <p class="text-xs font-semibold text-slate-900">Preview</p>
                    <div class="mt-3 rounded-[1.5rem] bg-slate-900 p-2">
                        <div class="rounded-[1.2rem] bg-[#efeae2] p-3">
                            <div class="mb-2 rounded-lg bg-[#075e54] px-2 py-1 text-[10px] text-white">SocialDesk</div>
                            <div class="max-w-[80%] rounded-lg bg-[#d9fdd3] p-2 text-xs whitespace-pre-wrap">{{ body || 'Your message' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    whatsappAccounts: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const name = ref('');
const category = ref('marketing');
const language = ref('en_US');
const accountId = ref(null);
const body = ref('');
const errors = ref({});
const submitting = ref(false);

function sanitize(v) {
    return v.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '').replace(/_{2,}/g, '_').slice(0, 512);
}

function addVar() {
    const nums = [...body.value.matchAll(/\{\{(\d+)\}\}/g)].map(m => parseInt(m[1])).sort((a, b) => a - b);
    const next = nums.length ? Math.max(...nums) + 1 : 1;
    body.value = (body.value + ' {{' + next + '}}').trim();
}

function close() {
    emit('close');
    setTimeout(() => { name.value = ''; category.value = 'marketing'; language.value = 'en_US'; accountId.value = null; body.value = ''; errors.value = {}; }, 150);
}

function buildComponents() {
    const component = { type: 'BODY', text: body.value };
    
    // Find all variables, e.g., {{1}}, {{2}}
    const nums = [...body.value.matchAll(/\{\{(\d+)\}\}/g)].map(m => parseInt(m[1])).sort((a, b) => a - b);
    
    // If variables exist, Meta strictly requires an example for each
    if (nums.length > 0) {
        // Create an array of dummy strings (e.g., ["John", "Monday"])
        const dummyExamples = nums.map(n => `Sample_Value_${n}`);
        component.example = {
            body_text: dummyExamples // Note: Meta requires this to be an array inside an array
        };
    }
    
    return [component];
}

function validate() {
    const e = {};
    if (!/^[a-z0-9_]+$/.test(name.value)) e.name = 'Invalid name (lowercase, numbers, underscores only)';
    if (!body.value.trim()) e.body = 'Body required';
    const nums = [...body.value.matchAll(/\{\{(\d+)\}\}/g)].map(m => parseInt(m[1])).sort((a, b) => a - b);
    for (let i = 0; i < nums.length; i++) if (nums[i] !== i + 1) { e.vars = 'Variables must be sequential starting at {{1}}'; break; }
    if (!accountId.value) e.account = 'Select account';
    errors.value = e;
    return Object.keys(e).length === 0;
}

function submit() {
    if (submitting.value) return;
    if (!validate()) return;
    submitting.value = true;
    router.post(route('templates.store', { workspace: workspaceSlug.value }), {
        channel_account_id: accountId.value,
        name: name.value,
        category: category.value,
        language: language.value,
        components: buildComponents(),
    }, {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: (errs) => { errors.value = errs; },
        onFinish: () => { submitting.value = false; },
    });
}
</script>
