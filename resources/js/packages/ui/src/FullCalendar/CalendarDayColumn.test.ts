import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { computed } from 'vue';
import CalendarDayColumn from './CalendarDayColumn.vue';
import { getLocalizedDayJs } from '../utils/time';
import type { CalendarEvent, DayEvent } from './calendarTypes';
import type { TimeEntry } from '@/packages/api/src';

const DAY = '2026-07-14';

/** Stand-ins for what the calendar derives from the user's "no project" color. */
const SELECTION_BACKGROUND = '#dcdee1';
const SELECTION_BORDER = '#b5b9bf';

/*
 * The event block is wrapped in a Reka `TooltipTrigger as-child`, which clones the vnode and
 * merges props onto it. These tests pin the two things that would silently break if that
 * merge ever stopped behaving: the drag/click handler must survive, and no wrapper element
 * may appear between the inset container and the absolutely-positioned block.
 */
function dayEvent(
    overrides: Partial<DayEvent> = {},
    eventOverrides: Partial<CalendarEvent> = {}
): DayEvent {
    const timeEntry = {
        id: 'entry-1',
        start: '2026-07-14T10:00:00Z',
        end: '2026-07-14T11:00:00Z',
        description: 'Render test entry',
    } as TimeEntry;

    return {
        event: {
            id: 'entry-1',
            timeEntry,
            isRunning: false,
            isBreak: false,
            isMisplacedBreak: false,
            durationMinutes: 60,
            title: 'Render test entry',
            backgroundColor: '#cccccc',
            borderColor: '#999999',
            dayStart: getLocalizedDayJs(timeEntry.start),
            dayEnd: getLocalizedDayJs(timeEntry.end),
            ...eventOverrides,
        },
        top: 100,
        height: 50,
        left: '0%',
        width: '100%',
        isClippedStart: false,
        isClippedEnd: false,
        ...overrides,
    };
}

function mountColumn(overrides: Record<string, unknown> = {}) {
    return mount(CalendarDayColumn, {
        props: {
            dayStr: DAY,
            totalGridHeight: 1200,
            hasActivityStatus: false,
            showExternalLane: false,
            dayEvents: [dayEvent()],
            getEventStyle: () => ({
                position: 'absolute',
                top: '100px',
                height: '50px',
                left: '0%',
                width: '100%',
            }),
            getEventOpacityClass: () => 'opacity-90',
            getEventDurationSeconds: () => 3600,
            suppressEventTooltips: false,
            isDragging: false,
            dragEventId: null,
            dragPreview: undefined,
            showDragLabels: false,
            dragRangeLabel: null,
            dragDurationLabel: null,
            resizeEventId: null,
            resizeCrossDayPreview: undefined,
            showNowIndicator: false,
            nowIndicatorTop: 0,
            activityBoxes: [],
            getActivityBoxLabel: () => '',
            getActivityBoxActivities: () => [],
            getActivityPercentage: () => '0',
            getActivityText: () => '',
            getTopActivity: () => null,
            isDayView: false,
            externalEventBoxes: [],
            showSelection: false,
            isSelectionStart: false,
            isSelectionIntermediate: false,
            isSelectionEnd: false,
            selectionTop: 0,
            selectionHeight: 0,
            selectionEndTop: 0,
            selectionEndHeight: 0,
            showSelectionLabels: false,
            selectionRangeLabel: null,
            selectionDurationLabel: null,
            selectionBackgroundColor: SELECTION_BACKGROUND,
            selectionBorderColor: SELECTION_BORDER,
            ...overrides,
        },
        global: {
            provide: {
                organization: computed(() => ({
                    time_format: '24-hours',
                    interval_format: 'hours-minutes',
                    number_format: 'point',
                })),
            },
        },
    });
}

