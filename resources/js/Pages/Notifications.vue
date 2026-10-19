<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    notifications: { type: Array, required: true },
    unreadCount: { type: Number, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);

const levelMeta = {
    info: { icon: 'fa-solid fa-circle-info', tile: 'bg-slate-100 text-slate-500' },
    success: { icon: 'fa-solid fa-circle-check', tile: 'bg-emerald-100 text-emerald-600' },
    warning: { icon: 'fa-solid fa-triangle-exclamation', tile: 'bg-amber-100 text-amber-600' },
    danger: { icon: 'fa-solid fa-circle-exclamation', tile: 'bg-rose-100 text-rose-600' },
};

const meta = (level) => levelMeta[level] ?? levelMeta.info;

function open(notification) {
    router.post(route('notifications.read', { workspace: workspaceSlug.value, notification: notification.id }), {}, {
        preserveScroll: true,
    });
}

function markAllRead() {
    router.post(route('notifications.readAll', { workspace: workspaceSlug.value }), {}, { preserveScroll: true });
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-3xl space-y-6">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Notifications</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ unreadCount }} unread
                        </p>
                    </div>
                    <button
                        v-if="unreadCount"
                        type="button"
                        class="rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                        @click="markAllRead"
                    >
                        Mark all as read
                    </button>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="divide-y divide-slate-100">
                        <button
                            v-for="notification in notifications"
                            :key="notification.id"
                            type="button"
                            class="flex w-full items-start gap-3 p-4 text-left transition hover:bg-slate-50"
                            :class="!notification.readAt && 'bg-brand-50/40'"
                            @click="open(notification)"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="meta(notification.level).tile">
                                <i :class="meta(notification.level).icon" class="text-sm"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-800">{{ notification.title }}</span>
                                    <span v-if="!notification.readAt" class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                                </span>
                                <span v-if="notification.body" class="mt-0.5 block text-xs leading-relaxed text-slate-500">
                                    {{ notification.body }}
                                </span>
                                <span class="mt-1 block text-[11px] font-medium text-slate-400">{{ notification.createdAt }}</span>
                            </span>
                            <i v-if="notification.url" class="fa-solid fa-chevron-right mt-1 text-[10px] text-slate-300"></i>
                        </button>

                        <div v-if="!notifications.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <i class="fa-solid fa-bell-slash text-2xl text-slate-300"></i>
                            <p class="text-sm font-semibold text-slate-500">No notifications yet</p>
                            <p class="text-xs text-slate-400">
                                Broadcast results, wallet warnings and template approvals will show up here.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
