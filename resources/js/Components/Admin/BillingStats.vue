<script setup lang="ts">
import AdminStat from '@/Components/Admin/AdminStat.vue';
import { formatMoney } from '@/Components/Admin/format';
import { computed } from 'vue';

export type BillingStatsPayload = {
    currency: string;
    monthly_revenue: number;
    paying: number;
    other_currencies: number;
    trialing: number;
    trials_ending_soon: number;
    lapsed: number;
    unbilled: number;
    enforced: boolean;
};

const props = withDefaults(
    defineProps<{
        stats: BillingStatsPayload;
        /** The overview shows the three that matter at a glance; billing shows all six. */
        variant?: 'full' | 'compact';
    }>(),
    { variant: 'full' }
);

const revenueDescription = computed(() =>
    props.stats.other_currencies > 0
        ? `${props.stats.other_currencies} paying organizations billed in another currency are not counted`
        : `Across ${props.stats.paying} paying organizations`
);

const trialDescription = computed(() =>
    props.stats.trials_ending_soon > 0
        ? `${props.stats.trials_ending_soon} ending within a week`
        : 'Organizations inside a trial'
);
</script>

<template>
    <dl
        :class="[
            'grid gap-3',
            variant === 'compact'
                ? 'grid-cols-1 sm:grid-cols-3'
                : 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3',
        ]">
        <AdminStat
            label="Monthly revenue"
            tone="success"
            :value="formatMoney(stats.monthly_revenue, stats.currency)"
            :description="revenueDescription" />

        <AdminStat
            label="Trialing"
            :tone="stats.trials_ending_soon > 0 ? 'warning' : 'neutral'"
            :value="stats.trialing"
            :description="trialDescription" />

        <AdminStat
            label="Lapsed"
            :tone="stats.lapsed > 0 ? 'danger' : 'neutral'"
            :value="stats.lapsed"
            description="Paid plans that have run out or been cancelled" />

        <template v-if="variant === 'full'">
            <AdminStat
                label="Paying"
                :value="stats.paying"
                description="Organizations on an active paid plan" />
            <AdminStat
                label="Unbilled"
                :value="stats.unbilled"
                description="Organizations with no subscription recorded" />
            <AdminStat
                label="Enforcement"
                :tone="stats.enforced ? 'success' : 'neutral'"
                :value="stats.enforced ? 'On' : 'Off'"
                :description="
                    stats.enforced
                        ? 'These records decide what organizations may do'
                        : 'Records are bookkeeping only - set BILLING_ENFORCE to change that'
                " />
        </template>
    </dl>
</template>
