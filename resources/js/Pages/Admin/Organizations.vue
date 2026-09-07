<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, { type AdminPaginatorMeta } from '@/Components/Admin/AdminPagination.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import { BuildingOffice2Icon } from '@heroicons/vue/20/solid';
import { formatDate } from '@/Components/Admin/format';

type OrganizationRow = {
    id: string;
    name: string;
    currency: string;
    owner_email: string | null;
    personal_team: boolean;
    members_count: number | null;
    plan: string | null;
    status: string | null;
    created_at: string | null;
};

const props = defineProps<{
    organizations: { data: OrganizationRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
    planOptions: Record<string, string>;
}>();

const columns: AdminColumn[] = [
    { key: 'name', label: 'Organization', sortable: true },
    { key: 'owner', label: 'Owner' },
    { key: 'members', label: 'Members', align: 'right' },
    { key: 'plan', label: 'Plan' },
    { key: 'currency', label: 'Currency' },
    { key: 'created_at', label: 'Created', sortable: true },
];

const planFilterOptions = { ...props.planOptions, none: 'No subscription' };
</script>

<template>
    <AdminLayout title="Organizations">
        <template #header>
            <PageTitle :icon="BuildingOffice2Icon" title="Organizations" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="organizations.total"
            item-label="organizations"
            search-placeholder="Search by name or owner email"
            :selects="[
                { key: 'plan', label: 'Plan', options: planFilterOptions, anyLabel: 'Any plan' },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="minmax(220px, 2fr) minmax(200px, 1.5fr) 110px 150px 110px 150px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="organizations.data.length"
            empty-message="No organizations match those filters.">
            <TableRow
                v-for="organization in organizations.data"
                :key="organization.id"
                :href="route('admin.organizations.show', organization.id)">
                <AdminTableCell edge="start">
                    <div class="min-w-0">
                        <div class="font-medium text-text-primary truncate">
                            {{ organization.name }}
                        </div>
                        <div v-if="organization.personal_team" class="text-xs text-text-tertiary">
                            Personal
                        </div>
                    </div>
                </AdminTableCell>
                <AdminTableCell>
                    <span class="truncate text-text-secondary">
                        {{ organization.owner_email ?? '--' }}
                    </span>
                </AdminTableCell>
                <AdminTableCell align="right">
                    <span class="tabular-nums">{{ organization.members_count ?? 0 }}</span>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge
                        :tone="
                            organization.plan === null || organization.plan === 'free'
                                ? 'neutral'
                                : 'success'
                        ">
                        {{ organization.plan ? planOptions[organization.plan] : 'None' }}
                    </AdminBadge>
                </AdminTableCell>
                <AdminTableCell>{{ organization.currency }}</AdminTableCell>
                <AdminTableCell edge="end">
                    <span class="text-text-secondary">{{
                        formatDate(organization.created_at)
                    }}</span>
                </AdminTableCell>
            </TableRow>
        </AdminTable>

        <AdminPagination :meta="organizations" item-label="organizations" />
    </AdminLayout>
</template>
