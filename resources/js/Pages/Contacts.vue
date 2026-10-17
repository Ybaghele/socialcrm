<script setup>
import { computed, inject, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    filters: { type: Object, required: true },
    contacts: { type: Object, required: true },
    pipelineStages: { type: Array, required: true },
    stats: { type: Object, required: true },
});

const page = usePage();
const toast = inject('toast', () => {});
const workspaceSlug = computed(() => page.props.workspace?.slug);

const search = ref(props.filters.q ?? '');
const stage = ref(props.filters.stage ?? 'all');
const channel = ref(props.filters.channel ?? 'all');
const consent = ref(props.filters.consent ?? 'all');

const showCreateModal = ref(false);

const form = useForm({
    name: '',
    phone: '',
    email: '',
    pipeline_stage_id: '',
    is_opted_in: true,
});

function openCreateModal() {
    form.clearErrors();
    form.reset();
    showCreateModal.value = true;
}

function submitContact() {
    form.post(route('contacts.store', { workspace: workspaceSlug.value }), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
}

const channelStyle = (value) =>
    ({
        whatsapp: { icon: 'fa-brands fa-whatsapp', soft: 'text-emerald-700 bg-emerald-50 border-emerald-200' },
        instagram: { icon: 'fa-brands fa-instagram', soft: 'text-rose-700 bg-rose-50 border-rose-200' },
        messenger: { icon: 'fa-brands fa-facebook-messenger', soft: 'text-blue-700 bg-blue-50 border-blue-200' },
    })[value] ?? { icon: 'fa-solid fa-comment', soft: 'text-slate-600 bg-slate-50 border-slate-200' };

const initials = (name) =>
    (name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

function relativeTime(iso) {
    if (!iso) {
        return '—';
    }

    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60_000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;

    return new Date(iso).toLocaleDateString([], { day: '2-digit', month: 'short' });
}

function applyFilters() {
    router.get(
        route('contacts.index', { workspace: workspaceSlug.value }),
        { q: search.value || undefined, stage: stage.value, channel: channel.value, consent: consent.value },
        { preserveState: true, preserveScroll: true, replace: true, only: ['contacts', 'filters', 'stats'] },
    );
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});

watch([stage, channel, consent], applyFilters);

const exportHref = computed(() =>
    route('contacts.export', {
        workspace: workspaceSlug.value,
        q: props.filters.q || undefined,
        stage: props.filters.stage,
        channel: props.filters.channel,
        consent: props.filters.consent,
    }),
);

function openChat(contact) {
    if (contact.conversationId) {
        router.get(route('inbox', { workspace: workspaceSlug.value, conversation: contact.conversationId }));
    } else {
        router.get(route('inbox', { workspace: workspaceSlug.value, q: contact.name }));
    }
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <!-- Header -->
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Lead CRM &amp; Unified Directory</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Every contact captured automatically across Meta messaging channels.
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <a
                            :href="exportHref"
                            class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-arrow-down text-slate-500"></i>
                            <span>Export CSV</span>
                        </a>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-700"
                            @click="openCreateModal"
                        >
                            <i class="fa-solid fa-user-plus text-xs"></i>
                            <span>Add Contact</span>
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Total leads</p>
                        <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ stats.total.toLocaleString() }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Opted in</p>
                        <p class="mt-1 text-2xl font-extrabold text-emerald-600">{{ stats.optedIn.toLocaleString() }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">New this month</p>
                        <p class="mt-1 text-2xl font-extrabold text-brand-600">{{ stats.newThisMonth.toLocaleString() }}</p>
                    </div>
                </div>

                <!-- Table card -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                        <div class="relative w-full sm:w-72">
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Filter leads by name, phone, handle or tag…"
                                class="w-full rounded-lg border-slate-200 bg-slate-50 py-1.5 pl-8 pr-3 text-xs text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                            />
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                v-model="channel"
                                class="rounded-lg border-slate-200 py-1.5 pl-3 pr-8 text-xs font-medium text-slate-600 focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="all">All channels</option>
                                <option value="whatsapp">WhatsApp</option>
                                <option value="instagram">Instagram</option>
                                <option value="messenger">Messenger</option>
                            </select>

                            <select
                                v-model="stage"
                                class="rounded-lg border-slate-200 py-1.5 pl-3 pr-8 text-xs font-medium text-slate-600 focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="all">All stages</option>
                                <option v-for="item in pipelineStages" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                            </select>

                            <select
                                v-model="consent"
                                class="rounded-lg border-slate-200 py-1.5 pl-3 pr-8 text-xs font-medium text-slate-600 focus:border-brand-500 focus:ring-brand-500"
                            >
                                <option value="all">Any consent</option>
                                <option value="opted_in">Opted in</option>
                                <option value="opted_out">Opted out</option>
                            </select>

                            <span class="text-xs text-slate-500">
                                <strong class="text-slate-700">{{ contacts.total }}</strong> leads
                            </span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="border-b border-slate-100 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <tr>
                                    <th class="px-6 py-3.5">Contact</th>
                                    <th class="px-4 py-3.5">Source channel</th>
                                    <th class="px-4 py-3.5">Pipeline stage</th>
                                    <th class="px-4 py-3.5">Tags</th>
                                    <th class="px-4 py-3.5">Total DMs</th>
                                    <th class="px-4 py-3.5">Last touch</th>
                                    <th class="px-6 py-3.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="contact in contacts.data" :key="contact.id" class="transition hover:bg-slate-50/70">
                                    <td class="px-6 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <img
                                                v-if="contact.avatarUrl"
                                                :src="contact.avatarUrl"
                                                :alt="contact.name"
                                                class="h-8 w-8 rounded-full object-cover"
                                            />
                                            <div
                                                v-else
                                                class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500"
                                            >
                                                {{ initials(contact.name) }}
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-1.5 font-bold text-slate-900">
                                                    {{ contact.name }}
                                                    <i
                                                        v-if="!contact.optedIn"
                                                        class="fa-solid fa-bell-slash text-[10px] text-amber-500"
                                                        title="Opted out of marketing"
                                                    ></i>
                                                </div>
                                                <div class="font-mono text-[11px] text-slate-400">
                                                    {{ contact.phone || contact.handle || contact.email || '—' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3.5">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="item in contact.channels"
                                                :key="item"
                                                class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize"
                                                :class="channelStyle(item).soft"
                                            >
                                                <i :class="channelStyle(item).icon"></i> {{ item }}
                                            </span>
                                            <span v-if="!contact.channels.length" class="text-slate-400">—</span>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3.5">
                                        <span
                                            v-if="contact.stage"
                                            class="rounded px-2 py-0.5 text-[11px] font-semibold text-white"
                                            :style="{ backgroundColor: contact.stage.color || '#64748b' }"
                                        >
                                            {{ contact.stage.name }}
                                        </span>
                                        <span v-else class="text-slate-400">Unassigned</span>
                                    </td>

                                    <td class="px-4 py-3.5">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="tag in contact.tags"
                                                :key="tag.id"
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600"
                                            >
                                                #{{ tag.name }}
                                            </span>
                                            <span v-if="!contact.tags.length" class="text-slate-400">—</span>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3.5 font-medium text-slate-700">{{ contact.messagesCount }} msgs</td>

                                    <td class="px-4 py-3.5 text-[11px] text-slate-400">{{ relativeTime(contact.lastTouch) }}</td>

                                    <td class="px-6 py-3.5 text-right">
                                        <button
                                            type="button"
                                            class="rounded bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-200"
                                            @click="openChat(contact)"
                                        >
                                            Open chat
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="!contacts.data.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-regular fa-address-book text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No leads match these filters</p>
                            <p class="text-xs text-slate-400">Try clearing the search, or simulate an inbound message in the inbox.</p>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="contacts.links && contacts.links.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-3"
                    >
                        <p class="text-[11px] text-slate-400">
                            Showing {{ contacts.from ?? 0 }}–{{ contacts.to ?? 0 }} of {{ contacts.total }}
                        </p>
                        <div class="flex flex-wrap items-center gap-1">
                            <a
                                v-for="(link, index) in contacts.links"
                                :key="index"
                                :href="link.url || undefined"
                                class="rounded-md px-2.5 py-1 text-[11px] font-semibold transition"
                                :class="
                                    link.active
                                        ? 'bg-brand-600 text-white'
                                        : link.url
                                          ? 'text-slate-600 hover:bg-slate-100'
                                          : 'pointer-events-none text-slate-300'
                                "
                                @click.prevent="link.url && router.get(link.url, {}, { preserveScroll: true, preserveState: true })"
                                v-html="link.label"
                            ></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add contact modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
            <div class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-user-plus text-brand-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">Add Contact</h3>
                    </div>
                    <button type="button" class="text-slate-400 transition hover:text-slate-600" @click="showCreateModal = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form class="space-y-3 text-xs" @submit.prevent="submitContact">
                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Full name<span class="text-rose-500"> *</span></label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g., Ananya Sharma"
                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-[11px] text-rose-600">{{ form.errors.name }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Phone (with country code)</label>
                            <input
                                v-model="form.phone"
                                type="tel"
                                placeholder="+919820044821"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            />
                            <p v-if="form.errors.phone" class="mt-1 text-[11px] text-rose-600">{{ form.errors.phone }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                placeholder="name@example.com"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                            />
                            <p v-if="form.errors.email" class="mt-1 text-[11px] text-rose-600">{{ form.errors.email }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Pipeline stage</label>
                        <select v-model="form.pipeline_stage_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                            <option value="">Unassigned</option>
                            <option v-for="item in pipelineStages" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                        <p v-if="form.errors.pipeline_stage_id" class="mt-1 text-[11px] text-rose-600">{{ form.errors.pipeline_stage_id }}</p>
                    </div>

                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-600">
                        <input v-model="form.is_opted_in" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                        <span>Contact has opted in to marketing messages (WhatsApp consent).</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                            @click="showCreateModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-user-plus'"></i>
                            Add Contact
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </OmniLayout>
</template>
