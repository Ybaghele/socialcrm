<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { route } from '@ziggy';

const props = defineProps({
    token: { type: String, required: true },
    workspaceName: { type: String, required: true },
    inviterName: { type: String, default: null },
    role: { type: String, required: true },
    expiresAt: { type: String, default: null },
});

const form = useForm({});

function accept() {
    form.post(route('invitations.accept', { token: props.token }));
}

const roleLabel = props.role === 'admin' ? 'an Admin' : 'an Agent';
</script>

<template>
    <GuestLayout>
        <Head title="Join team" />

        <div class="text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                <i class="fa-solid fa-users"></i>
            </div>
            <h1 class="mt-4 text-lg font-bold text-slate-900">Join {{ workspaceName }}</h1>
            <p class="mt-2 text-sm text-slate-500">
                <template v-if="inviterName"><strong>{{ inviterName }}</strong> invited you</template>
                <template v-else>You've been invited</template>
                to join this workspace as {{ roleLabel }}.
            </p>
        </div>

        <button
            type="button"
            class="mt-6 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-60"
            :disabled="form.processing"
            @click="accept"
        >
            <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-circle-check'"></i>
            Accept invitation
        </button>

        <p class="mt-4 text-center text-[11px] text-slate-400">
            You'll be able to switch back to your other workspaces from the profile menu.
        </p>
    </GuestLayout>
</template>
