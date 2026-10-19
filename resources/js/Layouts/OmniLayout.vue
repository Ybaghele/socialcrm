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
    <div class="flex h-screen flex-col overflow-hidden bg-slate-50 font-sans text-slate-800">
        <!-- ---------------------------------------------------------- -->
        <!-- Top platform header                                         -->
        <!-- ---------------------------------------------------------- -->
        <header class="z-20 flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm sm:px-6">
            <div class="flex items-center gap-4 sm:gap-6">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-indigo-400 text-white shadow-md shadow-brand-500/30">
                        <i class="fa-solid fa-bolt text-sm"></i>
                    </div>
                    <div class="leading-tight">
                        <p class="text-sm font-extrabold tracking-tight text-slate-900">OmniFlow</p>
                        <p class="text-[11px] font-medium text-slate-400">{{ workspace?.name ?? 'Workspace' }}</p>
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
        <nav class="z-10 flex h-12 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
            <div class="flex h-full items-center gap-1">
                <Link
                    :href="route('inbox', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Inbox'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-inbox text-xs"></i> Unified Inbox
                    <span
                        v-if="navCounts.unread"
                        class="rounded-full bg-brand-100 px-1.5 py-0.5 text-[10px] font-bold text-brand-700"
                    >
                        {{ navCounts.unread }}
                    </span>
                </Link>

                <Link
                    :href="route('contacts.index', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Contacts'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-address-book text-xs"></i> Lead CRM
                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">
                        {{ navCounts.contacts }}
                    </span>
                </Link>

                <Link
                    :href="route('campaigns.index', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Campaigns'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-bullhorn text-xs"></i> Campaigns
                    <span
                        v-if="navCounts.activeCampaigns"
                        class="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700"
                    >
                        {{ navCounts.activeCampaigns }} live
                    </span>
                </Link>

                <Link
                    :href="route('templates.index', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Templates'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-file-lines text-xs"></i> Templates
                </Link>

                <Link
                    :href="route('billing', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Billing'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-wallet text-xs"></i> Billing
                </Link>

                <Link
                    :href="route('channels.index', { workspace: workspace?.slug })"
                    class="flex h-full items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        $page.component === 'Channels'
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i class="fa-solid fa-plug text-xs"></i> Channels
                    <span
                        v-if="!channels.length"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700"
                    >
                        !
                    </span>
                </Link>
            </div>

            <button
                v-if="canSimulateInbound"
                type="button"
                class="hidden items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-bold text-brand-700 transition hover:bg-brand-100 disabled:opacity-60 sm:inline-flex"
                :disabled="simulating"
                @click="simulateInbound"
            >
                <i class="fa-solid text-[11px]" :class="simulating ? 'fa-circle-notch fa-spin' : 'fa-play'"></i>
                {{ simulating ? 'Simulating…' : 'Simulate inbound' }}
            </button>
        </nav>

        <!-- ---------------------------------------------------------- -->
        <!-- Page content                                                -->
        <!-- ---------------------------------------------------------- -->
        <main class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <slot />
        </main>

        <!-- Toasts -->
        <div class="pointer-events-none fixed right-4 top-20 z-50 flex w-80 flex-col gap-2">
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
