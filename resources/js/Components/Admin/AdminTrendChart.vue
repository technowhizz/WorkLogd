<script setup lang="ts">
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { computed } from 'vue';
import { useCssVariable } from '@/packages/ui/src';
import Card from '@/Components/Common/Card.vue';

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent]);

const props = defineProps<{
    title: string;
    points: { date: string; value: number }[];
}>();

// Reading the accent straight off the theme is what keeps the charts in step with the rest of the
// app when the palette changes, rather than pinning a hex here.
const accent = useCssVariable('--color-accent-500');
const gridColor = useCssVariable('--color-border-secondary');
const textColor = useCssVariable('--color-text-tertiary');

const accentRgb = computed(() => `rgb(${accent.value})`);

const total = computed(() => props.points.reduce((sum, point) => sum + point.value, 0));

const option = computed(() => ({
    grid: { left: 40, right: 12, top: 16, bottom: 24 },
    tooltip: { trigger: 'axis' },
    xAxis: {
        type: 'category',
        data: props.points.map((point) => point.date),
        axisLine: { lineStyle: { color: gridColor.value } },
        axisLabel: { color: textColor.value, fontSize: 10, hideOverlap: true },
    },
    yAxis: {
        type: 'value',
        minInterval: 1,
        splitLine: { lineStyle: { color: gridColor.value } },
        axisLabel: { color: textColor.value, fontSize: 10 },
    },
    series: [
        {
            type: 'line',
            smooth: true,
            showSymbol: false,
            data: props.points.map((point) => point.value),
            lineStyle: { color: accentRgb.value, width: 2 },
            areaStyle: { color: `rgba(${accent.value}, 0.12)` },
        },
    ],
}));
</script>

<template>
    <Card>
        <div class="px-4 pt-4 flex items-baseline justify-between">
            <h4 class="font-medium text-text-primary text-sm">{{ title }}</h4>
            <span class="text-sm text-text-tertiary tabular-nums">{{
                total.toLocaleString()
            }}</span>
        </div>
        <VChart
            class="h-52 w-full px-2 pb-2"
            :option="option"
            :autoresize="true"
            :init-options="{ renderer: 'canvas' }" />
    </Card>
</template>
