<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import Card from '@/Components/Common/Card.vue';
import AdminBadge from '@/Components/Admin/AdminBadge.vue';
import AdminDangerDialog from '@/Components/Admin/AdminDangerDialog.vue';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput, Checkbox } from '@/packages/ui/src';
import { UserCircleIcon } from '@heroicons/vue/20/solid';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDate } from '@/Components/Admin/format';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    is_placeholder: boolean;
    is_admin: boolean;
    email_verified: boolean;
    can_be_impersonated: boolean;
    created_at: string | null;
    timezone: string;
    week_start: string;
    pending_email: string | null;
    is_admin_by_configuration: boolean;
    is_self: boolean;
};

const props = defineProps<{
    user: AdminUser;
    organizations: { id: string; name: string; role: string; is_owner: boolean }[];
    options: { timezones: Record<string, string>; weekdays: Record<string, string> };
}>();

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    timezone: props.user.timezone,
    week_start: props.user.week_start,
    is_admin: props.user.is_admin,
    is_email_verified: props.user.email_verified,
    password: '',
});

/*
 * Revoking your own access locks you out, and an environment granted admin gets it back on the
 * next request either way. The server enforces both - this only explains why the switch is dead.
 */
const adminLocked = computed(() => props.user.is_self || props.user.is_admin_by_configuration);
const adminLockReason = computed(() => {
    if (props.user.is_admin_by_configuration) {
        return 'Granted by the SUPER_ADMINS environment variable, so it cannot be revoked here.';
    }
    if (props.user.is_self) {
        return 'You cannot revoke your own access to this portal.';
    }
    return 'Grants full access to this portal, across every organization.';
});

function save() {
    form.put(route('admin.users.update', props.user.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('password'),
    });
}

const showDelete = ref(false);

function destroy() {
    router.delete(route('admin.users.destroy', props.user.id));
}

function impersonate() {
    router.post(route('admin.users.impersonate', props.user.id));
}

function resendVerification() {
    router.post(route('admin.users.resend-verification', props.user.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :title="user.name">
        <template #header>
            <div class="flex items-center gap-3 min-w-0">
                <PageTitle :icon="UserCircleIcon" :title="user.name" />
                <AdminBadge v-if="user.is_admin" tone="danger">Super admin</AdminBadge>
                <AdminBadge v-if="user.is_placeholder">Placeholder</AdminBadge>
            </div>
            <div class="flex items-center gap-2">
                <SecondaryButton
                    v-if="!user.email_verified"
                    @click="resendVerification"
                    >Resend verification</SecondaryButton
                >
                <SecondaryButton
                    v-if="user.can_be_impersonated && !user.is_self"
                    @click="impersonate"
                    >Impersonate</SecondaryButton
                >
                <SecondaryButton
                    v-if="!user.is_self"
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
                        <h4 class="font-medium text-text-primary">Account</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="name" value="Name" />
                                <TextInput id="name" v-model="form.name" type="text" class="w-full mt-1" />
                                <p v-if="form.errors.name" class="text-xs text-accent-600 mt-1">
                                    {{ form.errors.name }}
                                </p>
                            </div>
                            <div>
                                <InputLabel for="email" value="Email" />
                                <TextInput id="email" v-model="form.email" type="email" class="w-full mt-1" />
                                <p v-if="form.errors.email" class="text-xs text-accent-600 mt-1">
                                    {{ form.errors.email }}
                                </p>
                                <p v-if="user.pending_email" class="text-xs text-text-tertiary mt-1">
                                    Change to {{ user.pending_email }} is awaiting confirmation.
                                </p>
                            </div>
                            <div>
                                <InputLabel for="timezone" value="Timezone" />
                                <select
                                    id="timezone"
                                    v-model="form.timezone"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option v-for="(label, value) in options.timezones" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="week_start" value="Week starts on" />
                                <select
                                    id="week_start"
                                    v-model="form.week_start"
                                    class="mt-1 w-full rounded-md border-input-border bg-input-background text-text-primary text-sm">
                                    <option v-for="(label, value) in options.weekdays" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="password" value="Set a new password" />
                                <TextInput
                                    id="password"
                                    v-model="form.password"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="Leave empty to keep the current one"
                                    class="w-full mt-1" />
                            </div>
                        </div>

                        <div class="space-y-3 pt-2">
                            <label class="flex items-start gap-2 text-sm">
                                <Checkbox
                                    v-model:checked="form.is_admin"
                                    :disabled="adminLocked"
                                    data-testid="is_admin_checkbox"
                                    :class="adminLocked ? 'opacity-40 pointer-events-none' : ''" />
                                <span>
                                    <span class="text-text-primary">Super admin</span>
                                    <span class="block text-xs text-text-tertiary">{{ adminLockReason }}</span>
                                </span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model:checked="form.is_email_verified" />
                                <span class="text-text-primary">Email address verified</span>
                            </label>
                        </div>

                        <div class="pt-2">
                            <PrimaryButton type="submit" :loading="form.processing">Save</PrimaryButton>
                        </div>
                    </form>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <div class="p-5 space-y-3 text-sm">
                            <h4 class="font-medium text-text-primary">At a glance</h4>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Joined</span>
                                <span class="text-text-primary">{{ formatDate(user.created_at) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-text-tertiary">Email</span>
                                <AdminBadge :tone="user.email_verified ? 'success' : 'warning'">
                                    {{ user.email_verified ? 'Verified' : 'Unverified' }}
                                </AdminBadge>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-text-tertiary">Organizations</span>
                                <span class="text-text-primary">{{ organizations.length }}</span>
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <div class="p-5">
                            <h4 class="font-medium text-text-primary mb-3">Memberships</h4>
                            <div class="divide-y divide-border-secondary">
                                <div
                                    v-for="organization in organizations"
                                    :key="organization.id"
                                    class="py-2 flex items-center justify-between gap-2 text-sm">
                                    <Link
                                        :href="route('admin.organizations.show', organization.id)"
                                        class="truncate text-text-primary hover:underline">
                                        {{ organization.name }}
                                    </Link>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <AdminBadge v-if="organization.is_owner" tone="info">Owner</AdminBadge>
                                        <AdminBadge>{{ organization.role }}</AdminBadge>
                                    </div>
                                </div>
                                <p v-if="organizations.length === 0" class="py-3 text-sm text-text-tertiary">
                                    Belongs to no organization.
                                </p>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </MainContainer>

        <AdminDangerDialog
            v-model:show="showDelete"
            title="Delete this user?"
            action-label="Delete user"
            @confirm="destroy">
            This deletes <strong>{{ user.name }}</strong> and their personal organization. It is
            refused if they own an organization that other people are still members of.
        </AdminDangerDialog>
    </AdminLayout>
</template>
