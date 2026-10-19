<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-slate-950 via-brand-900 to-indigo-900 px-4 py-10"
    >
        <div class="w-full max-w-md">
            <Link href="/" class="mb-8 flex justify-center">
                <img src="/images/logo-white.png" alt="SocialDesk" class="h-11 w-auto" />
            </Link>

            <div class="overflow-hidden rounded-2xl bg-white px-6 py-8 shadow-2xl shadow-black/40">
                <slot />
            </div>

            <p class="mt-6 text-center text-xs font-medium text-white/50">
                © {{ new Date().getFullYear() }} SocialDesk — Unified customer messaging
            </p>
        </div>
    </div>
</template>
