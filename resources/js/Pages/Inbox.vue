<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, inject } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import axios from 'axios';
import OmniLayout from '@/Layouts/OmniLayout.vue';
import QuickRepliesModal from '@/Components/Inbox/QuickRepliesModal.vue';
import TagsModal from '@/Components/Inbox/TagsModal.vue';

const props = defineProps({
    filters: { type: Object, required: true },
    conversations: { type: Array, required: true },
    selectedId: { type: Number, default: null },
    thread: { type: Object, default: null },
    messages: { type: Array, required: true },
    pipelineStages: { type: Array, required: true },
    cannedResponses: { type: Array, required: true },
    tags: { type: Array, required: true },
});

const page = usePage();
const toast = inject('toast', () => {});
const workspaceSlug = computed(() => page.props.workspace?.slug);
const workspaceId = computed(() => page.props.workspace?.id);

/* ------------------------------------------------------------------ *
 * Local UI state                                                      *
 * ------------------------------------------------------------------ */
const searchQuery = ref(props.filters.q ?? '');
const channelFilter = ref(props.filters.channel ?? 'all');
const statusFilter = ref(props.filters.status ?? 'all');
const inputMode = ref('reply');
const replyText = ref('');
const showCannedMenu = ref(false);
const sending = ref(false);
const showMobileContact = ref(false);
const mobileListVisible = ref(false);
const showQuickReplies = ref(false);
const showTags = ref(false);
const threadScroller = ref(null);

/* ------------------------------------------------------------------ *
 * Derived data                                                        *
 * ------------------------------------------------------------------ */
const channelStyle = (channel) =>
    ({
        whatsapp: { icon: 'fa-brands fa-whatsapp', badge: 'bg-emerald-500', soft: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: 'bg-emerald-500' },
        instagram: { icon: 'fa-brands fa-instagram', badge: 'bg-rose-500', soft: 'bg-rose-50 text-rose-700 border-rose-200', dot: 'bg-rose-500' },
        messenger: { icon: 'fa-brands fa-facebook-messenger', badge: 'bg-blue-500', soft: 'bg-blue-50 text-blue-700 border-blue-200', dot: 'bg-blue-500' },
    })[channel] ?? { icon: 'fa-solid fa-comment', badge: 'bg-slate-400', soft: 'bg-slate-50 text-slate-600 border-slate-200', dot: 'bg-slate-400' };

const filteredConversations = computed(() => {
    const term = searchQuery.value.trim().toLowerCase();

    return props.conversations.filter((conversation) => {
        if (channelFilter.value !== 'all' && conversation.channel !== channelFilter.value) {
            return false;
        }

        if (statusFilter.value !== 'all' && conversation.status !== statusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        const haystack = [conversation.contact.name, conversation.contact.phone, conversation.contact.handle, conversation.lastMessage]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return haystack.includes(term);
    });
});

const activeChat = computed(() => props.thread);

const unreadTotal = computed(() => page.props.navCounts?.unread ?? 0);

const windowInfo = computed(() => {
    if (!activeChat.value) {
        return null;
    }

    if (!activeChat.value.withinWindow) {
        return { open: false, label: '24-hour window closed', hint: 'Send an approved template to re-open the conversation.' };
    }

    const closes = new Date(activeChat.value.windowClosesAt).getTime();
    const hoursLeft = Math.max(0, Math.floor((closes - Date.now()) / 3_600_000));

    return { open: true, label: `Reply window open · ${hoursLeft}h left`, hint: 'Free-form replies allowed.' };
});

const initials = (name) =>
    (name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

function formatListTime(iso) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const now = new Date();

    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString([], { day: '2-digit', month: 'short' });
}

const formatTime = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '');

const formatDateTime = (iso) =>
    iso ? new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

/* ------------------------------------------------------------------ *
 * Interactions                                                        *
 * ------------------------------------------------------------------ */
