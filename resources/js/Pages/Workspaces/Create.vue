<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    defaultName: { type: String, default: '' },
    hasWorkspace: { type: Boolean, default: false },
    trialDays: { type: Number, default: 14 },
});

const form = useForm({ name: props.defaultName });

const submit = () => {
    form.post(route('workspaces.store'));
};
</script>

<template>
    <Head title="Create workspace" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Create a workspace
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <p class="text-sm text-gray-600">
                            <template v-if="hasWorkspace">
                                A workspace keeps its own channels, contacts,
                                campaigns and wallet. You will be its owner.
                            </template>
                            <template v-else>
                                A workspace is where your WhatsApp, Instagram
                                and Messenger conversations live. You get a
                                {{ trialDays }}-day trial — no card needed.
                            </template>
                        </p>

                        <form class="mt-6 max-w-md" @submit.prevent="submit">
                            <InputLabel for="name" value="Business name" />

                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.name"
                                required
                                autofocus
                                autocomplete="organization"
                                placeholder="e.g. Acme Traders"
                            />

                            <InputError class="mt-2" :message="form.errors.name" />

                            <div class="mt-6 flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">
                                    Create workspace
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
