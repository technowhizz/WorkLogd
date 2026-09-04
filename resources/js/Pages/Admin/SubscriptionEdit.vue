<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import Card from '@/Components/Common/Card.vue';
import AdminDangerDialog from '@/Components/Admin/AdminDangerDialog.vue';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput } from '@/packages/ui/src';
import { CreditCardIcon } from '@heroicons/vue/20/solid';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Subscription = {
    id: string;
    organization_id: string;
    organization_name: string;
    plan: string;
    status: string;
    seats: number | null;
    price: number | null;
    currency: string | null;
    billing_interval: string | null;
    external_reference: string | null;
    note: string | null;
    starts_at: string | null;
    trial_ends_at_input: string | null;
    ends_at_input: string | null;
};

const props = defineProps<{
    subscription: Subscription | null;
    organizationId: string | null;
    organizations: { id: string; name: string }[];
    options: {
        plans: Record<string, string>;
        statuses: Record<string, string>;
        intervals: Record<string, string>;
        currencies: Record<string, string>;
        trial_days: number;
    };
}>();

const isNew = props.subscription === null;

const form = useForm({
    organization_id: props.subscription?.organization_id ?? props.organizationId ?? '',
    plan: props.subscription?.plan ?? 'free',
    status: props.subscription?.status ?? 'active',
    starts_at: props.subscription?.starts_at ?? '',
    trial_ends_at: props.subscription?.trial_ends_at_input ?? '',
    ends_at: props.subscription?.ends_at_input ?? '',
    seats: props.subscription?.seats ?? null,
    price: props.subscription?.price ?? null,
    currency: props.subscription?.currency ?? '',
    billing_interval: props.subscription?.billing_interval ?? '',
    external_reference: props.subscription?.external_reference ?? '',
    note: props.subscription?.note ?? '',
});

function save() {
    if (isNew) {
        form.post(route('admin.subscriptions.store'));
        return;
    }

    form.put(route('admin.subscriptions.update', props.subscription!.id), { preserveScroll: true });
}

function startTrial() {
    router.post(
        route('admin.subscriptions.start-trial', props.subscription!.id),
        {},
        { preserveScroll: true }
    );
}

const showDelete = ref(false);

function destroy() {
    router.delete(route('admin.subscriptions.destroy', props.subscription!.id));
}
</script>

<template>
    <AdminLayout :title="isNew ? 'New subscription' : subscription!.organization_name">
        <template #header>
            <PageTitle
                :icon="CreditCardIcon"
                :title="isNew ? 'New subscription' : subscription!.organization_name" />
            <div v-if="!isNew" class="flex items-center gap-2">
                <SecondaryButton @click="startTrial">
                    Start {{ options.trial_days }} day trial
                </SecondaryButton>
                <SecondaryButton
                    class="text-accent-600 border-accent-500/30"
                    @click="showDelete = true"
                    >Delete</SecondaryButton
                >
            </div>
        </template>

        <MainContainer class="py-6">
            <Card class="max-w-3xl">
                <form class="p-5 space-y-5" @submit.prevent="save">
                    <div>
                        <InputLabel for="organization_id" value="Organization" />
                        <select
                            id="organization_id"
                            v-model="form.organization_id"
                            :disabled="!isNew"
                            class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm disabled:opacity-60">
                            <option value="" disabled>Choose an organization</option>
                            <option v-for="option in organizations" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.organization_id" class="text-xs text-accent-600 mt-1">
                            {{ form.errors.organization_id }}
                        </p>
                        <p v-if="!isNew" class="text-xs text-text-tertiary mt-1">
                            One subscription per organization, so this cannot be moved. Delete it
                            and create another instead.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="plan" value="Plan" />
                            <select
                                id="plan"
                                v-model="form.plan"
                                class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option v-for="(label, value) in options.plans" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel for="status" value="Status" />
                            <select
                                id="status"
                                v-model="form.status"
                                class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option v-for="(label, value) in options.statuses" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <InputLabel for="starts_at" value="Starts at" />
                            <TextInput
                                id="starts_at"
                                v-model="form.starts_at"
                                type="datetime-local"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel for="ends_at" value="Ends at" />
                            <TextInput
                                id="ends_at"
                                v-model="form.ends_at"
                                type="datetime-local"
                                class="w-full mt-1" />
                            <p class="text-xs text-text-tertiary mt-1">
                                Leave empty for a subscription that keeps renewing.
                            </p>
                        </div>

                        <div>
                            <InputLabel for="trial_ends_at" value="Trial ends at" />
                            <TextInput
                                id="trial_ends_at"
                                v-model="form.trial_ends_at"
                                type="datetime-local"
                                class="w-full mt-1" />
                            <p class="text-xs text-text-tertiary mt-1">
                                Only counts while the status is trialing.
                            </p>
                        </div>
                        <div>
                            <InputLabel for="seats" value="Seats" />
                            <TextInput id="seats" v-model="form.seats" type="number" class="w-full mt-1" />
                            <p class="text-xs text-text-tertiary mt-1">
                                Real members paid for. Empty means no cap.
                            </p>
                            <p v-if="form.errors.seats" class="text-xs text-accent-600 mt-1">
                                {{ form.errors.seats }}
                            </p>
                        </div>

                        <div>
                            <InputLabel for="price" value="Price per interval (in cents)" />
                            <TextInput id="price" v-model="form.price" type="number" class="w-full mt-1" />
                            <p v-if="form.errors.price" class="text-xs text-accent-600 mt-1">
                                {{ form.errors.price }}
                            </p>
                        </div>
                        <div>
                            <InputLabel for="billing_interval" value="Billing interval" />
                            <select
                                id="billing_interval"
                                v-model="form.billing_interval"
                                class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option value="">Not set</option>
                                <option v-for="(label, value) in options.intervals" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <InputLabel for="currency" value="Currency" />
                            <select
                                id="currency"
                                v-model="form.currency"
                                class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option value="">Not set</option>
                                <option v-for="(label, code) in options.currencies" :key="code" :value="code">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <InputLabel for="external_reference" value="External reference" />
                            <TextInput
                                id="external_reference"
                                v-model="form.external_reference"
                                type="text"
                                class="w-full mt-1" />
                            <p class="text-xs text-text-tertiary mt-1">
                                How this is identified wherever the money is actually taken.
                            </p>
                        </div>
                    </div>

                    <div>
                        <InputLabel for="note" value="Note" />
                        <textarea
                            id="note"
                            v-model="form.note"
                            rows="3"
                            class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm"></textarea>
                    </div>

                    <div class="pt-1">
                        <PrimaryButton type="submit" :loading="form.processing">
                            {{ isNew ? 'Create subscription' : 'Save' }}
                        </PrimaryButton>
                    </div>
                </form>
            </Card>
        </MainContainer>

        <AdminDangerDialog
            v-if="!isNew"
            v-model:show="showDelete"
            title="Delete this subscription?"
            action-label="Delete subscription"
            @confirm="destroy">
            The organization keeps all its data and reverts to having no billing arrangement
            recorded. Nothing is cancelled wherever the money is actually taken.
        </AdminDangerDialog>
    </AdminLayout>
</template>
