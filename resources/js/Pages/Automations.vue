<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    automations: { type: Array, required: true },
    businessHours: { type: Object, required: true },
    options: { type: Object, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const TYPES = [
    { value: 'auto_reply', label: 'Auto-replies', icon: 'fa-solid fa-comments', blurb: 'Answer an inbound message straight away — by keyword, first message or out of hours.' },
    { value: 'routing', label: 'Routing', icon: 'fa-solid fa-people-arrows', blurb: 'Hand a new conversation to the right agent without anyone watching the inbox.' },
    { value: 'sequence', label: 'Follow-ups', icon: 'fa-solid fa-clock-rotate-left', blurb: 'Chase a thread that has gone quiet with timed template messages.' },
];

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MERGE_TAGS = ['{{first_name}}', '{{name}}', '{{phone}}', '{{city}}'];

const activeTab = ref('auto_reply');
const showModal = ref(false);
const editing = ref(null);
const form = ref(blank('auto_reply'));
const errors = ref({});
const saving = ref(false);

const hours = ref({ ...props.businessHours, days: [...(props.businessHours.days ?? [])] });
const savingHours = ref(false);

const templates = computed(() => props.options.templates ?? []);
const users = computed(() => props.options.users ?? []);

function blank(type) {
    return {
        type,
        name: '',
        status: 'active',
        priority: 0,
        channels: [],
        keywords: '',
        onlyFirstMessage: false,
        outsideBusinessHours: false,
        reply: '',
        assignUserId: null,
        strategy: 'round_robin',
        userId: null,
        userIds: [],
        noReplyAfterHours: 24,
        steps: [{ delayMinutes: 0, templateId: null, variables: [] }],
    };
}

function fromAutomation(automation) {
    const conditions = automation.conditions ?? {};
    const actions = automation.actions ?? {};

    return {
        type: automation.type,
        name: automation.name,
        status: automation.status,
        priority: automation.priority ?? 0,
        channels: [...(conditions.channels ?? [])],
        keywords: (conditions.keywords ?? []).join(', '),
        onlyFirstMessage: !!conditions.only_first_message,
        outsideBusinessHours: !!conditions.outside_business_hours,
        reply: actions.reply ?? '',
        assignUserId: actions.assign_user_id ?? null,
        strategy: actions.strategy ?? 'round_robin',
        userId: actions.user_id ?? null,
        userIds: [...(actions.user_ids ?? [])],
        noReplyAfterHours: conditions.no_reply_after_hours ?? 24,
        steps: (automation.steps ?? []).map((step) => ({
            delayMinutes: step.delayMinutes,
            templateId: step.templateId,
            variables: [...(step.variables ?? [])],
        })),
    };
}

const list = computed(() => props.automations.filter((automation) => automation.type === activeTab.value));

function countFor(type) {
    return props.automations.filter((automation) => automation.type === type).length;
}

function userFor(id) {
    return users.value.find((user) => user.id === id)?.name ?? 'an agent';
}

function channelLabel(value) {
    return (props.options.channels ?? []).find((channel) => channel.value === value)?.label ?? value;
}

function templateFor(id) {
    return templates.value.find((template) => template.id === id);
}

function conditionChips(automation) {
    const conditions = automation.conditions ?? {};
    const chips = [];

    if (conditions.keywords?.length) {
        chips.push(`Keywords: ${conditions.keywords.join(', ')}`);
    }

    if (conditions.only_first_message) {
        chips.push('First message only');
    }

    if (conditions.outside_business_hours) {
        chips.push('Outside business hours');
    }

    if (conditions.no_reply_after_hours) {
        chips.push(`No reply for ${conditions.no_reply_after_hours}h`);
    }

    chips.push(conditions.channels?.length ? conditions.channels.map(channelLabel).join(' + ') : 'All channels');

    if (!conditions.keywords?.length && !conditions.only_first_message && !conditions.outside_business_hours && automation.type === 'auto_reply') {
        chips.push('Any message');
    }

    return chips;
}

function actionSummary(automation) {
    const actions = automation.actions ?? {};

    if (automation.type === 'auto_reply') {
        const reply = (actions.reply ?? '').slice(0, 60);
        const suffix = (actions.reply ?? '').length > 60 ? '…' : '';
        const hand = actions.assign_user_id ? ` · assign to ${userFor(actions.assign_user_id)}` : '';

        return `Reply “${reply}${suffix}”${hand}`;
    }

    if (automation.type === 'routing') {
        if (actions.strategy === 'specific_user') {
            return `Assign to ${userFor(actions.user_id)}`;
        }

        const pool = (actions.user_ids ?? []).map(userFor);
        const who = pool.length ? pool.join(', ') : 'any available agent';

        return actions.strategy === 'least_busy' ? `Least busy of ${who}` : `Round-robin across ${who}`;
    }

    const count = (automation.steps ?? []).length;

    return `${count} step${count === 1 ? '' : 's'}, first after ${automation.steps?.[0]?.delayMinutes ?? 0} min`;
}

// ---- Modal ----------------------------------------------------------------

function openCreate() {
    editing.value = null;
    form.value = blank(activeTab.value);
    errors.value = {};
    showModal.value = true;
}

function openEdit(automation) {
    editing.value = automation;
    form.value = fromAutomation(automation);
    errors.value = {};
    showModal.value = true;
}

function toggleInList(key, value) {
    const list = form.value[key];
    const index = list.indexOf(value);

    if (index === -1) {
        list.push(value);
    } else {
        list.splice(index, 1);
    }
}

function addStep() {
    form.value.steps.push({ delayMinutes: 1440, templateId: null, variables: [] });
}

function removeStep(index) {
    form.value.steps.splice(index, 1);
}

// Keep the variable inputs in step with the chosen template's placeholder count.
function onTemplateChange(step) {
    const template = templateFor(step.templateId);
    const count = template?.placeholders ?? 0;

    step.variables = Array.from({ length: count }, (_, index) => step.variables[index] ?? '');
}

function save() {
    const payload = { ...form.value };
    const options = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (bag) => (errors.value = bag),
        onSuccess: () => {
            showModal.value = false;
            errors.value = {};
        },
    };

    if (editing.value) {
        router.patch(route('automations.update', { workspace: workspaceSlug.value, automation: editing.value.id }), payload, options);
    } else {
        router.post(route('automations.store', { workspace: workspaceSlug.value }), payload, options);
    }
}

