<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const props = defineProps({
    segments: { type: Array, required: true },
    optedInCount: { type: Number, required: true },
    options: { type: Object, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

// The rule catalogue: how each field is labelled and edited.
const FIELD_META = {
    tag: { label: 'Has tag' },
    pipeline_stage: { label: 'Pipeline stage' },
    source_channel: { label: 'First channel' },
    city: { label: 'City' },
    last_interaction_days: { label: 'Active in last (days)' },
};

const showBuilder = ref(false);
const editingId = ref(null);
const name = ref('');
const rules = ref([]);
const errors = ref({});
const saving = ref(false);

const previewCount = ref(null);
const previewing = ref(false);
let previewTimer = null;

function optionsFor(field) {
    switch (field) {
        case 'tag':
            return props.options.tags ?? [];
        case 'pipeline_stage':
            return props.options.stages ?? [];
        case 'source_channel':
            return (props.options.channels ?? []).map((channel) => ({ id: channel.value, name: channel.label }));
        case 'city':
            return (props.options.cities ?? []).map((city) => ({ id: city, name: city }));
        default:
            return [];
    }
}

function isSelected(rule, value) {
    return Array.isArray(rule.value) && rule.value.includes(value);
}

function toggleValue(rule, value) {
    const list = Array.isArray(rule.value) ? [...rule.value] : [];
    const index = list.indexOf(value);

    if (index === -1) {
        list.push(value);
    } else {
        list.splice(index, 1);
    }

    rule.value = list;
}

function addRule() {
    rules.value.push({ field: 'tag', op: 'in', value: [] });
}

function removeRule(index) {
    rules.value.splice(index, 1);
}

function changeField(rule, field) {
    rule.field = field;
    rule.op = 'in';
    rule.value = field === 'last_interaction_days' ? 30 : [];
}

function ruleLabel(rule) {
    const field = FIELD_META[rule.field]?.label ?? rule.field;

    if (rule.field === 'last_interaction_days') {
        return `Active in last ${rule.value || 0} days`;
    }

    const options = optionsFor(rule.field);
    const names = (Array.isArray(rule.value) ? rule.value : []).map((value) => {
        const found = options.find((option) => option.id === value);

        return found ? found.name : value;
    });

    if (!names.length) {
        return `${field}: anyone`;
    }

    return `${field} ${rule.op === 'not_in' ? 'is none of' : 'is any of'} ${names.join(', ')}`;
}

function schedulePreview() {
    window.clearTimeout(previewTimer);
    previewing.value = true;

    previewTimer = window.setTimeout(async () => {
        try {
            const { data } = await axios.post(route('segments.preview', { workspace: workspaceSlug.value }), {
                rules: rules.value,
            });
            previewCount.value = data.count;
        } catch (error) {
            previewCount.value = null;
        } finally {
            previewing.value = false;
        }
    }, 300);
}

watch(rules, schedulePreview, { deep: true });

function openCreate() {
    editingId.value = null;
    name.value = '';
    rules.value = [{ field: 'tag', op: 'in', value: [] }];
    errors.value = {};
    previewCount.value = props.optedInCount;
    showBuilder.value = true;
    schedulePreview();
}

function openEdit(segment) {
    editingId.value = segment.id;
    name.value = segment.name;
    rules.value = (segment.filters?.all ?? []).map((rule) => ({
        field: rule.field,
        op: rule.op ?? 'in',
        value: rule.value ?? [],
    }));
    errors.value = {};
    previewCount.value = segment.count ?? null;
    showBuilder.value = true;
    schedulePreview();
}

function save() {
    const payload = { name: name.value, rules: rules.value };
    const options = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (bag) => (errors.value = bag),
        onSuccess: () => (showBuilder.value = false),
    };

    if (editingId.value) {
        router.patch(route('segments.update', { workspace: workspaceSlug.value, segment: editingId.value }), payload, options);
    } else {
        router.post(route('segments.store', { workspace: workspaceSlug.value }), payload, options);
    }
}

function destroy(segment) {
    if (!window.confirm(`Delete the audience “${segment.name}”?`)) {
        return;
    }

    router.delete(route('segments.destroy', { workspace: workspaceSlug.value, segment: segment.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-5xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Audiences</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Save reusable audiences, then pick one when you launch a broadcast. Opt-outs are always excluded.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                        @click="openCreate"
                    >
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>New Audience</span>
                    </button>
                </div>

                <!-- Saved audiences -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                        <h3 class="text-sm font-bold text-slate-800">Saved audiences</h3>
                        <span class="text-[11px] font-semibold text-slate-400">
                            {{ optedInCount.toLocaleString() }} opted-in contact{{ optedInCount === 1 ? '' : 's' }} total
                        </span>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <div v-for="segment in segments" :key="segment.id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-bold text-slate-900">{{ segment.name }}</h4>
                                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold text-brand-700">
                                        {{ (segment.count ?? 0).toLocaleString() }} match{{ segment.count === 1 ? '' : 'es' }}
                                    </span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <template v-if="segment.filters?.all?.length">
                                        <span
                                            v-for="(rule, index) in segment.filters.all"
                                            :key="index"
                                            class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-500"
                                        >
                                            {{ ruleLabel(rule) }}
                                        </span>
                                    </template>
                                    <span v-else class="text-[11px] text-slate-400">All opted-in contacts</span>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                    @click="openEdit(segment)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-50"
                                    @click="destroy(segment)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>

                        <div v-if="!segments.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-filter text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No saved audiences yet</p>
                            <p class="text-xs text-slate-400">
                                Build one from tags, pipeline stage, city, channel or recent activity to target your broadcasts.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Builder -->
        <div v-if="showBuilder" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-filter text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">
                            {{ editingId ? 'Edit audience' : 'New audience' }}
                        </h3>
                    </div>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="showBuilder = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="text-xs">
                    <label class="mb-1 block font-semibold text-slate-700">Audience name</label>
                    <input
                        v-model="name"
                        type="text"
                        placeholder="e.g., Mumbai VIP customers"
                        class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                    />
                    <p v-if="errors.name" class="mt-1 text-[11px] text-rose-600">{{ errors.name }}</p>
                </div>

                <div class="space-y-3">
                    <div v-for="(rule, index) in rules" :key="index" class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                        <div class="flex items-center gap-2">
                            <select
                                :value="rule.field"
                                class="rounded-lg border border-slate-200 py-1.5 pl-2 pr-6 text-[11px] font-semibold text-slate-600"
                                @change="changeField(rule, $event.target.value)"
                            >
                                <option v-for="(meta, field) in FIELD_META" :key="field" :value="field">{{ meta.label }}</option>
                            </select>

                            <select
                                v-if="rule.field === 'tag' || rule.field === 'pipeline_stage'"
                                v-model="rule.op"
                                class="rounded-lg border border-slate-200 py-1.5 pl-2 pr-6 text-[11px] font-semibold text-slate-600"
                            >
                                <option value="in">is any of</option>
                                <option value="not_in">is none of</option>
                            </select>

                            <button
                                type="button"
                                class="ml-auto text-slate-400 transition hover:text-rose-500"
                                title="Remove rule"
                                @click="removeRule(index)"
                            >
                                <i class="fa-solid fa-trash text-[11px]"></i>
                            </button>
                        </div>

                        <div v-if="rule.field === 'last_interaction_days'" class="mt-2">
                            <input
                                v-model.number="rule.value"
                                type="number"
                                min="1"
                                max="3650"
                                class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-xs"
                            />
                            <span class="ml-2 text-[11px] text-slate-500">days</span>
                        </div>

                        <div v-else class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="option in optionsFor(rule.field)"
                                :key="option.id"
                                type="button"
                                class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition"
                                :class="isSelected(rule, option.id)
                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                    : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'"
                                @click="toggleValue(rule, option.id)"
                            >
                                {{ option.name }}
                            </button>
                            <span v-if="!optionsFor(rule.field).length" class="text-[11px] text-slate-400">
                                Nothing to choose from yet — add tags or contacts first.
                            </span>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-[11px] font-semibold text-slate-500 transition hover:border-brand-300 hover:text-brand-600"
                        @click="addRule"
                    >
                        <i class="fa-solid fa-plus text-[10px]"></i>
                        Add another rule
                    </button>
                </div>

                <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <span class="text-[11px] font-semibold text-slate-500">Audience size</span>
                    <span class="text-xs font-bold text-slate-800">
                        <i v-if="previewing" class="fa-solid fa-circle-notch fa-spin mr-1 text-slate-400"></i>
                        <template v-if="previewCount === null">—</template>
                        <template v-else>{{ previewCount.toLocaleString() }} opted-in contact{{ previewCount === 1 ? '' : 's' }}</template>
                    </span>
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                        @click="showBuilder = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                        :disabled="saving || !name.trim()"
                        @click="save"
                    >
                        <i class="fa-solid" :class="saving ? 'fa-circle-notch fa-spin' : 'fa-floppy-disk'"></i>
                        {{ editingId ? 'Save changes' : 'Save audience' }}
                    </button>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
