<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-slate-50 to-slate-100 px-4 py-10"
    >
        <div class="w-full max-w-md">
            <Link href="/" class="mx-auto mb-8 flex w-fit items-center justify-center">
                <img src="/images/logo.png" alt="SocialDesk" class="h-16 w-auto" />
            </Link>

            <div class="overflow-hidden rounded-2xl bg-white px-6 py-8 shadow-xl shadow-slate-200/70 sm:px-8">
                <slot />
            </div>

            <p class="mt-6 text-center text-xs font-medium text-slate-400">
                © {{ new Date().getFullYear() }} SocialDesk — Unified customer messaging
            </p>
        </div>
    </div>
</template>