function openConversation(id) {
    mobileListVisible.value = false;

    if (id === props.selectedId) {
        return;
    }

    router.get(
        route('inbox', { workspace: workspaceSlug.value, conversation: id }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

async function sendMessage() {
    if (!activeChat.value || sending.value || !replyText.value.trim()) {
        return;
    }

    sending.value = true;
    const isNote = inputMode.value === 'note';

    try {
        await axios.post(route('conversations.messages.store', { workspace: workspaceSlug.value, conversation: activeChat.value.id }), {
            body: replyText.value,
            note: isNote,
        });

        replyText.value = '';
        showCannedMenu.value = false;
        stickToBottom = true;
        router.reload({ preserveScroll: true, preserveState: true });
    } catch (error) {
        const message =
            error?.response?.data?.message ??
            'Could not send that message. Check the 24-hour reply window and try again.';
        toast(message, 'error');
    } finally {
        sending.value = false;
    }
}

function toggleStatus() {
    if (!activeChat.value) {
        return;
    }

    const next = activeChat.value.status === 'resolved' ? 'open' : 'resolved';

    router.patch(
        route('conversations.update', { workspace: workspaceSlug.value, conversation: activeChat.value.id }),
        { status: next },
        { preserveScroll: true, preserveState: true },
    );
}

function updateStage(event) {
    if (!activeChat.value) {
        return;
    }

    router.patch(
        route('contacts.update', { workspace: workspaceSlug.value, contact: activeChat.value.contact.id }),
        { pipeline_stage_id: Number(event.target.value) },
        { preserveScroll: true, preserveState: true },
    );
}

function useCanned(response) {
    replyText.value = response.body;
    inputMode.value = 'reply';
    showCannedMenu.value = false;
}

function manageQuickReplies() {
    showCannedMenu.value = false;
    showQuickReplies.value = true;
}

function simulateInbound() {
    router.post(route('inbox.simulate', { workspace: workspaceSlug.value }), {}, { preserveScroll: true, preserveState: true });
}

/* ------------------------------------------------------------------ *
 * Live inbox                                                          *
 *                                                                     *
 * Reverb is optional (shared hosting has no websocket process), so    *
 * the page also polls the props it renders every 15s while the tab is *
 * visible. Echo, when available, still refreshes instantly.           *
 * ------------------------------------------------------------------ */
const POLL_INTERVAL_MS = 15_000;
const POLLED_PROPS = ['conversations', 'selectedId', 'thread', 'messages', 'cannedResponses', 'tags', 'navCounts'];

let inboxChannel = null;
let reloadTimer = null;
let pollTimer = null;
let pollInFlight = false;
let visitInFlight = false;
let stopVisitStart = null;
let stopVisitFinish = null;
let isUnmounted = false;

function scheduleReload() {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => router.reload({ preserveScroll: true, preserveState: true }), 400);
}

/**
 * Refresh only the props the inbox renders. Shared props are skipped except
 * the unread badge, keeping the 15s request cheap on shared hosting.
 */
function refreshInbox() {
    if (isUnmounted || document.hidden || sending.value || pollInFlight || visitInFlight) {
        return;
    }

    pollInFlight = true;

    router.reload({
        only: POLLED_PROPS,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            pollInFlight = false;
        },
        onCancel: () => {
            pollInFlight = false;
        },
    });
}

function handleVisibilityChange() {
    if (!document.hidden) {
        refreshInbox();
    }
}

onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibilityChange);
    stopVisitStart = router.on('start', () => {
        visitInFlight = true;
    });
    stopVisitFinish = router.on('finish', () => {
        visitInFlight = false;
    });

    pollTimer = window.setInterval(refreshInbox, POLL_INTERVAL_MS);

    if (!window.Echo || !workspaceId.value) {
        return; // Reverb not running / not subscribed — polling keeps the inbox fresh.
    }

    inboxChannel = window.Echo.private(`workspace.${workspaceId.value}.inbox`);

    inboxChannel.listen('.message.received', (event) => {
        toast(event?.preview ? `New message: ${event.preview}` : 'New inbound message', 'info');
        scheduleReload();
    });

    inboxChannel.listen('.message.updated', () => scheduleReload());
});

onBeforeUnmount(() => {
    isUnmounted = true;
    clearTimeout(reloadTimer);
    clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    stopVisitStart?.();
    stopVisitFinish?.();

    if (inboxChannel && window.Echo) {
        window.Echo.leave(`workspace.${workspaceId.value}.inbox`);
    }
});

/* Keep the reader's scroll position while they read history; only follow
 * new messages when they are already at the bottom of the thread. */
