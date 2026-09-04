<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { SecondaryButton } from '@/packages/ui/src';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/16/solid';

/** The subset of Laravel's paginator payload the admin tables actually read. */
export type AdminPaginatorMeta = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    meta: AdminPaginatorMeta;
    itemLabel?: string;
}>();

function goTo(page: number) {
    if (page < 1 || page > props.meta.last_page) {
        return;
    }

    const query = Object.fromEntries(new URLSearchParams(window.location.search).entries());
    query.page = String(page);

    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <div
        v-if="meta.total > 0"
        class="flex items-center justify-between gap-3 py-4 px-4 sm:px-6 lg:px-8 3xl:px-12 border-t border-default-background-separator">
        <div class="text-sm text-text-tertiary">
            Showing {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} of
            {{ meta.total.toLocaleString() }} {{ itemLabel ?? 'results' }}
        </div>
        <div class="flex items-center gap-2">
            <SecondaryButton
                size="small"
                :class="meta.current_page <= 1 ? 'opacity-40 pointer-events-none' : ''"
                @click="goTo(meta.current_page - 1)">
                <ChevronLeftIcon class="w-4 h-4" />
            </SecondaryButton>
            <span class="text-sm text-text-secondary tabular-nums">
                Page {{ meta.current_page }} of {{ meta.last_page }}
            </span>
            <SecondaryButton
                size="small"
                :class="meta.current_page >= meta.last_page ? 'opacity-40 pointer-events-none' : ''"
                @click="goTo(meta.current_page + 1)">
                <ChevronRightIcon class="w-4 h-4" />
            </SecondaryButton>
        </div>
    </div>
</template>
