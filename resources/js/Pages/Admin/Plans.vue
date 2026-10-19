<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@ziggy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    plans: { type: Array, required: true },
});

const editing = ref(null);
const errors = ref({});
const processing = ref(false);

const fields = reactive({
    name: '',
    price: 0,
    monthly_active_contacts: 0,
    max_agent_seats: 0,
    max_instagram_accounts: 0,
    is_active: true,
});

const rupee = (paise) =>
    '₹' + (Number(paise ?? 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 });

const number = (value) => Number(value ?? 0).toLocaleString('en-IN');

function openEdit(plan) {
    editing.value = plan;
    errors.value = {};

    Object.assign(fields, {
        name: plan.name,
        price: Math.round(plan.pricePaise / 100),
        monthly_active_contacts: plan.monthlyActiveContacts,
        max_agent_seats: plan.maxAgentSeats,
        max_instagram_accounts: plan.maxInstagramAccounts,
        is_active: plan.isActive,
    });
}

function save() {
    processing.value = true;

    router.patch(route('admin.plans.update', { plan: editing.value.id }), { ...fields }, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
        onError: (bag) => (errors.value = bag),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <AdminLayout>
        <div class="mx-auto w-full max-w-6xl p-4 sm:p-8">
            <div class="mb-5">
                <h1 class="text-xl font-bold text-slate-900">Plans</h1>
                <p class="mt-1 text-xs text-slate-500">
                    The catalogue shown on the landing page and in tenant Billing. Edits apply to every tenant on the plan.
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="border-b border-slate-100 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Plan</th>
                                <th class="px-5 py-3 font-semibold">Price</th>
                                <th class="px-5 py-3 font-semibold">Active leads</th>
                                <th class="px-5 py-3 font-semibold">Seats</th>
                                <th class="px-5 py-3 font-semibold">IG accounts</th>
                                <th class="px-5 py-3 font-semibold">Subscribers</th>
                                <th class="px-5 py-3 font-semibold">Available</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="plan in plans" :key="plan.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-3.5">
                                    <p class="font-semibold text-slate-800">{{ plan.name }}</p>
                                    <p class="text-[11px] text-slate-400">{{ plan.slug }}</p>
                                </td>
                                <td class="px-5 py-3.5 font-semibold text-slate-700">{{ rupee(plan.pricePaise) }}</td>
                                <td class="px-5 py-3.5 text-slate-700">{{ number(plan.monthlyActiveContacts) }}</td>
                                <td class="px-5 py-3.5 text-slate-700">{{ number(plan.maxAgentSeats) }}</td>
                                <td class="px-5 py-3.5 text-slate-700">{{ number(plan.maxInstagramAccounts) }}</td>
                                <td class="px-5 py-3.5 text-slate-700">{{ number(plan.subscribers) }}</td>
                                <td class="px-5 py-3.5">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                                        :class="plan.isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ plan.isActive ? 'Active' : 'Hidden' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button
                                        type="button"
                                        class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-200"
                                        @click="openEdit(plan)"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="!plans.length" class="px-6 py-16 text-center text-xs text-slate-400">
                        No plans have been seeded yet.
                    </p>
                </div>
            </div>
        </div>

        <Modal :show="!!editing" max-width="md" @close="editing = null">
            <div v-if="editing" class="p-6">
                <h3 class="text-sm font-bold text-slate-900">Edit {{ editing.name }}</h3>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Name</label>
                        <input v-model="fields.name" type="text" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="errors.name" class="mt-1 text-[11px] text-rose-600">{{ errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Price (₹ / month)</label>
                        <input v-model="fields.price" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="errors.price" class="mt-1 text-[11px] text-rose-600">{{ errors.price }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Active leads / month</label>
                        <input v-model="fields.monthly_active_contacts" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="errors.monthly_active_contacts" class="mt-1 text-[11px] text-rose-600">{{ errors.monthly_active_contacts }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Agent seats</label>
                        <input v-model="fields.max_agent_seats" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="errors.max_agent_seats" class="mt-1 text-[11px] text-rose-600">{{ errors.max_agent_seats }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">Instagram accounts</label>
                        <input v-model="fields.max_instagram_accounts" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700 outline-none focus:border-brand-400" />
                        <p v-if="errors.max_instagram_accounts" class="mt-1 text-[11px] text-rose-600">{{ errors.max_instagram_accounts }}</p>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-xs font-semibold text-slate-600">
                    <input v-model="fields.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                    Available (unticked hides it from the landing page and new signups)
                </label>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" @click="editing = null">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="processing"
                        @click="save"
                    >
                        {{ processing ? 'Saving…' : 'Save plan' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
