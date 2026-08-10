<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    Button,
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/packages/ui/src';
import { RefreshCw } from '@lucide/vue';
import JiraSyncDialog from '@/Components/Jira/JiraSyncDialog.vue';

const props = defineProps<{
    isConnected: boolean;
    /**
     * The calendar's visible window in local dates (YYYY-MM-DD), both ends **inclusive** —
     * `endDate` is the last visible column. The page converts the half open window that
     * `dates-change` emits, so everything downstream of it speaks the same dialect as the Jira
     * endpoints.
     */
    startDate: string | null;
    endDate: string | null;
}>();

const showDialog = ref(false);

const hasRange = computed(() => props.startDate !== null && props.endDate !== null);
const canSync = computed(() => props.isConnected && hasRange.value);

const reason = computed(() => {
    if (!props.isConnected) {
        return 'Connect your Jira account under Profile Settings to sync your time.';
    }
    if (!hasRange.value) {
        return 'Waiting for the calendar to finish loading.';
    }
    return 'Sync the dates currently on screen to Jira.';
});
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <!--
                    The span is what makes the tooltip work at all: the button's base class sets
                    disabled:pointer-events-none, so a disabled button never receives hover and
                    can never explain why it is disabled. Same pattern as TimesheetCell.
                -->
                <span :class="canSync ? 'inline-block' : 'inline-block cursor-not-allowed'">
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8"
                        :disabled="!canSync"
                        aria-label="Sync to Jira"
                        data-testid="jira_sync_button"
                        @click="showDialog = true">
                        <RefreshCw class="h-3.5 w-3.5 mr-1.5" />
                        Jira
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent side="bottom" data-testid="jira_sync_button_tooltip">
                {{ reason }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>

    <JiraSyncDialog
        v-if="canSync"
        :show="showDialog"
        :start-date="startDate!"
        :end-date="endDate!"
        @close="showDialog = false" />
</template>
