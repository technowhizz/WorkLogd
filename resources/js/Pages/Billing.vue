<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import Card from '@/Components/Common/Card.vue';
import { PrimaryButton, SecondaryButton } from '@/packages/ui/src';
import { CreditCardIcon, CheckIcon } from '@heroicons/vue/20/solid';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Subscription = {
    status: string;
    price: string | null;
    quantity: number | null;
    interval: string | null;
    is_active: boolean;
    on_grace_period: boolean;
    ends_at: string | null;
};

const props = defineProps<{
    organization: { id: string; name: string };
    seats: number;
    currency: string;
    prices: { monthly: string | null; yearly: string | null };
    subscription: Subscription | null;
    enforced: boolean;
    entitled: boolean;
}>();

const checkout = useForm({ interval: 'yearly' });

function subscribe(interval: 'monthly' | 'yearly') {
    checkout.interval = interval;
    checkout.post(route('billing.checkout'));
}

function manage() {
    router.get(route('billing.portal'));
}

const isSubscribed = computed(() => props.subscription?.is_active === true);

const statusLabel = computed(() => {
    if (!props.subscription) return 'Free';
    if (props.subscription.on_grace_period) return 'Cancelling';
    return (
        {
            active: 'Active',
            past_due: 'Payment failed',
            unpaid: 'Unpaid',
            canceled: 'Cancelled',
            incomplete: 'Awaiting payment',
        }[props.subscription.status] ?? props.subscription.status
    );
});

const professionalFeatures = [
    'Unlimited team members',
    'Unlimited Jira worklog syncing',
    'Google Calendar integration',
    'Project & task estimates',
    'Rounding',
    'Shareable and PDF reports',
];

const freeFeatures = [
    'One user',
    '5 Jira worklogs synced per week',
    'Clients, projects, tags & tasks',
    'Billable hours & rates',
    'Reporting',
];
</script>

<template>
    <AppLayout title="Billing" data-testid="billing_view">
        <MainContainer
            class="py-5 border-b border-default-background-separator flex justify-between items-center">
            <PageTitle :icon="CreditCardIcon" title="Billing" />
            <SecondaryButton v-if="subscription" @click="manage">
                Manage payment & invoices
            </SecondaryButton>
        </MainContainer>

        <MainContainer class="py-6 space-y-6">
            <Card>
                <div class="p-5 flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="font-medium text-text-primary">
                            {{ isSubscribed ? 'Professional' : 'Free' }}
                        </h3>
                        <p class="text-sm text-text-secondary pt-1">
                            {{ organization.name }} &middot; {{ seats }}
                            {{ seats === 1 ? 'member' : 'members' }}
                            <template v-if="subscription?.quantity">
                                &middot; billed for {{ subscription.quantity }}
                            </template>
                        </p>
                        <p
                            v-if="subscription?.on_grace_period && subscription.ends_at"
                            class="text-sm text-amber-600 dark:text-amber-400 pt-1">
                            Cancelled &mdash; access continues until
                            {{ new Date(subscription.ends_at).toLocaleDateString() }}.
                        </p>
                        <p
                            v-else-if="subscription?.status === 'past_due'"
                            class="text-sm text-accent-600 pt-1">
                            The last payment failed. Update the card to keep the plan active.
                        </p>
                    </div>
                    <span class="text-sm text-text-tertiary">{{ statusLabel }}</span>
                </div>
            </Card>

            <div
                v-if="!enforced"
                class="rounded-lg border border-border-secondary bg-secondary px-4 py-3 text-sm text-text-secondary">
                Plan limits are not being applied on this instance yet, so everything is available
                regardless of what is shown here.
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card>
                    <div class="p-5 space-y-4">
                        <div>
                            <h4 class="font-medium text-text-primary">Free</h4>
                            <p class="text-sm text-text-tertiary pt-1">
                                For one person tracking their own work.
                            </p>
                        </div>
                        <ul class="space-y-1.5">
                            <li
                                v-for="feature in freeFeatures"
                                :key="feature"
                                class="flex items-start gap-2 text-sm text-text-secondary">
                                <CheckIcon class="w-4 h-4 mt-0.5 shrink-0 text-icon-default" />
                                <span>{{ feature }}</span>
                            </li>
                        </ul>
                    </div>
                </Card>

                <Card>
                    <div class="p-5 space-y-4">
                        <div>
                            <h4 class="font-medium text-text-primary">Professional</h4>
                            <p class="text-sm text-text-tertiary pt-1">
                                Per member, per month. Charged for the
                                {{ seats }} {{ seats === 1 ? 'member' : 'members' }} you have now
                                &mdash; adding people adjusts it automatically.
                            </p>
                        </div>
                        <ul class="space-y-1.5">
                            <li
                                v-for="feature in professionalFeatures"
                                :key="feature"
                                class="flex items-start gap-2 text-sm text-text-secondary">
                                <CheckIcon class="w-4 h-4 mt-0.5 shrink-0 text-accent-600" />
                                <span>{{ feature }}</span>
                            </li>
                        </ul>

                        <div v-if="!isSubscribed" class="flex flex-wrap gap-2 pt-1">
                            <PrimaryButton
                                v-if="prices.yearly"
                                data-testid="subscribe_yearly"
                                :loading="checkout.processing"
                                @click="subscribe('yearly')">
                                Subscribe yearly
                            </PrimaryButton>
                            <SecondaryButton
                                v-if="prices.monthly"
                                data-testid="subscribe_monthly"
                                :loading="checkout.processing"
                                @click="subscribe('monthly')">
                                Subscribe monthly
                            </SecondaryButton>
                            <p
                                v-if="!prices.yearly && !prices.monthly"
                                class="text-sm text-text-tertiary">
                                No plan is available to buy on this instance yet.
                            </p>
                        </div>

                        <div v-else class="flex flex-wrap gap-2 pt-1">
                            <SecondaryButton
                                v-if="subscription?.interval === 'monthly' && prices.yearly"
                                :loading="checkout.processing"
                                @click="subscribe('yearly')">
                                Switch to yearly
                            </SecondaryButton>
                            <SecondaryButton
                                v-if="subscription?.interval === 'yearly' && prices.monthly"
                                :loading="checkout.processing"
                                @click="subscribe('monthly')">
                                Switch to monthly
                            </SecondaryButton>
                        </div>
                    </div>
                </Card>
            </div>

            <p class="text-sm text-text-tertiary">
                Need something else &mdash; a larger team, an invoice, or terms of your own? Get in
                touch and we will sort it out.
            </p>
        </MainContainer>
    </AppLayout>
</template>
