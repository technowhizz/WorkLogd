<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import Card from '@/Components/Common/Card.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import AdminDangerDialog from '@/Components/Admin/AdminDangerDialog.vue';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput, Checkbox } from '@/packages/ui/src';
import { BuildingOffice2Icon } from '@heroicons/vue/20/solid';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDate } from '@/Components/Admin/format';

type Organization = {
    id: string;
    name: string;
    currency: string;
    owner_email: string | null;
    personal_team: boolean;
    members_count: number | null;
    plan: string | null;
    created_at: string | null;
    billable_rate: number | null;
    date_format: string;
    time_format: string;
    interval_format: string;
    number_format: string;
    currency_format: string;
    employees_can_see_billable_rates: boolean;
    employees_can_manage_tasks: boolean;
    prevent_overlapping_time_entries: boolean;
    breaks_enabled: boolean;
    jira_site_url: string | null;
};

const props = defineProps<{
    organization: Organization;
    members: { id: string; name: string; email: string; role: string; is_placeholder: boolean }[];
    invitations: {
        id: string;
        email: string;
        role: string;
        accepted_at: string | null;
        created_at: string | null;
    }[];
    subscriptionId: string | null;
    options: {
        currencies: Record<string, string>;
        date_format: Record<string, string>;
        time_format: Record<string, string>;
        interval_format: Record<string, string>;
        number_format: Record<string, string>;
        currency_format: Record<string, string>;
        importers: string[];
        timezones: Record<string, string>;
    };
}>();

const form = useForm({
    name: props.organization.name,
    currency: props.organization.currency,
    billable_rate: props.organization.billable_rate,
    date_format: props.organization.date_format,
    time_format: props.organization.time_format,
    interval_format: props.organization.interval_format,
    number_format: props.organization.number_format,
    currency_format: props.organization.currency_format,
    employees_can_see_billable_rates: props.organization.employees_can_see_billable_rates,
    employees_can_manage_tasks: props.organization.employees_can_manage_tasks,
    prevent_overlapping_time_entries: props.organization.prevent_overlapping_time_entries,
    breaks_enabled: props.organization.breaks_enabled,
});

function save() {
    form.put(route('admin.organizations.update', props.organization.id), { preserveScroll: true });
}

const showDelete = ref(false);

function destroy() {
    router.delete(route('admin.organizations.destroy', props.organization.id));
}

const importForm = useForm<{ file: File | null; type: string; timezone: string }>({
    file: null,
    type: props.options.importers[0] ?? '',
    timezone: 'UTC',
});

function submitImport() {
    importForm.post(route('admin.organizations.import', props.organization.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => importForm.reset(),
    });
}
</script>