describe('CalendarDayColumn event blocks', () => {
    it('still emits event-pointerdown from the block itself', async () => {
        const wrapper = mountColumn();

        await wrapper.get('.fc-event').trigger('pointerdown');

        expect(wrapper.emitted('event-pointerdown')).toHaveLength(1);
    });

    it('keeps the block a direct child of the inset container, absolutely positioned', () => {
        const wrapper = mountColumn();

        expect(wrapper.findAll('.fc-event')).toHaveLength(1);

        const event = wrapper.get('.fc-event');
        expect(event.attributes('data-event-id')).toBe('entry-1');
        expect(event.attributes('style')).toContain('position: absolute');
        expect(event.element.parentElement?.classList.contains('absolute')).toBe(true);
    });

    it('merges the tooltip trigger onto the block rather than a wrapper', () => {
        const wrapper = mountColumn();

        expect(wrapper.get('.fc-event').attributes('data-grace-area-trigger')).toBeDefined();
    });

    it('does not start a drag when the pointer lands on a resizer', async () => {
        const wrapper = mountColumn();

        await wrapper.get('.fc-event-resizer-end').trigger('pointerdown');

        expect(wrapper.emitted('resizer-pointerdown')).toHaveLength(1);
        expect(wrapper.emitted('event-pointerdown')).toBeUndefined();
    });
});

/*
 * The two grips are pinned to the block's edges and hit-test even at `opacity: 0`, so on a short
 * block they used to cover all of it — and since a pointer-down on a grip is deliberately not a
 * click on the entry, the edit dialog became unreachable. A 15-minute entry is 25px tall at the
 * default zoom and 4px at the minimum, so this was the normal case, not an edge case.
 *
 * Heights below are the laid-out `dayEvent.height`, and the assertions are on the grip that is
 * left over once 16px of the block are reserved for the click.
 */
describe('CalendarDayColumn resize grips', () => {
    function gripColumn(height: number, overrides: Partial<DayEvent> = {}) {
        return mountColumn({ dayEvents: [dayEvent({ height, ...overrides })] });
    }

    it('gives a block with room for them the full-size grips', () => {
        const wrapper = gripColumn(50);

        expect(wrapper.get('.fc-event-resizer-start').attributes('style')).toContain(
            'height: 12px'
        );
        expect(wrapper.get('.fc-event-resizer-end').attributes('style')).toContain('height: 12px');
    });

    it('shrinks both grips once the full pair would leave too little to click', () => {
        // 30px - two 12px grips (10px of each inside the block) leaves 10px; two 6px ones leave 22.
        const wrapper = gripColumn(30);

        expect(wrapper.get('.fc-event-resizer-start').attributes('style')).toContain('height: 6px');
        expect(wrapper.get('.fc-event-resizer-end').attributes('style')).toContain('height: 6px');
    });

    it('drops the grips entirely on a block too short to carry even the small ones', () => {
        // A 15-minute entry is this tall at ~50px/hour, and only 4px at the minimum zoom.
        const wrapper = gripColumn(12);

        expect(wrapper.find('.fc-event-resizer').exists()).toBe(false);
    });

    it('still opens the entry from a pointer-down anywhere on a block with no grips', async () => {
        const wrapper = gripColumn(4);

        // Whatever the pointer lands on is the block itself, which is the click-to-edit path.
        await wrapper.get('.fc-event').trigger('pointerdown');

        expect(wrapper.emitted('event-pointerdown')).toHaveLength(1);
        expect(wrapper.emitted('resizer-pointerdown')).toBeUndefined();
    });

    it('keeps a full-size grip on a block that only renders one', () => {
        // Clipped at midnight, so only the end is draggable and it costs half the room.
        const wrapper = gripColumn(26, { isClippedStart: true });

        expect(wrapper.find('.fc-event-resizer-start').exists()).toBe(false);
        expect(wrapper.get('.fc-event-resizer-end').attributes('style')).toContain('height: 12px');
    });

    it('counts only the grips a running entry actually shows', () => {
        // No end grip on a running entry, so 20px is enough for a small start one.
        const wrapper = mountColumn({
            dayEvents: [dayEvent({ height: 20 }, { isRunning: true })],
        });

        expect(wrapper.find('.fc-event-resizer-end').exists()).toBe(false);
        expect(wrapper.get('.fc-event-resizer-start').attributes('style')).toContain('height: 6px');
    });
});

