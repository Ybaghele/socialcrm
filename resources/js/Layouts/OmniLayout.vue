<script setup>
import { computed, provide, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const page = usePage();

const workspace = computed(() => page.props.workspace);
const channels = computed(() => page.props.channels ?? []);
const usage = computed(() => page.props.usage ?? { used: 0, limit: null, percent: 0 });
const navCounts = computed(() => page.props.navCounts ?? { unread: 0, contacts: 0, activeCampaigns: 0 });
const canSimulateInbound = computed(() => page.props.canSimulateInbound === true);
const user = computed(() => page.props.auth?.user);
const isSuperAdmin = computed(() => user.value?.is_super_admin === true);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
);

const roleLabel = computed(() => {
    const role = workspace.value?.role;

    return role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Agent';
});

const channelMeta = {
    whatsapp: { label: 'WhatsApp', icon: 'fa-brands fa-whatsapp', dot: 'bg-emerald-500', text: 'text-emerald-600' },
    instagram: { label: 'Instagram', icon: 'fa-brands fa-instagram', dot: 'bg-rose-500', text: 'text-rose-600' },
    messenger: { label: 'Messenger', icon: 'fa-brands fa-facebook-messenger', dot: 'bg-blue-500', text: 'text-blue-600' },
};

const meta = (channel) => channelMeta[channel] ?? { label: channel, icon: 'fa-solid fa-comment', dot: 'bg-slate-400', text: 'text-slate-500' };

// Primary navigation, shared by the desktop tab strip and the mobile drawer so
// the two can never drift apart.
const canManageTeam = computed(() => ['owner', 'admin'].includes(workspace.value?.role));
const myWorkspaces = computed(() => page.props.myWorkspaces ?? []);
const notifications = computed(() => page.props.notifications ?? { unread: 0, items: [] });

const navItems = computed(() => {
    const slug = workspace.value?.slug;

    if (!slug) {
        return [];
    }

    return [
        { label: 'Unified Inbox', icon: 'fa-solid fa-inbox', href: route('inbox', { workspace: slug }), component: 'Inbox', badge: navCounts.value.unread || null, badgeClass: 'bg-brand-100 text-brand-700' },
        { label: 'Analytics', icon: 'fa-solid fa-chart-line', href: route('analytics.index', { workspace: slug }), component: 'Analytics', badge: null, badgeClass: '' },
        { label: 'Lead CRM', icon: 'fa-solid fa-address-book', href: route('contacts.index', { workspace: slug }), component: 'Contacts', badge: navCounts.value.contacts || null, badgeClass: 'bg-slate-100 text-slate-500' },
        { label: 'Campaigns', icon: 'fa-solid fa-bullhorn', href: route('campaigns.index', { workspace: slug }), component: 'Campaigns', badge: navCounts.value.activeCampaigns ? `${navCounts.value.activeCampaigns} live` : null, badgeClass: 'bg-emerald-100 text-emerald-700' },
        { label: 'Templates', icon: 'fa-solid fa-file-lines', href: route('templates.index', { workspace: slug }), component: 'Templates', badge: null, badgeClass: '' },
        { label: 'Audiences', icon: 'fa-solid fa-filter', href: route('segments.index', { workspace: slug }), component: 'Segments', badge: null, badgeClass: '' },
        { label: 'Automations', icon: 'fa-solid fa-bolt', href: route('automations.index', { workspace: slug }), component: 'Automations', badge: null, badgeClass: '' },
        ...(canManageTeam.value
            ? [{ label: 'Team', icon: 'fa-solid fa-users', href: route('members.index', { workspace: slug }), component: 'Members', badge: null, badgeClass: '' }]
            : []),
        { label: 'Billing', icon: 'fa-solid fa-wallet', href: route('billing', { workspace: slug }), component: 'Billing', badge: null, badgeClass: '' },
        { label: 'Channels', icon: 'fa-solid fa-plug', href: route('channels.index', { workspace: slug }), component: 'Channels', badge: channels.value.length ? null : '!', badgeClass: 'bg-amber-100 text-amber-700' },
    ];
});

const isActive = (component) => page.component === component;

