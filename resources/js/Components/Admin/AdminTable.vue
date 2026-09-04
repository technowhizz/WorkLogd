<script setup lang="ts">
import TableHeading from '@/Components/Common/TableHeading.vue';
import { ChevronUpIcon, ChevronDownIcon } from '@heroicons/vue/16/solid';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

export type AdminColumn = {
    key: string;
    label: string;
    /** Only columns the controller allows are sortable - the rest are display only. */
    sortable?: boolean;
    align?: 'left' | 'right';
    class?: string;
};

const props = defineProps<{
    columns: AdminColumn[];
    /** Tailwind grid-template-columns for the row grid, e.g. '2fr 1fr 1fr'. */
    gridTemplate: string;
    sort?: string | null;
    direction?: string | null;
    /** Rows currently on screen, used only to decide whether to show the empty state. */
    total: number;
    emptyMessage?: string;
}>();

/**
 * Sorting is server side, so a header click is a navigation. `preserveState` keeps the filter
 * controls from being torn down and rebuilt underneath the pointer.
 */
function sortBy(column: AdminColumn) {
    if (!column.sortable) {
        return;
    }

    const nextDirection =
        props.sort === column.key && props.direction === 'desc' ? 'asc' : 'desc';

    router.get(
        window.location.pathname,
        { ...currentQuery(), sort: column.key, direction: nextDirection, page: 1 },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function currentQuery(): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(window.location.search).entries());
}

function isSorted(column: AdminColumn): boolean {
    return column.sortable === true && props.sort === column.key;
}

const gridStyle = computed(() => ({ gridTemplateColumns: props.gridTemplate }));
</script>

<template>
    <div class="flow-root max-w-[100vw] overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div data-testid="admin_table" class="grid min-w-full" :style="gridStyle">
                <TableHeading>
                    <div
                        v-for="(column, index) in columns"
                        :key="column.key"
                        :class="[
                            'py-1.5 pr-3 text-text-tertiary select-none flex items-center gap-1',
                            index === 0 ? 'pl-4 sm:pl-6 lg:pl-8 3xl:pl-12' : '',
                            index === columns.length - 1 ? 'pr-4 sm:pr-6 lg:pr-8 3xl:pr-12' : '',
                            column.align === 'right' ? 'justify-end text-right' : 'text-left',
                            column.sortable
                                ? 'cursor-pointer hover:bg-secondary hover:text-text-primary transition-colors'
                                : '',
                            column.class ?? '',
                        ]"
                        @click="sortBy(column)">
                        <span>{{ column.label }}</span>
                        <ChevronDownIcon
                            v-if="isSorted(column) && direction === 'desc'"
                            class="w-3 h-3" />
                        <ChevronUpIcon
                            v-else-if="isSorted(column) && direction === 'asc'"
                            class="w-3 h-3" />
                    </div>
                </TableHeading>

                <slot></slot>

                <div
                    v-if="total === 0"
                    class="col-span-full py-16 text-center text-text-secondary text-sm">
                    {{ emptyMessage ?? 'Nothing to show.' }}
                </div>
            </div>
        </div>
    </div>
</template>
