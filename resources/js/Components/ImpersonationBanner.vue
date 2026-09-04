<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { EyeIcon } from '@heroicons/vue/20/solid';
import { computed } from 'vue';

/**
 * Shown for the whole time an admin is signed in as somebody else.
 *
 * It is deliberately loud and unmissable: every action taken while it is up is attributed to the
 * person being impersonated, and the audit trail will say so.
 */
const page = usePage<{ impersonating: { name: string | null } | null }>();

const impersonating = computed(() => page.props.impersonating);

function stop() {
    router.post(route('admin.stop-impersonating'));
}
</script>

<template>
    <div
        v-if="impersonating"
        data-testid="impersonation_banner"
        class="bg-accent-600 text-white px-4 py-2 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2 text-sm">
            <EyeIcon class="w-4 h-4 shrink-0" />
            <span>
                You are signed in as
                <strong>{{ impersonating.name ?? 'another user' }}</strong>
                . Everything you do here is recorded as theirs.
            </span>
        </div>
        <button
            type="button"
            data-testid="stop_impersonating_button"
            class="text-sm font-medium underline underline-offset-2 hover:no-underline"
            @click="stop">
            Stop impersonating
        </button>
    </div>
</template>
