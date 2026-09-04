<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MagnifyingGlassIcon } from '@heroicons/vue/16/solid';
import { TextInput } from '@/packages/ui/src';
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';

export type AdminFilterSelect = {
    key: string;
    label: string;
    options: Record<string, string>;
    /** Label for the "no filter" option. */
    anyLabel?: string;
};

const props = withDefaults(
    defineProps<{
        filters: Record<string, string | null>;
        selects?: AdminFilterSelect[];
        searchPlaceholder?: string;
        /** Total rows the current filter matches, shown so the count is never a mystery. */
        total?: number;
        itemLabel?: string;
    }>(),
    { selects: () => [], searchPlaceholder: 'Search...', itemLabel: 'results' }
);

const search = ref(props.filters.search ?? '');
// Typing should not fire a request per keystroke against a table of thousands of rows.
const debouncedSearch = refDebounced(search, 300);

watch(debouncedSearch, (value) => {
    navigate({ search: value === '' ? undefined : value });
});

function navigate(changes: Record<string, string | undefined>) {
    const query: Record<string, string> = Object.fromEntries(
        new URLSearchParams(window.location.search).entries()
    );

    for (const [key, value] of Object.entries(changes)) {
        if (value === undefined || value === '') {
            delete query[key];
        } else {
            query[key] = value;
        }
    }

    // Any filter change invalidates the page number - page 7 of the old result set is meaningless.
    delete query.page;

    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 py-3 px-4 sm:px-6 lg:px-8 3xl:px-12">
        <div class="relative">
            <MagnifyingGlassIcon
                class="w-4 h-4 text-icon-default absolute left-2.5 top-1/2 -translate-y-1/2" />
            <TextInput
                v-model="search"
                data-testid="admin_search"
                type="text"
                class="pl-8 w-64"
                :placeholder="searchPlaceholder" />
        </div>

        <select
            v-for="select in selects"
            :key="select.key"
            :data-testid="'admin_filter_' + select.key"
            :value="filters[select.key] ?? ''"
            class="rounded-md border-input-border bg-input-background text-text-primary text-sm py-1.5 focus:border-input-border-active focus:ring-0"
            @change="
                navigate({ [select.key]: ($event.target as HTMLSelectElement).value || undefined })
            ">
            <option value="">{{ select.anyLabel ?? 'All ' + select.label.toLowerCase() }}</option>
            <option v-for="(label, value) in select.options" :key="value" :value="value">
                {{ label }}
            </option>
        </select>

        <div v-if="total !== undefined" class="ml-auto text-sm text-text-tertiary">
            {{ total.toLocaleString() }} {{ itemLabel }}
        </div>
    </div>
</template>
