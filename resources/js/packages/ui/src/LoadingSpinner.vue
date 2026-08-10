<script setup lang="ts">
import { computed } from 'vue';
import { twMerge } from 'tailwind-merge';

/**
 * The WorkLog'd loading indicator, built from the logo's own two parts. The tile never moves,
 * so the mark stays readable through the whole loop - never spin the whole tile.
 *
 * `tally` is the default and covers any indeterminate wait. `count` implies a known duration:
 * use it only for jobs that end (exports, imports, batch approvals). Implying progress that
 * does not exist is worse than showing no spinner.
 *
 * Both stop under prefers-reduced-motion, handled in the stylesheet.
 */
const props = withDefaults(
    defineProps<{
        class?: string;
        /**
         * Edge length in px. The brand ships three sizes - 22 inline, 32 for a panel, 64 for a
         * full page. Under 20 switches to the heavier cut so the rule survives.
         */
        size?: number;
        variant?: 'tally' | 'count';
        label?: string;
        /** Force a fixed surface, ex. on an accent-filled button. */
        theme?: 'auto' | 'light' | 'dark';
    }>(),
    { class: undefined, size: 22, variant: 'tally', label: 'Loading', theme: 'auto' }
);

const THEMES = {
    light: { tile: '#201E1D', rule: '#F3F2F2' },
    dark: { tile: '#F3F2F2', rule: '#201E1D' },
    auto: { tile: 'var(--wl-logo-tile)', rule: 'var(--wl-logo-rule)' },
} as const;

const palette = computed(() => THEMES[props.theme] ?? THEMES.auto);
const small = computed(() => props.size < 20);

const wedgePoints = computed(() =>
    small.value ? '32,12 64,12 50,54 32,54' : '34,14 62,14 50,52 34,52'
);
const rule = computed(() =>
    small.value ? { x: 32, y: 68, width: 48, height: 10 } : { x: 34, y: 66, width: 44, height: 6 }
);

// The tile softens only in the 24-40px band; square below and above
const radius = computed(() =>
    props.size >= 24 && props.size <= 40 ? 'var(--wl-r-logo, 7px)' : '0'
);
</script>

<template>
    <svg
        :class="
            twMerge('-ml-1 mr-3 shrink-0', 'wl-spin-' + variant, small ? 'wl-sm' : '', props.class)
        "
        viewBox="0 0 96 96"
        :width="size"
        :height="size"
        role="img"
        :aria-label="label"
        :style="{ borderRadius: radius, overflow: 'hidden' }"
        data-testid="worklogd_spinner">
        <rect width="96" height="96" :fill="palette.tile" />
        <polygon
            class="wl-part wl-wedge"
            fill="var(--wl-logo-wedge, #EC3013)"
            :points="wedgePoints" />
        <rect
            class="wl-part wl-rule"
            :fill="palette.rule"
            :x="rule.x"
            :y="rule.y"
            :width="rule.width"
            :height="rule.height" />
    </svg>
</template>
