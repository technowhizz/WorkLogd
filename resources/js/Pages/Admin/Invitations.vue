<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import TableRow from '@/Components/TableRow.vue';
import AdminTable, { type AdminColumn } from '@/Components/Admin/AdminTable.vue';
import AdminTableCell from '@/Components/Admin/AdminTableCell.vue';
import AdminFilterBar from '@/Components/Admin/AdminFilterBar.vue';
import AdminPagination, { type AdminPaginatorMeta } from '@/Components/Admin/AdminPagination.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import { SecondaryButton } from '@/packages/ui/src';
import { EnvelopeIcon } from '@heroicons/vue/20/solid';
import { Link, router } from '@inertiajs/vue3';
import { formatDate } from '@/Components/Admin/format';

type InvitationRow = {
    id: string;
    email: string;
    role: string;
    organization_id: string;
    organization_name: string;
    accepted_at: string | null;
    created_at: string | null;
};

defineProps<{
    invitations: { data: InvitationRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
}>();

const columns: AdminColumn[] = [
    { key: 'email', label: 'Invitee', sortable: true },
    { key: 'organization', label: 'Organization' },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'state', label: 'State' },
    { key: 'created_at', label: 'Sent', sortable: true },
    { key: 'actions', label: '', align: 'right' },
];

function withdraw(id: string) {
    router.delete(route('admin.invitations.destroy', id), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="Invitations">
        <template #header>
            <PageTitle :icon="EnvelopeIcon" title="Invitations" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="invitations.total"
            item-label="invitations"
            search-placeholder="Search by email"
            :selects="[
                {
                    key: 'state',
                    label: 'State',
                    anyLabel: 'All invitations',
                    options: { pending: 'Pending', accepted: 'Accepted' },
                },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="minmax(220px, 1.5fr) minmax(200px, 1.5fr) 130px 130px 150px 130px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="invitations.data.length"
            empty-message="No invitations match those filters.">
            <TableRow v-for="invitation in invitations.data" :key="invitation.id">
                <AdminTableCell edge="start">
                    <span class="text-text-primary truncate">{{ invitation.email }}</span>
                </AdminTableCell>
                <AdminTableCell>
                    <Link
                        :href="route('admin.organizations.show', invitation.organization_id)"
                        class="text-text-secondary truncate hover:underline">
                        {{ invitation.organization_name }}
                    </Link>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge tone="info">{{ invitation.role }}</AdminBadge>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge :tone="invitation.accepted_at ? 'success' : 'warning'">
                        {{ invitation.accepted_at ? 'Accepted' : 'Pending' }}
                    </AdminBadge>
                </AdminTableCell>
                <AdminTableCell>
                    <span class="text-text-secondary">{{ formatDate(invitation.created_at) }}</span>
                </AdminTableCell>
                <AdminTableCell edge="end" align="right">
                    <SecondaryButton
                        v-if="!invitation.accepted_at"
                        size="small"
                        class="text-accent-600 border-accent-500/30"
                        @click="withdraw(invitation.id)"
                        >Withdraw</SecondaryButton
                    >
                </AdminTableCell>
            </TableRow>
        </AdminTable>

        <AdminPagination :meta="invitations" item-label="invitations" />
    </AdminLayout>
</template>
