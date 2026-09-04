<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, {
    type AdminPaginatorMeta,
} from '@/Components/Admin/AdminPagination.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import { UserGroupIcon } from '@heroicons/vue/20/solid';
import { formatDate } from '@/Components/Admin/format';

type UserRow = {
    id: string;
    name: string;
    email: string;
    is_placeholder: boolean;
    is_admin: boolean;
    email_verified: boolean;
    can_be_impersonated: boolean;
    created_at: string | null;
};

defineProps<{
    users: { data: UserRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
}>();

const columns: AdminColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'flags', label: 'Status' },
    { key: 'created_at', label: 'Joined', sortable: true },
];
</script>

<template>
    <AdminLayout title="Users">
        <template #header>
            <PageTitle :icon="UserGroupIcon" title="Users" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="users.total"
            item-label="users"
            search-placeholder="Search by name or email"
            :selects="[
                {
                    key: 'type',
                    label: 'Type',
                    anyLabel: 'All users',
                    options: {
                        real: 'Real users',
                        placeholder: 'Placeholders',
                        admin: 'Super admins',
                        unverified: 'Unverified email',
                    },
                },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="minmax(200px, 1.5fr) minmax(220px, 2fr) minmax(240px, 1fr) 150px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="users.data.length"
            empty-message="No users match those filters.">
            <TableRow
                v-for="user in users.data"
                :key="user.id"
                :href="route('admin.users.show', user.id)">
                <AdminTableCell edge="start">
                    <span class="font-medium text-text-primary truncate">{{ user.name }}</span>
                </AdminTableCell>
                <AdminTableCell>
                    <span class="text-text-secondary truncate">{{ user.email }}</span>
                </AdminTableCell>
                <AdminTableCell>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <AdminBadge v-if="user.is_admin" tone="danger">Super admin</AdminBadge>
                        <AdminBadge v-if="user.is_placeholder">Placeholder</AdminBadge>
                        <AdminBadge v-if="!user.email_verified" tone="warning">Unverified</AdminBadge>
                        <AdminBadge
                            v-if="user.email_verified && !user.is_placeholder && !user.is_admin"
                            tone="success"
                            >Active</AdminBadge
                        >
                    </div>
                </AdminTableCell>
                <AdminTableCell edge="end">
                    <span class="text-text-secondary">{{ formatDate(user.created_at) }}</span>
                </AdminTableCell>
            </TableRow>
        </AdminTable>

        <AdminPagination :meta="users" item-label="users" />
    </AdminLayout>
</template>
