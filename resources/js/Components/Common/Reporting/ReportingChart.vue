<script setup lang="ts">
import VChart, { THEME_KEY } from 'vue-echarts';
import { computed, provide, inject, shallowRef, type ComputedRef } from 'vue';
import LinearGradient from 'zrender/lib/graphic/LinearGradient';
import { formatDate, formatReportingDuration, formatWeek } from '@/packages/ui/src/utils/time';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart } from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import type { AggregatedTimeEntries, Organization } from '@/packages/api/src';
import { useCssVariable } from '@/packages/ui/src';
import { calendarDateForBucket } from '@/utils/calendarLink';

use([CanvasRenderer, BarChart, TitleComponent, GridComponent, TooltipComponent, LegendComponent]);

provide(THEME_KEY, 'dark');

const organization = inject<ComputedRef<Organization>>('organization');
const chart = shallowRef(null);
type GroupedData = AggregatedTimeEntries['grouped_data'];

const props = withDefaults(
    defineProps<{
        groupedData: GroupedData;
        groupedType: string | null;
        /**
         * Off by default so the public shared report stays inert: /calendar is behind auth, and
         * sending an unauthenticated reader there would swap the report they are reading for a
         * login screen. Any future mount site is safe until it opts in.
         */
        clickable?: boolean;
    }>(),
    { clickable: false }
);

const emit = defineEmits<{
    (e: 'bucket-click', date: string): void;
}>();

/**
 * Resolves a clicked bar to the day the calendar should open on and hands it to the page,
 * which owns the navigation — this component is mounted on a page that has no calendar to go
 * to, so it reports the intent rather than acting on it.
 *
 * Only the drawn bar is a hit target. Gap-filled buckets have no height and so cannot be
 * clicked; that is deliberate, and making the whole column band clickable would mean resolving
 * the category from the pixel position instead.
 */
function onBarClick(params: { componentType: string; dataIndex: number }) {
    if (!props.clickable || params.componentType !== 'series') return;

    const date = calendarDateForBucket(
        props.groupedData?.[params.dataIndex]?.key,
        props.groupedType
    );
    if (date) {
        emit('bucket-click', date);
    }
}

const xAxisLabels = computed(() => {
    if (props.groupedType === 'week') {
        return props?.groupedData?.map((el) => formatWeek(el.key));
    }
    return props?.groupedData?.map((el) =>
        formatDate(el.key ?? '', organization?.value?.date_format)
    );
});
const accentColor = useCssVariable('--theme-color-chart');
const labelColor = useCssVariable('--color-text-secondary');
const markLineColor = useCssVariable('--color-border-secondary');
const splitLineColor = useCssVariable('--color-border-tertiary');

const seriesData = computed(() => {
    return props?.groupedData?.map((el) => {
        return {
            value: el.seconds,
            ...{
                itemStyle: {
                    borderColor: new LinearGradient(0, 0, 0, 1, [
                        {
                            offset: 0,
                            color: 'rgba(' + accentColor.value + ',0.7)',
                        },
                        {
                            offset: 1,
                            color: 'rgba(' + accentColor.value + ',0.5)',
                        },
                    ]),
                    emphasis: {
                        color: new LinearGradient(0, 0, 0, 1, [
                            {
                                offset: 0,
                                color: 'rgba(' + accentColor.value + ',0.9)',
                            },
                            {
                                offset: 1,
                                color: 'rgba(' + accentColor.value + ',0.7)',
                            },
                        ]),
                    },
                    borderRadius: [12, 12, 0, 0],
                    color: new LinearGradient(0, 0, 0, 1, [
                        {
                            offset: 0,
                            color: 'rgba(' + accentColor.value + ',0.7)',
                        },
                        {
                            offset: 1,
                            color: 'rgba(' + accentColor.value + ',0.5)',
                        },
                    ]),
                },
            },
        };
    });
});

const option = computed(() => ({
    tooltip: {
        trigger: 'item',
    },
    grid: {
        top: 0,
        right: 0,
        bottom: 50,
        left: 0,
    },
    backgroundColor: 'transparent',
    xAxis: {
        type: 'category',
        data: xAxisLabels.value,
        markLine: {
            lineStyle: {
                color: markLineColor.value,
                type: 'dashed',
            },
        },
        axisLine: {
            show: false,
        },
        axisLabel: {
            fontSize: 12,
            fontWeight: 400,
            color: labelColor.value,
            margin: 16,
            fontFamily: 'Inter, sans-serif',
        },
        axisTick: {
            show: false,
        },
    },
    yAxis: {
        type: 'value',
        axisLabel: {
            show: false,
        },
        splitLine: {
            lineStyle: {
                color: splitLineColor.value,
            },
        },
    },
    series: [
        {
            data: seriesData.value,
            type: 'bar',
            // Only over the bar itself, which is exactly the clickable area — echarts hands the
            // cursor to zrender per element, so blank canvas keeps the default arrow.
            cursor: props.clickable ? 'pointer' : 'default',
            tooltip: {
                valueFormatter: (value: number) => {
                    return formatReportingDuration(
                        value,
                        organization?.value?.interval_format,
                        organization?.value?.number_format
                    );
                },
            },
        },
    ],
}));
</script>

<template>
    <div class="w-[calc(100%-1px)]">
        <v-chart
            v-if="groupedData && groupedData?.length > 0"
            ref="chart"
            data-testid="reporting_chart"
            :autoresize="true"
            class="chart"
            :option="option"
            @click="onBarClick" />
        <div v-else class="chart flex flex-col items-center justify-center">
            <p class="text-lg text-text-primary font-semibold">No time entries found</p>
            <p>Try to change the filters and time range</p>
        </div>
    </div>
</template>

<style scoped>
.chart {
    height: 300px;
    background: transparent;
}
</style>
