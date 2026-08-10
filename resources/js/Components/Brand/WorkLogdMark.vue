<script setup lang="ts">
import { computed } from 'vue';

/**
 * The WorkLog'd mark: a tapered wedge struck above a ruled line - a tally mark over a log entry.
 *
 * Ported from brand/code/WorkLogdLogo.jsx. Geometry is authored on a 96x96 grid and must not be
 * redrawn by eye. Never round the tile, outline the wedge, rotate it, or add a gradient.
 *
 * Themes itself from the CSS variables, so in normal use you pass no theme at all. Force one
 * only where the surface is fixed regardless of the app's mode.
 */
const props = withDefaults(
    defineProps<{
        size?: number;
        theme?: 'auto' | 'light' | 'dark';
        /** Sitting on an accent field: the tile becomes the accent and the wedge flips out. */
        onAccent?: boolean;
    }>(),
    { size: 32, theme: 'auto', onAccent: false }
);

const THEMES = {
    light: { tile: '#201E1D', rule: '#F3F2F2', wedge: '#EC3013' },
    dark: { tile: '#F3F2F2', rule: '#201E1D', wedge: '#EC3013' },
    auto: {
        tile: 'var(--wl-logo-tile)',
        rule: 'var(--wl-logo-rule)',
        wedge: 'var(--wl-logo-wedge)',
    },
} as const;

const palette = computed(() => THEMES[props.theme] ?? THEMES.auto);
const tile = computed(() => (props.onAccent ? 'var(--wl-accent)' : palette.value.tile));
// Never accent on accent - on an accent field the wedge takes the rule colour instead
const wedge = computed(() => (props.onAccent ? palette.value.rule : palette.value.wedge));

// Below 20px the rule in the standard cut lands under a pixel, so the mark switches to a
// heavier cut rather than losing the line that makes it read as a log entry.
const small = computed(() => props.size < 20);

const wedgePoints = computed(() =>
    small.value ? '32,12 64,12 50,54 32,54' : '34,14 62,14 50,52 34,52'
);
const rule = computed(() =>
    small.value ? { x: 32, y: 68, width: 48, height: 10 } : { x: 34, y: 66, width: 44, height: 6 }
);

/*
 * The mark itself is square. Its tile is allowed a 7px softening in the 24-40px band only, so
 * it sits comfortably beside rounded controls; at 64px and above it is square again.
 */
const radius = computed(() =>
    props.size >= 24 && props.size <= 40 ? 'var(--wl-r-logo, 7px)' : '0'
);
</script>

<template>
    <svg
        viewBox="0 0 96 96"
        :width="size"
        :height="size"
        role="img"
        aria-label="WorkLog'd"
        :style="{ borderRadius: radius, overflow: 'hidden' }"
        data-testid="worklogd_mark">
        <rect width="96" height="96" :fill="tile" />
        <polygon :points="wedgePoints" :fill="wedge" />
        <rect
            :x="rule.x"
            :y="rule.y"
            :width="rule.width"
            :height="rule.height"
            :fill="palette.rule" />
    </svg>
</template>
