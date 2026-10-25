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

            <div class="grid flex-1 grid-cols-1 overflow-auto lg:grid-cols-[1fr_380px]">
                <div class="overflow-auto px-6 py-6">
                    <form class="space-y-5" @submit.prevent="submit">
                        <div v-if="Object.keys(errors).length" class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">
                            <div v-for="(message, key) in errors" :key="key">{{ message }}</div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-xs font-semibold text-slate-700">Template name *</label>
                                <input v-model="name" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm" @input="name = sanitize(name)" />
                                <p class="mt-1 text-[11px] text-slate-400">Lowercase letters, numbers and underscores.</p>
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
                                    <option v-for="account in whatsappAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                                </select>
                            </div>
                        </div>

                        <section class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-700">Header <span class="font-normal text-slate-400">(optional)</span></label>
                                    <p class="mt-0.5 text-[11px] text-slate-400">A short title shown above the message.</p>
                                </div>
                                <span class="text-[10px] tabular-nums" :class="header.length > 60 ? 'text-rose-600' : 'text-slate-400'">{{ header.length }}/60</span>
                            </div>
                            <input v-model="header" maxlength="60" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Festive offer" />
                            <p class="mt-1 text-[11px] text-slate-400">Static text only. Variables are filled in the body.</p>
                        </section>

                        <section>
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-semibold text-slate-700">Body *</label>
                                <button type="button" class="text-[11px] font-semibold text-brand-700" @click="addVar">+ Add variable</button>
                            </div>
                            <textarea
                                ref="bodyInput"
                                v-model="body"
                                rows="6"
                                maxlength="1024"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                placeholder="Hi {{1}}, enjoy {{2}} off."
                            ></textarea>
                            <div class="mt-1 flex items-center justify-between gap-3 text-[11px]">
                                <p class="text-slate-400">Variables must be sequential, starting with the first placeholder.</p>
                                <span class="shrink-0 tabular-nums" :class="body.length > 1024 ? 'text-rose-600' : 'text-slate-400'">{{ body.length }}/1024</span>
                            </div>
                            <p v-if="bodyRuleError" class="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800">
                                <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                                <span>{{ bodyRuleError }}</span>
                            </p>
                        </section>

                        <section class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-700">Footer <span class="font-normal text-slate-400">(optional)</span></label>
                                    <p class="mt-0.5 text-[11px] text-slate-400">Small print below the message, such as opt-out instructions.</p>
                                </div>
                                <span class="text-[10px] tabular-nums" :class="footer.length > 60 ? 'text-rose-600' : 'text-slate-400'">{{ footer.length }}/60</span>
                            </div>
                            <input v-model="footer" maxlength="60" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Reply STOP to unsubscribe" />
                        </section>

                        <section class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-700">Buttons <span class="font-normal text-slate-400">(optional)</span></label>
                                    <p class="mt-0.5 text-[11px] text-slate-400">Up to 10 quick replies, website links or phone calls. Quick replies are grouped first.</p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="buttons.length >= 10"
                                    @click="addButton"
                                >
                                    <i class="fa-solid fa-plus mr-1"></i> Add
                                </button>
                            </div>

                            <p v-if="!buttons.length" class="mt-3 rounded-lg border border-dashed border-slate-200 px-3 py-4 text-center text-[11px] text-slate-400">
                                No buttons added.
                            </p>

                            <div v-else class="mt-3 space-y-2">
                                <div v-for="(button, index) in buttons" :key="button.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="grid gap-2 sm:grid-cols-[150px_1fr_auto]">
                                        <select v-model="button.type" class="rounded-lg border border-slate-200 px-2.5 py-2 text-xs" @change="resetButtonTarget(button)">
                                            <option value="QUICK_REPLY">Quick reply</option>
                                            <option value="URL">Website</option>
                                            <option value="PHONE_NUMBER">Phone call</option>
                                        </select>
                                        <input v-model="button.text" maxlength="25" class="rounded-lg border border-slate-200 px-2.5 py-2 text-xs" :placeholder="buttonTextPlaceholder(button.type)" />
                                        <button type="button" class="rounded-lg border border-rose-200 px-2.5 py-2 text-xs text-rose-600 transition hover:bg-rose-50" @click="removeButton(index)">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>

                                    <input
                                        v-if="button.type === 'URL'"
                                        v-model="button.url"
                                        type="url"
                                        maxlength="2000"
                                        class="mt-2 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-xs"
                                        placeholder="https://example.com/offer"
                                    />
                                    <input
                                        v-else-if="button.type === 'PHONE_NUMBER'"
                                        v-model="button.phone_number"
                                        type="tel"
                                        maxlength="20"
                                        class="mt-2 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-xs"
                                        placeholder="+919820044821"
                                    />
                                    <p v-if="button.type === 'URL'" class="mt-1 text-[11px] text-slate-400">Static URL. Dynamic URL variables are not supported yet.</p>
                                    <p v-else-if="button.type === 'PHONE_NUMBER'" class="mt-1 text-[11px] text-slate-400">Include the country code.</p>
                                </div>
                            </div>
                        </section>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs" @click="close">Cancel</button>
                            <button type="submit" :disabled="submitting" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">
                                {{ submitting ? 'Submitting…' : 'Submit to Meta' }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="hidden border-l border-slate-100 bg-slate-50 p-6 lg:block">
                    <p class="text-xs font-semibold text-slate-900">Preview</p>
                    <div class="mt-3 rounded-[1.5rem] bg-slate-900 p-2">
                        <div class="rounded-[1.2rem] bg-[#efeae2] p-3">
                            <div class="mb-2 rounded-lg bg-[#075e54] px-2 py-1 text-[10px] text-white">SocialDesk</div>
                            <div class="max-w-[85%] rounded-lg bg-[#d9fdd3] p-2 text-xs text-slate-800">
                                <p v-if="header.trim()" class="mb-1 whitespace-pre-wrap font-bold">{{ header }}</p>
                                <p class="whitespace-pre-wrap">{{ body || 'Your message' }}</p>
                                <p v-if="footer.trim()" class="mt-1 whitespace-pre-wrap text-[10px] text-slate-500">{{ footer }}</p>
                            </div>
                            <div v-if="buttons.length" class="mt-1 max-w-[85%] space-y-1">
                                <div
                                    v-for="button in orderedButtons"
                                    :key="button.id"
                                    class="rounded-lg bg-white px-2 py-1.5 text-center text-[11px] font-semibold text-[#007a5a]"
                                >
                                    {{ button.text || buttonTextPlaceholder(button.type) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-[11px] leading-relaxed text-slate-400">
                        Meta reviews every template. You will see its status here after syncing.
                    </p>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
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
const header = ref('');
const body = ref('');
const footer = ref('');
const buttons = ref([]);
const bodyInput = ref(null);
const errors = ref({});
const submitting = ref(false);

let buttonSequence = 0;

const variableCount = computed(() => {
    const numbers = [...body.value.matchAll(/\{\{(\d+)\}\}/g)].map((match) => parseInt(match[1], 10));

    return numbers.length ? Math.max(...numbers) : 0;
});

const staticWordCount = computed(() => {
    const staticText = body.value.replace(/\{\{\d+\}\}/g, ' ');
    return staticText.trim().split(/\s+/).filter((word) => /[\p{L}\p{N}]/u.test(word)).length;
});

const bodyRuleError = computed(() => {
    const trimmed = body.value.trim();

    if (!trimmed || variableCount.value === 0) {
        return '';
    }

    if (/^\{\{\d+\}\}/.test(trimmed)) {
        return 'A template body cannot start with a variable. Add fixed text before {{1}}.';
    }

    if (/\{\{\d+\}\}$/.test(trimmed)) {
        return 'A template body cannot end with a variable. Add fixed text after the last variable.';
    }

    const requiredWords = variableCount.value * 2 + 1;

    if (staticWordCount.value < requiredWords) {
        const missing = requiredWords - staticWordCount.value;
        const variableLabel = variableCount.value === 1 ? 'variable' : 'variables';
        const wordLabel = missing === 1 ? 'word' : 'words';

        return `Meta needs at least ${requiredWords} fixed words for ${variableCount.value} ${variableLabel}. Add ${missing} more ${wordLabel} or remove a variable.`;
    }

    return '';
});

const orderedButtons = computed(() => [
    ...buttons.value.filter((button) => button.type === 'QUICK_REPLY'),
    ...buttons.value.filter((button) => button.type !== 'QUICK_REPLY'),
]);

function sanitize(value) {
    return value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '').replace(/_{2,}/g, '_').slice(0, 512);
}

function addVar() {
    const insertion = `{{${variableCount.value + 1}}}`;
    const input = bodyInput.value;

    if (input && typeof input.selectionStart === 'number') {
        const start = input.selectionStart;
        const end = input.selectionEnd;

        body.value = body.value.slice(0, start) + insertion + body.value.slice(end);

        nextTick(() => {
            input.focus();
            input.setSelectionRange(start + insertion.length, start + insertion.length);
        });

        return;
    }

    body.value = (body.value + ' ' + insertion).trim();
}

function emptyButton() {
    return {
        id: ++buttonSequence,
        type: 'QUICK_REPLY',
        text: '',
        url: '',
        phone_number: '',
    };
}

function addButton() {
    if (buttons.value.length >= 10) {
        return;
    }

    buttons.value.push(emptyButton());
}

function removeButton(index) {
    buttons.value.splice(index, 1);
}

function resetButtonTarget(button) {
    button.url = '';
    button.phone_number = '';
}

function buttonTextPlaceholder(type) {
    return {
        QUICK_REPLY: 'Yes, tell me more',
        URL: 'View offer',
        PHONE_NUMBER: 'Call us',
    }[type] ?? 'Button label';
}

function close() {
    emit('close');

    setTimeout(() => {
        name.value = '';
        category.value = 'marketing';
        language.value = 'en_US';
        accountId.value = null;
        header.value = '';
        body.value = '';
        footer.value = '';
        buttons.value = [];
        errors.value = {};
    }, 150);
}

function buildButtons() {
    return orderedButtons.value.map((button) => {
        const payload = {
            type: button.type,
            text: button.text.trim(),
        };

        if (button.type === 'URL') {
            payload.url = button.url.trim();
        }

        if (button.type === 'PHONE_NUMBER') {
            payload.phone_number = button.phone_number.trim().replace(/[\s\-()]+/g, '');
        }

        return payload;
    });
}

function isValidUrl(value) {
    try {
        return ['http:', 'https:'].includes(new URL(value).protocol);
    } catch {
        return false;
    }
}

function validate() {
    const nextErrors = {};
    const numbers = [...new Set([...body.value.matchAll(/\{\{(\d+)\}\}/g)].map((match) => parseInt(match[1], 10)))].sort((a, b) => a - b);
    const urlButtons = buttons.value.filter((button) => button.type === 'URL');
    const phoneButtons = buttons.value.filter((button) => button.type === 'PHONE_NUMBER');

    if (!/^[a-z0-9_]+$/.test(name.value)) {
        nextErrors.name = 'Use lowercase letters, numbers and underscores only.';
    }

    if (!accountId.value) {
        nextErrors.channel_account_id = 'Select a WhatsApp account.';
    }

    if (!body.value.trim()) {
        nextErrors.body = 'A message body is required.';
    } else if (body.value.length > 1024) {
        nextErrors.body = 'The body cannot be longer than 1,024 characters.';
    } else if (bodyRuleError.value) {
        nextErrors.body = bodyRuleError.value;
    }

    for (let index = 0; index < numbers.length; index++) {
        if (numbers[index] !== index + 1) {
            nextErrors.body = 'Body variables must be sequential, starting at {{1}}.';
            break;
        }
    }

    if (/\{\{\d+\}\}/.test(header.value)) {
        nextErrors.header_text = 'Header variables are not supported yet.';
    }

    if (/\{\{\d+\}\}/.test(footer.value)) {
        nextErrors.footer_text = 'Footer text cannot contain variables.';
    }

    if (buttons.value.length > 10) {
        nextErrors.buttons = 'A template can have at most ten buttons.';
    }

    if (urlButtons.length > 2) {
        nextErrors.buttons = 'A template can have at most two website buttons.';
    }

    if (phoneButtons.length > 1) {
        nextErrors.buttons = 'A template can have at most one phone button.';
    }

    buttons.value.forEach((button, index) => {
        if (!button.text.trim()) {
            nextErrors[`buttons.${index}.text`] = 'Every button needs a label.';
        }

        if (button.type === 'URL') {
            if (!button.url.trim() || !isValidUrl(button.url.trim())) {
                nextErrors[`buttons.${index}.url`] = 'Enter a valid website URL.';
            } else if (/\{\{\d+\}\}/.test(button.url)) {
                nextErrors[`buttons.${index}.url`] = 'Website buttons cannot contain variables yet.';
            }
        }

        if (button.type === 'PHONE_NUMBER') {
            const phone = button.phone_number.replace(/[\s\-()]+/g, '');

            if (!/^\+?[1-9]\d{6,14}$/.test(phone)) {
                nextErrors[`buttons.${index}.phone_number`] = 'Use a country code, for example +919820044821.';
            }
        }
    });

    errors.value = nextErrors;

    return Object.keys(nextErrors).length === 0;
}

function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    submitting.value = true;

    router.post(
        route('templates.store', { workspace: workspaceSlug.value }),
        {
            channel_account_id: accountId.value,
            name: name.value,
            category: category.value,
            language: language.value,
            header_text: header.value.trim() || null,
            body: body.value.trim(),
            footer_text: footer.value.trim() || null,
            buttons: buildButtons(),
        },
        {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: (routeErrors) => {
                errors.value = routeErrors;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>
