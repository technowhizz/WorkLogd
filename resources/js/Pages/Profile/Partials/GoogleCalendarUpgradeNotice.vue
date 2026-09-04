<script setup lang="ts">
import ActionSection from '@/Components/ActionSection.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import GoogleIcon from '@/packages/ui/src/Icons/GoogleIcon.vue';
import { Link } from '@inertiajs/vue3';
import { canManageBilling } from '@/utils/permissions';
import { useAppName } from '@/utils/appName';

/**
 * Shown in place of the connect form when the instance has Google Calendar configured but the
 * organization's plan does not include it.
 *
 * Deliberately not hidden. Somebody who came looking for the calendar should find out that it
 * exists and what it would take to have it, rather than concluding the feature was dropped.
 */
const appName = useAppName();
</script>

<template>
    <ActionSection>
        <template #title> Google Calendar </template>

        <template #description>
            See your calendar alongside your time entries, and turn a meeting into a tracked entry
            with one click.
        </template>

        <template #content>
            <div class="flex items-start gap-3">
                <GoogleIcon class="w-5 h-5 mt-0.5 shrink-0 opacity-60" />
                <div class="space-y-3 min-w-0">
                    <p class="text-sm text-text-secondary">
                        The Google Calendar integration is part of the Professional plan.
                        {{ appName }} is on the free plan for this organization, so it is not
                        available yet.
                    </p>
                    <Link v-if="canManageBilling()" :href="route('billing.show')">
                        <SecondaryButton>See plans</SecondaryButton>
                    </Link>
                    <p v-else class="text-sm text-text-tertiary">
                        Ask an owner or administrator of this organization to upgrade.
                    </p>
                </div>
            </div>
        </template>
    </ActionSection>
</template>
