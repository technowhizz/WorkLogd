<script setup lang="ts">
/**
 * The apostrophe in the wordmark IS the mark's wedge. Never a typed ' character at display
 * size - that is one of the two rules the brand package calls out as easy to get wrong.
 * Plain "WorkLog'd" is fine in running body copy.
 */
withDefaults(defineProps<{ color?: string }>(), { color: 'var(--wl-accent)' });
</script>

<template>
    <svg
        viewBox="0 0 28 38"
        aria-hidden="true"
        class="wl-wedge"
        :style="{
            // Tailwind's preflight sets `svg { display: block }`, which would break the
            // wordmark across two lines - white-space: nowrap cannot hold a block-level child
            // on one line. The brand CSS omits this because a bare SVG is inline by default.
            display: 'inline-block',
            height: '0.31em',
            width: 'auto',
            verticalAlign: '0.46em',
            margin: '0 0.03em',
        }">
        <polygon points="0,0 28,0 16,38 0,38" :fill="color" />
    </svg>
</template>
