<script setup lang="ts">
import { computed } from 'vue';
import WorkLogdMark from '@/Components/Brand/WorkLogdMark.vue';
import WorkLogdWordmark from '@/Components/Brand/WorkLogdWordmark.vue';

/**
 * One line, always. There is no stacked lockup - where the wordmark will not fit, pass
 * `:show-wordmark="false"` and show the mark alone rather than wrapping or shrinking the type.
 */
const props = withDefaults(
    defineProps<{
        /** Wordmark font size in px. The mark is drawn at 1.9x this. Minimum 24 for a lockup. */
        size?: number;
        theme?: 'auto' | 'light' | 'dark';
        showWordmark?: boolean;
    }>(),
    { size: 24, theme: 'auto', showWordmark: true }
);

const gap = computed(() => props.size * 0.5);
</script>

<template>
    <span
        class="inline-flex items-center"
        :style="{ gap: gap + 'px', whiteSpace: 'nowrap' }"
        data-testid="worklogd_lockup">
        <WorkLogdMark :size="size * 1.9" :theme="theme" />
        <WorkLogdWordmark v-if="showWordmark" :size="size" :theme="theme" />
    </span>
</template>
