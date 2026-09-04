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
import { SecondaryButton } from '@/packages/ui/src';
import { KeyIcon } from '@heroicons/vue/20/solid';
import { Link, router } from '@inertiajs/vue3';
import { formatDate } from '@/Components/Admin/format';

type TokenRow = {
    id: string;
    name: string | null;
    user_email: string | null;
    user_id: string | null;
    revoked: boolean;
    scopes: string[];
    expires_at: string | null;
    created_at: string | null;
};

defineProps<{
    tokens: { data: TokenRow[] } & AdminPaginatorMeta;
    filters: Record<string, string | null>;
}>();

const columns: AdminColumn[] = [
    { key: 'name', label: 'Token', sortable: true },
    { key: 'user', label: 'Owner' },
    { key: 'state', label: 'State' },
    { key: 'expires_at', label: 'Expires', sortable: true },
    { key: 'actions', label: '', align: 'right' },
];

function isExpired(token: TokenRow): boolean {
    return token.expires_at !== null && new Date(token.expires_at).getTime() < Date.now();
}

function revoke(id: string) {
    router.delete(route('admin.tokens.revoke', id), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="API tokens">
        <template #header>
            <PageTitle :icon="KeyIcon" title="API tokens" />
        </template>

        <AdminFilterBar
            :filters="filters"
            :total="tokens.total"
            item-label="tokens"
            search-placeholder="Search by token name or owner email"
            :selects="[
                {
                    key: 'state',
                    label: 'State',
                    anyLabel: 'All tokens',
                    options: { active: 'Active', revoked: 'Revoked' },
                },
            ]" />

        <AdminTable
            :columns="columns"
            grid-template="minmax(200px, 1.5fr) minmax(220px, 1.5fr) 140px 150px 130px"
            :sort="filters.sort"
            :direction="filters.direction"
            :total="tokens.data.length"
            empty-message="No tokens match those filters.">
            <TableRow v-for="token in tokens.data" :key="token.id">
                <AdminTableCell edge="start">
                    <span class="text-text-primary truncate">{{ token.name ?? 'Unnamed' }}</span>
                </AdminTableCell>
                <AdminTableCell>
                    <Link
                        v-if="token.user_id"
                        :href="route('admin.users.show', token.user_id)"
                        class="text-text-secondary truncate hover:underline">
                        {{ token.user_email ?? token.user_id }}
                    </Link>
                    <span v-else class="text-text-tertiary">--</span>
                </AdminTableCell>
                <AdminTableCell>
                    <AdminBadge v-if="token.revoked" tone="danger">Revoked</AdminBadge>
                    <AdminBadge v-else-if="isExpired(token)" tone="warning">Expired</AdminBadge>
                    <AdminBadge v-else tone="success">Active</AdminBadge>
                </AdminTableCell>
                <AdminTableCell>
                    <span class="text-text-secondary">{{ formatDate(token.expires_at) }}</span>
                </AdminTableCell>
                <AdminTableCell edge="end" align="right">
                    <SecondaryButton
                        v-if="!token.revoked"
                        size="small"
                        class="text-accent-600 border-accent-500/30"
                        @click="revoke(token.id)"
                        >Revoke</SecondaryButton
                    >
                </AdminTableCell>
            </TableRow>
        </AdminTable>

        <AdminPagination :meta="tokens" item-label="tokens" />
    </AdminLayout>
</template>