<template>
    <AdminLayout :title="organization.name">
        <template #header>
            <div class="flex items-center gap-3 min-w-0">
                <PageTitle :icon="BuildingOffice2Icon" :title="organization.name" />
                <AdminBadge v-if="organization.personal_team">Personal</AdminBadge>
            </div>
            <div class="flex items-center gap-2">
                <Link :href="route('admin.organizations.billing', organization.id)">
                    <SecondaryButton>Billing</SecondaryButton>
                </Link>
                <a :href="route('admin.organizations.export', organization.id)">
                    <SecondaryButton>Export</SecondaryButton>
                </a>
                <SecondaryButton
                    class="text-accent-600 border-accent-500/30"
                    @click="showDelete = true"
                    >Delete</SecondaryButton
                >
            </div>
        </template>

        <MainContainer class="py-6 space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <Card class="lg:col-span-2">
                    <form class="p-5 space-y-4" @submit.prevent="save">
                        <h4 class="font-medium text-text-primary">Settings</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="name" value="Name" />
                                <TextInput
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    class="w-full mt-1" />
                                <p v-if="form.errors.name" class="text-xs text-accent-600 mt-1">
                                    {{ form.errors.name }}
                                </p>
                            </div>
                            <div>
                                <InputLabel for="currency" value="Currency" />
                                <select
                                    id="currency"
                                    v-model="form.currency"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, code) in options.currencies"
                                        :key="code"
                                        :value="code">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="billable_rate" value="Billable rate (in cents)" />
                                <TextInput
                                    id="billable_rate"
                                    v-model="form.billable_rate"
                                    type="number"
                                    class="w-full mt-1" />
                                <p
                                    v-if="form.errors.billable_rate"
                                    class="text-xs text-accent-600 mt-1">
                                    {{ form.errors.billable_rate }}
                                </p>
                            </div>
                            <div>
                                <InputLabel for="date_format" value="Date format" />
                                <select
                                    id="date_format"
                                    v-model="form.date_format"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, value) in options.date_format"
                                        :key="value"
                                        :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="time_format" value="Time format" />
                                <select
                                    id="time_format"
                                    v-model="form.time_format"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, value) in options.time_format"
                                        :key="value"
                                        :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="interval_format" value="Interval format" />
                                <select
                                    id="interval_format"
                                    v-model="form.interval_format"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, value) in options.interval_format"
                                        :key="value"
                                        :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="number_format" value="Number format" />
                                <select
                                    id="number_format"
                                    v-model="form.number_format"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, value) in options.number_format"
                                        :key="value"
                                        :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="currency_format" value="Currency format" />
                                <select
                                    id="currency_format"
                                    v-model="form.currency_format"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option
                                        v-for="(label, value) in options.currency_format"
                                        :key="value"
                                        :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-2 pt-2">
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model:checked="form.employees_can_see_billable_rates" />
                                <span>Employees can see billable rates</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model:checked="form.employees_can_manage_tasks" />
                                <span>Employees can manage tasks</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model:checked="form.prevent_overlapping_time_entries" />
                                <span>Prevent overlapping time entries</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model:checked="form.breaks_enabled" />
                                <span>Breaks enabled</span>
                            </label>
                        </div>

                        <div class="pt-2">
                            <PrimaryButton type="submit" :loading="form.processing"
                                >Save</PrimaryButton
                            >
                        </div>
                    </form>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <div class="p-5 space-y-3 text-sm">
                            <h4 class="font-medium text-text-primary">At a glance</h4>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Owner</span>
                                <span class="text-text-primary truncate">{{
                                    organization.owner_email ?? '--'
                                }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Members</span>
                                <span class="text-text-primary">{{
                                    organization.members_count ?? 0
                                }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Created</span>
                                <span class="text-text-primary">{{
                                    formatDate(organization.created_at)
                                }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Jira site</span>
                                <span class="text-text-primary truncate">{{
                                    organization.jira_site_url ?? '--'
                                }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-text-tertiary">Plan</span>
                                <AdminBadge
                                    :tone="
                                        organization.plan && organization.plan !== 'free'
                                            ? 'success'
                                            : 'neutral'
                                    ">
                                    {{ organization.plan ?? 'None' }}
                                </AdminBadge>
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <form class="p-5 space-y-3" @submit.prevent="submitImport">
                            <h4 class="font-medium text-text-primary">Import data</h4>
                            <p class="text-xs text-text-tertiary">
                                Imports into this organization. A failed import rolls back entirely.
                            </p>
                            <input
                                type="file"
                                class="block w-full text-sm text-text-secondary"
                                @change="
                                    importForm.file =
                                        ($event.target as HTMLInputElement).files?.[0] ?? null
                                " />
                            <select
                                v-model="importForm.type"
                                class="w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option
                                    v-for="importer in options.importers"
                                    :key="importer"
                                    :value="importer">
                                    {{ importer }}
                                </option>
                            </select>
                            <select
                                v-model="importForm.timezone"
                                class="w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                <option
                                    v-for="(label, value) in options.timezones"
                                    :key="value"
                                    :value="value">
                                    {{ label }}
                                </option>
                            </select>
                            <SecondaryButton type="submit" :loading="importForm.processing"
                                >Import</SecondaryButton
                            >
                        </form>
                    </Card>
                </div>
            </div>

            <Card>
                <div class="p-5">
                    <h4 class="font-medium text-text-primary mb-3">
                        Members ({{ members.length }})
                    </h4>
                    <div class="divide-y divide-border-secondary">
                        <div
                            v-for="member in members"
                            :key="member.id"
                            class="py-2 flex items-center justify-between gap-3 text-sm">
                            <Link
                                :href="route('admin.users.show', member.id)"
                                class="min-w-0 hover:text-text-primary transition">
                                <span class="font-medium text-text-primary">{{ member.name }}</span>
                                <span class="text-text-tertiary ml-2 truncate">{{
                                    member.email
                                }}</span>
                            </Link>
                            <div class="flex items-center gap-2 shrink-0">
                                <AdminBadge v-if="member.is_placeholder">Placeholder</AdminBadge>
                                <AdminBadge tone="info">{{ member.role }}</AdminBadge>
                            </div>
                        </div>
                        <p v-if="members.length === 0" class="py-4 text-sm text-text-tertiary">
                            No members.
                        </p>
                    </div>
                </div>
            </Card>

            <Card>
                <div class="p-5">
                    <h4 class="font-medium text-text-primary mb-3">
                        Invitations ({{ invitations.length }})
                    </h4>
                    <div class="divide-y divide-border-secondary">
                        <div
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="py-2 flex items-center justify-between gap-3 text-sm">
                            <span class="text-text-primary truncate">{{ invitation.email }}</span>
                            <div class="flex items-center gap-2 shrink-0">
                                <AdminBadge tone="info">{{ invitation.role }}</AdminBadge>
                                <AdminBadge :tone="invitation.accepted_at ? 'success' : 'warning'">
                                    {{ invitation.accepted_at ? 'Accepted' : 'Pending' }}
                                </AdminBadge>
                            </div>
                        </div>
                        <p v-if="invitations.length === 0" class="py-4 text-sm text-text-tertiary">
                            No invitations.
                        </p>
                    </div>
                </div>
            </Card>
        </MainContainer>

        <AdminDangerDialog
            v-model:show="showDelete"
            title="Delete this organization?"
            :confirm-text="organization.name"
            action-label="Delete organization"
            @confirm="destroy">
            This deletes
            <strong>{{ organization.name }}</strong>
            and every time entry, project, client, task, tag and report inside it. Placeholder
            members are deleted with it. This cannot be undone.
        </AdminDangerDialog>
    </AdminLayout>
</template>
