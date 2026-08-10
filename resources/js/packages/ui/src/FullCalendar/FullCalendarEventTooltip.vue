<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { formatHumanReadableDuration, formatStartEnd } from '../utils/time';
import {
    EXTERNAL_REFERENCE_DETECTOR,
    type ExternalReferenceDetector,
} from '../TimeEntry/externalSyncTypes';
import type { Organization } from '@/packages/api/src';

const props = defineProps<{
    title: string;
    /**
     * The description as typed, when it differs from the displayed title. Only used to detect
     * an external reference in, so leaving it out simply detects nothing.
     */
    description?: string | null;
    /** Timestamps of the whole entry, not of the day segment it is rendered in. */
    start: string;
    end?: string | null;
    durationSeconds: number;
    projectName?: string | null;
    taskName?: string | null;
    clientName?: string | null;
}>();

const organization = inject('organization') as ComputedRef<Organization | undefined> | undefined;

const timeRange = computed(() =>
    formatStartEnd(props.start, props.end ?? null, organization?.value?.time_format)
);

const duration = computed(() =>
    formatHumanReadableDuration(
        props.durationSeconds,
        organization?.value?.interval_format,
        organization?.value?.number_format
    )
);

const hasMeta = computed(() => !!(props.projectName || props.taskName || props.clientName));

/*
 * The issue the entry's time will be logged against, if the host tracks such things at all —
 * injected exactly as the edit dialog does it, so this package still knows nothing about Jira.
 *
 * Unlike the dialog there is no "No ticket" counterpart here: the dialog is where you would fix
 * that and so says so, while this popup only describes an entry you are pointing at. The dot on
 * the block already reports a missing ticket, and the popup is shown for every entry, so a line
 * saying "no" on most of them would be noise on hover.
 */
const externalReferenceDetector = inject<ComputedRef<ExternalReferenceDetector | null> | null>(
    EXTERNAL_REFERENCE_DETECTOR,
    null
);

const externalReference = computed(() =>
    externalReferenceDetector?.value ? externalReferenceDetector.value(props.description) : null
);
</script>

<template>
    <div class="max-w-xs" data-testid="calendar_event_tooltip">
        <!--
            The event block clamps the description to however many lines it has room for, so
            a short entry hides most of it. This is the one place it is shown in full — it
            wraps instead of truncating, and long unbroken tokens break rather than overflow.
        -->
        <div class="font-semibold whitespace-normal break-words">{{ title }}</div>
        <!--
            The same chip the edit dialog shows, so the ticket an entry logs against reads the
            same whether you point at it or open it. No `title` attribute: this popup is
            deliberately not hit-testable, so a native tooltip on it could never appear.
        -->
        <div
            v-if="externalReference"
            class="mt-1.5 inline-flex items-center rounded border border-current px-1.5 py-0.5 font-mono text-[11px] font-medium opacity-80"
            data-testid="calendar_event_tooltip_reference">
            {{ externalReference.label }}
        </div>
        <div v-if="hasMeta" class="mt-1 space-y-0.5 opacity-90">
            <div v-if="projectName" class="break-words">{{ projectName }}</div>
            <div v-if="taskName" class="break-words">{{ taskName }}</div>
            <div v-if="clientName" class="break-words opacity-85">{{ clientName }}</div>
        </div>
        <div class="mt-1.5 flex items-center gap-1.5 tabular-nums opacity-90">
            <span>{{ timeRange }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ duration }}</span>
        </div>
    </div>
</template>
