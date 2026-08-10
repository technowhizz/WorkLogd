import { computed, ref, onUnmounted, type Ref, type ComputedRef } from 'vue';
import type { Dayjs } from 'dayjs';
import type { TimeEntry } from '@/packages/api/src';
import { getLocalizedDayJs, getLocalizedDayJsFromMinutes } from '../utils/time';
import type { CalendarSettings } from './calendarSettings';
import { pixelsToMinutesFor } from './calendarSettings';
import type { CalendarEvent, DayEvent } from './calendarTypes';
import { DRAG_THRESHOLD } from './calendarTypes';
import { createEscapeCancel } from './escapeCancel';

export function useEventDrag(params: {
    calendarSettings: Ref<CalendarSettings>;
    viewDays: ComputedRef<Dayjs[]>;
    optimisticOverrides: Ref<Map<string, TimeEntry>>;
    updateTimeEntry: (entry: TimeEntry) => Promise<void>;
    emitRefresh: () => void;
    minutesToPixels: (minutes: number) => number;
    pixelsToMinutesFromMidnight: (px: number) => number;
    getDayFromClientX: (clientX: number) => string | null;
    clientYToGridPixels: (clientY: number) => number;
    onClickEvent: (ev: CalendarEvent) => void;
}) {
    const isDragging = ref(false);
    const dragEventId = ref<string | null>(null);
    const dragOffsetMinutes = ref(0);
    const dragCurrentTop = ref(0);
    /**
     * Snapped, clamped minutes-from-midnight where the dragged segment currently starts.
     * `dragCurrentTop` is this same value in pixels; keeping the minutes as well means the
     * live times are read off the number the gesture actually produced rather than a
     * pixel round-trip back through the zoom factor.
     */
    const dragCurrentMinutes = ref(0);
    const dragCurrentDay = ref<string | null>(null);
    const dragOriginalDayStr = ref<string | null>(null);
    const dragOriginalHeight = ref(0);
    const dragVisibleDurationMinutes = ref(0);

    // Non-reactive state
    let dragStartClientX = 0;
    let dragStartClientY = 0;
    let dragStartEventTop = 0;
    let dragOriginalEvent: CalendarEvent | null = null;
    let dragFullDurationMinutes = 0;
    let dragEventStartOffsetMinutes = 0;
    let hasMoved = false;

    const escapeCancel = createEscapeCancel(() => cancelDrag());

    function addDragListeners() {
        document.addEventListener('pointermove', onDragPointerMove);
        document.addEventListener('pointerup', onDragPointerUp);
        escapeCancel.listen();
    }

    /**
     * The closed hand belongs to a move in progress, so it is switched on where the drag
     * actually begins — at the threshold in `onDragPointerMove` — and not here, since the
     * listeners are attached on pointer-down, while the gesture may still turn out to be a
     * plain click. Switching it off in the teardown instead of at each exit covers pointer-up,
     * escape-to-cancel and unmount alike, so the cursor can never be left stuck.
     */
    function removeDragListeners() {
        document.body.classList.remove('fc-dragging-active');
        document.removeEventListener('pointermove', onDragPointerMove);
        document.removeEventListener('pointerup', onDragPointerUp);
        escapeCancel.stop();
    }

    /**
     * Aborts an in-flight move: the preview is discarded and the entry keeps
     * its stored times — no optimistic override and no update request.
     *
     * This also suppresses the click-to-edit path in `onDragPointerUp`, so
     * cancelling before the drag threshold is passed opens no modal either.
     * Detaching the listeners makes the cancel terminal, and also drops the
     * `fc-dragging-active` cursor override.
     */
    function cancelDrag() {
        removeDragListeners();

        isDragging.value = false;
        dragEventId.value = null;
        dragOriginalDayStr.value = null;
        dragCurrentDay.value = null;

        dragOriginalEvent = null;
        hasMoved = false;
    }

    function onEventPointerDown(e: PointerEvent, ev: CalendarEvent, dayEvent: DayEvent) {
        if (e.button !== 0) return;
        const target = e.target as HTMLElement;
        if (target.closest('.fc-event-resizer')) return;
        if (ev.isRunning) return;

        e.preventDefault();

        dragStartClientX = e.clientX;
        dragStartClientY = e.clientY;
        dragStartEventTop = dayEvent.top;
        dragOriginalEvent = ev;
        hasMoved = false;
        dragOriginalHeight.value = dayEvent.height;

        const s = params.calendarSettings.value;
        dragVisibleDurationMinutes.value = pixelsToMinutesFor(s, dayEvent.height);

        const originDay = params.getDayFromClientX(e.clientX);
        dragOriginalDayStr.value = originDay;

        if (ev.timeEntry.end) {
            const evStart = getLocalizedDayJs(ev.timeEntry.start);
            const evEnd = getLocalizedDayJs(ev.timeEntry.end);
            dragFullDurationMinutes = evEnd.diff(evStart, 'minute');
        } else {
            dragFullDurationMinutes = dragVisibleDurationMinutes.value;
        }

        if (dayEvent.isClippedStart && originDay && ev.timeEntry.end) {
            const dayMidnight = getLocalizedDayJsFromMinutes(originDay, 0);
            const evStart = getLocalizedDayJs(ev.timeEntry.start);
            const eventStartFromGridStart = evStart.diff(dayMidnight, 'minute') - s.startHour * 60;
            const segmentTopMinutes = pixelsToMinutesFor(s, dayEvent.top);
            dragEventStartOffsetMinutes = segmentTopMinutes - eventStartFromGridStart;
        } else {
            dragEventStartOffsetMinutes = 0;
        }

        const gridY = params.clientYToGridPixels(e.clientY);
        dragOffsetMinutes.value =
            params.pixelsToMinutesFromMidnight(gridY) -
            params.pixelsToMinutesFromMidnight(dayEvent.top);

        addDragListeners();
    }

    function onDragPointerMove(e: PointerEvent) {
        if (!dragOriginalEvent) return;
        const dx = e.clientX - dragStartClientX;
        const dy = e.clientY - dragStartClientY;

        if (!hasMoved && Math.sqrt(dx * dx + dy * dy) < DRAG_THRESHOLD) {
            return;
        }

        if (!hasMoved) {
            hasMoved = true;
            isDragging.value = true;
            dragEventId.value = dragOriginalEvent!.id;
            document.body.classList.add('fc-dragging-active');
        }

        const s = params.calendarSettings.value;
        const startMin = s.startHour * 60;

        const clampedMinutes = clampedMinutesFromClientY(e.clientY);
        dragCurrentMinutes.value = clampedMinutes;
        dragCurrentTop.value = params.minutesToPixels(clampedMinutes - startMin);

        const dayStr = params.getDayFromClientX(e.clientX);
        if (dayStr) {
            dragCurrentDay.value = dayStr;
        }
    }

    /**
     * Where the dragged segment starts, in minutes from midnight, for a given cursor
     * position: snapped to the grid and clamped to the visible window (with the same four
     * hours of slack above it the move has always allowed).
     */
    function clampedMinutesFromClientY(clientY: number): number {
        const s = params.calendarSettings.value;
        const startMin = s.startHour * 60;
        const gridY = params.clientYToGridPixels(clientY);
        const rawMinutes = params.pixelsToMinutesFromMidnight(gridY) - dragOffsetMinutes.value;
        const snappedMinutes = Math.floor(rawMinutes / s.snapMinutes) * s.snapMinutes;
        const lowerBound = startMin - 4 * 60;
        return Math.max(lowerBound, Math.min(snappedMinutes, s.endHour * 60));
    }

    /**
     * Materializes the drag state into the times the entry would get. Pure and derived only
     * from `dragCurrentMinutes` / `dragCurrentDay`, so it serves both the pointer-up commit
     * and the live read the preview labels do mid-drag — what you see while dragging is by
     * construction what gets saved.
     *
     * `null` while there is nothing to move: no event under the pointer, or a running entry,
     * which has no end to shift.
     */
    function computeDraggedTimes(): { start: Dayjs; end: Dayjs } | null {
        const ev = dragOriginalEvent;
        if (!ev || !ev.timeEntry.end) return null;

        const targetDateStr =
            dragCurrentDay.value ||
            dragOriginalDayStr.value ||
            params.viewDays.value[0]!.format('YYYY-MM-DD');
        const originalDayStr = dragOriginalDayStr.value || targetDateStr;

        const s = params.calendarSettings.value;
        const startMin = s.startHour * 60;

        const originalSegmentStart = getLocalizedDayJsFromMinutes(
            originalDayStr,
            startMin + params.pixelsToMinutesFromMidnight(dragStartEventTop)
        );
        const newSegmentStart = getLocalizedDayJsFromMinutes(
            targetDateStr,
            dragCurrentMinutes.value
        );
        const deltaMs = newSegmentStart.diff(originalSegmentStart);

        const origStart = getLocalizedDayJs(ev.timeEntry.start);
        const origEnd = getLocalizedDayJs(ev.timeEntry.end);
        const durationMs = origEnd.diff(origStart);
        const newStartLocal = origStart.add(deltaMs, 'millisecond');

        return { start: newStartLocal, end: newStartLocal.add(durationMs, 'millisecond') };
    }

    /**
     * The moved entry's times as they stand right now, so the preview can show the range and
     * duration *while* you drag rather than only once the entry has landed.
     */
    const dragTimes = computed<{ start: Dayjs; end: Dayjs } | null>(() =>
        isDragging.value ? computeDraggedTimes() : null
    );

    const dragDurationSeconds = computed<number | null>(() => {
        const times = dragTimes.value;
        if (!times) return null;
        const diff = times.end.diff(times.start, 'second');
        return diff > 0 ? diff : 0;
    });

    async function onDragPointerUp(e: PointerEvent) {
        removeDragListeners();

        if (!hasMoved) {
            isDragging.value = false;
            dragEventId.value = null;
            dragOriginalDayStr.value = null;
            dragCurrentDay.value = null;
            if (dragOriginalEvent && !dragOriginalEvent.isRunning) {
                params.onClickEvent(dragOriginalEvent);
            }
            return;
        }

        // The pointer can travel between the last move event and the release, so the drop
        // position is re-read here — the same `clientY` the commit has always used — and then
        // materialized through the one function the live preview reads from.
        dragCurrentMinutes.value = clampedMinutesFromClientY(e.clientY);
        const times = computeDraggedTimes();
        const timeEntry = dragOriginalEvent?.timeEntry;

        isDragging.value = false;
        dragEventId.value = null;
        dragOriginalDayStr.value = null;
        dragCurrentDay.value = null;

        if (!times || !timeEntry) return;

        const updatedTimeEntry = {
            ...timeEntry,
            start: times.start.utc().format(),
            end: times.end.utc().format(),
        } as TimeEntry;

        params.optimisticOverrides.value = new Map(params.optimisticOverrides.value).set(
            updatedTimeEntry.id,
            updatedTimeEntry
        );

        try {
            await params.updateTimeEntry(updatedTimeEntry);
        } catch {
            // Revert optimistic override on failure; mutation layer already shows error notification
            const reverted = new Map(params.optimisticOverrides.value);
            reverted.delete(updatedTimeEntry.id);
            params.optimisticOverrides.value = reverted;
        }
        params.emitRefresh();
    }

    /**
     * Computes a preview style for every day column that the dragged event
     * would span. Derives the actual start/end datetime of the moved event,
     * then clips each view day's grid to show the visible portion.
     */
    const dragPreviewsByDay = computed<Record<string, Record<string, string>>>(() => {
        if (!isDragging.value || !dragOriginalEvent) return {};
        if (!dragCurrentDay.value) return {};

        const s = params.calendarSettings.value;
        const gridTotalMinutes = (s.endHour - s.startHour) * 60;
        const startMin = s.startHour * 60;
        const currentTopMinutes = pixelsToMinutesFor(s, dragCurrentTop.value);

        const offset =
            dragCurrentDay.value === dragOriginalDayStr.value ? dragEventStartOffsetMinutes : 0;

        // Minutes from grid-start on cursor day where the event visually begins
        const eventStartOnGrid = currentTopMinutes - offset;

        const baseStyle = {
            position: 'absolute',
            left: '0',
            right: '0',
            backgroundColor: dragOriginalEvent.backgroundColor,
            borderColor: dragOriginalEvent.borderColor,
            opacity: '0.7',
            zIndex: '100',
            borderRadius: 'calc(var(--radius) - 4px)',
            border: '1px solid var(--border)',
        };

        // Single-day fast path: event fits within cursor day's grid
        const eventEndOnGrid = eventStartOnGrid + dragFullDurationMinutes;
        if (eventEndOnGrid <= gridTotalMinutes && eventStartOnGrid >= 0) {
            const previewTop = params.minutesToPixels(eventStartOnGrid);
            const previewHeight = params.minutesToPixels(
                Math.max(s.snapMinutes, dragFullDurationMinutes)
            );
            return {
                [dragCurrentDay.value]: {
                    ...baseStyle,
                    top: `${previewTop}px`,
                    height: `${previewHeight}px`,
                },
            };
        }

        // Multi-day: compute actual start/end datetimes, then clip per day
        const eventStartAbsolute = getLocalizedDayJsFromMinutes(
            dragCurrentDay.value,
            startMin + eventStartOnGrid
        );
        const eventEndAbsolute = getLocalizedDayJsFromMinutes(
            dragCurrentDay.value,
            startMin + eventStartOnGrid + dragFullDurationMinutes
        );

        const result: Record<string, Record<string, string>> = {};

        for (const viewDay of params.viewDays.value) {
            const dayStr = viewDay.format('YYYY-MM-DD');
            const dayGridStart = viewDay.startOf('day').add(s.startHour, 'hour');
            const dayGridEnd = viewDay.startOf('day').add(s.endHour, 'hour');

            // Does the event overlap this day's grid window?
            if (eventEndAbsolute.isAfter(dayGridStart) && eventStartAbsolute.isBefore(dayGridEnd)) {
                const segStart = eventStartAbsolute.isAfter(dayGridStart)
                    ? eventStartAbsolute
                    : dayGridStart;
                const segEnd = eventEndAbsolute.isBefore(dayGridEnd)
                    ? eventEndAbsolute
                    : dayGridEnd;
                const segStartMin = segStart.diff(dayGridStart, 'minute');
                const segEndMin = segEnd.diff(dayGridStart, 'minute');
                const segHeight = Math.max(s.snapMinutes, segEndMin - segStartMin);

                result[dayStr] = {
                    ...baseStyle,
                    top: `${params.minutesToPixels(segStartMin)}px`,
                    height: `${params.minutesToPixels(segHeight)}px`,
                };
            }
        }

        return result;
    });

    onUnmounted(() => {
        removeDragListeners();
    });

    return {
        isDragging,
        dragEventId,
        dragCurrentTop,
        dragCurrentDay,
        dragOriginalDayStr,
        dragOriginalHeight,
        dragVisibleDurationMinutes,
        dragPreviewsByDay,
        dragTimes,
        dragDurationSeconds,
        onEventPointerDown,
    };
}
