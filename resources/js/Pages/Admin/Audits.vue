<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, { type AdminPaginatorMeta } from '@/Components/Admin/AdminPagination.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import AdminJsonBlock from '@/Components/Admin/AdminJsonBlock.vue';
import { ArchiveBoxIcon, ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';
import { ref } from 'vue';
import { formatDateTime } from '@/Components/Admin/format';

type AuditRow = {
    id: number;
    event: string;
    auditable_type: string;
    auditable_id: string;
    user_name: string | null;
    user_id: string | null;
    url: string | null;
    ip_address: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string | null;
};

defineProps<{
    audits: { data: AuditRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
    eventOptions: Record<string, string>;
}>();

const columns: AdminColumn[] = [
    { key: 'created_at', label: 'When', sortable: true },
    { key: 'event', label: 'Event', sortable: true },
    { key: 'auditable_type', label: 'Record', sortable: true },
    { key: 'user', label: 'By' },
    { key: 'expand', label: '', align: 'right' },
];

const expanded = ref<number | null>(null);

function toggle(id: number) {
    expanded.value = expanded.value === id ? null : id;
}

function eventTone(event: string): 'success' | 'info' | 'danger' | 'neutral' {
    if (event === 'created') return 'success';
    if (event === 'updated') return 'info';
    if (event === 'deleted') return 'danger';
    return 'neutral';
}
</script>

<template>
    <AdminLayout title="Audit log">
        <template #header>
            <PageTitle :icon="ArchiveBoxIcon" title="Audit log" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="audits.total"
            item-label="entries"
            search-placeholder="Search by record type, record id or IP"
            :selects="[
                { key: 'event', label: 'Event', options: eventOptions, anyLabel: 'Any event' },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="180px 120px minmax(220px, 1.5fr) minmax(160px, 1fr) 60px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="audits.data.length"
            empty-message="No audit entries match those filters.">
            <template v-for="audit in audits.data" :key="audit.id">
                <TableRow>
                    <AdminTableCell edge="start">
                        <span class="text-text-secondary text-xs">{{
                            formatDateTime(audit.created_at)
                        }}</span>
                    </AdminTableCell>
                    <AdminTableCell>
                        <AdminBadge :tone="eventTone(audit.event)">{{ audit.event }}</AdminBadge>
                    </AdminTableCell>
                    <AdminTableCell>
                        <div class="min-w-0">
                            <div class="text-text-primary truncate">{{ audit.auditable_type }}</div>
                            <div class="text-xs text-text-tertiary truncate">
                                {{ audit.auditable_id }}
                            </div>
                        </div>
                    </AdminTableCell>
                    <AdminTableCell>
                        <span class="text-text-secondary truncate">{{
                            audit.user_name ?? 'System'
                        }}</span>
                    </AdminTableCell>
                    <AdminTableCell edge="end" align="right">
                        <button
                            type="button"
                            class="text-icon-default hover:text-text-primary transition"
                            :data-testid="'audit_toggle_' + audit.id"
                            @click="toggle(audit.id)">
                            <ChevronDownIcon v-if="expanded === audit.id" class="w-4 h-4" />
                            <ChevronRightIcon v-else class="w-4 h-4" />
                        </button>
                    </AdminTableCell>
                </TableRow>

                <div
                    v-if="expanded === audit.id"
                    class="col-span-full bg-secondary border-b border-row-separator px-4 sm:px-6 lg:px-8 3xl:px-12 py-4 space-y-3">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <AdminJsonBlock title="Before" :value="audit.old_values" />
                        <AdminJsonBlock title="After" :value="audit.new_values" />
                    </div>
                    <div class="text-xs text-text-tertiary space-x-4">
                        <span v-if="audit.url">URL: {{ audit.url }}</span>
                        <span v-if="audit.ip_address">IP: {{ audit.ip_address }}</span>
                    </div>
                </div>
            </template>
        </AdminTable>

        <AdminPagination :meta="audits" item-label="entries" />
    </AdminLayout>
</template>
