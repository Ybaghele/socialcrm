<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@ziggy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    filters: { type: Object, required: true },
    events: { type: Object, required: true },
    counts: { type: Object, required: true },
});

const filters = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? 'all',
    provider: props.filters.provider ?? 'all',
});

const viewing = ref(null);

const number = (value) => Number(value ?? 0).toLocaleString('en-IN');

const dateTime = (iso) =>
    iso
        ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
        : '—';

const statusTone = {
    processed: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
    ignored: 'bg-slate-100 text-slate-500',
};

const pills = [
    { key: 'pending', label: 'Pending', tone: 'bg-amber-50 text-amber-700 border-amber-200' },
    { key: 'processed', label: 'Processed', tone: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    { key: 'failed', label: 'Failed', tone: 'bg-rose-50 text-rose-700 border-rose-200' },
    { key: 'ignored', label: 'Ignored', tone: 'bg-slate-50 text-slate-600 border-slate-200' },
];

function apply() {
    router.get(
        route('admin.webhooks.index'),
        {
            q: filters.q || undefined,
            status: filters.status === 'all' ? undefined : filters.status,
            provider: filters.provider === 'all' ? undefined : filters.provider,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function filterByStatus(status) {
    filters.status = filters.status === status ? 'all' : status;
    apply();
}

function retry(event) {
    router.post(route('admin.webhooks.retry', { webhookEvent: event.id }), {}, { preserveScroll: true });
}

const prettyPayload = (payload) => JSON.stringify(payload, null, 2);
</script>

<template>
    <AdminLayout>
        <div class="mx-auto w-full max-w-7xl p-4 sm:p-8">
            <div class="mb-5">
                <h1 class="text-xl font-bold text-slate-900">Webhook events</h1>
                <p class="mt-1 text-xs text-slate-500">
                    Raw inbound payloads, stored before processing. The first stop when a tenant reports a missing message.
                </p>
            </div>

            <div class="mb-4 flex flex-wrap gap-2">
                <button
                    v-for="pill in pills"
                    :key="pill.key"
                    type="button"
                    class="flex items-center gap-2 rounded-xl border px-3.5 py-2 text-left transition"
                    :class="[pill.tone, filters.status === pill.key && 'ring-2 ring-brand-400']"
                    @click="filterByStatus(pill.key)"
                >
                    <span class="text-sm font-bold">{{ number(counts[pill.key]) }}</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wide">{{ pill.label }}</span>
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400"></i>
                        <input
                            v-model="filters.q"
                            type="text"
                            placeholder="Search event type or id…"
                            class="w-64 rounded-lg border border-slate-200 py-2 pl-8 pr-3 text-xs font-medium text-slate-700 outline-none transition focus:border-brand-400"
                            @keyup.enter="apply"
                        />
                    </div>
                    <select v-model="filters.provider" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 outline-none focus:border-brand-400" @change="apply">
                        <option value="all">All providers</option>
                        <option value="meta">Meta</option>
                        <option value="razorpay">Razorpay</option>
                        <option value="shopify">Shopify</option>
                    </select>
                    <select v-model="filters.status" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 outline-none focus:border-brand-400" @change="apply">
                        <option value="all">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="processed">Processed</option>
                        <option value="failed">Failed</option>
                        <option value="ignored">Ignored</option>
                    </select>
                    <button type="button" class="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-800" @click="apply">
                        Apply
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[64rem] text-left text-sm">
                        <thead class="border-b border-slate-100 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">#</th>
                                <th class="px-5 py-3 font-semibold">Provider</th>
                                <th class="px-5 py-3 font-semibold">Event</th>
                                <th class="px-5 py-3 font-semibold">Channel</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Received</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="event in events.data" :key="event.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-3.5 font-semibold text-slate-700">{{ event.id }}</td>
                                <td class="px-5 py-3.5 text-slate-600">{{ event.provider }}</td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-slate-700">{{ event.eventType ?? '—' }}</p>
                                    <p v-if="event.error" class="max-w-sm truncate text-[11px] text-rose-500" :title="event.error">{{ event.error }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-slate-600">{{ event.channelAccount ?? '—' }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusTone[event.status]">
                                        {{ event.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-[11px] text-slate-400">{{ dateTime(event.createdAt) }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-200"
                                            @click="viewing = event"
                                        >
                                            Payload
                                        </button>
                                        <button
                                            v-if="event.retryable"
                                            type="button"
                                            class="rounded-lg bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 transition hover:bg-brand-100"
                                            @click="retry(event)"
                                        >
                                            Retry
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="!events.data.length" class="px-6 py-16 text-center text-xs text-slate-400">
                        No webhook events match these filters.
                    </p>
                </div>

                <div
                    v-if="events.links && events.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-3"
                >
                    <p class="text-[11px] text-slate-400">
                        Showing {{ events.from ?? 0 }}–{{ events.to ?? 0 }} of {{ events.total }}
                    </p>
                    <div class="flex flex-wrap items-center gap-1">
                        <a
                            v-for="(link, index) in events.links"
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

        <Modal :show="!!viewing" max-width="2xl" @close="viewing = null">
            <div v-if="viewing" class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Event #{{ viewing.id }}</h3>
                        <p class="text-[11px] text-slate-400">
                            {{ viewing.provider }} · {{ viewing.eventType ?? '—' }} · {{ dateTime(viewing.createdAt) }}
                        </p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusTone[viewing.status]">{{ viewing.status }}</span>
                </div>

                <p v-if="viewing.error" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-[11px] text-rose-700">{{ viewing.error }}</p>

                <pre class="mt-4 max-h-96 overflow-auto rounded-xl bg-slate-900 p-4 text-[11px] leading-relaxed text-slate-100">{{ prettyPayload(viewing.payload) }}</pre>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        v-if="viewing.retryable"
                        type="button"
                        class="rounded-lg bg-brand-50 px-3.5 py-2 text-xs font-semibold text-brand-700 transition hover:bg-brand-100"
                        @click="retry(viewing); viewing = null"
                    >
                        Retry this event
                    </button>
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="viewing = null">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
