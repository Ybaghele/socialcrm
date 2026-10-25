<template>
    <Modal :show="show" maxWidth="lg" @close="close">
        <div class="flex max-h-[85vh] flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Tags</h2>
                    <p class="text-xs text-slate-500">
                        {{ contact ? `Organise ${contact.name} with workspace tags.` : 'Manage workspace tags.' }}
                    </p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="flex shrink-0 border-b border-slate-100 px-6">
                <button
                    type="button"
                    class="-mb-px border-b-2 px-1 py-3 text-xs font-bold transition"
                    :class="tab === 'assign' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-400 hover:text-slate-600'"
                    @click="tab = 'assign'"
                >
                    Assign to contact
                </button>
                <button
                    type="button"
                    class="-mb-px ml-5 border-b-2 px-1 py-3 text-xs font-bold transition"
                    :class="tab === 'manage' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-400 hover:text-slate-600'"
                    @click="tab = 'manage'"
                >
                    Manage tags
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                <div v-if="tab === 'assign'">
                    <div v-if="Object.keys(assignmentErrors).length" class="mb-3 rounded-lg bg-rose-50 p-2.5 text-[11px] text-rose-700">
                        <p v-for="(message, key) in assignmentErrors" :key="key">{{ message }}</p>
                    </div>

                    <div v-if="tags.length" class="space-y-1.5">
                        <label
                            v-for="tag in tags"
                            :key="tag.id"
                            class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-slate-200 px-3 py-2.5 transition hover:bg-slate-50"
                        >
                            <input
                                v-model="selectedTagIds"
                                type="checkbox"
                                :value="tag.id"
                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            />
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }"></span>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700">{{ tag.name }}</span>
                        </label>
                    </div>

                    <div v-else class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center">
                        <i class="fa-solid fa-tags text-xl text-slate-300"></i>
                        <p class="mt-2 text-xs font-semibold text-slate-500">No tags in this workspace</p>
                        <p class="mt-1 text-[11px] text-slate-400">Create your first tag in the Manage tags tab.</p>
                        <button type="button" class="mt-3 text-[11px] font-bold text-brand-700" @click="tab = 'manage'">
                            Go to Manage tags
                        </button>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600" @click="close">
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="savingAssignment || !contact"
                            class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                            @click="saveAssignment"
                        >
                            {{ savingAssignment ? 'Saving…' : 'Save tags' }}
                        </button>
                    </div>
                </div>

                <div v-else>
                    <div class="space-y-1.5">
                        <div
                            v-for="tag in tags"
                            :key="tag.id"
                            class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2"
                            :class="editingId === tag.id ? 'border-brand-200 bg-brand-50/60' : 'border-slate-100'"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }"></span>
                                <span class="truncate text-sm font-medium text-slate-700">{{ tag.name }}</span>
                                <span v-if="editingId === tag.id" class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-brand-600">editing</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    class="rounded p-1 text-slate-300 transition hover:bg-white hover:text-brand-600"
                                    title="Edit tag"
                                    @click="editTag(tag)"
                                >
                                    <i class="fa-solid fa-pen text-[10px]"></i>
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-slate-300 transition hover:bg-white hover:text-rose-600"
                                    title="Delete tag"
                                    @click="destroyTag(tag)"
                                >
                                    <i class="fa-solid fa-trash text-[10px]"></i>
                                </button>
                            </div>
                        </div>

                        <p v-if="!tags.length" class="rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center text-xs text-slate-400">
                            No tags yet. Add the first one below.
                        </p>
                    </div>

                    <form class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3" @submit.prevent="submitTag">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                {{ editingId ? 'Edit tag' : 'New tag' }}
                            </p>
                            <button
                                v-if="editingId"
                                type="button"
                                class="text-[11px] font-semibold text-slate-500 hover:text-slate-700"
                                @click="resetTagForm"
                            >
                                Cancel edit
                            </button>
                        </div>

                        <div v-if="Object.keys(tagErrors).length" class="mt-2 rounded-lg bg-rose-50 p-2.5 text-[11px] text-rose-700">
                            <p v-for="(message, key) in tagErrors" :key="key">{{ message }}</p>
                        </div>

                        <div class="mt-2 flex items-center gap-2">
                            <input
                                v-model="color"
                                type="color"
                                class="h-9 w-10 cursor-pointer rounded-lg border border-slate-200 bg-white p-1"
                                title="Tag colour"
                            />
                            <input
                                v-model="name"
                                type="text"
                                maxlength="50"
                                placeholder="VIP"
                                class="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <button
                                type="submit"
                                :disabled="savingTag || !name.trim()"
                                class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                            >
                                {{ savingTag ? 'Saving…' : editingId ? 'Save' : 'Add' }}
                            </button>
                        </div>
                        <p class="mt-1.5 text-[10px] text-slate-400">The # is optional. Tags are shared across this workspace.</p>
                    </form>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    tags: { type: Array, default: () => [] },
    contact: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const DEFAULT_COLOR = '#64748b';

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const tab = ref('assign');
const selectedTagIds = ref([]);
const assignmentErrors = ref({});
const savingAssignment = ref(false);

