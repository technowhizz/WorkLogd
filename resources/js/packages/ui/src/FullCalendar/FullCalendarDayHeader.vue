<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { formatHumanReadableDuration } from '../utils/time';
import type { Organization } from '@/packages/api/src';
import type { Dayjs } from 'dayjs';
import type { DaySyncStatus } from '../TimeEntry/externalSyncTypes';

const props = defineProps<{
    date: Dayjs;
    totalSeconds?: number;
    breakSeconds?: number;
    isToday?: boolean;
    /**
     * The day's external sync state, when the page tracks one: the state of its most severe
     * entry, with pending read as 'attention'. Null when there is nothing to say.
     */
    syncStatus?: DaySyncStatus | null;
}>();

// The same colours as the entry dots (ExternalSyncIndicator), so the header points at the
// worst entry under it
const SYNC_DOT: Record<DaySyncStatus, { classes: string; label: string }> = {
    synced: {
        classes: 'bg-green-500 dark:bg-green-400',
        label: 'Everything with a ticket on this day is logged',
    },
    attention: {
        classes: 'bg-text-quaternary',
        label: 'Something on this day is not logged yet',
    },
    outdated: {
        classes: 'bg-amber-500 dark:bg-amber-400',
        label: 'Something on this day has changed since it was logged',
    },
    'missing-reference': {
        classes: 'bg-red-500 dark:bg-red-400',
        label: 'Something on this day has no ticket',
    },
    error: {
        classes: 'bg-red-500 dark:bg-red-400',
        label: 'Something on this day failed to log',
    },
};

const syncDot = computed(() => (props.syncStatus ? SYNC_DOT[props.syncStatus] : null));

const totalSecondsValue = computed(() => props.totalSeconds ?? 0);
const breakSecondsValue = computed(() => props.breakSeconds ?? 0);

const organization = inject('organization') as ComputedRef<Organization | undefined> | undefined;
const intervalFormat = computed(() => organization?.value?.interval_format);
const numberFormat = computed(() => organization?.value?.number_format);

const hasBreak = computed(() => breakSecondsValue.value > 0);

// Without breaks the work time stands alone, so it needs no label. Once break
// time joins it, both halves are labelled to keep them apart.
const durationSummary = computed(() => {
    const work = formatHumanReadableDuration(
        totalSecondsValue.value,
        intervalFormat.value,
        numberFormat.value
    );
    if (!hasBreak.value) {
        return work;
    }
    const breakTime = formatHumanReadableDuration(
        breakSecondsValue.value,
        intervalFormat.value,
        numberFormat.value
    );
    return `${work} work · ${breakTime} break`;
});
</script>

<template>
    <div class="fc-day-header-custom">
        <div
            class="flex items-center justify-center gap-1.5 text-sm text-foreground"
            :class="isToday ? 'font-semibold' : 'font-medium'">
            <!--
                The day's sync state at a glance, so a week can be scanned for unlogged work
                without opening the sync dialog. It takes the colour of the day's worst entry -
                the per-entry dots carry the detail of what and why.
            -->
            <span
                v-if="syncDot"
                class="h-2 w-2 shrink-0 rounded-full"
                :class="syncDot.classes"
                :title="syncDot.label"
                :aria-label="syncDot.label"
                role="img"
                data-testid="day_sync_indicator"
                :data-sync-state="syncStatus"></span>
            <!-- Month included so a week spanning two of them is unambiguous - the toolbar title
                 only ever names one -->
            <span>{{ date.format('ddd D MMM') }}</span>
        </div>
        <span
            class="block text-xs text-muted-foreground font-medium mt-0.5"
            data-testid="day_duration_summary"
            >{{ durationSummary }}</span
        >
    </div>
</template>
