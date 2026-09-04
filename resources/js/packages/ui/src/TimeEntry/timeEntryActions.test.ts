import { describe, expect, it, vi } from 'vitest';
import type { TimeEntry } from '@/packages/api/src';
import { duplicatePayload, splitPointOf, splitTimeEntry } from './timeEntryActions';

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

describe('duplicatePayload', () => {
    it('copies the entry without carrying its id across', () => {
        const payload = duplicatePayload(entry());

        expect(payload).not.toBeNull();
        expect(payload).not.toHaveProperty('id');
        expect(payload?.description).toBe('PROJ-1 a thing');
        expect(payload?.start).toBe('2026-08-10T09:00:00Z');
        expect(payload?.end).toBe('2026-08-10T11:00:00Z');
    });

    it('refuses a running entry, which has no length to copy', () => {
        expect(duplicatePayload(entry({ end: null }))).toBeNull();
    });
});

describe('splitPointOf', () => {
    it('falls at the midpoint', () => {
        expect(splitPointOf(entry())).toContain('10:00');
    });

    it('rounds down to the minute so the two halves do not appear to overlap', () => {
        // 09:00 to 10:01 halves at 09:30:30, which would display as two entries meeting twice.
        const point = splitPointOf(entry({ end: '2026-08-10T10:01:00Z' }));

        expect(point).toContain('09:30:00');
    });

    it('refuses a running entry', () => {
        expect(splitPointOf(entry({ end: null }))).toBeNull();
    });
});

describe('splitTimeEntry', () => {
    it('shortens the original and creates the second half', async () => {
        const update = vi.fn().mockResolvedValue(undefined);
        const create = vi.fn().mockResolvedValue(undefined);

        const result = await splitTimeEntry(entry(), update, create);

        expect(result.ok).toBe(true);
        expect(update).toHaveBeenCalledOnce();
        expect(create).toHaveBeenCalledOnce();
        // The original ends where the new one starts.
        const shortened = update.mock.calls[0]?.[0];
        const created = create.mock.calls[0]?.[0];
        expect(shortened?.end).toBe(created?.start);
        expect(created?.end).toBe('2026-08-10T11:00:00Z');
    });

    it('creates nothing when shortening the original failed', async () => {
        const update = vi.fn().mockRejectedValue(new Error('nope'));
        const create = vi.fn();

        const result = await splitTimeEntry(entry(), update, create);

        expect(result.ok).toBe(false);
        // Otherwise the entry would be duplicated rather than split.
        expect(create).not.toHaveBeenCalled();
    });

    it('puts the original back when creating the second half failed', async () => {
        const update = vi.fn().mockResolvedValue(undefined);
        const create = vi.fn().mockRejectedValue(new Error('nope'));

        const result = await splitTimeEntry(entry(), update, create);

        expect(result.ok).toBe(false);
        // Second call restores the original end, so half the tracked time is not silently lost.
        expect(update).toHaveBeenCalledTimes(2);
        expect(update.mock.calls[1]?.[0]?.end).toBe('2026-08-10T11:00:00Z');
    });

    it('refuses a running entry without touching anything', async () => {
        const update = vi.fn();
        const create = vi.fn();

        const result = await splitTimeEntry(entry({ end: null }), update, create);

        expect(result.ok).toBe(false);
        expect(update).not.toHaveBeenCalled();
        expect(create).not.toHaveBeenCalled();
    });
});
