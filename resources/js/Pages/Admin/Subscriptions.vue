<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, { type AdminPaginatorMeta } from '@/Components/Admin/AdminPagination.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import BillingStats, { type BillingStatsPayload } from '@/Components/Admin/BillingStats.vue';
import { PrimaryButton } from '@/packages/ui/src';
import { CreditCardIcon } from '@heroicons/vue/20/solid';
import { Link } from '@inertiajs/vue3';
import { formatMoney, formatRelative } from '@/Components/Admin/format';

type SubscriptionRow = {
    id: string;
    organization_id: string;
    organization_name: string;
    plan: string;
    status: string;
    is_active: boolean;
    is_on_trial: boolean;
    seats: number | null;
    members_count: number | null;
    price: number | null;
    currency: string | null;
    billing_interval: string | null;
    monthly_price: number | null;
    trial_ends_at: string | null;
    ends_at: string | null;
};

const props = defineProps<{
    subscriptions: { data: SubscriptionRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
    stats: BillingStatsPayload;
    options: {
        plans: Record<string, string>;
        statuses: Record<string, string>;
        intervals: Record<string, string>;
    };
}>();

const columns: AdminColumn[] = [
    { key: 'organization', label: 'Organization' },
    { key: 'plan', label: 'Plan', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'seats', label: 'Members / seats', align: 'right' },
    { key: 'price', label: 'Price', sortable: true, align: 'right' },
    { key: 'renews', label: 'Trial / ends' },
];

function statusTone(row: SubscriptionRow): 'success' | 'info' | 'warning' | 'danger' {
    if (row.status === 'active') return 'success';
    if (row.status === 'trialing') return 'info';
    if (row.status === 'past-due') return 'warning';
    return 'danger';
}

/** Red when the organization has more members than it has paid for. */
function overSeats(row: SubscriptionRow): boolean {
    return row.seats !== null && (row.members_count ?? 0) > row.seats;
}

const statuses = props.options.statuses;
const plans = props.options.plans;
</script>

<template>
    <AdminLayout title="Billing">
        <template #header>
            <PageTitle :icon="CreditCardIcon" title="Billing" />
            <Link :href="route('admin.subscriptions.create')">
                <PrimaryButton>New subscription</PrimaryButton>
            </Link>
        </template>

        <MainContainer class="py-5">
            <BillingStats :stats="stats" />
        </MainContainer>

        <AdminFilterBar
            :filters="filters"
            :total="subscriptions.total"
            item-label="subscriptions"
            search-placeholder="Search by organization"
            :selects="[
                { key: 'plan', label: 'Plan', options: plans, anyLabel: 'Any plan' },
                { key: 'status', label: 'Status', options: statuses, anyLabel: 'Any status' },
                {
                    key: 'trials',
                    label: 'Trials',
                    options: { ending: 'Trial ending within a week' },
                    anyLabel: 'All trials',
                },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="minmax(200px, 2fr) 140px 130px 140px 150px minmax(160px, 1fr)"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="subscriptions.data.length"
            empty-message="No subscriptions match those filters.">
            <TableRow
                v-for="subscription in subscriptions.data"
                :key="subscription.id"
                :href="route('admin.subscriptions.edit', subscription.id)">
                <AdminTableCell edge="start">
                    <span class="font-medium text-text-primary truncate">
                        {{ subscription.organization_name }}
                    </span>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge :tone="subscription.plan === 'free' ? 'neutral' : 'success'">
                        {{ plans[subscription.plan] ?? subscription.plan }}
                    </AdminBadge>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge :tone="statusTone(subscription)">
                        {{ statuses[subscription.status] ?? subscription.status }}
                    </AdminBadge>
                </AdminTableCell>
                <AdminTableCell align="right">
                    <span
                        class="tabular-nums"
                        :class="overSeats(subscription) ? 'text-accent-600 font-medium' : ''">
                        {{ subscription.members_count ?? 0 }} /
                        {{ subscription.seats === null ? '∞' : subscription.seats }}
                    </span>
                </AdminTableCell>
                <AdminTableCell align="right">
                    <div class="text-right">
                        <div class="tabular-nums text-text-primary">
                            {{ formatMoney(subscription.price, subscription.currency) }}
                        </div>
                        <div
                            v-if="subscription.billing_interval"
                            class="text-xs text-text-tertiary">
                            {{ subscription.billing_interval }}
                        </div>
                    </div>
                </AdminTableCell>
                <AdminTableCell edge="end">
                    <span v-if="subscription.is_on_trial" class="text-text-secondary">
                        Trial ends {{ formatRelative(subscription.trial_ends_at) }}
                    </span>
                    <span v-else-if="subscription.ends_at" class="text-text-secondary">
                        Ends {{ formatRelative(subscription.ends_at) }}
                    </span>
                    <span v-else class="text-text-tertiary">--</span>
                </AdminTableCell>
            </TableRow>
        </AdminTable>

        <AdminPagination :meta="subscriptions" item-label="subscriptions" />
    </AdminLayout>
</template>
