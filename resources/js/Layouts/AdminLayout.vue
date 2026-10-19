<script setup>
import { computed, provide, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
);

// Built inside a computed so Ziggy's config is guaranteed to be in place by the
// time the routes are resolved (same pattern as OmniLayout).
const navItems = computed(() => [
    { label: 'Overview', icon: 'fa-solid fa-gauge-high', href: route('admin.dashboard'), match: ['Admin/Dashboard'] },
    { label: 'Workspaces', icon: 'fa-solid fa-building', href: route('admin.workspaces.index'), match: ['Admin/Workspaces', 'Admin/Workspace'] },
    { label: 'Plans', icon: 'fa-solid fa-layer-group', href: route('admin.plans.index'), match: ['Admin/Plans'] },
    { label: 'Webhooks', icon: 'fa-solid fa-satellite-dish', href: route('admin.webhooks.index'), match: ['Admin/Webhooks'] },
]);

function isActive(item) {
    return item.match.includes(page.component);
}

/* Toasts — same pattern as OmniLayout so flash messages behave identically. */
const toasts = ref([]);
let toastId = 0;

function toast(message, type = 'info') {
    if (!message) {
        return;
    }

    const id = ++toastId;
    toasts.value.push({
        id,
        message,
        type,
        icon: type === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check',
    });

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
    <div class="flex h-[100dvh] flex-col overflow-hidden bg-slate-100 font-sans text-slate-800">
        <header class="z-20 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-slate-800 bg-slate-900 px-4 shadow-sm sm:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('admin.dashboard')" class="flex items-center gap-2.5">
                    <img src="/images/logo.png" alt="SocialDesk" class="h-9 w-auto rounded bg-white p-0.5" />
                    <span class="hidden leading-tight sm:block">
                        <span class="block text-sm font-bold text-white">SocialDesk</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-widest text-brand-300">Platform console</span>
                    </span>
                </Link>
            </div>

            <div class="flex items-center gap-3">
                <Link
                    :href="route('dashboard')"
                    class="hidden items-center gap-2 rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white sm:inline-flex"
                >
                    <i class="fa-solid fa-arrow-left text-[10px]"></i> Back to app
                </Link>
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                        {{ initials }}
                    </div>
                    <div class="hidden text-left leading-tight sm:block">
                        <p class="text-xs font-bold text-white">{{ user?.name }}</p>
                        <p class="text-[11px] font-medium text-slate-400">Platform staff</p>
                    </div>
                </div>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-800 hover:text-white"
                    aria-label="Log out"
                >
                    <i class="fa-solid fa-right-from-bracket"></i>
                </Link>
            </div>
        </header>

        <nav class="z-10 flex h-12 shrink-0 items-center border-b border-slate-200 bg-white px-2 sm:px-6">
            <div class="flex h-full min-w-0 items-center gap-1 overflow-x-auto">
                <Link
                    v-for="item in navItems"
                    :key="item.label"
                    :href="item.href"
                    class="flex h-full shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition"
                    :class="
                        isActive(item)
                            ? 'border-brand-600 text-brand-700'
                            : 'border-transparent text-slate-500 hover:text-slate-800'
                    "
                >
                    <i :class="item.icon" class="text-xs"></i> {{ item.label }}
                </Link>
            </div>
        </nav>

        <main class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </main>

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
