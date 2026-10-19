<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { route } from '@ziggy';

defineProps({
    reason: { type: String, required: true },
    mismatch: { type: Boolean, default: false },
});
</script>

<template>
    <GuestLayout>
        <Head title="Invitation" />

        <div class="text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-500">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h1 class="mt-4 text-lg font-bold text-slate-900">Invitation unavailable</h1>
            <p class="mt-2 text-sm text-slate-500">{{ reason }}</p>
        </div>

        <div class="mt-6 space-y-3">
            <Link
                v-if="mismatch"
                :href="route('logout')"
                method="post"
                as="button"
                class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-50"
            >
                <i class="fa-solid fa-right-from-bracket text-xs"></i>
                Sign in with the invited email
            </Link>

            <Link
                :href="route('home')"
                class="flex w-full items-center justify-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-bold text-brand-700 transition hover:bg-brand-100"
            >
                Back to SocialDesk
            </Link>
        </div>
    </GuestLayout>
</template>
