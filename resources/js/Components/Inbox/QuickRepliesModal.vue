<template>
    <Modal :show="show" maxWidth="2xl" @close="close">
        <div class="flex max-h-[85vh] flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Quick replies</h2>
                    <p class="text-xs text-slate-500">Reusable replies shared with everyone in this workspace.</p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="border-b border-slate-100 p-4 md:border-b-0 md:border-r">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Saved replies</p>
                        <span class="text-[11px] font-semibold text-slate-400">{{ responses.length }}</span>
                    </div>

                    <div class="space-y-1.5">
                        <div
                            v-for="response in responses"
                            :key="response.id"
                            class="group rounded-lg border px-3 py-2 transition"
                            :class="editingId === response.id ? 'border-brand-200 bg-brand-50/60' : 'border-slate-100 hover:bg-slate-50'"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <button type="button" class="min-w-0 flex-1 text-left" @click="edit(response)">
                                    <span class="block truncate text-xs font-bold text-slate-700">{{ response.title }}</span>
                                    <span v-if="response.shortcut" class="mt-0.5 block font-mono text-[10px] font-semibold text-brand-600">{{ response.shortcut }}</span>
                                    <span class="mt-0.5 block truncate text-[11px] text-slate-400">{{ response.body }}</span>
                                </button>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button
                                        type="button"
                                        class="rounded p-1 text-slate-300 transition hover:bg-white hover:text-brand-600"
                                        title="Edit quick reply"
                                        @click="edit(response)"
                                    >
                                        <i class="fa-solid fa-pen text-[10px]"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded p-1 text-slate-300 transition hover:bg-white hover:text-rose-600"
                                        title="Delete quick reply"
                                        @click="destroy(response)"
                                    >
                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="!responses.length" class="rounded-lg border border-dashed border-slate-200 px-4 py-8 text-center">
                            <i class="fa-regular fa-lightbulb text-xl text-slate-300"></i>
                            <p class="mt-2 text-xs font-semibold text-slate-500">No quick replies yet</p>
                            <p class="mt-1 text-[11px] text-slate-400">Add your first one with the form.</p>
                        </div>
                    </div>
                </div>

                <form class="p-4" @submit.prevent="submit">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                            {{ editingId ? 'Edit reply' : 'New reply' }}
                        </p>
                        <button
                            v-if="editingId"
                            type="button"
                            class="text-[11px] font-semibold text-slate-500 hover:text-slate-700"
                            @click="startCreate"
                        >
                            Cancel edit
                        </button>
                    </div>

                    <div v-if="Object.keys(errors).length" class="mt-2 rounded-lg bg-rose-50 p-2.5 text-[11px] text-rose-700">
                        <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
                    </div>

                    <div class="mt-3 space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-700">Title *</label>
                            <input
                                v-model="title"
                                type="text"
                                maxlength="120"
                                placeholder="Shipping time"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-700">Shortcut</label>
                            <input
                                v-model="shortcut"
                                type="text"
                                maxlength="32"
                                placeholder="/shipping"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                            <p class="mt-1 text-[10px] text-slate-400">Optional. Letters, numbers, dashes and underscores.</p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold text-slate-700">Message *</label>
                                <span class="text-[10px] text-slate-400">{{ body.length }}/4096</span>
                            </div>
                            <textarea
                                v-model="body"
                                rows="5"
                                maxlength="4096"
                                placeholder="Hi! Orders usually ship within 2 business days."
                                class="mt-1 w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                            ></textarea>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600" @click="close">
                            Close
                        </button>
                        <button
                            type="submit"
                            :disabled="saving || !title.trim() || !body.trim()"
                            class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                        >
                            {{ saving ? 'Saving…' : editingId ? 'Save changes' : 'Add quick reply' }}
                        </button>
                    </div>
                </form>
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
    responses: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const editingId = ref(null);
const title = ref('');
const shortcut = ref('');
const body = ref('');
const errors = ref({});
const saving = ref(false);

function resetForm() {
    editingId.value = null;
    title.value = '';
    shortcut.value = '';
    body.value = '';
    errors.value = {};
}

function startCreate() {
    resetForm();
}

function edit(response) {
    editingId.value = response.id;
    title.value = response.title;
    shortcut.value = response.shortcut ?? '';
    body.value = response.body;
    errors.value = {};
}

function close() {
    emit('close');
}

function submit() {
    if (saving.value || !title.value.trim() || !body.value.trim()) {
        return;
    }

    saving.value = true;

    const payload = {
        title: title.value.trim(),
        body: body.value.trim(),
        shortcut: shortcut.value.trim() || null,
    };

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => resetForm(),
        onError: (validationErrors) => {
            errors.value = validationErrors;
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (editingId.value) {
        router.patch(
            route('canned-responses.update', { workspace: workspaceSlug.value, cannedResponse: editingId.value }),
            payload,
            options,
        );

        return;
    }

    router.post(route('canned-responses.store', { workspace: workspaceSlug.value }), payload, options);
}

function destroy(response) {
    if (!window.confirm(`Delete the quick reply "${response.title}"?`)) {
        return;
    }

    if (editingId.value === response.id) {
        resetForm();
    }

    router.delete(route('canned-responses.destroy', { workspace: workspaceSlug.value, cannedResponse: response.id }), {
        preserveScroll: true,
        preserveState: true,
    });
}

watch(
    () => props.show,
    (show) => {
        if (!show) {
            resetForm();
        }
    },
);
</script>
