import { computed, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import type { TimeEntry } from '@/packages/api/src';
import { useEventResize } from './useEventResize';
import type { CalendarEvent, DayEvent } from './calendarTypes';
import { getLocalizedDayJs } from '../utils/time';
import {
    TEST_DAY,
    dispatchPointer,
    minutesToPixels,
    pixelsToMinutesFromMidnight,
    pointerDownEvent,
    pressKey,
    testCalendarSettings,
    testViewDays,
    withSetup,
} from './testUtils';

function calendarEvent(): CalendarEvent {
    const timeEntry = {
        id: 'te-1',
        start: `${TEST_DAY}T10:00:00Z`,
        end: `${TEST_DAY}T11:00:00Z`,
        duration: 3600,
        description: 'Work',
        project_id: null,
        task_id: null,
        organization_id: 'org-1',
        user_id: 'user-1',
        tags: [],
        billable: false,
        type: 'work',
    } as unknown as TimeEntry;

    return {
        id: timeEntry.id,
        timeEntry,
        isRunning: false,
        isBreak: false,
        isMisplacedBreak: false,
        durationMinutes: 60,
        title: 'Work',
        backgroundColor: '#fff',
        borderColor: '#000',
        dayStart: getLocalizedDayJs(timeEntry.start),
        dayEnd: getLocalizedDayJs(timeEntry.end),
    } as CalendarEvent;
}

const ORIGINAL_TOP = minutesToPixels(10 * 60);
const ORIGINAL_HEIGHT = minutesToPixels(60);

function dayEvent(event: CalendarEvent): DayEvent {
    return {
        event,
        top: ORIGINAL_TOP,
        height: ORIGINAL_HEIGHT,
        left: '0',
        width: '100%',
        isClippedStart: false,
        isClippedEnd: false,
    };
}

describe('useEventResize escape-to-cancel', () => {
    const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
    const emitRefresh = vi.fn();
    let teardown: (() => void) | null = null;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        teardown?.();
        teardown = null;
        document.body.classList.remove('fc-resizing-active');
    });

    function resize() {
        const { result, unmount } = withSetup(() =>
            useEventResize({
                calendarSettings: ref({ ...testCalendarSettings }),
                viewDays: computed(testViewDays),
                eventsByDay: computed(() => ({}) as Record<string, DayEvent[]>),
                optimisticOverrides: ref(new Map<string, TimeEntry>()),
                updateTimeEntry,
                emitRefresh,
                minutesToPixels,
                pixelsToMinutesFromMidnight,
                getDayFromClientX: () => TEST_DAY,
                clientYToGridPixels: (clientY: number) => clientY,
            })
        );
        teardown = unmount;

        const event = calendarEvent();
        return {
            ...result,
            /** Grab the bottom edge and drag it down to 12:00. */
            grabBottomEdge: () =>
                result.onResizerPointerDown(
                    pointerDownEvent(10, ORIGINAL_TOP + ORIGINAL_HEIGHT),
                    event,
                    dayEvent(event),
                    'end',
                    TEST_DAY
                ),
        };
    }

    it('saves the resize on pointer-up when escape is not pressed', async () => {
        const r = resize();
        r.grabBottomEdge();
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));
        dispatchPointer('pointerup', 10, minutesToPixels(12 * 60));
        await flushPromises();

        expect(updateTimeEntry).toHaveBeenCalledTimes(1);
    });

    it('discards the resize when escape is pressed mid-drag', () => {
        const r = resize();
        r.grabBottomEdge();
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));
        expect(r.isResizing.value).toBe(true);

        pressKey('Escape');

        expect(r.isResizing.value).toBe(false);
        expect(r.resizeEventId.value).toBeNull();
        expect(r.resizeCurrentTop.value).toBe(ORIGINAL_TOP);
        expect(r.resizeCurrentHeight.value).toBe(ORIGINAL_HEIGHT);
        expect(r.resizeLiveDurationSeconds.value).toBeNull();
        expect(updateTimeEntry).not.toHaveBeenCalled();
    });

    it('drops the resizing cursor override on cancel', () => {
        const r = resize();
        r.grabBottomEdge();
        expect(document.body.classList.contains('fc-resizing-active')).toBe(true);

        pressKey('Escape');

        expect(document.body.classList.contains('fc-resizing-active')).toBe(false);
    });

    it('stays cancelled — releasing the button after escape saves nothing', async () => {
        const r = resize();
        r.grabBottomEdge();
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));

        pressKey('Escape');
        dispatchPointer('pointermove', 10, minutesToPixels(14 * 60));
        dispatchPointer('pointerup', 10, minutesToPixels(14 * 60));
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
    });

    it('ignores keys other than escape', () => {
        const r = resize();
        r.grabBottomEdge();
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));

        pressKey('a');

        expect(r.isResizing.value).toBe(true);
    });
});

