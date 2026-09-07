<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, { type AdminPaginatorMeta } from '@/Components/Admin/AdminPagination.vue';
import { SecondaryButton } from '@/packages/ui/src';
import {
    ExclamationTriangleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
} from '@heroicons/vue/20/solid';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDateTime } from '@/Components/Admin/format';

type FailedJobRow = {
    uuid: string;
    connection: string;
    queue: string;
    failed_at: string;
    summary: string;
    exception: string;
};

defineProps<{
    jobs: { data: FailedJobRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
}>();

const columns: AdminColumn[] = [
    { key: 'failed_at', label: 'Failed at', sortable: true },
    { key: 'queue', label: 'Queue', sortable: true },
    { key: 'summary', label: 'Exception' },
    { key: 'actions', label: '', align: 'right' },
];

const expanded = ref<string | null>(null);

function retry(uuid: string) {
    router.post(route('admin.failed-jobs.retry', uuid), {}, { preserveScroll: true });
}

function remove(uuid: string) {
    router.delete(route('admin.failed-jobs.destroy', uuid), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="Failed jobs">
        <template #header>
            <PageTitle :icon="ExclamationTriangleIcon" title="Failed jobs" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="jobs.total"
            item-label="failed jobs"
            search-placeholder="Search the exception or queue" />

        <AdminTable
            :columns="columns"
            grid-template="180px 140px minmax(300px, 3fr) 200px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="jobs.data.length"
            empty-message="Nothing has failed. ">
            <template v-for="job in jobs.data" :key="job.uuid">
                <TableRow>
                    <AdminTableCell edge="start">
                        <span class="text-text-secondary text-xs">{{
                            formatDateTime(job.failed_at)
                        }}</span>
                    </AdminTableCell>
                    <AdminTableCell>
                        <span class="text-text-secondary">{{ job.queue }}</span>
                    </AdminTableCell>
                    <AdminTableCell>
                        <button
                            type="button"
                            class="flex items-center gap-1 min-w-0 text-left hover:text-text-primary transition"
                            @click="expanded = expanded === job.uuid ? null : job.uuid">
                            <ChevronDownIcon
                                v-if="expanded === job.uuid"
                                class="w-4 h-4 shrink-0 text-icon-default" />
                            <ChevronRightIcon v-else class="w-4 h-4 shrink-0 text-icon-default" />
                            <span class="truncate font-mono text-xs">{{ job.summary }}</span>
                        </button>
                    </AdminTableCell>
                    <AdminTableCell edge="end" align="right">
                        <div class="flex items-center gap-2">
                            <SecondaryButton size="small" @click="retry(job.uuid)"
                                >Retry</SecondaryButton
                            >
                            <SecondaryButton
                                size="small"
                                class="text-accent-600 border-accent-500/30"
                                @click="remove(job.uuid)"
                                >Delete</SecondaryButton
                            >
                        </div>
                    </AdminTableCell>
                </TableRow>

                <div
                    v-if="expanded === job.uuid"
                    class="col-span-full bg-secondary border-b border-row-separator px-4 sm:px-6 lg:px-8 3xl:px-12 py-4">
                    <pre
                        class="text-xs font-mono bg-card-background border border-card-border rounded-lg p-3 overflow-x-auto max-h-96 text-text-secondary"
                        >{{ job.exception }}</pre
                    >
                </div>
            </template>
        </AdminTable>

        <AdminPagination :meta="jobs" item-label="failed jobs" />
    </AdminLayout>
</template>
