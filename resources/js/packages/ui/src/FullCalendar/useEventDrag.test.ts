import { computed, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import type { TimeEntry } from '@/packages/api/src';
import { useEventDrag } from './useEventDrag';
import type { CalendarEvent, DayEvent } from './calendarTypes';
import { getDayJsInstance, getLocalizedDayJs } from '../utils/time';
import {
    TEST_DAY,
    dispatchPointer,
    minutesToPixels,
    pixelsToMinutesFromMidnight,
    pointerDownEvent,
    pressKey,
    testCalendarSettings,
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

function dayEvent(event: CalendarEvent): DayEvent {
    return {
        event,
        top: minutesToPixels(10 * 60),
        height: minutesToPixels(60),
        left: '0',
        width: '100%',
        isClippedStart: false,
        isClippedEnd: false,
    };
}

const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
const onClickEvent = vi.fn();
const emitRefresh = vi.fn();

/** Anything left of this x is the first day of the view, anything right of it the last. */
const DAY_BOUNDARY_X = 100;

function mountDrag(days: string[] = [TEST_DAY]) {
    const { result, unmount } = withSetup(() =>
        useEventDrag({
            calendarSettings: ref({ ...testCalendarSettings }),
            viewDays: computed(() => days.map((d) => getDayJsInstance()(d))),
            optimisticOverrides: ref(new Map<string, TimeEntry>()),
            updateTimeEntry,
            emitRefresh,
            minutesToPixels,
            pixelsToMinutesFromMidnight,
            getDayFromClientX: (clientX: number) =>
                clientX < DAY_BOUNDARY_X ? days[0]! : days[days.length - 1]!,
            clientYToGridPixels: (clientY: number) => clientY,
            onClickEvent,
        })
    );

    const event = calendarEvent();
    return {
        ...result,
        unmount,
        press: (clientX: number, clientY: number) =>
            result.onEventPointerDown(pointerDownEvent(clientX, clientY), event, dayEvent(event)),
    };
}

describe('useEventDrag escape-to-cancel', () => {
    let teardown: (() => void) | null = null;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        teardown?.();
        teardown = null;
    });

    function drag() {
        const d = mountDrag();
        teardown = d.unmount;
        return { ...d, press: (clientY: number) => d.press(10, clientY) };
    }

    it('saves the move on pointer-up when escape is not pressed', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);
        dispatchPointer('pointerup', 10, 1200);
        await flushPromises();

        expect(updateTimeEntry).toHaveBeenCalledTimes(1);
    });

    it('discards the move when escape is pressed mid-drag', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);
        expect(d.isDragging.value).toBe(true);

        pressKey('Escape');

        expect(d.isDragging.value).toBe(false);
        expect(d.dragEventId.value).toBeNull();
        expect(d.dragCurrentDay.value).toBeNull();
        expect(updateTimeEntry).not.toHaveBeenCalled();
    });

    it('stays cancelled — releasing the button after escape saves nothing', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);

        pressKey('Escape');
        dispatchPointer('pointermove', 10, 1400);
        dispatchPointer('pointerup', 10, 1400);
        await flushPromises();

        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(onClickEvent).not.toHaveBeenCalled();
    });

    it('suppresses click-to-edit when escape is pressed before the drag threshold', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1002); // below DRAG_THRESHOLD

        pressKey('Escape');
        dispatchPointer('pointerup', 10, 1002);
        await flushPromises();

        expect(onClickEvent).not.toHaveBeenCalled();
        expect(updateTimeEntry).not.toHaveBeenCalled();
    });

    it('still opens the edit modal for a plain click without escape', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointerup', 10, 1000);
        await flushPromises();

        expect(onClickEvent).toHaveBeenCalledTimes(1);
    });

    it('ignores keys other than escape', () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);

        pressKey('a');

        expect(d.isDragging.value).toBe(true);
    });
});

/**
 * The closed hand has to survive the pointer leaving the block it started on, so it is a class
 * on `body` rather than a rule on the event. It must appear only once the gesture is really a
 * move — the listeners go on at pointer-down, when it may still be a click — and it must be
 * gone by every exit, or the whole page is left stuck with a grabbing cursor.
 */
describe('useEventDrag grabbing cursor', () => {
    let teardown: (() => void) | null = null;

    const isGrabbing = () => document.body.classList.contains('fc-dragging-active');

    beforeEach(() => {
        vi.clearAllMocks();
        document.body.classList.remove('fc-dragging-active');
    });

    afterEach(() => {
        teardown?.();
        teardown = null;
        document.body.classList.remove('fc-dragging-active');
    });

    function drag() {
        const d = mountDrag();
        teardown = d.unmount;
        return { ...d, press: (clientY: number) => d.press(10, clientY) };
    }

    it('leaves the cursor alone on pointer-down', () => {
        const d = drag();
        d.press(1000);

        expect(isGrabbing()).toBe(false);
    });

    it('leaves it alone while the pointer stays inside the drag threshold', () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1002);

        expect(isGrabbing()).toBe(false);
    });

    it('grabs once the pointer passes the threshold', () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);

        expect(isGrabbing()).toBe(true);
    });

    it('releases on pointer-up', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);
        dispatchPointer('pointerup', 10, 1200);
        await flushPromises();

        expect(isGrabbing()).toBe(false);
    });

    it('releases when a click never becomes a drag', async () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointerup', 10, 1000);
        await flushPromises();

        expect(isGrabbing()).toBe(false);
        expect(onClickEvent).toHaveBeenCalledTimes(1);
    });

    it('releases when the drag is cancelled with escape', () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);
        expect(isGrabbing()).toBe(true);

        pressKey('Escape');

        expect(isGrabbing()).toBe(false);
    });

    it('releases when the calendar unmounts mid-drag', () => {
        const d = drag();
        d.press(1000);
        dispatchPointer('pointermove', 10, 1200);

        d.unmount();
        teardown = null;

        expect(isGrabbing()).toBe(false);
    });
});