const editingId = ref(null);
const name = ref('');
const color = ref(DEFAULT_COLOR);
const tagErrors = ref({});
const savingTag = ref(false);

function syncSelected() {
    selectedTagIds.value = (props.contact?.tags ?? []).map((tag) => tag.id);
}

function close() {
    emit('close');
}

function saveAssignment() {
    if (!props.contact || savingAssignment.value) {
        return;
    }

    savingAssignment.value = true;
    assignmentErrors.value = {};

    router.patch(
        route('contacts.tags.sync', { workspace: workspaceSlug.value, contact: props.contact.id }),
        { tag_ids: selectedTagIds.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => close(),
            onError: (validationErrors) => {
                assignmentErrors.value = validationErrors;
            },
            onFinish: () => {
                savingAssignment.value = false;
            },
        },
    );
}

function resetTagForm() {
    editingId.value = null;
    name.value = '';
    color.value = DEFAULT_COLOR;
    tagErrors.value = {};
}

function editTag(tag) {
    editingId.value = tag.id;
    name.value = tag.name;
    color.value = tag.color || DEFAULT_COLOR;
    tagErrors.value = {};
}

function submitTag() {
    if (savingTag.value || !name.value.trim()) {
        return;
    }

    savingTag.value = true;

    const payload = {
        name: name.value.trim(),
        color: color.value,
    };

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => resetTagForm(),
        onError: (validationErrors) => {
            tagErrors.value = validationErrors;
        },
        onFinish: () => {
            savingTag.value = false;
        },
    };

    if (editingId.value) {
        router.patch(route('tags.update', { workspace: workspaceSlug.value, tag: editingId.value }), payload, options);

        return;
    }

    router.post(route('tags.store', { workspace: workspaceSlug.value }), payload, options);
}

function destroyTag(tag) {
    if (!window.confirm(`Delete the tag "${tag.name}"? It will be removed from every contact.`)) {
        return;
    }

    if (editingId.value === tag.id) {
        resetTagForm();
    }

    router.delete(route('tags.destroy', { workspace: workspaceSlug.value, tag: tag.id }), {
        preserveScroll: true,
        preserveState: true,
    });
}

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        tab.value = 'assign';
        assignmentErrors.value = {};
        syncSelected();
    },
);

watch(
    () => props.contact?.id,
    () => {
        if (props.show) {
            syncSelected();
        }
    },
);

watch(
    () => props.tags,
    (tags) => {
        const ids = new Set(tags.map((tag) => tag.id));
        selectedTagIds.value = selectedTagIds.value.filter((id) => ids.has(id));
    },
);
</script>
