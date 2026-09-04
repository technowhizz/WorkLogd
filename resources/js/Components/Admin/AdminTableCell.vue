<script setup lang="ts">
import { twMerge } from 'tailwind-merge';

withDefaults(
    defineProps<{
        /** First and last cells carry the row's outer padding, matching the header. */
        edge?: 'start' | 'end' | 'none';
        align?: 'left' | 'right';
    }>(),
    { edge: 'none', align: 'left' }
);
</script>

<template>
    <div
        :class="
            twMerge(
                'px-3 py-2.5 text-sm flex items-center',
                edge === 'start' ? 'pl-4 sm:pl-6 lg:pl-8 3xl:pl-12' : '',
                edge === 'end' ? 'pr-4 sm:pr-6 lg:pr-8 3xl:pr-12' : '',
                align === 'right' ? 'justify-end text-right' : ''
            )
        ">
        <slot></slot>
    </div>
</template>
