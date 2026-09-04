<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AdminStat from '@/Components/Admin/AdminStat.vue';
import AdminTrendChart from '@/Components/Admin/AdminTrendChart.vue';
import BillingStats, { type BillingStatsPayload } from '@/Components/Admin/BillingStats.vue';
import { Squares2X2Icon } from '@heroicons/vue/20/solid';
import { router } from '@inertiajs/vue3';

defineProps<{
    server: { version: string | null; build: string | null; environment: string; php: string };
    users: { total: number; placeholder: number; active: number; admins: number };
    organizations: { total: number; team: number };
    billing: BillingStatsPayload;
    range: string;
    charts: {
        registrations: { date: string; value: number }[];
        timeEntriesCreated: { date: string; value: number }[];
        timeEntriesImported: { date: string; value: number }[];
    };
}>();

const ranges = { week: 'Last week', month: 'Last month', year: 'Last year' };

function setRange(range: string) {
    router.get(route('admin.overview'), { range }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AdminLayout title="Overview">
        <template #header>
            <PageTitle :icon="Squares2X2Icon" title="Overview" />
            <select
                :value="range"
                data-testid="range_select"
                class="rounded-md border-input-border bg-input-background text-text-primary text-sm py-1.5"
                @change="setRange(($event.target as HTMLSelectElement).value)">
                <option v-for="(label, value) in ranges" :key="value" :value="value">
                    {{ label }}
                </option>
            </select>
        </template>

        <MainContainer class="py-6 space-y-8">
            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-text-tertiary">Instance</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <AdminStat
                        label="Organizations"
                        :value="organizations.total.toLocaleString()"
                        :description="organizations.team.toLocaleString() + ' are shared, the rest are personal'" />
                    <AdminStat
                        label="Users"
                        :value="users.total.toLocaleString()"
                        :description="users.placeholder.toLocaleString() + ' placeholders alongside them'" />
                    <AdminStat
                        label="Active this week"
                        tone="success"
                        :value="users.active.toLocaleString()"
                        description="Users who touched a time entry in the last seven days" />
                    <AdminStat
                        label="Super admins"
                        :value="users.admins.toLocaleString()"
                        description="Accounts that can reach this portal" />
                </dl>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-text-tertiary">Billing</h3>
                <BillingStats :stats="billing" variant="compact" />
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-text-tertiary">Trends</h3>
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <AdminTrendChart title="User registrations" :points="charts.registrations" />
                    <AdminTrendChart title="Time entries created" :points="charts.timeEntriesCreated" />
                    <AdminTrendChart title="Time entries imported" :points="charts.timeEntriesImported" />
                </div>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold text-text-tertiary">Server</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <AdminStat label="Version" :value="server.version ?? '--'" />
                    <AdminStat label="Build" :value="server.build ?? '--'" />
                    <AdminStat label="Environment" :value="server.environment" />
                    <AdminStat label="PHP" :value="server.php" />
                </dl>
            </section>
        </MainContainer>
    </AdminLayout>
</template>
