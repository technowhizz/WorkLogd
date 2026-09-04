<script setup lang="ts">
import { twMerge } from 'tailwind-merge';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        description?: string;
        tone?: 'neutral' | 'success' | 'warning' | 'danger';
    }>(),
    { tone: 'neutral' }
);

/*
 * Built on the same card surface as the app's own StatCard rather than reusing it directly, since
 * these carry a supporting line underneath and a tone the original has no notion of.
 */
const valueClasses = computed(
    () =>
        ({
            neutral: 'text-text-primary',
            success: 'text-emerald-600 dark:text-emerald-400',
            warning: 'text-amber-600 dark:text-amber-400',
            danger: 'text-accent-600 dark:text-accent-400',
        })[props.tone]
);
</script>

<template>
    <div
        class="rounded-lg bg-card-background border-card-border shadow-card border px-3.5 py-2.5 min-w-0">
        <dt class="font-medium text-sm text-text-secondary truncate">{{ label }}</dt>
        <dd :class="twMerge('text-xl pt-1 font-medium font-display', valueClasses)">
            {{ value }}
        </dd>
        <p v-if="description" class="text-xs text-text-tertiary pt-1 leading-snug">
            {{ description }}
        </p>
    </div>
</template>