/*
 * The ghost is colored by the parent so it matches the entry the drag will create. The
 * stylesheet reads those two custom properties, so a ghost that drops them silently reverts to
 * an uncolored box — assert they reach every ghost box, including the cross-day ones.
 */
describe('CalendarDayColumn selection ghost', () => {
    it('carries the selection colors on the single-day ghost', () => {
        const wrapper = mountColumn({
            showSelection: true,
            isSelectionStart: true,
            selectionTop: 100,
            selectionHeight: 50,
        });

        const style = wrapper.get('.fc-selection-ghost').attributes('style');
        expect(style).toContain(`--fc-selection-bg: ${SELECTION_BACKGROUND}`);
        expect(style).toContain(`--fc-selection-border: ${SELECTION_BORDER}`);
    });

    it('carries them on the intermediate and end ghosts of a cross-day selection', () => {
        const wrapper = mountColumn({
            showSelection: true,
            isSelectionIntermediate: true,
            isSelectionEnd: true,
            selectionEndTop: 0,
            selectionEndHeight: 200,
        });

        const ghosts = wrapper.findAll('.fc-selection-ghost');
        expect(ghosts).toHaveLength(2);
        ghosts.forEach((ghost) => {
            expect(ghost.attributes('style')).toContain(
                `--fc-selection-bg: ${SELECTION_BACKGROUND}`
            );
            expect(ghost.attributes('style')).toContain(
                `--fc-selection-border: ${SELECTION_BORDER}`
            );
        });
    });

    /* The labels are what e2e reads the live selection off, so the hooks are pinned. */
    it('still labels the selection through the shared gesture markup', () => {
        const wrapper = mountColumn({
            showSelection: true,
            isSelectionStart: true,
            selectionTop: 100,
            selectionHeight: 50,
            showSelectionLabels: true,
            selectionRangeLabel: '10:00 - 11:00',
            selectionDurationLabel: '1h 00min',
        });

        expect(wrapper.get('[data-selection-range]').text()).toBe('10:00 - 11:00');
        expect(wrapper.get('[data-selection-duration]').text()).toBe('1h 00min');
        expect(wrapper.get('.fc-gesture-labels').attributes('style')).toContain('top: 100px');
    });
});

/*
 * Moving an entry gets the same live readout as drawing one, on the preview of where it would
 * land. Only the column under the cursor carries it, so a move that spans days states its times
 * once rather than on every column it touches.
 */
describe('CalendarDayColumn drag labels', () => {
    const DRAG_PREVIEW = {
        position: 'absolute',
        top: '200px',
        height: '100px',
        zIndex: '100',
    };

    function draggingColumn(overrides: Record<string, unknown> = {}) {
        return mountColumn({
            isDragging: true,
            dragEventId: 'entry-1',
            dragPreview: DRAG_PREVIEW,
            showDragLabels: true,
            dragRangeLabel: '12:00 - 13:00',
            dragDurationLabel: '1h 00min',
            ...overrides,
        });
    }

    it('states the range and duration on the preview', () => {
        const wrapper = draggingColumn();

        expect(wrapper.get('[data-drag-range]').text()).toBe('12:00 - 13:00');
        expect(wrapper.get('[data-drag-duration]').text()).toBe('1h 00min');
    });

    it('lines the labels up with the preview box', () => {
        const wrapper = draggingColumn();

        const style = wrapper.get('.fc-gesture-labels').attributes('style');
        expect(style).toContain('top: 200px');
        expect(style).toContain('height: 100px');
    });

    it('leaves the other columns of a cross-day move unlabelled', () => {
        const wrapper = draggingColumn({ showDragLabels: false });

        expect(wrapper.find('.fc-cross-day-preview').exists()).toBe(true);
        expect(wrapper.find('[data-drag-range]').exists()).toBe(false);
    });

    it('labels nothing on a column the move does not reach', () => {
        const wrapper = draggingColumn({ dragPreview: undefined });

        expect(wrapper.find('[data-drag-range]').exists()).toBe(false);
    });

    it('shows no labels when nothing is being dragged', () => {
        const wrapper = mountColumn();

        expect(wrapper.find('[data-drag-range]').exists()).toBe(false);
    });
});