const threadRevision = computed(
    () => `${props.thread?.id ?? 'none'}:${props.messages.length}:${props.messages.at(-1)?.id ?? 'none'}`,
);

let stickToBottom = true;
let lastThreadId = null;
let lastMessageCount = 0;

function onThreadScroll() {
    const scroller = threadScroller.value;

    stickToBottom = !scroller || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 96;
}

watch(
    threadRevision,
    async () => {
        const threadId = props.thread?.id ?? null;
        const count = props.messages.length;
        const threadChanged = threadId !== lastThreadId;
        const grew = count > lastMessageCount;

        lastThreadId = threadId;
        lastMessageCount = count;

        if (!threadChanged && !grew) {
            return;
        }

        if (threadChanged) {
            stickToBottom = true;
        }

        if (threadChanged || stickToBottom) {
            await nextTick();

            if (threadScroller.value) {
                threadScroller.value.scrollTop = threadScroller.value.scrollHeight;
            }
        }
    },
    { immediate: true },
);
</script>

<template>
    <OmniLayout>
        <div class="flex min-h-0 flex-1">
            <!-- ------------------------------------------------------ -->
            <!-- Column 1 : conversation list                            -->
            <!-- ------------------------------------------------------ -->
            <section class="flex w-full flex-col border-r border-slate-200 bg-white sm:w-80 lg:w-96" :class="activeChat && !mobileListVisible ? 'hidden sm:flex' : 'flex'">
                <div class="shrink-0 border-b border-slate-100 p-3">
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search leads, phone, handle…"
                            class="w-full rounded-lg border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                        />
                    </div>

                    <!-- Channel pills -->
                    <div class="mt-3 flex items-center gap-1.5 overflow-x-auto">
                        <button
                            v-for="pill in [
                                { key: 'all', label: 'All', icon: 'fa-solid fa-layer-group' },
                                { key: 'whatsapp', label: 'WhatsApp', icon: 'fa-brands fa-whatsapp' },
                                { key: 'instagram', label: 'Instagram', icon: 'fa-brands fa-instagram' },
                                { key: 'messenger', label: 'Messenger', icon: 'fa-brands fa-facebook-messenger' },
                            ]"
                            :key="pill.key"
                            type="button"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition"
                            :class="
                                channelFilter === pill.key
                                    ? 'border-brand-200 bg-brand-50 text-brand-700'
                                    : 'border-slate-200 text-slate-500 hover:border-slate-300 hover:text-slate-700'
                            "
                            @click="channelFilter = pill.key"
                        >
                            <i :class="pill.icon" class="text-[10px]"></i> {{ pill.label }}
                        </button>
                    </div>

                    <!-- Status filter -->
                    <div class="mt-2 flex items-center rounded-lg bg-slate-100 p-0.5 text-xs font-semibold">
                        <button
                            v-for="option in [
                                { key: 'all', label: 'All' },
                                { key: 'open', label: 'Open' },
                                { key: 'resolved', label: 'Resolved' },
                            ]"
                            :key="option.key"
                            type="button"
                            class="flex-1 rounded-md py-1.5 transition"
                            :class="statusFilter === option.key ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'"
                            @click="statusFilter = option.key"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <span>{{ filteredConversations.length }} conversations</span>
                    <span v-if="unreadTotal" class="text-brand-600">{{ unreadTotal }} unread</span>
                </div>

                <!-- Thread list -->
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button
                        v-for="conversation in filteredConversations"
                        :key="conversation.id"
                        type="button"
                        class="flex w-full gap-3 border-b border-slate-100 px-4 py-3 text-left transition"
                        :class="conversation.id === selectedId ? 'bg-brand-50/60' : 'hover:bg-slate-50'"
                        @click="openConversation(conversation.id)"
                    >
                        <div class="relative shrink-0">
                            <img
                                v-if="conversation.contact.avatarUrl"
                                :src="conversation.contact.avatarUrl"
                                :alt="conversation.contact.name"
                                class="h-10 w-10 rounded-full object-cover"
                            />
                            <div
                                v-else
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500"
                            >
                                {{ initials(conversation.contact.name) }}
                            </div>
                            <span
                                class="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full text-[8px] text-white ring-2 ring-white"
                                :class="channelStyle(conversation.channel).badge"
                            >
                                <i :class="channelStyle(conversation.channel).icon"></i>
                            </span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-bold text-slate-800">{{ conversation.contact.name }}</p>
                                <span class="shrink-0 text-[10px] font-medium text-slate-400">{{ formatListTime(conversation.lastTime) }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center justify-between gap-2">
                                <p class="truncate text-xs text-slate-500">{{ conversation.lastMessage || 'No messages yet' }}</p>
                                <span
                                    v-if="conversation.unread"
                                    class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white"
                                >
                                    {{ conversation.unread }}
                                </span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-1.5">
                                <span
                                    v-if="conversation.contact.stage"
                                    class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                    :style="{ backgroundColor: conversation.contact.stage.color || '#64748b' }"
                                >
                                    {{ conversation.contact.stage.name }}
                                </span>
                                <span
                                    v-for="tag in conversation.contact.tags.slice(0, 2)"
                                    :key="tag.id"
                                    class="rounded-full border border-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-500"
                                >
                                    {{ tag.name }}
                                </span>
                                <span v-if="conversation.status === 'resolved'" class="ml-auto text-[10px] font-semibold text-emerald-600">
                                    <i class="fa-solid fa-circle-check"></i> Resolved
                                </span>
                            </div>
                        </div>
                    </button>

                    <div v-if="!filteredConversations.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                        <i class="fa-regular fa-comments text-2xl text-slate-300"></i>
                        <p class="text-sm font-semibold text-slate-500">No conversations match</p>
                        <p class="text-xs text-slate-400">Try clearing the filters, or simulate an inbound message.</p>
                        <button
                            type="button"
                            class="mt-1 inline-flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-700"
                            @click="simulateInbound"
                        >
                            <i class="fa-solid fa-play text-[10px]"></i> Simulate inbound
                        </button>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------ -->
            <!-- Column 2 : active thread                                -->
            <!-- ------------------------------------------------------ -->
            <section v-if="activeChat" class="min-w-0 flex-1 flex-col border-r border-slate-200" :class="mobileListVisible ? 'hidden sm:flex' : 'flex'">
                <header class="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
                    <div class="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 sm:hidden"
                            @click="mobileListVisible = true"
                        >
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>

                        <div class="relative shrink-0">
                            <img
                                v-if="activeChat.contact.avatarUrl"
                                :src="activeChat.contact.avatarUrl"
                                :alt="activeChat.contact.name"
                                class="h-9 w-9 rounded-full object-cover"
                            />
                            <div v-else class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                                {{ initials(activeChat.contact.name) }}
                            </div>
                            <span
                                class="absolute -bottom-0.5 -right-0.5 flex h-3.5 w-3.5 items-center justify-center rounded-full text-[8px] text-white ring-2 ring-white"
                                :class="channelStyle(activeChat.channel).badge"
                            >
                                <i :class="channelStyle(activeChat.channel).icon"></i>
                            </span>
                        </div>

                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-800">{{ activeChat.contact.name }}</p>
                            <p class="truncate text-[11px] text-slate-400">
                                {{ activeChat.contact.handle || activeChat.contact.phone || 'No handle' }}
                                <template v-if="activeChat.assignedTo"> · assigned to {{ activeChat.assignedTo }}</template>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="hidden items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition sm:inline-flex"
                            :class="
                                activeChat.status === 'resolved'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                            "
                            @click="toggleStatus"
                        >
                            <i class="fa-solid text-[11px]" :class="activeChat.status === 'resolved' ? 'fa-rotate-left' : 'fa-circle-check'"></i>
                            {{ activeChat.status === 'resolved' ? 'Reopen' : 'Resolve' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 p-2 text-slate-400 transition hover:border-slate-300 hover:text-slate-600 xl:hidden"
                            @click="showMobileContact = true"
                        >
                            <i class="fa-solid fa-id-card text-xs"></i>
                        </button>
                    </div>
                </header>

                <!-- Window notice -->
                <div
                    class="flex shrink-0 items-center gap-2 border-b px-6 py-2 text-[11px] font-medium"
                    :class="windowInfo.open ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-amber-100 bg-amber-50 text-amber-700'"
                >
                    <i class="fa-solid" :class="windowInfo.open ? 'fa-clock' : 'fa-triangle-exclamation'"></i>
                    <span>{{ windowInfo.label }}</span>
                    <span class="hidden text-current/70 sm:inline">{{ windowInfo.hint }}</span>
                </div>

                <!-- Messages -->
                <div
                    ref="threadScroller"
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50/60 px-4 py-5 sm:px-6"
                    @scroll.passive="onThreadScroll"
                >
                    <div v-for="message in messages" :key="message.id">
                        <!-- Internal note -->
                        <div v-if="message.isInternal" class="mx-auto max-w-2xl">
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                                <p class="mb-1 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-amber-700">
                                    <i class="fa-solid fa-lock text-[10px]"></i> Internal note · {{ message.author || 'System' }}
                                </p>
                                <p class="text-sm text-amber-900">{{ message.body }}</p>
                                <p class="mt-1 text-[10px] text-amber-600">{{ formatDateTime(message.createdAt) }}</p>
                            </div>
                        </div>

                        <!-- Outbound -->
                        <div v-else-if="message.direction === 'outbound'" class="flex justify-end">
                            <div class="max-w-[80%] sm:max-w-[65%]">
                                <div class="overflow-hidden rounded-2xl rounded-tr-none bg-brand-600 text-sm text-white shadow-sm">
                                    <img
                                        v-if="message.imageUrl"
                                        :src="message.imageUrl"
                                        alt="Template image"
                                        loading="lazy"
                                        class="max-h-64 w-full object-cover"
                                    />
                                    <p v-if="message.body" class="whitespace-pre-line px-4 py-2.5">{{ message.body }}</p>
                                </div>
                                <p class="mt-1 flex items-center justify-end gap-1.5 text-[10px] text-slate-400">
                                    {{ message.author ? message.author + ' · ' : '' }}{{ formatTime(message.createdAt) }}
                                    <i
                                        class="fa-solid text-[9px]"
                                        :class="message.status === 'read' ? 'fa-check-double text-brand-500' : 'fa-check'"
                                    ></i>
                                </p>
                            </div>
                        </div>

                        <!-- Inbound -->
                        <div v-else class="flex justify-start">
                            <div class="max-w-[80%] sm:max-w-[65%]">
                                <div class="rounded-2xl rounded-tl-none border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 shadow-sm">
                                    {{ message.body }}
                                </div>
                                <p class="mt-1 text-[10px] text-slate-400">{{ formatTime(message.createdAt) }}</p>
                            </div>
                        </div>
                    </div>

                    <p v-if="!messages.length" class="py-10 text-center text-sm text-slate-400">No messages in this thread yet.</p>
                </div>

                <!-- Composer -->
                <footer class="shrink-0 border-t border-slate-200 bg-white p-3 sm:p-4">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex items-center rounded-lg bg-slate-100 p-0.5 text-xs font-semibold">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 transition"
                                :class="inputMode === 'reply' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'"
                                @click="inputMode = 'reply'"
                            >
                                <i class="fa-solid fa-reply text-[10px]"></i> Reply
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 transition"
                                :class="inputMode === 'note' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'"
                                @click="inputMode = 'note'"
                            >
                                <i class="fa-solid fa-lock text-[10px]"></i> Internal note
                            </button>
                        </div>

                        <div class="relative ml-auto">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                                @click="showCannedMenu = !showCannedMenu"
                            >
                                <i class="fa-solid fa-bolt text-[10px]"></i> Canned
                            </button>

                            <div v-if="showCannedMenu" class="absolute bottom-10 right-0 z-30 w-72 rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                                <p class="px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">Quick replies</p>
                                <button
                                    v-for="response in cannedResponses"
                                    :key="response.id"
                                    type="button"
                                    class="block w-full rounded-lg px-3 py-2 text-left transition hover:bg-slate-50"
                                    @click="useCanned(response)"
                                >
                                    <span class="block text-xs font-semibold text-slate-700">{{ response.title }}</span>
                                    <span class="block truncate text-[11px] text-slate-400">{{ response.body }}</span>
                                </button>
                                <button
                                    v-if="!cannedResponses.length"
                                    type="button"
                                    class="block w-full rounded-lg px-3 py-3 text-left text-xs font-semibold text-brand-700 transition hover:bg-brand-50"
                                    @click="manageQuickReplies"
                                >
                                    No quick replies yet — create your first one
                                </button>
                                <div class="mt-1 border-t border-slate-100 pt-1">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-xs font-semibold text-brand-700 transition hover:bg-brand-50"
                                        @click="manageQuickReplies"
                                    >
                                        <i class="fa-solid fa-sliders text-[10px]"></i> Manage quick replies
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-end gap-2">
                        <textarea
                            v-model="replyText"
                            rows="2"
                            :placeholder="inputMode === 'note' ? 'Write a note only your team can see…' : 'Type a reply… (Enter to send)'"
                            class="flex-1 resize-none rounded-xl border-slate-200 bg-slate-50 py-2.5 text-sm text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                            @keydown.enter.exact.prevent="sendMessage"
                        ></textarea>
                        <button
                            type="button"
                            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white shadow-sm transition disabled:opacity-50"
                            :class="inputMode === 'note' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-brand-600 hover:bg-brand-700'"
                            :disabled="sending || !replyText.trim()"
                            @click="sendMessage"
                        >
                            <i class="fa-solid" :class="sending ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
                        </button>
                    </div>
                </footer>
            </section>

            <!-- Empty state (no conversation selected) -->
            <section v-else class="hidden flex-1 flex-col items-center justify-center gap-3 bg-slate-50/60 text-center sm:flex">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-xl text-slate-300 shadow-sm">
                    <i class="fa-regular fa-comments"></i>
                </div>
                <p class="text-sm font-semibold text-slate-600">Pick a conversation</p>
                <p class="max-w-xs text-xs text-slate-400">Select a thread on the left to read the history and reply.</p>
            </section>

            <!-- ------------------------------------------------------ -->
            <!-- Column 3 : lead dossier                                 -->
            <!-- ------------------------------------------------------ -->
            <aside
                v-if="activeChat"
                class="hidden w-80 shrink-0 flex-col overflow-y-auto bg-white xl:flex"
            >
                <div class="border-b border-slate-100 p-5 text-center">
                    <div class="relative mx-auto h-16 w-16">
                        <img
                            v-if="activeChat.contact.avatarUrl"
                            :src="activeChat.contact.avatarUrl"
                            :alt="activeChat.contact.name"
                            class="h-16 w-16 rounded-full object-cover"
                        />
                        <div v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-lg font-bold text-slate-500">
                            {{ initials(activeChat.contact.name) }}
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-bold text-slate-800">{{ activeChat.contact.name }}</p>
                    <p class="text-xs text-slate-400">{{ activeChat.contact.handle || activeChat.contact.phone || '—' }}</p>
                    <p v-if="activeChat.contact.location" class="mt-0.5 text-[11px] text-slate-400">
                        <i class="fa-solid fa-location-dot"></i> {{ activeChat.contact.location }}
                    </p>

                    <div class="mt-3 flex items-center justify-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                            :class="channelStyle(activeChat.channel).soft"
                        >
                            <i :class="channelStyle(activeChat.channel).icon"></i> {{ activeChat.channel }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                            :class="
                                activeChat.status === 'resolved'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : 'border-slate-200 bg-slate-50 text-slate-600'
                            "
                        >
                            <span class="h-1.5 w-1.5 rounded-full" :class="activeChat.status === 'resolved' ? 'bg-emerald-500' : 'bg-brand-500'"></span>
                            {{ activeChat.status }}
                        </span>
                    </div>
                </div>

                <!-- Pipeline + stats -->
                <div class="space-y-3 border-b border-slate-100 p-5">
                    <div>
                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-400">Pipeline stage</label>
                        <select
                            :value="activeChat.contact.stage?.id"
                            class="w-full rounded-lg border-slate-200 text-sm text-slate-700 focus:border-brand-500 focus:ring-brand-500"
                            @change="updateStage"
                        >
                            <option v-for="stage in pipelineStages" :key="stage.id" :value="stage.id">{{ stage.name }}</option>
                        </select>
                    </div>

                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-400">Tags</label>
                            <button
                                type="button"
                                class="text-[11px] font-bold text-brand-700 transition hover:text-brand-800"
                                @click="showTags = true"
                            >
                                {{ activeChat.contact.tags.length ? 'Edit' : 'Add tags' }}
                            </button>
                        </div>
                        <div v-if="activeChat.contact.tags.length" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="tag in activeChat.contact.tags"
                                :key="tag.id"
                                class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                            >
                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }"></span>
                                {{ tag.name }}
                            </span>
                        </div>
                        <p v-else class="text-xs text-slate-400">No tags yet.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <div class="rounded-lg border border-slate-100 bg-slate-50 p-2.5 text-center">
                            <p class="text-lg font-extrabold text-slate-800">{{ activeChat.totalMessages }}</p>
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Messages</p>
                        </div>
                        <div class="rounded-lg border border-slate-100 bg-slate-50 p-2.5 text-center">
                            <p class="text-lg font-extrabold text-slate-800">
                                {{ activeChat.firstResponseAt ? '✓' : '—' }}
                            </p>
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">First reply</p>
                        </div>
                    </div>
                </div>

                <!-- Enroll -->
                <div class="border-b border-slate-100 p-5">
                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-brand-600 to-indigo-500 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:opacity-90"
                        @click="toast('Campaign enrollment arrives with the Campaigns screen.', 'info')"
                    >
                        <i class="fa-solid fa-bullhorn"></i> Enroll in campaign
                    </button>
                </div>

                <!-- Activity log -->
                <div class="p-5">
                    <p class="mb-3 text-[11px] font-bold uppercase tracking-wide text-slate-400">Activity log</p>
                    <ol v-if="activeChat.activity.length" class="relative space-y-4 border-l border-slate-200 pl-4">
                        <li v-for="entry in activeChat.activity" :key="entry.id" class="relative">
                            <span class="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full border-2 border-white bg-brand-500"></span>
                            <p class="text-xs font-semibold text-slate-700">{{ entry.description }}</p>
                            <p class="text-[10px] text-slate-400">
                                {{ entry.author || 'Automation' }} · {{ formatDateTime(entry.createdAt) }}
                            </p>
                        </li>
                    </ol>
                    <p v-else class="text-xs text-slate-400">No activity recorded for this lead yet.</p>
                </div>
            </aside>
        </div>

        <!-- Mobile dossier drawer -->
        <div v-if="showMobileContact && activeChat" class="fixed inset-0 z-40 flex xl:hidden">
            <div class="absolute inset-0 bg-slate-900/40" @click="showMobileContact = false"></div>
            <div class="relative ml-auto flex h-full w-80 max-w-[85%] flex-col overflow-y-auto bg-white">
                <div class="flex items-center justify-between border-b border-slate-100 p-4">
                    <p class="text-sm font-bold text-slate-700">Lead details</p>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="showMobileContact = false">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="p-5 text-center">
                    <p class="text-sm font-bold text-slate-800">{{ activeChat.contact.name }}</p>
                    <p class="text-xs text-slate-400">{{ activeChat.contact.handle || activeChat.contact.phone || '—' }}</p>
                </div>
                <div class="space-y-3 px-5 pb-5">
                    <select
                        :value="activeChat.contact.stage?.id"
                        class="w-full rounded-lg border-slate-200 text-sm text-slate-700"
                        @change="updateStage"
                    >
                        <option v-for="stage in pipelineStages" :key="stage.id" :value="stage.id">{{ stage.name }}</option>
                    </select>
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Tags</label>
                            <button
                                type="button"
                                class="text-[11px] font-bold text-brand-700"
                                @click="showMobileContact = false; showTags = true"
                            >
                                {{ activeChat.contact.tags.length ? 'Edit' : 'Add tags' }}
                            </button>
                        </div>
                        <div v-if="activeChat.contact.tags.length" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="tag in activeChat.contact.tags"
                                :key="tag.id"
                                class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                            >
                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }"></span>
                                {{ tag.name }}
                            </span>
                        </div>
                        <p v-else class="text-xs text-slate-400">No tags yet.</p>
                    </div>
                    <p class="text-xs text-slate-500">{{ activeChat.totalMessages }} messages · first reply
                        {{ activeChat.firstResponseAt ? 'sent' : 'pending' }}</p>
                </div>
            </div>
        </div>

        <QuickRepliesModal :show="showQuickReplies" :responses="cannedResponses" @close="showQuickReplies = false" />
        <TagsModal v-if="activeChat" :show="showTags" :tags="tags" :contact="activeChat.contact" @close="showTags = false" />
    </OmniLayout>
</template>