describe('useEventResize commit guards', () => {
    const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
    const emitRefresh = vi.fn();
    let teardown: (() => void) | null = null;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        teardown?.();
        teardown = null;
        document.body.classList.remove('fc-resizing-active');
    });

    /**
     * Same harness as above, but the event under test is configurable and the
     * optimistic-override map is handed back so a test can prove that a click
     * left no trace at all.
     */
    function resize(event: CalendarEvent = calendarEvent(), top = ORIGINAL_TOP, height?: number) {
        const optimisticOverrides = ref(new Map<string, TimeEntry>());
        const { result, unmount } = withSetup(() =>
            useEventResize({
                calendarSettings: ref({ ...testCalendarSettings }),
                viewDays: computed(testViewDays),
                eventsByDay: computed(() => ({}) as Record<string, DayEvent[]>),
                optimisticOverrides,
                updateTimeEntry,
                emitRefresh,
                minutesToPixels,
                pixelsToMinutesFromMidnight,
                getDayFromClientX: () => TEST_DAY,
                clientYToGridPixels: (clientY: number) => clientY,
            })
        );
        teardown = unmount;

        const laidOut = { ...dayEvent(event), top, height: height ?? ORIGINAL_HEIGHT };
        return {
            ...result,
            optimisticOverrides,
            bottom: laidOut.top + laidOut.height,
            grabEdge: (edge: 'start' | 'end') =>
                result.onResizerPointerDown(
                    pointerDownEvent(
                        10,
                        edge === 'end' ? laidOut.top + laidOut.height : laidOut.top
                    ),
                    event,
                    laidOut,
                    edge,
                    TEST_DAY
                ),
        };
    }

    function runningEvent(): CalendarEvent {
        const base = calendarEvent();
        return {
            ...base,
            isRunning: true,
            timeEntry: { ...base.timeEntry, end: null } as unknown as TimeEntry,
        } as CalendarEvent;
    }

    /** 10:03–10:57 — deliberately off the 15-minute grid. */
    function offGridEvent(): CalendarEvent {
        const base = calendarEvent();
        return {
            ...base,
            timeEntry: {
                ...base.timeEntry,
                start: `${TEST_DAY}T10:03:00Z`,
                end: `${TEST_DAY}T10:57:00Z`,
            } as unknown as TimeEntry,
        } as CalendarEvent;
    }

    it('saves nothing when the handle is pressed and released without moving', async () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointerup', 10, r.bottom);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
        expect(r.optimisticOverrides.value.size).toBe(0);
        expect(r.isResizing.value).toBe(false);
        expect(r.resizeEventId.value).toBeNull();
        expect(document.body.classList.contains('fc-resizing-active')).toBe(false);
    });

    it('saves nothing when the pointer jitters below the drag threshold', async () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointermove', 12, r.bottom + 2);
        dispatchPointer('pointerup', 12, r.bottom + 2);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
        expect(r.isResizing.value).toBe(false);
    });

    it('does not start a resize preview before the threshold is passed', () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointermove', 10, r.bottom + 2);

        expect(r.isResizing.value).toBe(false);
        expect(r.resizeEventId.value).toBeNull();
        expect(r.resizeLiveDurationSeconds.value).toBeNull();
        // The cursor override is still owned by the gesture until the button is released.
        expect(document.body.classList.contains('fc-resizing-active')).toBe(true);
    });

    it('leaves an off-grid entry untouched when its handle is only clicked', async () => {
        const event = offGridEvent();
        const originalStart = event.timeEntry.start;
        const originalEnd = event.timeEntry.end;

        const r = resize(event, minutesToPixels(10 * 60 + 3), minutesToPixels(54));
        r.grabEdge('end');
        dispatchPointer('pointerup', 10, r.bottom);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
        expect(r.optimisticOverrides.value.size).toBe(0);
        expect(event.timeEntry.start).toBe(originalStart);
        expect(event.timeEntry.end).toBe(originalEnd);
    });

    it('does not stop a running entry when its bottom handle is only clicked', async () => {
        const r = resize(runningEvent());
        r.grabEdge('end');
        dispatchPointer('pointerup', 10, r.bottom);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
    });

    it('saves nothing when the top handle is only clicked', async () => {
        const r = resize();
        r.grabEdge('start');
        dispatchPointer('pointerup', 10, ORIGINAL_TOP);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
    });

    it('saves nothing when a real resize lands back on the original times', async () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));
        expect(r.isResizing.value).toBe(true);

        dispatchPointer('pointermove', 10, minutesToPixels(11 * 60));
        dispatchPointer('pointerup', 10, minutesToPixels(11 * 60));
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(emitRefresh).not.toHaveBeenCalled();
        expect(r.optimisticOverrides.value.size).toBe(0);
    });

    it('still saves a resize that changes the times', async () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));
        dispatchPointer('pointerup', 10, minutesToPixels(12 * 60));
        await flushPromises();

        expect(updateTimeEntry).toHaveBeenCalledTimes(1);
        const saved = updateTimeEntry.mock.calls[0]![0] as TimeEntry;
        expect(saved.start).toBe(`${TEST_DAY}T10:00:00Z`);
        expect(getLocalizedDayJs(saved.end).format('HH:mm')).toBe('12:00');
        expect(emitRefresh).toHaveBeenCalledTimes(1);
        expect(r.optimisticOverrides.value.get('te-1')).toBeDefined();
    });

    it('allows a second gesture to resize after a click that saved nothing', async () => {
        const r = resize();
        r.grabEdge('end');
        dispatchPointer('pointerup', 10, r.bottom);
        await flushPromises();
        expect(updateTimeEntry).not.toHaveBeenCalled();

        r.grabEdge('end');
        dispatchPointer('pointermove', 10, minutesToPixels(12 * 60));
        dispatchPointer('pointerup', 10, minutesToPixels(12 * 60));
        await flushPromises();

        expect(updateTimeEntry).toHaveBeenCalledTimes(1);
    });
});