/**
 * The preview states where the entry would land while it is being moved, so those times are
 * read off the drag state mid-gesture. They must agree with what pointer-up commits.
 *
 * The entry runs 10:00–11:00 and its block starts at 1000px, which at the default zoom
 * (100px/hour) is 10:00 — so the pointer's y is the wall clock it is pointing at, and
 * pressing at 1000 grabs the block by its very top.
 */
describe('useEventDrag live times', () => {
    const NEXT_DAY = '2026-07-15';
    let teardown: (() => void) | null = null;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        teardown?.();
        teardown = null;
    });

    function drag(days: string[] = [TEST_DAY]) {
        const d = mountDrag(days);
        teardown = d.unmount;
        return d;
    }

    /** Local wall clock of the live times, e.g. `['10:00', '11:00']`. */
    function liveRange(d: ReturnType<typeof drag>) {
        const times = d.dragTimes.value;
        return times ? [times.start.format('HH:mm'), times.end.format('HH:mm')] : null;
    }

    it('exposes nothing before a drag starts', () => {
        const d = drag();
        d.press(10, 1000);

        expect(d.dragTimes.value).toBeNull();
        expect(d.dragDurationSeconds.value).toBeNull();
    });

    it('stays quiet while the pointer is still inside the drag threshold', () => {
        const d = drag();
        d.press(10, 1000);
        dispatchPointer('pointermove', 10, 1002);

        expect(d.dragTimes.value).toBeNull();
    });

    it('reports where the entry would land once the drag starts', () => {
        const d = drag();
        d.press(10, 1000);
        dispatchPointer('pointermove', 10, 1200);

        expect(liveRange(d)).toEqual(['12:00', '13:00']);
        expect(d.dragDurationSeconds.value).toBe(3600);
    });

    it('updates as the pointer keeps moving', () => {
        const d = drag();
        d.press(10, 1000);

        dispatchPointer('pointermove', 10, 1200);
        expect(liveRange(d)).toEqual(['12:00', '13:00']);

        dispatchPointer('pointermove', 10, 900);
        expect(liveRange(d)).toEqual(['09:00', '10:00']);
    });

    it('reports the snapped position rather than the raw cursor one', () => {
        const d = drag();
        d.press(10, 1000);
        // 1205px is 12:03, which snaps back to the 15-minute boundary below it.
        dispatchPointer('pointermove', 10, 1205);

        expect(liveRange(d)).toEqual(['12:00', '13:00']);
    });

    it('keeps the duration of the entry being moved, however far it travels', () => {
        const d = drag();
        d.press(10, 1000);

        dispatchPointer('pointermove', 10, 100);
        expect(d.dragDurationSeconds.value).toBe(3600);

        dispatchPointer('pointermove', 10, 2300);
        expect(d.dragDurationSeconds.value).toBe(3600);
    });

    it('follows the entry onto another day', () => {
        const d = drag([TEST_DAY, NEXT_DAY]);
        d.press(10, 1000);
        dispatchPointer('pointermove', 200, 1000);

        const times = d.dragTimes.value!;
        expect(times.start.format('YYYY-MM-DD HH:mm')).toBe(`${NEXT_DAY} 10:00`);
        expect(times.end.format('YYYY-MM-DD HH:mm')).toBe(`${NEXT_DAY} 11:00`);
        expect(d.dragDurationSeconds.value).toBe(3600);
    });

    it('matches the times the move commits on pointer-up', async () => {
        const d = drag();
        d.press(10, 1000);
        dispatchPointer('pointermove', 10, 1175);

        const live = d.dragTimes.value!;
        const liveStart = live.start.utc().format();
        const liveEnd = live.end.utc().format();

        dispatchPointer('pointerup', 10, 1175);
        await flushPromises();

        expect(updateTimeEntry).toHaveBeenCalledTimes(1);
        const saved = updateTimeEntry.mock.calls[0]![0] as TimeEntry;
        expect(saved.start).toBe(liveStart);
        expect(saved.end).toBe(liveEnd);
    });

    it('reports nothing once the move has landed', async () => {
        const d = drag();
        d.press(10, 1000);
        dispatchPointer('pointermove', 10, 1200);
        dispatchPointer('pointerup', 10, 1200);
        await flushPromises();

        expect(d.dragTimes.value).toBeNull();
        expect(d.dragDurationSeconds.value).toBeNull();
    });

    it('reports nothing after an escape cancel', () => {
        const d = drag();
        d.press(10, 1000);
        dispatchPointer('pointermove', 10, 1200);

        pressKey('Escape');

        expect(d.dragTimes.value).toBeNull();
        expect(d.dragDurationSeconds.value).toBeNull();
    });
});