// Notification levels → icon + colour for the bell dropdown and the list page.
const levelMeta = {
    info: { icon: 'fa-solid fa-circle-info', text: 'text-slate-400' },
    success: { icon: 'fa-solid fa-circle-check', text: 'text-emerald-500' },
    warning: { icon: 'fa-solid fa-triangle-exclamation', text: 'text-amber-500' },
    danger: { icon: 'fa-solid fa-circle-exclamation', text: 'text-rose-500' },
};

const levelIcon = (level) => (levelMeta[level] ?? levelMeta.info).icon;
const levelText = (level) => (levelMeta[level] ?? levelMeta.info).text;

function markAllRead() {
    if (!workspace.value) {
        return;
    }

    router.post(route('notifications.readAll', { workspace: workspace.value.slug }), {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

// Clicking a notification marks it read; the server then redirects to its link.
function openNotification(item) {
    if (!workspace.value) {
        return;
    }

    router.post(route('notifications.read', { workspace: workspace.value.slug, notification: item.id }), {}, {
        preserveScroll: true,
    });
}

// The mobile drawer is a full-screen overlay; it must close on every navigation.
const mobileNavOpen = ref(false);
watch(
    () => page.url,
    () => {
        mobileNavOpen.value = false;
    },
);

const simulating = ref(false);

function simulateInbound() {
    if (simulating.value || !workspace.value) {
        return;
    }

    simulating.value = true;

    router.post(
        route('inbox.simulate', { workspace: workspace.value.slug }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                simulating.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ *
 * Toasts. Any child page can `inject('toast')` and call it.
 * ------------------------------------------------------------------ */
const toasts = ref([]);
let toastId = 0;

function toast(message, type = 'info') {
    if (!message) {
        return;
    }

    const id = ++toastId;
    toasts.value.push({ id, message, type, icon: type === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check' });

    setTimeout(() => {
        toasts.value = toasts.value.filter((item) => item.id !== id);
    }, 4000);
}

provide('toast', toast);

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            toast(flash.success, 'success');
        }

        if (flash?.error) {
            toast(flash.error, 'error');
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="flex h-[100dvh] flex-col overflow-hidden bg-slate-50 font-sans text-slate-800">
        <!-- ---------------------------------------------------------- -->
        <!-- Top platform header                                         -->
        <!-- ---------------------------------------------------------- -->
        <header class="z-20 flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm sm:px-6">
            <div class="flex min-w-0 items-center gap-3 sm:gap-6">
                <button
                    type="button"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50 lg:hidden"
                    aria-label="Open navigation"
                    @click="mobileNavOpen = true"
                >
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="flex min-w-0 items-center gap-3">
                    <Link :href="route('dashboard')" class="flex items-center">
                        <img src="/images/logo.png" alt="SocialDesk" class="h-10 w-auto" />
                    </Link>
                    <div class="hidden leading-tight sm:block">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Workspace</p>
                        <p class="text-xs font-bold text-slate-700">{{ workspace?.name ?? '—' }}</p>
                    </div>
                </div>

                <!-- Channel connectivity badges -->
                <div class="hidden items-center gap-2 lg:flex">
                    <template v-if="channels.length">
                        <span
                            v-for="channel in channels"
                            :key="channel.channel + channel.name"
                            class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-600"
                        >
                            <i :class="[meta(channel.channel).icon, meta(channel.channel).text]" class="text-xs"></i>
                            <span class="max-w-[9rem] truncate">{{ channel.name }}</span>
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="channel.status === 'connected' ? 'bg-emerald-500' : 'bg-amber-400'"
                            ></span>
                        </span>
                    </template>
                    <Link
                        v-else
                        :href="route('channels.index', { workspace: workspace?.slug })"
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-amber-300 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 transition hover:border-amber-400 hover:bg-amber-100"
                    >
                        <i class="fa-solid fa-plug-circle-exclamation"></i> No channels connected — connect one
                    </Link>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Quota -->
                <div class="hidden items-center gap-3 md:flex">
                    <div class="text-right leading-tight">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Monthly Active Leads</p>
                        <p class="text-xs font-bold text-slate-700">
                            {{ usage.used.toLocaleString() }}
                            <span class="font-medium text-slate-400">/ {{ usage.limit ? usage.limit.toLocaleString() : '∞' }}</span>
                        </p>
                    </div>
                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="usage.percent >= 90 ? 'bg-rose-500' : 'bg-brand-600'"
                            :style="{ width: Math.min(100, usage.percent) + '%' }"
                        ></div>
                    </div>
                    <Link
                        :href="route('billing', { workspace: workspace?.slug })"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                    >
                        Upgrade
                    </Link>
                </div>

                <!-- Notifications -->
                <Dropdown align="right" width="72" content-classes="bg-white">
                    <template #trigger>
                        <button
                            type="button"
                            class="relative flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                            aria-label="Notifications"
                        >
                            <i class="fa-solid fa-bell"></i>
                            <span
                                v-if="notifications.unread"
                                class="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white"
                            >
                                {{ notifications.unread > 9 ? '9+' : notifications.unread }}
                            </span>
                        </button>
                    </template>
                    <template #content>
                        <div class="w-72">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                                <p class="text-xs font-bold text-slate-700">Notifications</p>
                                <button
                                    v-if="notifications.unread"
                                    type="button"
                                    class="text-[11px] font-semibold text-brand-600 hover:underline"
                                    @click="markAllRead"
                                >
                                    Mark all read
                                </button>
                            </div>

                            <div class="max-h-80 overflow-y-auto">
                                <button
                                    v-for="item in notifications.items"
                                    :key="item.id"
                                    type="button"
                                    class="flex w-full items-start gap-2.5 px-4 py-3 text-left transition hover:bg-slate-50"
                                    :class="!item.readAt && 'bg-brand-50/50'"
                                    @click="openNotification(item)"
                                >
                                    <i :class="[levelIcon(item.level), levelText(item.level)]" class="mt-0.5 text-xs"></i>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-xs font-semibold text-slate-700">{{ item.title }}</span>
                                        <span v-if="item.body" class="mt-0.5 block text-[11px] leading-snug text-slate-500">{{ item.body }}</span>
                                        <span class="mt-1 block text-[10px] font-medium text-slate-400">{{ item.createdAt }}</span>
                                    </span>
                                </button>

                                <p v-if="!notifications.items.length" class="px-4 py-8 text-center text-[11px] text-slate-400">
                                    You're all caught up.
                                </p>
                            </div>

                            <Link
                                v-if="workspace"
                                :href="route('notifications.index', { workspace: workspace.slug })"
                                class="block border-t border-slate-100 px-4 py-2.5 text-center text-[11px] font-semibold text-brand-600 transition hover:bg-slate-50"
                            >
                                View all
                            </Link>
                        </div>
                    </template>
                </Dropdown>

                <!-- Agent profile -->
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button type="button" class="flex items-center gap-2.5 rounded-xl px-1 py-1 transition hover:bg-slate-50">
                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">
                                {{ initials }}
                            </div>
                            <div class="hidden text-left leading-tight sm:block">
                                <p class="text-xs font-bold text-slate-700">{{ user?.name }}</p>
                                <p class="text-[11px] font-medium text-slate-400">{{ roleLabel }}</p>
                            </div>
                            <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                        </button>
                    </template>
                    <template #content>
                        <template v-if="myWorkspaces.length > 1">
                            <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                Switch workspace
                            </p>
                            <DropdownLink
                                v-for="item in myWorkspaces"
                                :key="item.id"
                                :href="route('workspaces.switch', { workspace: item.slug })"
                                method="post"
                                as="button"
                            >
                                <span class="flex items-center justify-between gap-3">
                                    <span class="truncate">{{ item.name }}</span>
                                    <i v-if="item.isCurrent" class="fa-solid fa-check text-[10px] text-brand-600"></i>
                                </span>
                            </DropdownLink>
                            <div class="my-1 border-t border-slate-100"></div>
                        </template>

                        <DropdownLink v-if="isSuperAdmin" :href="route('admin.dashboard')">Platform console</DropdownLink>
                        <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                        <DropdownLink :href="route('workspaces.create')">New workspace</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                    </template>
                </Dropdown>
            </div>
        </header>

        <!-- ---------------------------------------------------------- -->
        <!-- Tab navigation strip                                        -->
        <!-- ---------------------------------------------------------- -->
        <nav class="z-10 hidden h-12 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:flex">
            <div class="flex h-full min-w-0 items-center gap-1 overflow-x-auto">
                <Link
                    v-for="item in navItems"
                    :key="item.component"
                    :href="item.href"
                    class="flex h-full shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        isActive(item.component)
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i :class="item.icon" class="text-xs"></i> {{ item.label }}
                    <span
                        v-if="item.badge"
                        class="rounded-full px-1.5 py-0.5 text-[10px] font-bold"
                        :class="item.badgeClass"
                    >
                        {{ item.badge }}
                    </span>
                </Link>
            </div>

            <button
                v-if="canSimulateInbound"
                type="button"
                class="ml-3 inline-flex shrink-0 items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-700 transition hover:bg-brand-100 disabled:opacity-60"
                :disabled="simulating"
                @click="simulateInbound"
            >
                <i class="fa-solid text-[11px]" :class="simulating ? 'fa-circle-notch fa-spin' : 'fa-play'"></i>
                {{ simulating ? 'Simulating…' : 'Simulate inbound' }}
            </button>
        </nav>

        <!-- ---------------------------------------------------------- -->
        <!-- Mobile navigation drawer                                    -->
        <!-- ---------------------------------------------------------- -->
        <div v-if="mobileNavOpen" class="fixed inset-0 z-40 lg:hidden">
            <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" @click="mobileNavOpen = false"></div>

            <div class="relative flex h-full w-4/5 max-w-xs flex-col bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-100 p-4">
                    <img src="/images/logo.png" alt="SocialDesk" class="h-10 w-auto" />
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Close navigation"
                        @click="mobileNavOpen = false"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto p-3">
                    <Link
                        v-for="item in navItems"
                        :key="item.component"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition"
                        :class="
                            isActive(item.component)
                                ? 'bg-brand-50 text-brand-700'
                                : 'text-slate-600 hover:bg-slate-50'
                        "
                        @click="mobileNavOpen = false"
                    >
                        <i :class="item.icon" class="w-5 text-center text-sm"></i>
                        <span class="flex-1">{{ item.label }}</span>
                        <span
                            v-if="item.badge"
                            class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                            :class="item.badgeClass"
                        >
                            {{ item.badge }}
                        </span>
                    </Link>
                </nav>

                <div class="shrink-0 space-y-2 border-t border-slate-100 p-3">
                    <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        {{ workspace?.name ?? 'Workspace' }}
                    </p>
                    <button
                        v-if="canSimulateInbound"
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs font-bold text-brand-700 transition hover:bg-brand-100 disabled:opacity-60"
                        :disabled="simulating"
                        @click="simulateInbound"
                    >
                        <i class="fa-solid text-[11px]" :class="simulating ? 'fa-circle-notch fa-spin' : 'fa-play'"></i>
                        {{ simulating ? 'Simulating…' : 'Simulate inbound' }}
                    </button>
                    <Link
                        v-if="isSuperAdmin"
                        :href="route('admin.dashboard')"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-900 bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-800"
                        @click="mobileNavOpen = false"
                    >
                        <i class="fa-solid fa-shield-halved text-[11px]"></i> Platform console
                    </Link>
                    <Link
                        :href="route('profile.edit')"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                        @click="mobileNavOpen = false"
                    >
                        <i class="fa-solid fa-user-gear text-[11px]"></i> Account settings
                    </Link>
                </div>
            </div>
        </div>

        <!-- ---------------------------------------------------------- -->
        <!-- Page content                                                -->
        <!-- ---------------------------------------------------------- -->
        <main class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <slot />
        </main>

        <!-- Toasts -->
        <div class="pointer-events-none fixed left-4 right-4 top-20 z-50 flex flex-col gap-2 sm:left-auto sm:w-80">
            <transition-group
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-x-4 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="translate-x-4 opacity-0"
            >
                <div
                    v-for="item in toasts"
                    :key="item.id"
                    class="pointer-events-auto flex items-start gap-2.5 rounded-xl border bg-white p-3 shadow-lg"
                    :class="item.type === 'error' ? 'border-rose-200' : 'border-slate-200'"
                >
                    <i
                        :class="item.icon"
                        class="mt-0.5 text-sm"
                        :style="{ color: item.type === 'error' ? '#e11d48' : '#4f46e5' }"
                    ></i>
                    <p class="text-xs font-medium leading-relaxed text-slate-700">{{ item.message }}</p>
                </div>
            </transition-group>
        </div>
    </div>
</template>
