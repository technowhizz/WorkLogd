<script setup lang="ts">
import { twMerge } from 'tailwind-merge';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info';
    }>(),
    { tone: 'neutral' }
);

/*
 * Tinted rather than solid, so a single set of classes reads correctly against both the light and
 * the dark ground - the app switches between them on a `.dark` class and these badges sit on cards
 * in both. Neutral borrows the app's own surface tokens so a "nothing special" badge recedes.
 */
const toneClasses = computed(
    () =>
        ({
            neutral: 'bg-secondary text-text-secondary border-border-secondary',
            success:
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20',
            warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20',
            danger: 'bg-accent-500/10 text-accent-600 dark:text-accent-400 border-accent-500/20',
            info: 'bg-sky-500/10 text-sky-700 dark:text-sky-400 border-sky-500/20',
        })[props.tone]
);
</script>

<template>
    <span
        :class="
            twMerge(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                toneClasses
            )
        ">
        <slot></slot>
    </span>
</template>
