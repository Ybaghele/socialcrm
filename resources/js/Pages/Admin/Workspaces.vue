<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@ziggy';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    filters: { type: Object, required: true },
    workspaces: { type: Object, required: true },
});

const filters = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? 'all',
});

const rupee = (paise) =>
    '₹' + (Number(paise ?? 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 });

const number = (value) => Number(value ?? 0).toLocaleString('en-IN');

const shortDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const statusTone = {
    active: 'bg-emerald-100 text-emerald-700',
    suspended: 'bg-rose-100 text-rose-700',
};

const subscriptionTone = {
    active: 'text-emerald-600',
    trialing: 'text-sky-600',
    past_due: 'text-amber-600',
    cancelled: 'text-slate-400',
};

function apply() {
    router.get(
        route('admin.workspaces.index'),
        { q: filters.q || undefined, status: filters.status === 'all' ? undefined : filters.status },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
</script>

<template>
    <AdminLayout>
        <div class="mx-auto w-full max-w-7xl p-4 sm:p-8">
            <div class="mb-5 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Workspaces</h1>
                    <p class="mt-1 text-xs text-slate-500">{{ number(workspaces.total) }} tenant(s) on the platform.</p>
                </div>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="apply">
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400"></i>
                        <input
                            v-model="filters.q"
                            type="text"
                            placeholder="Search name, slug, owner…"
                            class="w-64 rounded-lg border border-slate-200 bg-white py-2 pl-8 pr-3 text-xs font-medium text-slate-700 outline-none transition focus:border-brand-400"
                        />
                    </div>
                    <select
                        v-model="filters.status"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 outline-none transition focus:border-brand-400"
                        @change="apply"
                    >
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                    <button
                        type="submit"
                        class="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-800"
                    >
                        Search
                    </button>
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] text-left text-sm">
                        <thead class="border-b border-slate-100 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Workspace</th>
                                <th class="px-5 py-3 font-semibold">Owner</th>
                                <th class="px-5 py-3 font-semibold">Plan</th>
                                <th class="px-5 py-3 font-semibold">Active leads</th>
                                <th class="px-5 py-3 font-semibold">Wallet</th>
                                <th class="px-5 py-3 font-semibold">Team</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Created</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="workspace in workspaces.data" :key="workspace.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-3.5">
                                    <p class="font-semibold text-slate-800">{{ workspace.name }}</p>
                                    <p class="text-[11px] text-slate-400">/{{ workspace.slug }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-slate-700">{{ workspace.owner?.name ?? '—' }}</p>
                                    <p class="text-[11px] text-slate-400">{{ workspace.owner?.email ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-slate-700">{{ workspace.plan ?? 'No plan' }}</p>
                                    <p class="text-[11px] font-semibold" :class="subscriptionTone[workspace.subscriptionStatus] ?? 'text-slate-400'">
                                        {{ workspace.subscriptionStatus ?? 'none' }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-slate-700">
                                    {{ number(workspace.contactsUsed) }}
                                    <span class="text-[11px] text-slate-400">/ {{ workspace.contactsLimit ? number(workspace.contactsLimit) : '∞' }}</span>
                                </td>
                                <td class="px-5 py-3.5 font-semibold text-slate-700">{{ rupee(workspace.walletPaise) }}</td>
                                <td class="px-5 py-3.5 text-slate-700">{{ number(workspace.members) }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusTone[workspace.status]">
                                        {{ workspace.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-[11px] text-slate-400">{{ shortDate(workspace.createdAt) }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <Link
                                        :href="route('admin.workspaces.show', { workspace: workspace.id })"
                                        class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-200"
                                    >
                                        Manage
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="!workspaces.data.length" class="flex flex-col items-center gap-2 px-6 py-16 text-center">
                        <i class="fa-regular fa-building text-2xl text-slate-300"></i>
                        <p class="text-sm font-semibold text-slate-500">No workspaces match these filters</p>
                    </div>
                </div>

                <div
                    v-if="workspaces.links && workspaces.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-3"
                >
                    <p class="text-[11px] text-slate-400">
                        Showing {{ workspaces.from ?? 0 }}–{{ workspaces.to ?? 0 }} of {{ workspaces.total }}
                    </p>
                    <div class="flex flex-wrap items-center gap-1">
                        <a
                            v-for="(link, index) in workspaces.links"
                            :key="index"
                            :href="link.url || undefined"
                            class="rounded-md px-2.5 py-1 text-[11px] font-semibold transition"
                            :class="
                                link.active
                                    ? 'bg-brand-600 text-white'
                                    : link.url
                                      ? 'text-slate-600 hover:bg-slate-100'
                                      : 'pointer-events-none text-slate-300'
                            "
                            @click.prevent="link.url && router.get(link.url, {}, { preserveScroll: true, preserveState: true })"
                            v-html="link.label"
                        ></a>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
