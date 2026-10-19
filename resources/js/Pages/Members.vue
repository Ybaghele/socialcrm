<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { route } from '@ziggy';
import OmniLayout from '@/Layouts/OmniLayout.vue';

const props = defineProps({
    members: { type: Array, required: true },
    invitations: { type: Array, required: true },
    seats: { type: Object, required: true },
    currentRole: { type: String, required: true },
    roles: { type: Array, required: true },
});

const page = usePage();
const workspaceSlug = computed(() => page.props.workspace?.slug);
const inviteLink = computed(() => page.props.flash?.inviteLink ?? null);
const copied = ref(false);

const form = useForm({
    email: '',
    role: props.roles.includes('agent') ? 'agent' : props.roles[0],
});

const seatsFull = computed(() => props.seats.max !== null && props.seats.used >= props.seats.max);

function initials(name) {
    return (name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function invite() {
    form.post(route('members.invitations.store', { workspace: workspaceSlug.value }), {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });
}

function resend(invitation) {
    router.post(
        route('members.invitations.resend', { workspace: workspaceSlug.value, invitation: invitation.id }),
        {},
        { preserveScroll: true },
    );
}

function revoke(invitation) {
    if (!window.confirm(`Revoke the invitation to ${invitation.email}?`)) {
        return;
    }

    router.delete(
        route('members.invitations.destroy', { workspace: workspaceSlug.value, invitation: invitation.id }),
        { preserveScroll: true },
    );
}

function changeRole(member, event) {
    router.patch(
        route('members.update', { workspace: workspaceSlug.value, user: member.id }),
        { role: event.target.value },
        { preserveScroll: true },
    );
}

function remove(member) {
    if (!window.confirm(`Remove ${member.name} from this workspace?`)) {
        return;
    }

    router.delete(
        route('members.destroy', { workspace: workspaceSlug.value, user: member.id }),
        { preserveScroll: true },
    );
}

function copyLink() {
    navigator.clipboard?.writeText(inviteLink.value).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    });
}

function expiresLabel(iso) {
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

// The owner can manage everyone but themselves/the owner; an admin only manages agents.
function canEdit(member) {
    if (member.isOwner || member.isSelf) {
        return false;
    }

    return props.currentRole === 'owner' || member.role === 'agent';
}
</script>

<template>
    <OmniLayout>
        <div class="flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-8">
            <div class="mx-auto max-w-4xl space-y-6">
                <!-- Header -->
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-bold text-slate-900">Team</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Invite agents, assign roles and keep an eye on your seats.
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-right shadow-sm">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Agent seats</p>
                        <p class="text-sm font-bold text-slate-800">
                            {{ seats.used }} <span class="font-medium text-slate-400">/ {{ seats.max ?? '∞' }}</span>
                        </p>
                    </div>
                </div>

                <!-- One-time invite link (mail may not be configured yet) -->
                <div
                    v-if="inviteLink"
                    class="flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-brand-700">Invitation link ready to share</p>
                        <p class="truncate text-[11px] text-brand-600/80">{{ inviteLink }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-brand-700"
                        @click="copyLink"
                    >
                        <i class="fa-solid" :class="copied ? 'fa-check' : 'fa-copy'"></i>
                        {{ copied ? 'Copied' : 'Copy link' }}
                    </button>
                </div>

                <!-- Invite -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-800">Invite a teammate</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        They get an email with a link to join. Invitations expire after 7 days.
                    </p>

                    <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="invite">
                        <div class="flex-1">
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                placeholder="teammate@company.com"
                                class="w-full rounded-lg border-slate-200 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                            />
                            <p v-if="form.errors.email" class="mt-1 text-[11px] font-medium text-rose-600">{{ form.errors.email }}</p>
                        </div>

                        <select
                            v-model="form.role"
                            class="rounded-lg border-slate-200 py-2 pl-3 pr-8 text-sm font-medium text-slate-600 focus:border-brand-500 focus:ring-brand-500"
                        >
                            <option v-for="role in roles" :key="role" :value="role">
                                {{ role === 'admin' ? 'Admin' : 'Agent' }}
                            </option>
                        </select>

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-60"
                            :disabled="form.processing || seatsFull"
                        >
                            <i class="fa-solid" :class="form.processing ? 'fa-circle-notch fa-spin' : 'fa-paper-plane'"></i>
                            Invite
                        </button>
                    </form>

                    <p v-if="seatsFull" class="mt-3 text-xs font-medium text-amber-600">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        You've used every seat on your plan. Upgrade on the Billing screen to add more.
                    </p>
                </div>

                <!-- Pending invitations -->
                <div v-if="invitations.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-800">
                        Pending invitations
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">{{ invitations.length }}</span>
                    </h2>
                    <ul class="divide-y divide-slate-100">
                        <li
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-700">{{ invitation.email }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ invitation.role === 'admin' ? 'Admin' : 'Agent' }}
                                    <template v-if="invitation.invitedBy"> · invited by {{ invitation.invitedBy }}</template>
                                    ·
                                    <span :class="invitation.isExpired ? 'text-rose-500' : ''">
                                        {{ invitation.isExpired ? 'expired' : 'expires' }} {{ expiresLabel(invitation.expiresAt) }}
                                    </span>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:text-brand-700"
                                    @click="resend(invitation)"
                                >
                                    Resend
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-50"
                                    @click="revoke(invitation)"
                                >
                                    Revoke
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Members -->
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-800">
                        Members
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">{{ members.length }}</span>
                    </h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="member in members" :key="member.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">
                                {{ initials(member.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-800">
                                    {{ member.name }}
                                    <span v-if="member.isSelf" class="ml-1 text-[11px] font-medium text-slate-400">(you)</span>
                                </p>
                                <p class="truncate text-[11px] text-slate-400">{{ member.email }}</p>
                            </div>

                            <span
                                v-if="member.isOwner"
                                class="rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-bold text-brand-700"
                            >
                                <i class="fa-solid fa-crown mr-1 text-[9px]"></i> Owner
                            </span>

                            <template v-else-if="canEdit(member)">
                                <select
                                    class="rounded-lg border-slate-200 py-1.5 pl-3 pr-8 text-xs font-semibold text-slate-600 focus:border-brand-500 focus:ring-brand-500"
                                    :value="member.role"
                                    @change="changeRole(member, $event)"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="agent">Agent</option>
                                </select>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-50"
                                    @click="remove(member)"
                                >
                                    Remove
                                </button>
                            </template>

                            <span
                                v-else
                                class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold capitalize text-slate-500"
                            >
                                {{ member.role }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </OmniLayout>
</template>