function toggle(automation) {
    router.post(route('automations.toggle', { workspace: workspaceSlug.value, automation: automation.id }), {}, { preserveScroll: true });
}

function destroy(automation) {
    if (!window.confirm(`Delete the automation “${automation.name}”?`)) {
        return;
    }

    router.delete(route('automations.destroy', { workspace: workspaceSlug.value, automation: automation.id }), { preserveScroll: true });
}

// ---- Business hours ------------------------------------------------------

function toggleDay(day) {
    const index = hours.value.days.indexOf(day);

    if (index === -1) {
        hours.value.days.push(day);
    } else {
        hours.value.days.splice(index, 1);
    }
}

function saveHours() {
    router.post(route('automations.business-hours', { workspace: workspaceSlug.value }), hours.value, {
        preserveScroll: true,
        onStart: () => (savingHours.value = true),
        onFinish: () => (savingHours.value = false),
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
                        <h2 class="text-xl font-bold text-slate-900">Automations</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Reply by keyword, route new conversations to the right agent, and chase threads that go quiet.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                        @click="openCreate"
                    >
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>New automation</span>
                    </button>
                </div>

                <!-- Business hours -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-clock text-slate-400"></i>
                            <h3 class="text-sm font-bold text-slate-800">Business hours</h3>
                        </div>
                        <span class="text-[11px] text-slate-400">Used by “outside business hours” rules</span>
                    </div>

                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">Timezone</label>
                            <select v-model="hours.timezone" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                                <option v-for="zone in options.timezones" :key="zone" :value="zone">{{ zone }}</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-600">Opens</label>
                                <input v-model="hours.start" type="time" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-600">Closes</label>
                                <input v-model="hours.end" type="time" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                            </div>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">Open days</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="(day, index) in DAYS"
                                    :key="day"
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-[11px] font-semibold transition"
                                    :class="hours.days.includes(index)
                                        ? 'border-brand-500 bg-brand-50 text-brand-700'
                                        : 'border-slate-200 bg-white text-slate-400 hover:border-slate-300'"
                                    @click="toggleDay(index)"
                                >
                                    {{ day }}
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between sm:col-span-2">
                            <p class="text-[11px] text-slate-400">Messages arriving outside this window count as out of hours.</p>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                :disabled="savingHours"
                                @click="saveHours"
                            >
                                <i class="fa-solid" :class="savingHours ? 'fa-circle-notch fa-spin' : 'fa-floppy-disk'"></i>
                                Save hours
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
                    <button
                        v-for="type in TYPES"
                        :key="type.value"
                        type="button"
                        class="flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold transition"
                        :class="activeTab === type.value ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-50'"
                        @click="activeTab = type.value"
                    >
                        <i :class="type.icon"></i>
                        <span>{{ type.label }}</span>
                        <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{{ countFor(type.value) }}</span>
                    </button>
                </div>

                <p class="-mt-3 text-[11px] text-slate-400">{{ TYPES.find((type) => type.value === activeTab)?.blurb }}</p>

                <!-- Rules -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="divide-y divide-slate-100">
                        <div v-for="automation in list" :key="automation.id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-bold text-slate-900">{{ automation.name }}</h4>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                                        :class="automation.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ automation.status === 'active' ? 'Active' : 'Paused' }}
                                    </span>
                                    <span v-if="automation.priority" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                                        Priority {{ automation.priority }}
                                    </span>
                                </div>

                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="(chip, index) in conditionChips(automation)"
                                        :key="index"
                                        class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-500"
                                    >
                                        {{ chip }}
                                    </span>
                                </div>

                                <p class="mt-2 text-[11px] text-slate-500">{{ actionSummary(automation) }}</p>
                                <p class="mt-1 text-[10px] text-slate-400">
                                    Ran {{ automation.runCount }} time{{ automation.runCount === 1 ? '' : 's' }}
                                    <template v-if="automation.contactCount"> · {{ automation.contactCount }} contact{{ automation.contactCount === 1 ? '' : 's' }}</template>
                                    <template v-if="automation.lastRunAt"> · last {{ new Date(automation.lastRunAt).toLocaleString() }}</template>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                    @click="toggle(automation)"
                                >
                                    {{ automation.status === 'active' ? 'Pause' : 'Resume' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                    @click="openEdit(automation)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-50"
                                    @click="destroy(automation)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>

                        <div v-if="!list.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-bolt text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No {{ TYPES.find((type) => type.value === activeTab)?.label.toLowerCase() }} yet</p>
                            <p class="text-xs text-slate-400">{{ TYPES.find((type) => type.value === activeTab)?.blurb }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Editor -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="max-h-[90vh] w-full max-w-xl space-y-4 overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-bolt text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">{{ editing ? 'Edit automation' : 'New automation' }}</h3>
                    </div>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="showModal = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- Type (only on create, so an existing rule cannot silently change shape) -->
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="type in TYPES"
                        :key="type.value"
                        type="button"
                        class="flex flex-col items-center gap-1 rounded-xl border px-2 py-3 text-[11px] font-semibold transition disabled:opacity-50"
                        :class="form.type === type.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                        :disabled="!!editing"
                        @click="form.type = type.value"
                    >
                        <i :class="type.icon"></i>
                        <span>{{ type.label }}</span>
                    </button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Name</label>
                        <input v-model="form.name" type="text" placeholder="e.g., Pricing questions" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        <p v-if="errors.name" class="mt-1 text-[11px] text-rose-600">{{ errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Status</label>
                        <select v-model="form.status" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Priority (lower runs first)</label>
                        <input v-model.number="form.priority" type="number" min="0" max="9999" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        <p v-if="errors.priority" class="mt-1 text-[11px] text-rose-600">{{ errors.priority }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Channels</label>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="channel in options.channels"
                                :key="channel.value"
                                type="button"
                                class="rounded-full border px-3 py-1 text-[11px] font-semibold transition"
                                :class="form.channels.includes(channel.value)
                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                    : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'"
                                @click="toggleInList('channels', channel.value)"
                            >
                                {{ channel.label }}
                            </button>
                        </div>
                        <p class="mt-1 text-[10px] text-slate-400">Leave all off to match every channel.</p>
                    </div>

                    <div v-if="form.type !== 'sequence'" class="sm:col-span-2">
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Business hours</label>
                        <label class="flex items-center gap-2 text-[11px] text-slate-600">
                            <input v-model="form.outsideBusinessHours" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                            Only when the workspace is closed
                        </label>
                    </div>
                </div>

                <!-- Auto-reply fields -->
                <div v-if="form.type === 'auto_reply'" class="space-y-3 border-t border-slate-100 pt-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Keywords (comma separated)</label>
                        <input v-model="form.keywords" type="text" placeholder="price, cost, how much" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                        <p class="mt-1 text-[10px] text-slate-400">Match any of these, case-insensitive. Leave blank to react to every message (a catch-all rule belongs last).</p>
                    </div>

                    <label class="flex items-center gap-2 text-[11px] text-slate-600">
                        <input v-model="form.onlyFirstMessage" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                        Only the contact's first message (a welcome)
                    </label>

                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Reply</label>
                        <textarea v-model="form.reply" rows="3" placeholder="Thanks for reaching out! Someone will be with you shortly." class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea>
                        <p v-if="errors.reply" class="mt-1 text-[11px] text-rose-600">{{ errors.reply }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Also hand the thread to</label>
                        <select v-model="form.assignUserId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option :value="null">Nobody in particular</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Routing fields -->
                <div v-if="form.type === 'routing'" class="space-y-3 border-t border-slate-100 pt-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Assign to</label>
                        <select v-model="form.strategy" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option value="round_robin">Round-robin — the agent with the fewest conversations</option>
                            <option value="least_busy">Least busy — fewest open conversations</option>
                            <option value="specific_user">One specific person</option>
                        </select>
                    </div>

                    <div v-if="form.strategy === 'specific_user'">
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Person</label>
                        <select v-model="form.userId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option :value="null">Choose someone…</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <p v-if="errors.userId" class="mt-1 text-[11px] text-rose-600">{{ errors.userId }}</p>
                    </div>

                    <div v-else>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Pick from</label>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="user in users"
                                :key="user.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-[11px] font-semibold transition"
                                :class="form.userIds.includes(user.id)
                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                    : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'"
                                @click="toggleInList('userIds', user.id)"
                            >
                                {{ user.name }}
                                <i v-if="!user.available" class="fa-solid fa-circle-pause ml-1 text-[9px] text-amber-500" title="Not accepting new conversations"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-[10px] text-slate-400">Leave all off to use every available agent.</p>
                    </div>
                </div>

                <!-- Sequence fields -->
                <div v-if="form.type === 'sequence'" class="space-y-3 border-t border-slate-100 pt-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Start when the contact has been silent for</label>
                        <div class="flex items-center gap-2">
                            <input v-model.number="form.noReplyAfterHours" type="number" min="1" max="720" class="w-24 rounded-lg border border-slate-200 px-3 py-2 text-xs" />
                            <span class="text-[11px] text-slate-500">hours after our last message</span>
                        </div>
                        <p v-if="errors.noReplyAfterHours" class="mt-1 text-[11px] text-rose-600">{{ errors.noReplyAfterHours }}</p>
                    </div>

                    <p v-if="!templates.length" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-700">
                        A follow-up sends approved WhatsApp templates — Meta has not approved one yet. Sync from the Templates screen first.
                    </p>

                    <div v-for="(step, index) in form.steps" :key="index" class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-slate-600">Step {{ index + 1 }}</span>
                            <button
                                v-if="form.steps.length > 1"
                                type="button"
                                class="text-slate-400 transition hover:text-rose-500"
                                @click="removeStep(index)"
                            >
                                <i class="fa-solid fa-trash text-[11px]"></i>
                            </button>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[10px] font-semibold text-slate-500">Wait (minutes after the previous step)</label>
                                <input v-model.number="step.delayMinutes" type="number" min="0" max="43200" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[10px] font-semibold text-slate-500">Template</label>
                                <select v-model="step.templateId" class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs" @change="onTemplateChange(step)">
                                    <option :value="null">Choose a template…</option>
                                    <option v-for="template in templates" :key="template.id" :value="template.id">
                                        {{ template.name }} ({{ template.category }})
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div v-if="step.variables.length" class="space-y-1.5">
                            <div v-for="(value, slot) in step.variables" :key="slot" class="flex items-center gap-2">
                                <span class="w-16 shrink-0 text-[10px] font-semibold text-slate-500">Variable {{ slot + 1 }}</span>
                                <input v-model="step.variables[slot]" type="text" :placeholder="MERGE_TAGS[0]" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs" />
                            </div>
                            <p class="text-[10px] text-slate-400">Merge tags: {{ MERGE_TAGS.join('  ') }}</p>
                        </div>
                        <p v-if="errors[`steps.${index}.templateId`]" class="text-[11px] text-rose-600">{{ errors[`steps.${index}.templateId`] }}</p>
                    </div>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-[11px] font-semibold text-slate-500 transition hover:border-brand-300 hover:text-brand-600"
                        @click="addStep"
                    >
                        <i class="fa-solid fa-plus text-[10px]"></i>
                        Add another step
                    </button>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                    <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" @click="showModal = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                        :disabled="saving || !form.name.trim()"
                        @click="save"
                    >
                        <i class="fa-solid" :class="saving ? 'fa-circle-notch fa-spin' : 'fa-floppy-disk'"></i>
                        {{ editing ? 'Save changes' : 'Create automation' }}
                    </button>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
