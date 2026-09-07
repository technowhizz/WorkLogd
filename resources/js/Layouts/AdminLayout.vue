<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';
import UserSettingsIcon from '@/Components/UserSettingsIcon.vue';
import NavigationSidebarItem from '@/Components/NavigationSidebarItem.vue';
import NotificationContainer from '@/Components/NotificationContainer.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import { Button } from '@/packages/ui/src';
import { PanelLeft } from '@lucide/vue';
import {
    ArrowLeftIcon,
    ArchiveBoxIcon,
    BuildingOffice2Icon,
    CreditCardIcon,
    EnvelopeIcon,
    ExclamationTriangleIcon,
    KeyIcon,
    Squares2X2Icon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { computed, ref } from 'vue';
import { twMerge } from 'tailwind-merge';
import type { User } from '@/types/models';

defineProps<{
    title: string;
    mainClass?: string;
}>();

const page = usePage<{
    app_name: string;
    auth: { user: User };
}>();

const showSidebarMenu = ref(false);
const sidebarVisible = ref(false);

function openSidebar() {
    showSidebarMenu.value = true;
    requestAnimationFrame(() => (sidebarVisible.value = true));
}

function closeSidebar() {
    sidebarVisible.value = false;
    setTimeout(() => (showSidebarMenu.value = false), 200);
}

const appName = computed(() => page.props.app_name ?? "WorkLog'd");
</script>

<template>
    <div v-bind="$attrs" class="flex flex-wrap bg-background text-text-secondary">
        <Teleport to="body">
            <div v-if="showSidebarMenu" class="fixed inset-0 z-40 lg:hidden" @click="closeSidebar">
                <div
                    class="absolute inset-0 bg-default-background transition-opacity duration-200"
                    :class="sidebarVisible ? 'opacity-50' : 'opacity-0'" />
            </div>
        </Teleport>

        <div
            :class="[
                sidebarVisible
                    ? 'max-lg:translate-x-0 max-lg:shadow-xl'
                    : 'max-lg:-translate-x-full',
            ]"
            class="flex-shrink-0 h-screen fixed w-[280px] px-2.5 py-4 hidden lg:flex flex-col justify-between bg-background border-r border-default-background-separator max-lg:z-50 max-lg:transition-transform max-lg:duration-200 max-lg:ease-in-out lg:w-[230px] lg:border-r-0"
            :style="showSidebarMenu ? { display: 'flex' } : undefined">
            <div class="flex flex-col h-full">
                <div
                    class="border-b border-default-background-separator pb-3 flex items-center gap-2 px-2">
                    <div class="flex-1 min-w-0">
                        <div class="font-display font-semibold text-text-primary truncate">
                            {{ appName }}
                        </div>
                        <div
                            class="text-2xs font-semibold uppercase tracking-wider text-accent-600">
                            Admin
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-7 w-7 flex-shrink-0 lg:hidden"
                        @click="closeSidebar">
                        <XMarkIcon class="h-4 w-4 text-icon-default" />
                    </Button>
                </div>

                <div
                    class="overflow-y-auto flex-1 w-full"
                    style="
                        scrollbar-width: thin;
                        scrollbar-color: var(--color-bg-primary) transparent;
                    ">
                    <nav class="pt-2">
                        <ul>
                            <NavigationSidebarItem
                                title="Overview"
                                :icon="Squares2X2Icon"
                                :href="route('admin.overview')"
                                :current="
                                    route().current('admin.overview')
                                "></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="Organizations"
                                :icon="BuildingOffice2Icon"
                                :href="route('admin.organizations.index')"
                                :current="
                                    route().current('admin.organizations.*')
                                "></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="Users"
                                :icon="UserGroupIcon"
                                :href="route('admin.users.index')"
                                :current="route().current('admin.users.*')"></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="Billing"
                                :icon="CreditCardIcon"
                                :href="route('admin.subscriptions.index')"
                                :current="
                                    route().current('admin.subscriptions.*')
                                "></NavigationSidebarItem>
                        </ul>
                    </nav>

                    <div class="text-text-tertiary text-xs font-semibold pt-5 pb-1.5 px-2">
                        System
                    </div>
                    <nav>
                        <ul>
                            <NavigationSidebarItem
                                title="Invitations"
                                :icon="EnvelopeIcon"
                                :href="route('admin.invitations.index')"
                                :current="
                                    route().current('admin.invitations.*')
                                "></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="API Tokens"
                                :icon="KeyIcon"
                                :href="route('admin.tokens.index')"
                                :current="
                                    route().current('admin.tokens.*')
                                "></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="Audit Log"
                                :icon="ArchiveBoxIcon"
                                :href="route('admin.audits.index')"
                                :current="
                                    route().current('admin.audits.*')
                                "></NavigationSidebarItem>
                            <NavigationSidebarItem
                                title="Failed Jobs"
                                :icon="ExclamationTriangleIcon"
                                :href="route('admin.failed-jobs.index')"
                                :current="
                                    route().current('admin.failed-jobs.*')
                                "></NavigationSidebarItem>
                        </ul>
                    </nav>
                </div>

                <div class="justify-self-end">
                    <ul
                        class="border-t border-default-background-separator pt-3 gap-1 flex justify-between items-center">
                        <UserSettingsIcon></UserSettingsIcon>
                        <NavigationSidebarItem
                            class="flex-1"
                            title="Back to app"
                            :icon="ArrowLeftIcon"
                            :href="route('dashboard')"></NavigationSidebarItem>
                    </ul>
                </div>
            </div>
        </div>

        <div class="flex-1 lg:ml-[230px] min-w-0">
            <div
                class="h-screen overflow-y-auto flex flex-col bg-default-background border-l border-default-background-separator">
                <div
                    class="lg:hidden w-full px-3 py-1 border-b border-b-default-background-separator text-text-secondary flex justify-between items-center">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-7 w-7 shrink-0"
                        @click="openSidebar">
                        <PanelLeft class="h-4 w-4 text-icon-default" />
                    </Button>
                    <span class="font-display font-semibold text-text-primary"
                        >{{ appName }} Admin</span
                    >
                </div>

                <Head :title="'Admin - ' + title" />

                <Banner />

                <!--
                    The wrapper carries the rule so it spans the full width. MainContainer is
                    mx-auto, and a direct flex child with auto side margins shrinks to its content
                    instead of stretching - which is why the app nests it the same way.
                -->
                <div class="border-b border-default-background-separator">
                    <MainContainer class="py-5 flex flex-wrap gap-3 justify-between items-center">
                        <slot name="header" />
                    </MainContainer>
                </div>

                <main :class="twMerge('pb-28 relative flex-1', mainClass)">
                    <slot />
                </main>
            </div>
        </div>
    </div>
    <NotificationContainer></NotificationContainer>
</template>
