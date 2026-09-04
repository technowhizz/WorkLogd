<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    title: string;
    value: unknown;
}>();

/**
 * Replaces the Filament pretty-JSON plugin the audit screen used to lean on. Plain pre-formatted
 * text is enough here - these payloads are small key/value diffs, not documents.
 */
const formatted = computed(() => {
    if (props.value === null || props.value === undefined) {
        return null;
    }

    try {
        return JSON.stringify(props.value, null, 2);
    } catch {
        return String(props.value);
    }
});
</script>

<template>
    <div class="min-w-0">
        <h5 class="text-xs font-semibold text-text-tertiary mb-1">{{ title }}</h5>
        <pre
            v-if="formatted"
            class="text-xs bg-card-background border border-card-border rounded-lg p-3 overflow-x-auto text-text-secondary max-h-64"
            >{{ formatted }}</pre
        >
        <p v-else class="text-xs text-text-tertiary italic">Nothing recorded.</p>
    </div>
</template>
