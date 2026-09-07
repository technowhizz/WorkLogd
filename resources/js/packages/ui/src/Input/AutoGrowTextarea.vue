<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { twMerge } from 'tailwind-merge';

/**
 * A textarea that grows with its content up to a limit, then scrolls.
 *
 * Written for descriptions, where Enter has always meant "save" - so Enter keeps meaning that and
 * Shift+Enter is what inserts a newline. Swapping those would be a nasty surprise for anyone used
 * to typing a description and hitting Enter.
 */
const model = defineModel<string | null>();

const props = withDefaults(
    defineProps<{
        /** Rows to show before the field starts scrolling instead of growing. */
        maxRows?: number;
        minRows?: number;
        placeholder?: string;
        id?: string;
        class?: Parameters<typeof twMerge>[0];
    }>(),
    { maxRows: 6, minRows: 1 }
);

const emit = defineEmits<{
    /** Enter without Shift. The host decides whether that means submit. */
    submit: [];
}>();

const textarea = ref<HTMLTextAreaElement | null>(null);

defineExpose({
    focus: () => textarea.value?.focus(),
    select: () => textarea.value?.select(),
});

/**
 * Measure by letting the field collapse first.
 *
 * Without resetting the height, scrollHeight only ever reports the current size or larger, so a
 * field that has grown can never shrink back when the text is deleted.
 */
function resize() {
    const element = textarea.value;
    if (!element) {
        return;
    }

    const styles = window.getComputedStyle(element);
    const lineHeight = Number.parseFloat(styles.lineHeight) || 20;
    const vertical =
        Number.parseFloat(styles.paddingTop) +
        Number.parseFloat(styles.paddingBottom) +
        Number.parseFloat(styles.borderTopWidth) +
        Number.parseFloat(styles.borderBottomWidth);

    element.style.height = 'auto';

    const min = lineHeight * props.minRows + vertical;
    const max = lineHeight * props.maxRows + vertical;
    const wanted =
        element.scrollHeight +
        Number.parseFloat(styles.borderTopWidth) +
        Number.parseFloat(styles.borderBottomWidth);

    element.style.height = `${Math.min(Math.max(wanted, min), max)}px`;
    // Only scrollable once it has stopped growing, so the scrollbar never appears on one line.
    element.style.overflowY = wanted > max ? 'auto' : 'hidden';
}

function onKeydown(event: KeyboardEvent) {
    if (event.key !== 'Enter') {
        return;
    }

    if (event.shiftKey) {
        // The browser inserts the newline; this just keeps the height honest afterwards.
        void nextTick(resize);

        return;
    }

    event.preventDefault();
    emit('submit');
}

onMounted(() => {
    resize();
});

watch(model, () => {
    void nextTick(resize);
});

const rows = computed(() => props.minRows);
</script>

<template>
    <textarea
        :id="id"
        ref="textarea"
        v-model="model"
        :rows="rows"
        :placeholder="placeholder"
        :class="
            twMerge(
                'border-input-border bg-input-background text-text-primary focus:border-input-border-active focus:ring-0 rounded-lg shadow-sm text-sm resize-none leading-5',
                props.class
            )
        "
        @keydown="onKeydown"
        @input="resize"></textarea>
</template>
