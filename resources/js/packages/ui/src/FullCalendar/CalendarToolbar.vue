<script setup lang="ts">
import { Button } from '..';
import { Minus, Plus } from '@lucide/vue';
import { Tabs, TabsList } from '../tabs';
import TabBarItem from '../TabBar/TabBarItem.vue';
import DateRangeNavigator from '../Input/DateRangeNavigator.vue';
import CalendarSettingsPopover from './CalendarSettingsPopover.vue';
import type { CalendarSettings } from './calendarSettings';
import { TIME_AXIS_WIDTH } from './calendarTypes';

/** The `px-2` on the toolbar, which the first column's offset is measured from. */
const CONTAINER_PADDING = 8;
/** The grid sits inside the calendar's 1px border, so its columns start one pixel further in. */
const CALENDAR_BORDER = 1;

defineProps<{
    rangeLabel: string;
    activeView: string;
    settings: CalendarSettings;
    canZoomIn: boolean;
    canZoomOut: boolean;
}>();

const emit = defineEmits<{
    prev: [];
    next: [];
    today: [];
    'zoom-in': [];
    'zoom-out': [];
    'change-view': [view: string];
    'update:settings': [value: CalendarSettings];
}>();
</script>

<template>
    <div class="flex items-center justify-between bg-default-background px-2 py-1.5">
        <div
            class="flex items-center gap-2"
            :style="{
                paddingLeft: TIME_AXIS_WIDTH - CONTAINER_PADDING + CALENDAR_BORDER + 'px',
            }">
            <DateRangeNavigator
                :label="rangeLabel"
                trigger-test-id="calendar-title"
                trigger-aria-label="today"
                @previous="emit('prev')"
                @next="emit('next')"
                @select="emit('today')" />

            <!-- Vertical zoom: each step shows one hour more or less. Set apart from the paging
                 controls, since it does something else entirely. -->
            <div class="flex items-center gap-2 ml-3">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 w-8 p-0"
                    aria-label="Zoom out"
                    title="Zoom out (show one more hour)"
                    data-testid="calendar-zoom-out"
                    :disabled="!canZoomOut"
                    @click="emit('zoom-out')">
                    <Minus class="h-4 w-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 w-8 p-0"
                    aria-label="Zoom in"
                    title="Zoom in (show one hour less)"
                    data-testid="calendar-zoom-in"
                    :disabled="!canZoomIn"
                    @click="emit('zoom-in')">
                    <Plus class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <!-- Right: Page supplied actions + View switcher + Settings -->
        <div class="flex items-center gap-1">
            <!--
                Anything provider specific lives here rather than in this package, which stays
                agnostic - the Jira sync button is passed in by Calendar.vue.
            -->
            <slot name="actions"></slot>
            <Tabs
                :model-value="activeView"
                @update:model-value="(v) => emit('change-view', String(v))">
                <TabsList class="flex items-center space-x-0.5 sm:space-x-1">
                    <TabBarItem value="timeGridWeek">week</TabBarItem>
                    <TabBarItem value="timeGridDay">day</TabBarItem>
                </TabsList>
            </Tabs>
            <CalendarSettingsPopover
                :settings="settings"
                @update:settings="(v) => emit('update:settings', v)">
                <template #extra-settings>
                    <slot name="extra-settings"></slot>
                </template>
            </CalendarSettingsPopover>
        </div>
    </div>
</template>
