import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CreateTimeEntryBody, TimeEntry } from '@/packages/api/src';
import { restorablePayload, useTimeEntryUndo } from './useTimeEntryUndo';

function entry(overrides: Partial<TimeEntry> = {}): TimeEntry {
    return {
        id: 'entry-1',
        start: '2026-08-10T09:00:00Z',
        end: '2026-08-10T11:00:00Z',
        billable: true,
        type: 'work',
        description: 'PROJ-1 a thing',
        project_id: 'project-1',
        task_id: null,
        tags: ['tag-1'],
        ...overrides,
    } as unknown as TimeEntry;
}

function payload(e: TimeEntry): Omit<CreateTimeEntryBody, 'member_id'> {
    return restorablePayload(e);
}

describe('useTimeEntryUndo', () => {
    beforeEach(() => {
        // Module-scoped state, so each test starts from nothing pending.
        useTimeEntryUndo().forget();
    });

    it('has nothing to undo until a deletion is remembered', () => {
        expect(useTimeEntryUndo().hasSomethingToUndo()).toBe(false);
    });

    it('restores the remembered entry', async () => {
        const restore = vi.fn().mockResolvedValue(undefined);
        const undoer = useTimeEntryUndo();
        undoer.remember({ entries: [payload(entry())], label: 'Time entry deleted', restore });

        expect(undoer.hasSomethingToUndo()).toBe(true);
        await expect(undoer.undo()).resolves.toBe(true);
        expect(restore).toHaveBeenCalledOnce();
        expect(restore.mock.calls[0]?.[0]?.description).toBe('PROJ-1 a thing');
    });

    it('restores every entry of a bulk deletion', async () => {
        const restore = vi.fn().mockResolvedValue(undefined);
        const undoer = useTimeEntryUndo();
        undoer.remember({
            entries: [payload(entry()), payload(entry({ id: 'entry-2' }))],
            label: '2 time entries deleted',
            restore,
        });

        await undoer.undo();

        expect(restore).toHaveBeenCalledTimes(2);
    });

    it('only undoes once, so a double press cannot duplicate the entry', async () => {
        const restore = vi.fn().mockResolvedValue(undefined);
        const undoer = useTimeEntryUndo();
        undoer.remember({ entries: [payload(entry())], label: 'Time entry deleted', restore });

        await undoer.undo();
        await expect(undoer.undo()).resolves.toBe(false);

        expect(restore).toHaveBeenCalledOnce();
    });

    it('drops what it was holding once a new deletion replaces it', async () => {
        const first = vi.fn().mockResolvedValue(undefined);
        const second = vi.fn().mockResolvedValue(undefined);
        const undoer = useTimeEntryUndo();

        undoer.remember({ entries: [payload(entry())], label: 'a', restore: first });
        undoer.remember({
            entries: [payload(entry({ id: 'entry-2' }))],
            label: 'b',
            restore: second,
        });
        await undoer.undo();

        // Only the most recent deletion is undoable - offering a stack would let somebody
        // resurrect something they deleted an hour ago without meaning to.
        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledOnce();
    });

    it('forgets on request', async () => {
        const restore = vi.fn();
        const undoer = useTimeEntryUndo();
        undoer.remember({ entries: [payload(entry())], label: 'a', restore });

        undoer.forget();

        expect(undoer.hasSomethingToUndo()).toBe(false);
        await expect(undoer.undo()).resolves.toBe(false);
    });
});

describe('restorablePayload', () => {
    it('carries the entry over without its id', () => {
        const result = restorablePayload(entry());

        expect(result).not.toHaveProperty('id');
        expect(result.start).toBe('2026-08-10T09:00:00Z');
        expect(result.end).toBe('2026-08-10T11:00:00Z');
        expect(result.tags).toEqual(['tag-1']);
    });
});
