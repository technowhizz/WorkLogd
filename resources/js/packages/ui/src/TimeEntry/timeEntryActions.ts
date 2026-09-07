import type { CreateTimeEntryBody, TimeEntry } from '@/packages/api/src';
import { getDayJsInstance } from '../utils/time';

/**
 * Duplicate and split, in one place.
 *
 * Both are offered from the calendar's right-click menu and from the edit dialog, and the two
 * must behave identically - a split that halves differently depending on where it was started
 * from would be its own bug report.
 */

/** The fields a copy of an entry carries over. Deliberately not the id, start or end. */
function copyableFields(
    entry: TimeEntry
): Omit<CreateTimeEntryBody, 'member_id' | 'start' | 'end'> {
    return {
        billable: entry.billable,
        type: entry.type,
        description: entry.description,
        project_id: entry.project_id,
        task_id: entry.task_id,
        tags: entry.tags,
    } as Omit<CreateTimeEntryBody, 'member_id' | 'start' | 'end'>;
}

export function duplicatePayload(entry: TimeEntry): Omit<CreateTimeEntryBody, 'member_id'> | null {
    if (entry.end === null) {
        return null;
    }

    return {
        ...copyableFields(entry),
        start: entry.start,
        end: entry.end,
    } as Omit<CreateTimeEntryBody, 'member_id'>;
}

/**
 * Where a split falls: the midpoint, rounded down to the minute.
 *
 * Rounded because the times are shown and edited to the minute, so a boundary at 10:17:30 would
 * display as two entries that appear to overlap.
 */
export function splitPointOf(entry: TimeEntry): string | null {
    if (entry.end === null) {
        return null;
    }

    const start = getDayJsInstance()(entry.start);
    const end = getDayJsInstance()(entry.end);

    return start
        .add(end.diff(start) / 2, 'millisecond')
        .startOf('minute')
        .utc()
        .format();
}

/**
 * Split an entry in two at its midpoint.
 *
 * The original is shortened first and the second half created after, so a failure part way leaves
 * the entry short rather than duplicated. The caller restores the original if the create fails -
 * losing half of somebody's tracked time silently would be far worse than an error.
 */
export async function splitTimeEntry(
    entry: TimeEntry,
    updateTimeEntry: (entry: TimeEntry) => Promise<void>,
    createTimeEntry: (entry: Omit<CreateTimeEntryBody, 'member_id'>) => Promise<unknown> | unknown
): Promise<{ ok: boolean }> {
    const midpoint = splitPointOf(entry);

    if (midpoint === null || entry.end === null) {
        return { ok: false };
    }

    try {
        await updateTimeEntry({ ...entry, end: midpoint });
    } catch {
        return { ok: false };
    }

    try {
        await createTimeEntry({
            ...copyableFields(entry),
            start: midpoint,
            end: entry.end,
        } as Omit<CreateTimeEntryBody, 'member_id'>);
    } catch {
        try {
            await updateTimeEntry({ ...entry });
        } catch {
            // Restoration failed too; the caller refreshes and the server state stands.
        }

        return { ok: false };
    }

    return { ok: true };
}
