<script setup lang="ts">
import { computed } from 'vue';
import WorkLogdWedge from '@/Components/Brand/WorkLogdWedge.vue';

const props = withDefaults(
    defineProps<{
        /** Font size in px. Never below 13 - drop to the mark alone instead. */
        size?: number;
        theme?: 'auto' | 'light' | 'dark';
        wedgeColor?: string;
    }>(),
    // wedgeColor is resolved below rather than defaulted, since the fallback depends on size
    { size: 24, theme: 'auto', wedgeColor: undefined }
);

const TEXT = {
    light: '#201E1D',
    dark: '#F3F2F2',
    auto: 'var(--wl-logo-text)',
} as const;

const color = computed(() => TEXT[props.theme] ?? TEXT.auto);

// At 13px and under the accent wedge is too small to read as a separate colour, so it takes
// the surrounding text colour instead.
const resolvedWedgeColor = computed(
    () => props.wedgeColor ?? (props.size <= 13 ? 'currentColor' : 'var(--wl-accent)')
);
</script>

<template>
    <span
        class="wl-wordmark"
        :style="{
            fontFamily: 'var(--wl-font)',
            fontWeight: 800,
            fontSize: size + 'px',
            letterSpacing: '-0.035em',
            lineHeight: 1,
            color,
            whiteSpace: 'nowrap',
        }"
        data-testid="worklogd_wordmark"
        >WORKLOG<WorkLogdWedge :color="resolvedWedgeColor" />D</span
    >
</template>
