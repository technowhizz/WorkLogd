<script setup lang="ts">
import { Modal, SecondaryButton, TextInput } from '@/packages/ui/src';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    title: string;
    actionLabel: string;
    /**
     * When set, the action stays locked until this exact text is typed. Reserved for the
     * deletions that take a lot of other data with them.
     */
    confirmText?: string;
}>();

const emit = defineEmits<{
    'update:show': [value: boolean];
    confirm: [];
}>();

const typed = ref('');

watch(
    () => props.show,
    (open) => {
        if (!open) {
            typed.value = '';
        }
    }
);

const canConfirm = computed(
    () => props.confirmText === undefined || typed.value === props.confirmText
);

function close() {
    emit('update:show', false);
}

function confirm() {
    if (!canConfirm.value) {
        return;
    }

    emit('confirm');
    close();
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="close">
        <div class="p-2 space-y-4">
            <h3 class="text-lg font-semibold text-text-primary">{{ title }}</h3>
            <p class="text-sm text-text-secondary leading-relaxed">
                <slot></slot>
            </p>
            <div v-if="confirmText !== undefined">
                <label class="block text-sm text-text-secondary mb-1">
                    Type <span class="font-medium text-text-primary">{{ confirmText }}</span> to
                    confirm
                </label>
                <TextInput
                    v-model="typed"
                    data-testid="danger_confirm_input"
                    type="text"
                    class="w-full" />
            </div>
        </div>

        <template #footer>
            <SecondaryButton @click="close">Cancel</SecondaryButton>
            <button
                type="button"
                data-testid="danger_confirm_button"
                :class="[
                    'h-9 px-3 text-sm rounded-lg font-medium transition inline-flex items-center',
                    canConfirm
                        ? 'bg-accent-600 text-white hover:bg-accent-700'
                        : 'bg-accent-600/40 text-white/70 cursor-not-allowed',
                ]"
                @click="confirm">
                {{ actionLabel }}
            </button>
        </template>
    </Modal>
</template>
